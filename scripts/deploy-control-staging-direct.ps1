[CmdletBinding()]
param(
    [switch]$ValidateOnly
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$script:TargetSha = '2111243fff34f724d98c7ae6a825785967fbe882'
$script:VpsHost = '185.186.77.210'
$script:VpsUser = 'root'
$script:VpsProjectDirectory = '/opt/nexo-control-staging'
$script:ProjectName = 'nexo-control-staging'
$script:ImageTag = "nexo-control-staging:$($script:TargetSha)"

function Stop-Deployment {
    param([Parameter(Mandatory = $true)][string]$Message)
    throw $Message
}

function Find-Executable {
    param([Parameter(Mandatory = $true)][string]$Name, [string[]]$FallbackPaths = @())
    $found = Get-Command $Name -CommandType Application -ErrorAction SilentlyContinue | Select-Object -First 1
    if ($found) { return $found.Source }
    foreach ($path in $FallbackPaths) {
        if ($path -and (Test-Path -LiteralPath $path -PathType Leaf)) { return $path }
    }
    Stop-Deployment "Could not find $Name. Install the Windows OpenSSH client and Git, then retry."
}

function Invoke-Native {
    param(
        [Parameter(Mandatory = $true)][string]$FilePath,
        [Parameter(Mandatory = $true)][string[]]$Arguments,
        [Parameter(Mandatory = $true)][string]$Label
    )
    $output = @(& $FilePath @Arguments 2>&1 | ForEach-Object { [string]$_ })
    $exitCode = $LASTEXITCODE
    if ($exitCode -ne 0) {
        $detail = ($output -join [Environment]::NewLine).Trim()
        if ($detail.Length -gt 1800) { $detail = $detail.Substring(0, 1800) }
        Stop-Deployment "$Label failed (exit $exitCode). $detail"
    }
    return (($output | ForEach-Object { [string]$_ }) -join [Environment]::NewLine).Trim()
}

function Write-PosixScript {
    param(
        [Parameter(Mandatory = $true)][string]$Path,
        [Parameter(Mandatory = $true)][string]$Content
    )
    $lfContent = $Content.Replace("`r`n", "`n").Replace("`r", "`n")
    [IO.File]::WriteAllText($Path, $lfContent, [Text.Encoding]::ASCII)
}

function Get-HttpStatus {
    param([Parameter(Mandatory = $true)][string]$Uri)
    try {
        $response = Invoke-WebRequest -Uri $Uri -UseBasicParsing -TimeoutSec 6 -ErrorAction Stop
        return [int]$response.StatusCode
    } catch {
        if ($_.Exception.Response -and $_.Exception.Response.StatusCode) {
            return [int]$_.Exception.Response.StatusCode
        }
        return 0
    }
}

function Get-RemoteDeploymentScript {
    param([Parameter(Mandatory = $true)][string]$RunId)
    $scriptText = @'
#!/bin/sh
set -eu

TARGET_SHA='__TARGET_SHA__'
IMAGE_TAG='__IMAGE_TAG__'
RUN_ID='__RUN_ID__'
STAGING_DIR='/opt/nexo-control-staging'
PROJECT='nexo-control-staging'
BUNDLE_DIR="/tmp/nexo-control-direct-$RUN_ID"
MARKER='nexo-control-staging-v1'
BUILD_MEMORY='768m'

fail() { printf 'ERROR: %s\n' "$1" >&2; exit 1; }
case "$RUN_ID" in *[!a-f0-9]*|'') fail 'Invalid transfer run ID.';; esac
[ "${#RUN_ID}" -eq 32 ] || fail 'Invalid transfer run ID length.'
[ -d "$BUNDLE_DIR" ] || fail 'Unique transfer directory is missing.'
cleanup() { rm -rf -- "$BUNDLE_DIR"; }
trap cleanup EXIT
trap 'exit 1' HUP INT TERM

for tool in docker curl ss awk df getconf flock python3 tar sha256sum sed grep; do
    command -v "$tool" >/dev/null 2>&1 || fail "Required VPS utility is missing: $tool"
done
docker compose version >/dev/null 2>&1 || fail 'Docker Compose plugin is unavailable.'
exec 9>/tmp/nexo-control-staging-build.lock
flock -n 9 || fail 'Another NEXO staging build or deployment holds the lock; wait and retry.'

if [ -e "$STAGING_DIR" ]; then
    [ -d "$STAGING_DIR" ] || fail "$STAGING_DIR exists and is not a directory."
    if [ -f "$STAGING_DIR/.nexo-staging-owned" ]; then
        [ "$(cat "$STAGING_DIR/.nexo-staging-owned")" = "$MARKER" ] || fail 'Staging ownership marker mismatch; refusing to touch this directory.'
    elif [ -n "$(find "$STAGING_DIR" -mindepth 1 -maxdepth 1 -print -quit)" ]; then
        fail 'Staging directory contains unmarked files; refusing to overwrite.'
    fi
fi

MEM_TOTAL_KB=$(awk '/^MemTotal:/ {print $2}' /proc/meminfo)
MEM_AVAILABLE_KB=$(awk '/^MemAvailable:/ {print $2}' /proc/meminfo)
DISK_AVAILABLE_KB=$(df -Pk /opt | awk 'NR==2 {print $4}')
CPU_COUNT=$(getconf _NPROCESSORS_ONLN)
LOAD_ONE=$(awk '{print $1}' /proc/loadavg)
DOCKER_INFO=$(docker info --format '{{.NCPU}}|{{.MemTotal}}') || fail 'Docker Engine is not accessible.'
DOCKER_PLATFORM=$(docker info --format '{{.OSType}}|{{.Architecture}}') || fail 'Could not determine Docker Engine platform.'
[ "$DOCKER_PLATFORM" = 'linux|x86_64' ] || [ "$DOCKER_PLATFORM" = 'linux|amd64' ] || fail "Expected a linux/amd64 Docker Engine; received $DOCKER_PLATFORM."
DOCKER_CPU_COUNT=${DOCKER_INFO%%|*}
DOCKER_MEM_BYTES=${DOCKER_INFO#*|}
case "$MEM_TOTAL_KB:$MEM_AVAILABLE_KB:$DISK_AVAILABLE_KB:$CPU_COUNT:$DOCKER_CPU_COUNT:$DOCKER_MEM_BYTES" in
    *[!0-9:]*|'') fail 'Could not parse host and Docker capacity measurements.';;
esac
[ "$MEM_TOTAL_KB" -ge 1835008 ] || fail "Host RAM is below the 1.75 GiB safety floor: ${MEM_TOTAL_KB} KiB."
[ "$MEM_AVAILABLE_KB" -ge 1310720 ] || fail "Available RAM is below 1.25 GiB: ${MEM_AVAILABLE_KB} KiB; a 2 GiB VPS cannot safely build while production is using the remaining memory."
[ "$DOCKER_MEM_BYTES" -ge 1920000000 ] || fail 'Docker Engine reports less than 1.92 GB memory.'
[ "$CPU_COUNT" -ge 2 ] && [ "$DOCKER_CPU_COUNT" -ge 2 ] || fail 'At least two CPUs must be available to both host and Docker.'
[ "$DISK_AVAILABLE_KB" -ge 6291456 ] || fail "Less than 6 GiB is free on /opt: ${DISK_AVAILABLE_KB} KiB."
awk -v load="$LOAD_ONE" -v cpus="$CPU_COUNT" 'BEGIN {exit !(load < cpus)}' || fail "System load $LOAD_ONE is not below CPU count $CPU_COUNT."
docker buildx version >/dev/null 2>&1 || fail 'Docker Buildx is required for a resource-limited build.'
docker buildx build --help 2>&1 | grep -q -- '--resource' || fail 'Docker Buildx cannot enforce the required per-step resource limits.'
BUILDER_INFO=$(docker buildx inspect 2>/dev/null) || fail 'Could not inspect the VPS Docker Buildx builder.'
printf '%s\n' "$BUILDER_INFO" | grep -Eq '^Driver: docker$' || fail 'The selected Buildx builder is not the local Docker Engine; refusing a remote or separately managed builder.'
BUILD_CPU=$(python3 -c 'import re; s=open("/proc/self/status").read(); m=re.search(r"^Cpus_allowed_list:\s*(.+)$", s, re.M); assert m; print(m.group(1).split(",")[-1].split("-")[0])') || fail 'Could not select one CPU for the isolated staging build.'
printf 'Capacity preflight: host RAM=%s KiB available=%s KiB disk=%s KiB CPUs=%s Docker CPUs=%s Docker RAM=%s bytes load1=%s\n' \
    "$MEM_TOTAL_KB" "$MEM_AVAILABLE_KB" "$DISK_AVAILABLE_KB" "$CPU_COUNT" "$DOCKER_CPU_COUNT" "$DOCKER_MEM_BYTES" "$LOAD_ONE"

container_json=$(docker inspect nexo-nginx nexo-app) || fail 'Could not inspect the existing NEXO gateway and backend containers.'
network_name=$(printf '%s' "$container_json" | python3 -c '
import json, subprocess, sys
items=json.load(sys.stdin)
by_name={x.get("Name", "").lstrip("/"): x for x in items}
if set(("nexo-nginx", "nexo-app")) - set(by_name): sys.exit("Expected nexo-nginx and nexo-app containers.")
for name in ("nexo-nginx", "nexo-app"):
    if by_name[name].get("State", {}).get("Status") != "running": sys.exit(name + " is not running.")
shared=set(by_name["nexo-nginx"].get("NetworkSettings", {}).get("Networks", {})) & set(by_name["nexo-app"].get("NetworkSettings", {}).get("Networks", {}))
for network in sorted(shared):
    data=json.loads(subprocess.check_output(["docker", "network", "inspect", network], text=True))[0]
    containers=data.get("Containers") or {}
    names={v.get("Name") for v in containers.values()}
    ids=set(containers)
    if data.get("Driver")=="bridge" and data.get("Scope")=="local" and by_name["nexo-nginx"]["Id"] in ids and by_name["nexo-app"]["Id"] in ids and {"nexo-nginx", "nexo-app"} <= names:
        print(network); break
else: sys.exit("No verified local bridge network is shared by the NEXO gateway and backend.")
') || fail 'Could not identify a verified shared Docker network for the production API.'
[ -n "$network_name" ] || fail 'Verified NEXO network name is empty.'
case "$network_name" in *[!A-Za-z0-9_.-]*|[!A-Za-z0-9]*|'') fail 'Verified NEXO network name is not safe for a Compose file.';; esac

backend_status=$(curl --silent --show-error --output /dev/null --write-out '%{http_code}' --max-time 8 http://127.0.0.1:18100/api/v1/live) || fail 'Production API health request failed before deployment.'
[ "$backend_status" = 200 ] || fail "Production API returned HTTP $backend_status before deployment."

listener_text=$(ss -H -ltn)
if printf '%s\n' "$listener_text" | grep -Eq '(^|[[:space:]])[^[:space:]]*:18101([[:space:]]|$)'; then
    published=$(docker ps -q --filter publish=18101)
    [ -n "$published" ] || fail 'Port 18101 is already occupied outside Docker.'
    for cid in $published; do
        owner=$(docker inspect --format '{{index .Config.Labels "com.docker.compose.project"}}' "$cid")
        [ "$owner" = "$PROJECT" ] || fail 'Port 18101 is published by a container outside the staging project.'
    done
fi

snapshot_existing() {
    for cid in $(docker ps -aq); do
        project_label=$(docker inspect --format '{{index .Config.Labels "com.docker.compose.project"}}' "$cid")
        [ "$project_label" = "$PROJECT" ] && continue
        docker inspect --format '{{.Id}}|{{.Name}}|{{.State.Status}}|{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}|{{.State.StartedAt}}' "$cid"
    done | sort
}
BEFORE_SNAPSHOT=$(snapshot_existing)

archive="$BUNDLE_DIR/source.tar"
expected_archive_sha=$(cat "$BUNDLE_DIR/source.tar.sha256")
actual_archive_sha=$(sha256sum "$archive" | awk '{print $1}')
[ "$actual_archive_sha" = "$expected_archive_sha" ] || fail 'Transferred source archive checksum mismatch.'
python3 - "$archive" <<'PY'
import pathlib, sys, tarfile
with tarfile.open(sys.argv[1], "r:") as source:
    for member in source.getmembers():
        path = pathlib.PurePosixPath(member.name)
        if any(part == "node_modules" or part == ".next" or part.startswith(".env") for part in path.parts):
            raise SystemExit("Source archive contains a forbidden dependency, build, or environment path: " + member.name)
        if path.suffix.lower() in {".key", ".pem", ".p12", ".pfx", ".jks", ".keystore"}:
            raise SystemExit("Source archive contains a possible secret or private key: " + member.name)
PY
mkdir -p "$BUNDLE_DIR/context"
tar -xf "$archive" -C "$BUNDLE_DIR/context"
grep -Eq '^FROM node:22-alpine AS control-build$' "$BUNDLE_DIR/context/Dockerfile" || fail 'Pinned Dockerfile does not contain control-build stage.'
grep -Eq '^FROM node:22-alpine AS production-control$' "$BUNDLE_DIR/context/Dockerfile" || fail 'Pinned Dockerfile does not contain production-control target.'
[ -s "$BUNDLE_DIR/context/control-center/package-lock.json" ] || fail 'Pinned Control Center lockfile is missing.'

printf 'Building only target %s from source SHA %s with a 768 MiB per-step memory cap on CPU %s.\n' 'production-control' "$TARGET_SHA" "$BUILD_CPU"
if ! docker buildx build --platform linux/amd64 --progress=plain --load \
    --resource "memory=$BUILD_MEMORY" --resource "memory-swap=$BUILD_MEMORY" \
    --resource "cpuset-cpus=$BUILD_CPU" --resource 'cpu-quota=75000' --resource 'cpu-period=100000' \
    --target production-control --tag "$IMAGE_TAG" "$BUNDLE_DIR/context"; then
    fail 'Resource-limited Docker build failed. Production services and configuration were not changed.'
fi
docker image inspect "$IMAGE_TAG" >/dev/null || fail 'Built Control Center image tag was not found.'

mkdir -p "$STAGING_DIR"
printf '%s\n' "$MARKER" > "$STAGING_DIR/.nexo-staging-owned"
chmod 0755 "$STAGING_DIR"
cat > "$STAGING_DIR/compose.yml" <<EOF
services:
  control:
    image: $IMAGE_TAG
    restart: unless-stopped
    environment:
      NODE_OPTIONS: --max-old-space-size=192
    expose:
      - "3000"
    mem_limit: 256m
    memswap_limit: 256m
    cpus: 0.50
    pids_limit: 128
    healthcheck:
      test: ["CMD", "node", "-e", "fetch('http://127.0.0.1:3000/login').then(r=>process.exit(r.ok?0:1)).catch(()=>process.exit(1))"]
      interval: 20s
      timeout: 5s
      retries: 3
      start_period: 35s
    networks: [staging_ui]
  gateway:
    image: nginx:1.27-alpine
    restart: unless-stopped
    ports:
      - "127.0.0.1:18101:80"
    volumes:
      - ./default.conf:/etc/nginx/conf.d/default.conf:ro
    depends_on:
      control:
        condition: service_healthy
    mem_limit: 32m
    memswap_limit: 32m
    cpus: 0.10
    pids_limit: 64
    healthcheck:
      test: ["CMD", "wget", "-q", "-O", "/dev/null", "http://127.0.0.1/login"]
      interval: 20s
      timeout: 5s
      retries: 3
      start_period: 5s
    networks: [staging_ui, production_edge]
networks:
  staging_ui:
    internal: true
  production_edge:
    external: true
    name: $network_name
EOF
cat > "$STAGING_DIR/default.conf" <<'NGINX'
server {
    listen 80;
    location = /api/v1 { return 308 /api/v1/; }
    location ^~ /api/v1/ {
        proxy_pass http://nexo-nginx/api/v1/;
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
    location / {
        proxy_pass http://control:3000;
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
NGINX
printf 'NEXO_UPSTREAM_NETWORK=%s\n' "$network_name" > "$STAGING_DIR/.env"
chmod 0644 "$STAGING_DIR/compose.yml" "$STAGING_DIR/default.conf"
chmod 0600 "$STAGING_DIR/.env"
cd "$STAGING_DIR"
docker compose --project-name "$PROJECT" -f compose.yml config >/dev/null || fail 'Staging Compose validation failed.'

docker compose --project-name "$PROJECT" -f compose.yml up -d --no-deps --force-recreate control || fail 'Control Center container failed to start.'
control_cid=$(docker compose --project-name "$PROJECT" -f compose.yml ps -q control)
[ -n "$control_cid" ] || fail 'Control Center container ID is missing.'
attempt=0
while [ "$attempt" -lt 45 ]; do
    health=$(docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' "$control_cid")
    [ "$health" = healthy ] && break
    [ "$health" = unhealthy ] && fail 'Control Center health check is unhealthy.'
    sleep 2
    attempt=$((attempt + 1))
done
[ "$health" = healthy ] || fail 'Timed out waiting for Control Center health.'

docker compose --project-name "$PROJECT" -f compose.yml up -d --no-deps --force-recreate gateway || fail 'Staging gateway failed to start.'
gateway_cid=$(docker compose --project-name "$PROJECT" -f compose.yml ps -q gateway)
[ -n "$gateway_cid" ] || fail 'Staging gateway container ID is missing.'
attempt=0
while [ "$attempt" -lt 30 ]; do
    health=$(docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' "$gateway_cid")
    [ "$health" = healthy ] && break
    [ "$health" = unhealthy ] && fail 'Staging gateway health check is unhealthy.'
    sleep 2
    attempt=$((attempt + 1))
done
[ "$health" = healthy ] || fail 'Timed out waiting for staging gateway health.'

login_html=$(curl --silent --show-error --fail --max-time 10 http://127.0.0.1:18101/login) || fail 'Staging /login request failed.'
login_status=200
live_status=$(curl --silent --show-error --output /dev/null --write-out '%{http_code}' --max-time 10 http://127.0.0.1:18101/api/v1/live) || fail 'Staging API live request failed.'
me_status=$(curl --silent --show-error --output /dev/null --write-out '%{http_code}' --max-time 10 http://127.0.0.1:18101/api/v1/me) || fail 'Staging API authentication check request failed.'
[ "$login_status" = 200 ] || fail "Staging /login returned HTTP $login_status."
[ "$live_status" = 200 ] || fail "Staging API live returned HTTP $live_status."
[ "$me_status" = 401 ] || fail "Staging unauthenticated /api/v1/me returned HTTP $me_status, expected 401."
asset_paths=$(printf '%s' "$login_html" | grep -oE '/_next/static/[^" ]+\.(js|css)' | sort -u || true)
[ -n "$asset_paths" ] || fail 'No Next.js JavaScript or CSS assets were found in /login HTML.'
for asset_path in $asset_paths; do
    asset_status=$(curl --silent --show-error --output /dev/null --write-out '%{http_code}' --max-time 10 "http://127.0.0.1:18101$asset_path") || fail "Could not load static asset $asset_path."
    [ "$asset_status" = 200 ] || fail "Static asset $asset_path returned HTTP $asset_status."
done
binding=$(docker inspect --format '{{range (index .NetworkSettings.Ports "80/tcp")}}{{.HostIp}}:{{.HostPort}}{{end}}' "$gateway_cid")
[ "$binding" = '127.0.0.1:18101' ] || fail "Unexpected staging binding: $binding"

after_backend=$(curl --silent --show-error --output /dev/null --write-out '%{http_code}' --max-time 8 http://127.0.0.1:18100/api/v1/live) || fail 'Production API health request failed after deployment.'
[ "$after_backend" = 200 ] || fail "Production API returned HTTP $after_backend after deployment."
AFTER_SNAPSHOT=$(snapshot_existing)
[ "$BEFORE_SNAPSHOT" = "$AFTER_SNAPSHOT" ] || fail 'A pre-existing container ID, running state, health state or start time changed.'

printf 'Verified: source=%s /login=%s /api/v1/live=%s backend-before=%s backend-after=%s binding=%s\n' \
    "$TARGET_SHA" "$login_status" "$live_status" "$backend_status" "$after_backend" "$binding"
docker compose --project-name "$PROJECT" -f compose.yml ps
'@
    return $scriptText.Replace('__TARGET_SHA__', $script:TargetSha).
        Replace('__IMAGE_TAG__', $script:ImageTag).
        Replace('__RUN_ID__', $RunId)
}

function Test-StaticSource {
    param([Parameter(Mandatory = $true)][string]$Root)
    $git = Find-Executable -Name 'git.exe' -FallbackPaths @((Join-Path $env:ProgramFiles 'Git\cmd\git.exe'))
    Invoke-Native -FilePath $git -Arguments @('-C', $Root, 'cat-file', '-e', "$($script:TargetSha)^{commit}") -Label 'Pinned source commit check' | Out-Null
    foreach ($path in @('Dockerfile', 'control-center/package.json', 'control-center/package-lock.json')) {
        Invoke-Native -FilePath $git -Arguments @('-C', $Root, 'cat-file', '-e', "$($script:TargetSha):$path") -Label "Pinned source file check: $path" | Out-Null
    }
    $dockerfile = Invoke-Native -FilePath $git -Arguments @('-C', $Root, 'show', "$($script:TargetSha):Dockerfile") -Label 'Read pinned Dockerfile'
    if (-not $dockerfile.Contains('FROM node:22-alpine AS control-build') -or
        -not $dockerfile.Contains('FROM node:22-alpine AS production-control') -or
        -not $dockerfile.Contains('ENV NEXT_PUBLIC_API_BASE_URL=/api/v1')) {
        Stop-Deployment 'Pinned Dockerfile is missing the expected Control Center production build stages or relative API URL.'
    }
    if (-not $dockerfile.Contains('ENV NODE_OPTIONS=--max-old-space-size=512')) {
        Stop-Deployment 'Pinned build does not cap Next.js memory at 512 MiB; refusing an on-server build.'
    }
    $scriptText = Get-RemoteDeploymentScript -RunId '0123456789abcdef0123456789abcdef'
    foreach ($safetyPattern in @(
        '127\.0\.0\.1:18101:80',
        'docker buildx build --platform linux/amd64 --progress=plain --load',
        '--resource "memory=\$BUILD_MEMORY" --resource "memory-swap=\$BUILD_MEMORY"',
        '--resource "cpuset-cpus=\$BUILD_CPU"',
        'docker compose --project-name "\$PROJECT" -f compose\.yml up -d --no-deps --force-recreate control',
        'docker compose --project-name "\$PROJECT" -f compose\.yml up -d --no-deps --force-recreate gateway',
        'docker inspect nexo-nginx nexo-app',
        '/api/v1/live'
    )) {
        if ($scriptText -notmatch $safetyPattern) { Stop-Deployment "Generated deployment script is missing safety invariant: $safetyPattern" }
    }
    if ($scriptText -match 'docker\s+(?:compose\s+down|volume\s+rm|system\s+prune)|migrate|git\s+reset') {
        Stop-Deployment 'Generated script contains a prohibited destructive or migration command.'
    }
    $gitBash = Join-Path $env:ProgramFiles 'Git\bin\bash.exe'
    if (Test-Path -LiteralPath $gitBash -PathType Leaf) {
        $bash = [pscustomobject]@{ Source = $gitBash }
    } else {
        $bash = Get-Command 'bash.exe' -CommandType Application -ErrorAction SilentlyContinue | Select-Object -First 1
    }
    if ($bash) {
        $shellCheckPath = Join-Path ([IO.Path]::GetTempPath()) ("nexo-control-staging-shell-{0}.sh" -f [guid]::NewGuid().ToString('N'))
        try {
            Write-PosixScript -Path $shellCheckPath -Content $scriptText
            try {
                Invoke-Native -FilePath $bash.Source -Arguments @('-n', $shellCheckPath) -Label 'Generated POSIX shell syntax check' | Out-Null
                Write-Host 'Generated POSIX shell syntax: valid.'
            } catch {
                if ($_.Exception.Message -match 'Access is denied|Permission denied') {
                    Write-Warning 'The sandbox blocked the local shell parser. The script will run sh -n on the VPS before deployment.'
                } else { throw }
            }
        } finally {
            Remove-Item -LiteralPath $shellCheckPath -Force -ErrorAction SilentlyContinue
        }
    } else {
        Write-Warning 'No local bash parser found; the script will run sh -n on the VPS before deployment.'
    }
    Write-Host "Pinned application source: $($script:TargetSha)"
    Write-Host 'Transfer contents: pinned Dockerfile and control-center source from git archive only.'
    Write-Host 'Local Docker/GitHub CLI required: no.'
    Write-Host 'Generated script safety checks: passed.'
}

if ($MyInvocation.InvocationName -ne '.') {
    $root = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
    Test-StaticSource -Root $root
    if ($ValidateOnly) {
        Write-Host 'Validation passed. No SSH, SCP, Docker or VPS commands were run.'
        return
    }

    $git = Find-Executable -Name 'git.exe' -FallbackPaths @((Join-Path $env:ProgramFiles 'Git\cmd\git.exe'))
    $ssh = Find-Executable -Name 'ssh.exe' -FallbackPaths @((Join-Path $env:WINDIR 'System32\OpenSSH\ssh.exe'))
    $scp = Find-Executable -Name 'scp.exe' -FallbackPaths @((Join-Path $env:WINDIR 'System32\OpenSSH\scp.exe'))
    $keyPath = Join-Path $env:USERPROFILE '.ssh\pad_deploy'
    if (-not (Test-Path -LiteralPath $keyPath -PathType Leaf)) { Stop-Deployment "SSH key not found: $keyPath. The key contents were not read." }

    $loopbackProbe = $null
    try {
        $loopbackProbe = [Net.Sockets.TcpListener]::new([Net.IPAddress]::Loopback, 18101)
        $loopbackProbe.Start()
    } catch {
        Stop-Deployment 'Local TCP port 18101 is already occupied; close that listener before deploying so the SSH tunnel can be verified.'
    } finally {
        if ($loopbackProbe) { $loopbackProbe.Stop() }
    }

    $runId = [guid]::NewGuid().ToString('N')
    $tempDirectory = Join-Path ([IO.Path]::GetTempPath()) "nexo-control-direct-$runId"
    New-Item -ItemType Directory -Path $tempDirectory | Out-Null
    $archivePath = Join-Path $tempDirectory 'source.tar'
    $hashPath = Join-Path $tempDirectory 'source.tar.sha256'
    $scriptPath = Join-Path $tempDirectory 'deploy.sh'
    $remoteDirectory = "/tmp/nexo-control-direct-$runId"
    $remoteDirectoryCreated = $false
    $tunnelProcess = $null
    $tunnelVerified = $false
    try {
        Invoke-Native -FilePath $git -Arguments @('-C', $root, 'archive', '--format=tar', "--output=$archivePath", $script:TargetSha, '--', 'Dockerfile', 'control-center', ':(exclude,glob)control-center/.env*', ':(exclude,glob)control-center/**/.env*', ':(exclude,glob)control-center/**/node_modules/**', ':(exclude,glob)control-center/**/.next/**') -Label 'Create exact-SHA source archive' | Out-Null
        if (-not (Test-Path -LiteralPath $archivePath -PathType Leaf) -or (Get-Item -LiteralPath $archivePath).Length -le 0) {
            Stop-Deployment 'Git did not produce the pinned source archive.'
        }
        $archiveHash = (Get-FileHash -LiteralPath $archivePath -Algorithm SHA256).Hash.ToLowerInvariant()
        Set-Content -LiteralPath $hashPath -Value $archiveHash -NoNewline -Encoding Ascii
        Write-PosixScript -Path $scriptPath -Content (Get-RemoteDeploymentScript -RunId $runId)
        $scriptBytes = [IO.File]::ReadAllBytes($scriptPath)
        if ($scriptBytes -contains 13 -or ($scriptBytes.Length -ge 3 -and $scriptBytes[0] -eq 239 -and $scriptBytes[1] -eq 187 -and $scriptBytes[2] -eq 191)) {
            Stop-Deployment 'Generated POSIX script is not LF-only ASCII; refusing to send it to the VPS.'
        }

        $sshOptions = @('-i', $keyPath, '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=12', '-o', 'ServerAliveInterval=10', '-o', 'ServerAliveCountMax=3')
        Invoke-Native -FilePath $ssh -Arguments ($sshOptions + @("$($script:VpsUser)@$($script:VpsHost)", "mkdir -m 700 $remoteDirectory")) -Label 'Create unique transfer directory' | Out-Null
        $remoteDirectoryCreated = $true
        $scpOptions = @('-i', $keyPath, '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=12')
        Invoke-Native -FilePath $scp -Arguments ($scpOptions + @($archivePath, $hashPath, $scriptPath, "$($script:VpsUser)@$($script:VpsHost):$remoteDirectory/")) -Label 'Transfer pinned source and deployment script' | Out-Null
        Invoke-Native -FilePath $ssh -Arguments ($sshOptions + @("$($script:VpsUser)@$($script:VpsHost)", "sh -n $remoteDirectory/deploy.sh")) -Label 'Generated POSIX shell syntax check (sh -n; stderr includes the failing line)' | Out-Null
        $deployResult = Invoke-Native -FilePath $ssh -Arguments ($sshOptions + @("$($script:VpsUser)@$($script:VpsHost)", "sh $remoteDirectory/deploy.sh")) -Label 'Build and deploy isolated staging'
        Write-Host $deployResult

        $tunnelOutPath = Join-Path ([IO.Path]::GetTempPath()) "nexo-control-tunnel-$runId.out.log"
        $tunnelErrPath = Join-Path ([IO.Path]::GetTempPath()) "nexo-control-tunnel-$runId.err.log"
        $tunnelArguments = '-i "{0}" -N -L 127.0.0.1:18101:127.0.0.1:18101 -o BatchMode=yes -o ExitOnForwardFailure=yes -o ConnectTimeout=12 -o ServerAliveInterval=10 -o ServerAliveCountMax=3 {1}@{2}' -f $keyPath, $script:VpsUser, $script:VpsHost
        $tunnelProcess = Start-Process -FilePath $ssh -ArgumentList $tunnelArguments -PassThru -WindowStyle Hidden `
            -RedirectStandardOutput $tunnelOutPath -RedirectStandardError $tunnelErrPath
        $loginStatus = 0
        for ($attempt = 0; $attempt -lt 30; $attempt++) {
            Start-Sleep -Milliseconds 500
            if ($tunnelProcess.HasExited) { break }
            $loginStatus = Get-HttpStatus -Uri 'http://127.0.0.1:18101/login'
            if ($loginStatus -eq 200) { break }
        }
        if ($tunnelProcess.HasExited -or $loginStatus -ne 200) {
            $tunnelError = if (Test-Path -LiteralPath $tunnelErrPath) { (Get-Content -LiteralPath $tunnelErrPath -Raw).Trim() } else { '' }
            if (-not $tunnelProcess.HasExited) { Stop-Process -Id $tunnelProcess.Id -Force -ErrorAction SilentlyContinue }
            Stop-Deployment "SSH tunnel verification failed (process exited=$($tunnelProcess.HasExited), GET /login=$loginStatus). $tunnelError"
        }
        $tunnelLiveStatus = Get-HttpStatus -Uri 'http://127.0.0.1:18101/api/v1/live'
        $tunnelMeStatus = Get-HttpStatus -Uri 'http://127.0.0.1:18101/api/v1/me'
        if ($tunnelLiveStatus -ne 200 -or $tunnelMeStatus -ne 401) {
            Stop-Process -Id $tunnelProcess.Id -Force -ErrorAction SilentlyContinue
            Stop-Deployment "Tunnel HTTP verification failed: /api/v1/live=$tunnelLiveStatus (expected 200), /api/v1/me=$tunnelMeStatus (expected 401)."
        }
        $tunnelVerified = $true
        Write-Host 'DEPLOYMENT SUCCESS — VPS and SSH tunnel smoke tests passed.'
        Write-Host 'Working URL: http://127.0.0.1:18101/login'
        Write-Host "Tunnel is running as PID $($tunnelProcess.Id). Close it with: Stop-Process -Id $($tunnelProcess.Id)"
        Write-Host "Tunnel command for a later session: ssh -i `"$keyPath`" -N -L 127.0.0.1:18101:127.0.0.1:18101 root@$($script:VpsHost)"
    } finally {
        # Remove only the unique transfer directory created by this invocation.
        if ($remoteDirectoryCreated -and (Test-Path -LiteralPath $keyPath -PathType Leaf)) {
            $cleanup = "rm -rf -- $remoteDirectory"
            try {
                Invoke-Native -FilePath $ssh -Arguments (@('-i', $keyPath, '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=8') + @("$($script:VpsUser)@$($script:VpsHost)", $cleanup)) -Label 'Clean unique transfer directory' | Out-Null
            } catch {
                Write-Warning 'Could not remove the unique temporary transfer directory; the failed deployment left only its isolated /tmp bundle.'
            }
        }
        if ($tunnelProcess -and -not $tunnelVerified -and -not $tunnelProcess.HasExited) {
            Stop-Process -Id $tunnelProcess.Id -Force -ErrorAction SilentlyContinue
        }
        Remove-Item -LiteralPath $tempDirectory -Recurse -Force -ErrorAction SilentlyContinue
    }
}
