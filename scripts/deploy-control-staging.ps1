[CmdletBinding()]
param(
    [switch]$ValidateOnly,
    [string]$ImageArchivePath,
    [Parameter(Mandatory = $true)]
    [ValidatePattern('^[A-Za-z0-9][A-Za-z0-9.-]*$')]
    [string]$VpsHost,
    [Parameter(Mandatory = $true)]
    [ValidateRange(1, 65535)]
    [int]$SshPort,
    [Parameter(Mandatory = $true)]
    [ValidatePattern('^[a-z_][a-z0-9_-]*$')]
    [string]$VpsUser,
    [ValidateNotNullOrEmpty()]
    [string]$SshKeyPath = (Join-Path $env:USERPROFILE '.ssh\nexo_deploy')
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$script:TargetSha = '2111243fff34f724d98c7ae6a825785967fbe882'
$script:VpsHost = $VpsHost
$script:SshPort = $SshPort
$script:VpsUser = $VpsUser
if ($script:VpsHost -eq '185.186.77.210') {
    throw 'Refusing deployment or rollback target: 185.186.77.210 is the protected old VPS.'
}
$script:VpsProjectDirectory = '/opt/nexo-control-staging'
$script:RemoteMarker = 'nexo-control-staging-v1'
$script:StagingProject = 'nexo-control-staging'
$script:StagingPort = 18101
$script:MinAvailableMemoryKb = 524288
$script:MinAvailableDiskKb = 2097152

function Stop-Deployment {
    param([Parameter(Mandatory = $true)][string]$Message)
    throw $Message
}

function Invoke-Native {
    param(
        [Parameter(Mandatory = $true)][string]$FilePath,
        [Parameter(Mandatory = $true)][string[]]$Arguments,
        [Parameter(Mandatory = $true)][string]$Label,
        [switch]$AllowFailure
    )

    $output = @(& $FilePath @Arguments 2>&1 | ForEach-Object { [string]$_ })
    $exitCode = $LASTEXITCODE
    if ($exitCode -ne 0 -and -not $AllowFailure) {
        $detail = ($output -join [Environment]::NewLine).Trim()
        if ($detail.Length -gt 1800) {
            $detail = $detail.Substring(0, 1800)
        }
        Stop-Deployment "$Label failed (exit $exitCode). $detail"
    }
    return [pscustomobject]@{ ExitCode = $exitCode; Output = $output }
}

function Get-OutputText {
    param([Parameter(Mandatory = $true)]$Result)
    return (($Result.Output | ForEach-Object { [string]$_ }) -join [Environment]::NewLine).Trim()
}

function Get-DockerContextInspectArguments {
    param([Parameter(Mandatory = $true)][string]$Context)
    return @('context', 'inspect', $Context)
}

function ConvertFrom-DockerContextInspectJson {
    param([Parameter(Mandatory = $true)][string]$Json)

    $contexts = @(ConvertFrom-Json -InputObject $Json -ErrorAction Stop)
    if ($contexts.Count -ne 1 -or -not $contexts[0].Endpoints.docker.Host) {
        throw 'Docker context inspect JSON does not contain exactly one Docker endpoint.'
    }
    return [string]$contexts[0].Endpoints.docker.Host
}

function Compress-ImageArchive {
    param(
        [Parameter(Mandatory = $true)][string]$SourcePath,
        [Parameter(Mandatory = $true)][string]$DestinationPath
    )

    $source = [IO.File]::OpenRead($SourcePath)
    $destination = [IO.File]::Create($DestinationPath)
    try {
        $gzip = [IO.Compression.GZipStream]::new(
            $destination,
            [IO.Compression.CompressionLevel]::Optimal,
            $true
        )
        try {
            $source.CopyTo($gzip)
        } finally {
            $gzip.Dispose()
        }
    } finally {
        $source.Dispose()
        $destination.Dispose()
    }
}

function Get-VerifiedPrebuiltImageArtifact {
    param([Parameter(Mandatory = $true)][string]$ArchivePath)

    if (-not (Test-Path -LiteralPath $ArchivePath -PathType Leaf)) {
        Stop-Deployment "Prebuilt image archive was not found: $ArchivePath"
    }
    $archive = (Resolve-Path -LiteralPath $ArchivePath).Path
    if ([IO.Path]::GetFileName($archive) -ne 'nexo-control-staging.tar.gz') {
        Stop-Deployment 'Prebuilt archive must be named nexo-control-staging.tar.gz.'
    }
    $artifactDirectory = Split-Path -Parent $archive
    $checksumPath = Join-Path $artifactDirectory 'SHA256SUMS'
    $metadataPath = Join-Path $artifactDirectory 'build-metadata.json'
    foreach ($requiredArtifact in @($checksumPath, $metadataPath)) {
        if (-not (Test-Path -LiteralPath $requiredArtifact -PathType Leaf)) {
            Stop-Deployment "Required companion artifact is missing: $requiredArtifact"
        }
    }

    $manifestMatches = @([regex]::Matches(
        (Get-Content -LiteralPath $checksumPath -Raw),
        '(?im)^([a-f0-9]{64})\s+\*?nexo-control-staging\.tar\.gz\s*$'
    ))
    if ($manifestMatches.Count -ne 1) {
        Stop-Deployment 'SHA256SUMS must contain exactly one checksum entry for nexo-control-staging.tar.gz.'
    }
    $expectedHash = $manifestMatches[0].Groups[1].Value.ToLowerInvariant()
    $actualHash = (Get-FileHash -LiteralPath $archive -Algorithm SHA256).Hash.ToLowerInvariant()
    if ($actualHash -ne $expectedHash) {
        Stop-Deployment "Prebuilt image checksum mismatch. Expected $expectedHash, calculated $actualHash."
    }

    try {
        $metadata = Get-Content -LiteralPath $metadataPath -Raw | ConvertFrom-Json -ErrorAction Stop
    } catch {
        Stop-Deployment "Could not parse build-metadata.json: $($_.Exception.Message)"
    }
    if ($metadata.target_sha -ne $script:TargetSha -or
        [string]$metadata.workflow_sha -notmatch '^[a-f0-9]{40}$' -or
        $metadata.platform -ne 'linux/amd64' -or
        $metadata.target -ne 'production-control' -or
        $metadata.image_tag -ne "nexo-control-staging:$script:TargetSha") {
        Stop-Deployment 'Prebuilt artifact metadata has an invalid workflow SHA or does not match the required application SHA, platform, target, and image tag.'
    }
    if ([long]$metadata.compressed_archive_bytes -ne (Get-Item -LiteralPath $archive).Length -or
        [long]$metadata.image_tar_bytes -le 0 -or
        [long]$metadata.image_size_bytes -le 0 -or
        [string]$metadata.image_id -notmatch '^sha256:[a-f0-9]{64}$') {
        Stop-Deployment 'Prebuilt image metadata has invalid archive sizes or image ID.'
    }

    return [pscustomobject]@{
        ArchivePath = $archive
        ArchiveSha256 = $actualHash
        ImageTag = [string]$metadata.image_tag
        ImageId = [string]$metadata.image_id
        ImageSizeBytes = [long]$metadata.image_size_bytes
        TarSizeBytes = [long]$metadata.image_tar_bytes
    }
}

function Find-Executable {
    param(
        [Parameter(Mandatory = $true)][string]$Name,
        [string[]]$FallbackPaths = @()
    )

    $found = Get-Command $Name -CommandType Application -ErrorAction SilentlyContinue |
        Select-Object -First 1
    if ($found) {
        return $found.Source
    }
    foreach ($path in $FallbackPaths) {
        if (Test-Path -LiteralPath $path -PathType Leaf) {
            return $path
        }
    }
    Stop-Deployment "Could not find $Name.exe. Install it or add it to PATH."
}

function Get-DockerContextAndExecutable {
    $dockerFallbacks = @(
        (Join-Path $env:ProgramFiles 'Docker\Docker\resources\bin\docker.exe'),
        (Join-Path ${env:ProgramFiles(x86)} 'Docker\Docker\resources\bin\docker.exe'),
        (Join-Path $env:LOCALAPPDATA 'Programs\Docker\Docker\resources\bin\docker.exe')
    )
    $dockerExe = Find-Executable -Name 'docker.exe' -FallbackPaths $dockerFallbacks
    $version = Invoke-Native -FilePath $dockerExe -Arguments @('--version') -Label 'Docker client check'
    Write-Host "Docker executable: $dockerExe"
    Write-Host "Docker client: $(Get-OutputText $version)"

    $contextsResult = Invoke-Native -FilePath $dockerExe `
        -Arguments @('context', 'ls', '--quiet') `
        -Label 'Docker context discovery'
    $contexts = @($contextsResult.Output | ForEach-Object { ([string]$_).Trim() } | Where-Object { $_ })
    if ($contexts.Count -eq 0) {
        Stop-Deployment 'Docker reports no contexts. Open Docker Desktop normally, then rerun this script.'
    }

    foreach ($context in $contexts) {
        # `docker context inspect` emits JSON. Avoid a Go template with nested quotes here:
        # Windows PowerShell 5.1 can alter those quotes in native executable arguments.
        $endpointResult = Invoke-Native -FilePath $dockerExe `
            -Arguments (Get-DockerContextInspectArguments -Context $context) `
            -Label "Inspect Docker context $context" -AllowFailure
        if ($endpointResult.ExitCode -ne 0) {
            continue
        }
        try {
            $endpoint = ConvertFrom-DockerContextInspectJson -Json (Get-OutputText $endpointResult)
        } catch {
            continue
        }
        # Build only through a local Windows named-pipe engine, never a remote context.
        if ($endpoint -notmatch '^npipe://') {
            continue
        }
        $info = Invoke-Native -FilePath $dockerExe `
            -Arguments @('--context', $context, 'info', '--format', '{{.OSType}}|{{.Architecture}}') `
            -Label "Docker Engine connectivity check for $context" -AllowFailure
        if ($info.ExitCode -ne 0) {
            continue
        }
        $engineInfo = Get-OutputText $info
        if ($engineInfo -notmatch '^linux\|amd64$') {
            continue
        }
        Write-Host "Docker context: $context ($endpoint; $engineInfo)"
        return [pscustomobject]@{ Executable = $dockerExe; Context = $context }
    }

    Stop-Deployment 'No accessible local Linux/amd64 Docker Engine context was found. Start Docker Desktop normally and confirm `docker context ls` and `docker info` work in this PowerShell window. This script does not modify or restart Docker Desktop.'
}

function Get-ControlBuildDefinition {
    param([Parameter(Mandatory = $true)][string]$RepositoryRoot)

    $dockerfilePath = Join-Path $RepositoryRoot 'Dockerfile'
    if (-not (Test-Path -LiteralPath $dockerfilePath -PathType Leaf)) {
        Stop-Deployment "Dockerfile not found: $dockerfilePath"
    }
    $dockerfile = Get-Content -LiteralPath $dockerfilePath -Raw
    $stages = [regex]::Matches($dockerfile, '(?im)^\s*FROM\s+[^\r\n]+?\s+AS\s+(?<name>[A-Za-z0-9_.-]+)\s*$')
    $stageDefinitions = @()
    for ($index = 0; $index -lt $stages.Count; $index++) {
        $start = $stages[$index].Index
        $end = if ($index + 1 -lt $stages.Count) { $stages[$index + 1].Index } else { $dockerfile.Length }
        $stageDefinitions += [pscustomobject]@{
            Name = $stages[$index].Groups['name'].Value
            Text = $dockerfile.Substring($start, $end - $start)
        }
    }

    $controlBuild = @($stageDefinitions | Where-Object {
        $_.Text -match '(?im)^\s*COPY\s+control-center/package\.json\s+control-center/package-lock\.json\s+\./' -and
        $_.Text -match '(?im)^\s*RUN\s+npm\s+ci\b' -and
        $_.Text -match '(?im)^\s*COPY\s+control-center/\s+\./' -and
        $_.Text -match '(?im)^\s*RUN\s+npm\s+run\s+build\b'
    })
    if ($controlBuild.Count -ne 1) {
        Stop-Deployment "Expected exactly one Dockerfile stage that installs and builds control-center; found $($controlBuild.Count). Review Dockerfile stages."
    }
    $buildStageName = $controlBuild[0].Name

    $runtimeStages = @($stageDefinitions | Where-Object {
        $_.Text -match "(?im)^\s*COPY\s+--from=$([regex]::Escape($buildStageName))\s+[^\r\n]*/\.next/standalone" -and
        $_.Text -match "(?im)^\s*COPY\s+--from=$([regex]::Escape($buildStageName))\s+[^\r\n]*/\.next/static" -and
        $_.Text -match '(?im)^\s*USER\s+node\s*$' -and
        $_.Text -match '(?im)^\s*CMD\s+\[\s*"node"\s*,\s*"server\.js"\s*\]'
    })
    if ($runtimeStages.Count -ne 1) {
        Stop-Deployment "Expected exactly one production runtime stage copying the Control Center standalone build; found $($runtimeStages.Count). Review Dockerfile stages."
    }

    # The Dockerfile explicitly copies control-center paths, so its build context is the repository root.
    foreach ($requiredPath in @('control-center\package.json', 'control-center\package-lock.json')) {
        if (-not (Test-Path -LiteralPath (Join-Path $RepositoryRoot $requiredPath) -PathType Leaf)) {
            Stop-Deployment "Docker build context is missing required path: $requiredPath"
        }
    }
    if ($dockerfile -notmatch '(?im)^\s*ENV\s+NEXT_PUBLIC_API_BASE_URL=/api/v1\s*$') {
        Stop-Deployment 'Dockerfile does not set NEXT_PUBLIC_API_BASE_URL=/api/v1; refusing to build an image with an unverified API base.'
    }

    return [pscustomobject]@{
        Dockerfile = $dockerfilePath
        Context = $RepositoryRoot
        BuildStage = $buildStageName
        RuntimeStage = $runtimeStages[0].Name
    }
}

function Invoke-Ssh {
    param(
        [Parameter(Mandatory = $true)][string]$SshExe,
        [Parameter(Mandatory = $true)][string]$KeyPath,
        [Parameter(Mandatory = $true)][string]$RemoteCommand,
        [Parameter(Mandatory = $true)][string]$Label,
        [switch]$AllowFailure
    )

    $args = @(
        '-i', $KeyPath,
        '-p', [string]$script:SshPort,
        '-o', 'IdentitiesOnly=yes',
        '-o', 'StrictHostKeyChecking=yes',
        '-o', 'BatchMode=yes',
        '-o', 'ConnectTimeout=12',
        '-o', 'ServerAliveInterval=10',
        '-o', 'ServerAliveCountMax=3',
        "$script:VpsUser@$script:VpsHost",
        $RemoteCommand
    )
    return Invoke-Native -FilePath $SshExe -Arguments $args -Label $Label -AllowFailure:$AllowFailure
}

function Get-RemoteJson {
    param(
        [Parameter(Mandatory = $true)][string]$SshExe,
        [Parameter(Mandatory = $true)][string]$KeyPath,
        [Parameter(Mandatory = $true)][string]$RemoteCommand,
        [Parameter(Mandatory = $true)][string]$Label
    )
    $result = Invoke-Ssh -SshExe $SshExe -KeyPath $KeyPath -RemoteCommand $RemoteCommand -Label $Label
    $jsonText = Get-OutputText $result
    try {
        return ,(ConvertFrom-Json -InputObject $jsonText -ErrorAction Stop)
    } catch {
        Stop-Deployment "$Label did not return valid JSON: $($_.Exception.Message)"
    }
}

function New-ComposeFile {
    param([Parameter(Mandatory = $true)][string]$ImageTag)
    return @"
services:
  control:
    image: $ImageTag
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
    networks:
      - staging_ui

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
    networks:
      - staging_ui
      - production_edge

networks:
  staging_ui:
    internal: true
  production_edge:
    external: true
    name: `${NEXO_UPSTREAM_NETWORK:?NEXO_UPSTREAM_NETWORK must be set in .env}
"@
}

function New-NginxFile {
    return @'
server {
    listen 80;

    location = /api/v1 {
        return 308 /api/v1/;
    }

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
'@
}

function New-RemoteDeploymentScript {
    param(
        [Parameter(Mandatory = $true)][string]$RunId,
        [Parameter(Mandatory = $true)][string]$ImageTag,
        [Parameter(Mandatory = $true)][string]$ArchiveSha256,
        [Parameter(Mandatory = $true)][long]$ArchiveBytes,
        [Parameter(Mandatory = $true)][long]$TarBytes,
        [Parameter(Mandatory = $true)][long]$ImageBytes
    )
    $template = @'
#!/bin/sh
set -eu

RUN_ID='__RUN_ID__'
IMAGE_TAG='__IMAGE_TAG__'
EXPECTED_SHA256='__ARCHIVE_SHA256__'
STAGING_DIR='/opt/nexo-control-staging'
BUNDLE_DIR="/tmp/nexo-control-staging-$RUN_ID"
PROJECT='nexo-control-staging'
MARKER='nexo-control-staging-v1'
EXPECTED_ARCHIVE_BYTES='__ARCHIVE_BYTES__'
EXPECTED_TAR_BYTES='__TAR_BYTES__'
IMAGE_SIZE_BYTES='__IMAGE_BYTES__'

fail() { printf 'ERROR: %s\n' "$1" >&2; exit 1; }
command -v docker >/dev/null 2>&1 || fail 'Docker CLI is unavailable on the VPS.'
docker compose version >/dev/null 2>&1 || fail 'Docker Compose plugin is unavailable on the VPS.'

if [ -e "$STAGING_DIR" ]; then
    [ -d "$STAGING_DIR" ] || fail "$STAGING_DIR exists and is not a directory."
    if [ -f "$STAGING_DIR/.nexo-staging-owned" ]; then
        [ "$(cat "$STAGING_DIR/.nexo-staging-owned")" = "$MARKER" ] || fail 'Staging ownership marker does not match; refusing to overwrite.'
    elif [ -n "$(find "$STAGING_DIR" -mindepth 1 -maxdepth 1 -print -quit)" ]; then
        fail "$STAGING_DIR already contains unmarked data; refusing to overwrite."
    fi
else
    mkdir -p "$STAGING_DIR"
fi

printf '%s\n' "$MARKER" > "$STAGING_DIR/.nexo-staging-owned"
install -m 0644 "$BUNDLE_DIR/compose.yml" "$STAGING_DIR/compose.yml"
install -m 0644 "$BUNDLE_DIR/default.conf" "$STAGING_DIR/default.conf"
install -m 0600 "$BUNDLE_DIR/.env" "$STAGING_DIR/.env"

cd "$STAGING_DIR"
docker compose --project-name "$PROJECT" -f compose.yml config >/dev/null || fail 'Compose configuration validation failed.'

MEM_AVAILABLE_KB=$(awk '/^MemAvailable:/ {print $2}' /proc/meminfo)
DISK_AVAILABLE_KB=$(df -Pk /opt | awk 'NR==2 {print $4}')
CPU_COUNT=$(getconf _NPROCESSORS_ONLN)
LOAD_ONE=$(awk '{print $1}' /proc/loadavg)
[ -n "$MEM_AVAILABLE_KB" ] && [ "$MEM_AVAILABLE_KB" -ge 524288 ] || fail 'Less than 512 MiB RAM is available.'
[ -n "$DISK_AVAILABLE_KB" ] || fail 'Could not read free disk space on /opt.'
REQUIRED_DISK_KB=$(awk -v archive="$EXPECTED_ARCHIVE_BYTES" -v tar="$EXPECTED_TAR_BYTES" -v image="$IMAGE_SIZE_BYTES" 'BEGIN {printf "%.0f", (archive + tar + image) / 1024 + 2097152}')
[ "$DISK_AVAILABLE_KB" -ge "$REQUIRED_DISK_KB" ] || fail "Insufficient disk on /opt: need ${REQUIRED_DISK_KB} KiB including a 2 GiB reserve."
[ -n "$CPU_COUNT" ] && [ "$CPU_COUNT" -ge 2 ] || fail 'At least two online CPU cores are required.'
awk -v load="$LOAD_ONE" -v cpus="$CPU_COUNT" 'BEGIN {exit !(load < cpus)}' || fail "System load ($LOAD_ONE) is at or above CPU count ($CPU_COUNT)."
printf 'VPS capacity: MemAvailable=%s KiB DiskAvailable=%s KiB CPUs=%s Load1=%s\n' "$MEM_AVAILABLE_KB" "$DISK_AVAILABLE_KB" "$CPU_COUNT" "$LOAD_ONE"

ss -H -ltn > "$BUNDLE_DIR/listeners.txt" 2>/dev/null || fail 'Could not verify listening TCP ports with ss.'
if grep -Eq '(^|[[:space:]])[^[:space:]]*:18101([[:space:]]|$)' "$BUNDLE_DIR/listeners.txt"; then
    # The port may belong to this isolated project during an idempotent retry only.
    PUBLISHED_IDS=$(docker ps -q --filter publish=18101)
    [ -n "$PUBLISHED_IDS" ] || fail 'TCP port 18101 is already in use.'
    for cid in $PUBLISHED_IDS; do
        PROJECT_LABEL=$(docker inspect --format '{{index .Config.Labels "com.docker.compose.project"}}' "$cid")
        [ "$PROJECT_LABEL" = "$PROJECT" ] || fail 'TCP port 18101 is used by a container outside the staging project.'
    done
fi

LIVE_STATUS=$(curl --silent --show-error --output /dev/null --write-out '%{http_code}' --max-time 8 http://127.0.0.1:18100/api/v1/live) || fail 'Production API live check at localhost:18100 failed.'
[ "$LIVE_STATUS" = '200' ] || fail "Production API live endpoint returned HTTP $LIVE_STATUS, expected 200."

ACTUAL_SHA256=$(sha256sum "$BUNDLE_DIR/nexo-control-staging.tar.gz" | awk '{print $1}')
[ "$ACTUAL_SHA256" = "$EXPECTED_SHA256" ] || fail 'Image archive checksum mismatch; refusing docker load.'
printf 'Archive SHA256 verified: %s\n' "$ACTUAL_SHA256"
ACTUAL_ARCHIVE_BYTES=$(stat -c '%s' "$BUNDLE_DIR/nexo-control-staging.tar.gz")
[ "$ACTUAL_ARCHIVE_BYTES" = "$EXPECTED_ARCHIVE_BYTES" ] || fail 'Image archive size does not match build metadata.'
gzip -dc "$BUNDLE_DIR/nexo-control-staging.tar.gz" > "$BUNDLE_DIR/image.tar" || fail 'Could not decompress image archive.'
ACTUAL_TAR_BYTES=$(stat -c '%s' "$BUNDLE_DIR/image.tar")
[ "$ACTUAL_TAR_BYTES" = "$EXPECTED_TAR_BYTES" ] || fail 'Decompressed image tar size does not match build metadata.'

snapshot_existing() {
    for cid in $(docker ps -aq); do
        project_label=$(docker inspect --format '{{index .Config.Labels "com.docker.compose.project"}}' "$cid")
        [ "$project_label" = "$PROJECT" ] && continue
        docker inspect --format '{{.Id}}|{{.Name}}|{{.State.Status}}|{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}|{{.State.StartedAt}}' "$cid"
    done | sort
}
BEFORE_SNAPSHOT=$(snapshot_existing)

docker load --input "$BUNDLE_DIR/image.tar"
docker image inspect "$IMAGE_TAG" >/dev/null || fail "Loaded image tag $IMAGE_TAG is missing."
rm -f "$BUNDLE_DIR/image.tar" "$BUNDLE_DIR/nexo-control-staging.tar.gz"

if ! docker compose --project-name "$PROJECT" -f compose.yml up -d --no-deps --force-recreate control; then
    fail 'Control Center did not start. Staging gateway was not started.'
fi

CONTROL_CID=$(docker compose --project-name "$PROJECT" -f compose.yml ps -q control)
[ -n "$CONTROL_CID" ] || fail 'Control Center container is missing after start.'
CONTROL_HEALTH=starting
attempt=0
while [ "$attempt" -lt 45 ]; do
    CONTROL_HEALTH=$(docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' "$CONTROL_CID")
    [ "$CONTROL_HEALTH" = healthy ] && break
    [ "$CONTROL_HEALTH" = unhealthy ] && fail 'Control Center health check is unhealthy.'
    sleep 2
    attempt=$((attempt + 1))
done
[ "$CONTROL_HEALTH" = healthy ] || fail 'Timed out waiting for Control Center health check.'
printf 'Control Center is healthy.\n'

if ! docker compose --project-name "$PROJECT" -f compose.yml up -d --no-deps --force-recreate gateway; then
    fail 'Staging gateway did not start.'
fi
GATEWAY_CID=$(docker compose --project-name "$PROJECT" -f compose.yml ps -q gateway)
[ -n "$GATEWAY_CID" ] || fail 'Staging gateway container is missing after start.'
GATEWAY_HEALTH=starting
attempt=0
while [ "$attempt" -lt 30 ]; do
    GATEWAY_HEALTH=$(docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' "$GATEWAY_CID")
    [ "$GATEWAY_HEALTH" = healthy ] && break
    [ "$GATEWAY_HEALTH" = unhealthy ] && fail 'Staging gateway health check is unhealthy.'
    sleep 2
    attempt=$((attempt + 1))
done
[ "$GATEWAY_HEALTH" = healthy ] || fail 'Timed out waiting for staging gateway health check.'

LOGIN_STATUS=$(curl --silent --show-error --output /dev/null --write-out '%{http_code}' --max-time 10 http://127.0.0.1:18101/login) || fail 'GET /login failed.'
LIVE_STATUS=$(curl --silent --show-error --output /dev/null --write-out '%{http_code}' --max-time 10 http://127.0.0.1:18101/api/v1/live) || fail 'GET /api/v1/live failed.'
ME_STATUS=$(curl --silent --show-error --output /dev/null --write-out '%{http_code}' --max-time 10 http://127.0.0.1:18101/api/v1/me) || fail 'GET /api/v1/me failed.'
[ "$LOGIN_STATUS" = '200' ] || fail "GET /login returned HTTP $LOGIN_STATUS, expected 200."
[ "$LIVE_STATUS" = '200' ] || fail "GET /api/v1/live returned HTTP $LIVE_STATUS, expected 200."
[ "$ME_STATUS" = '401' ] || fail "GET /api/v1/me returned HTTP $ME_STATUS, expected 401."

PORT_BINDING=$(docker inspect --format '{{range (index .NetworkSettings.Ports "80/tcp")}}{{.HostIp}}:{{.HostPort}}{{end}}' "$GATEWAY_CID")
[ "$PORT_BINDING" = '127.0.0.1:18101' ] || fail "Unexpected staging port binding: $PORT_BINDING"
POST_LISTENERS=$(ss -H -ltn | awk '$4 ~ /:18101$/ {print $4}')
for address in $POST_LISTENERS; do
    case "$address" in
        127.0.0.1:18101|\[::1\]:18101) ;;
        *) fail "Port 18101 is listening on a non-loopback address: $address" ;;
    esac
done

AFTER_SNAPSHOT=$(snapshot_existing)
[ "$BEFORE_SNAPSHOT" = "$AFTER_SNAPSHOT" ] || {
    printf '%s\n' 'Existing container ID/state/health snapshot changed during deployment.' >&2
    printf 'Before:\n%s\nAfter:\n%s\n' "$BEFORE_SNAPSHOT" "$AFTER_SNAPSHOT" >&2
    fail 'Existing containers changed; staging verification failed.'
}

printf 'Checks passed: /login=%s /api/v1/live=%s /api/v1/me=%s\n' "$LOGIN_STATUS" "$LIVE_STATUS" "$ME_STATUS"
printf 'Staging binding: %s\n' "$PORT_BINDING"
printf 'Existing containers retained identical IDs, running states, health states, and start times.\n'
docker compose --project-name "$PROJECT" -f compose.yml ps
printf 'Deployment complete.\n'
'@
    return $template.Replace('__RUN_ID__', $RunId).
        Replace('__IMAGE_TAG__', $ImageTag).
        Replace('__ARCHIVE_SHA256__', $ArchiveSha256).
        Replace('__ARCHIVE_BYTES__', [string]$ArchiveBytes).
        Replace('__TAR_BYTES__', [string]$TarBytes).
        Replace('__IMAGE_BYTES__', [string]$ImageBytes)
}

function Test-StaticPrerequisites {
    param([Parameter(Mandatory = $true)][string]$RepositoryRoot)
    $gitExe = Find-Executable -Name 'git.exe' -FallbackPaths @((Join-Path $env:ProgramFiles 'Git\cmd\git.exe'))
    $status = Invoke-Native -FilePath $gitExe `
        -Arguments @('-C', $RepositoryRoot, 'status', '--porcelain', '--untracked-files=all') -Label 'Git worktree check'
    $allowedUntracked = @(
        '?? scripts/deploy-control-staging-direct.ps1',
        '?? scripts/deploy-control-staging.ps1',
        '?? scripts/rollback-control-staging.ps1',
        '?? .github/workflows/build-control-staging-image.yml',
        ' M .github/workflows/build-control-staging-image.yml'
    )
    $allowedModified = @(
        ' M scripts/deploy-control-staging.ps1',
        ' M scripts/rollback-control-staging.ps1'
    )
    foreach ($entry in $status.Output) {
        $statusLine = [string]$entry
        if ($statusLine -notin $allowedUntracked -and $statusLine -notin $allowedModified) {
            Stop-Deployment "Git worktree has changes outside the deployment helper scripts: $statusLine"
        }
    }
    $headResult = Invoke-Native -FilePath $gitExe `
        -Arguments @('-C', $RepositoryRoot, 'rev-parse', 'HEAD') -Label 'Git target SHA check'
    $head = (Get-OutputText $headResult).ToLowerInvariant()
    Invoke-Native -FilePath $gitExe `
        -Arguments @('-C', $RepositoryRoot, 'cat-file', '-e', "$script:TargetSha^{commit}") `
        -Label 'Target commit availability check' | Out-Null
    $ancestorCheck = Invoke-Native -FilePath $gitExe `
        -Arguments @('-C', $RepositoryRoot, 'merge-base', '--is-ancestor', $script:TargetSha, 'HEAD') `
        -Label 'Target commit ancestry check' -AllowFailure
    if ($ancestorCheck.ExitCode -ne 0) {
        Stop-Deployment "Required target commit $script:TargetSha is not an ancestor of HEAD $head."
    }
    $buildSourceDiff = Invoke-Native -FilePath $gitExe `
        -Arguments @('-C', $RepositoryRoot, 'diff', '--quiet', $script:TargetSha, '--', 'Dockerfile', 'control-center') `
        -Label 'Target build source comparison' -AllowFailure
    if ($buildSourceDiff.ExitCode -ne 0) {
        Stop-Deployment "Dockerfile or Control Center source differs from required target $script:TargetSha."
    }
    $definition = Get-ControlBuildDefinition -RepositoryRoot $RepositoryRoot
    Write-Host "Build source matches required SHA $script:TargetSha (HEAD $head; only deployment helper files may be untracked)"
    Write-Host "Dockerfile context: $($definition.Context)"
    Write-Host "Discovered Control Center build stage: $($definition.BuildStage)"
    Write-Host "Discovered production runtime stage: $($definition.RuntimeStage)"
    return $definition
}

function Test-GeneratedStaticArtifacts {
    $deploymentSource = Get-Content -LiteralPath $PSCommandPath -Raw
    if (-not $deploymentSource.Contains("'-p', [string]`$script:SshPort") -or
        -not $deploymentSource.Contains("'-P', [string]`$script:SshPort") -or
        -not $deploymentSource.Contains("nexo_deploy") -or
        -not $deploymentSource.Contains("'-o', 'IdentitiesOnly=yes'") -or
        -not $deploymentSource.Contains("'-o', 'StrictHostKeyChecking=yes'") -or
        $deploymentSource -notmatch 'Tunnel command: ssh .*\-p \$script:SshPort.*StrictHostKeyChecking=yes') {
        Stop-Deployment 'SSH regression check failed: the configured port, NEXO key, identity restriction and strict host-key checking must be used.'
    }
    if ($deploymentSource -notmatch '(?s)\[Parameter\(Mandatory = \$true\)\][\s\S]{0,180}\[string\]\$VpsHost' -or
        $deploymentSource -notmatch '(?s)\[Parameter\(Mandatory = \$true\)\][\s\S]{0,100}\[int\]\$SshPort' -or
        $deploymentSource -notmatch 'Refusing deployment or rollback target: 185\.186\.77\.210 is the protected old VPS\.') {
        Stop-Deployment 'Destination safety regression check failed: host and port must be explicit and the old VPS must be rejected.'
    }
    $rollbackSource = Get-Content -LiteralPath (Join-Path $PSScriptRoot 'rollback-control-staging.ps1') -Raw
    if ($rollbackSource -notmatch '(?s)\[Parameter\(Mandatory = \$true\)\][\s\S]{0,180}\[string\]\$VpsHost' -or
        $rollbackSource -notmatch '(?s)\[Parameter\(Mandatory = \$true\)\][\s\S]{0,100}\[int\]\$SshPort' -or
        $rollbackSource -notmatch 'Refusing rollback target: 185\.186\.77\.210 is the protected old VPS\.' -or
        -not $rollbackSource.Contains("nexo_deploy") -or
        -not $rollbackSource.Contains("'StrictHostKeyChecking=yes'")) {
        Stop-Deployment 'Rollback safety regression check failed: explicit destination, NEXO key, strict host-key checking and old-VPS rejection are required.'
    }
    $workflowPath = Join-Path $PSScriptRoot '..\.github\workflows\build-control-staging-image.yml'
    $workflowSource = Get-Content -LiteralPath $workflowPath -Raw
    foreach ($requirement in @(
        'ref: ${{ inputs.target_sha }}',
        'persist-credentials: false',
        'target: production',
        'target: production-nginx',
        'target: production-control',
        'platforms: linux/amd64',
        'retention-days: 7',
        'nexo-full-stack-linux-amd64-2111243fff34f724d98c7ae6a825785967fbe882',
        'nexo-control-staging-2111243fff34f724d98c7ae6a825785967fbe882',
        'sha256sum nexo-full-stack.tar.gz',
        'sha256sum nexo-control-staging.tar.gz'
    )) {
        if (-not $workflowSource.Contains($requirement)) {
            Stop-Deployment "GitHub Actions workflow regression check failed; expected: $requirement"
        }
    }
    if ($workflowSource -match '(?im)^\s*(?:docker\s+compose\s+up|ssh\s|uses:\s*.*deploy)') {
        Stop-Deployment 'GitHub Actions workflow must build and publish artifacts only; deployment commands are forbidden.'
    }

    $contextInspectArguments = @(Get-DockerContextInspectArguments -Context 'regression-context')
    if (($contextInspectArguments -join ' ') -ne 'context inspect regression-context' -or
        $contextInspectArguments -contains '--format') {
        Stop-Deployment 'Docker context inspect regression check failed: command must request JSON without a Go template.'
    }
    $contextJsonFixture = '[{"Name":"regression-context","Endpoints":{"docker":{"Host":"npipe:////./pipe/dockerDesktopLinuxEngine"}}}]'
    $fixtureEndpoint = ConvertFrom-DockerContextInspectJson -Json $contextJsonFixture
    if ($fixtureEndpoint -ne 'npipe:////./pipe/dockerDesktopLinuxEngine') {
        Stop-Deployment 'Docker context inspect JSON parsing regression check failed.'
    }

    $compose = New-ComposeFile -ImageTag 'nexo-control-staging:2111243fff34f724d98c7ae6a825785967fbe882'
    $nginx = New-NginxFile
    if ($compose -notmatch '(?m)^\s*- "127\.0\.0\.1:18101:80"$' -or
        $compose -notmatch '(?m)^  control:$' -or
        $compose -notmatch '(?m)^  gateway:$' -or
        $compose -notmatch '(?m)^    internal: true$') {
        Stop-Deployment 'Generated Compose static safety checks failed.'
    }
    if ($nginx -notmatch 'proxy_pass http://nexo-nginx/api/v1/' -or
        $nginx -notmatch 'proxy_pass http://control:3000') {
        Stop-Deployment 'Generated Nginx static routing checks failed.'
    }

    $remoteScript = New-RemoteDeploymentScript `
        -RunId '0123456789abcdef0123456789abcdef' `
        -ImageTag 'nexo-control-staging:2111243fff34f724d98c7ae6a825785967fbe882' `
        -ArchiveSha256 ('a' * 64) `
        -ArchiveBytes 1000 `
        -TarBytes 2000 `
        -ImageBytes 3000
    if ($remoteScript -match 'docker\s+(?:compose\s+down|volume\s+rm|system\s+prune)|migrate|git\s+reset') {
        Stop-Deployment 'Generated remote deployment script contains a prohibited cleanup or migration command.'
    }
    $shell = Get-Command 'bash.exe' -CommandType Application -ErrorAction SilentlyContinue | Select-Object -First 1
    if (-not $shell) {
        $shell = Get-Command 'sh.exe' -CommandType Application -ErrorAction SilentlyContinue | Select-Object -First 1
    }
    if ($shell) {
        $tempScript = Join-Path ([IO.Path]::GetTempPath()) ("nexo-staging-shell-check-{0}.sh" -f [guid]::NewGuid().ToString('N'))
        try {
            Set-Content -LiteralPath $tempScript -Value $remoteScript -Encoding Ascii
            $syntaxCheck = Invoke-Native -FilePath $shell.Source -Arguments @('-n', $tempScript) `
                -Label 'Generated remote shell syntax check' -AllowFailure
            if ($syntaxCheck.ExitCode -eq 0) {
                Write-Host 'Generated remote deployment shell syntax: valid'
            } elseif ((Get-OutputText $syntaxCheck) -match 'Access is denied') {
                Write-Warning 'Sandbox denied launching the local shell parser; PowerShell checks passed, and the VPS script will run sh -n before making changes.'
            } else {
                Stop-Deployment "Generated remote shell syntax is invalid. $(Get-OutputText $syntaxCheck)"
            }
        } finally {
            Remove-Item -LiteralPath $tempScript -Force -ErrorAction SilentlyContinue
        }
    } else {
        Write-Warning 'No local bash.exe or sh.exe found; embedded POSIX shell syntax will be checked during VPS deployment.'
    }
    Write-Host 'Generated Compose and Nginx safety checks: passed'
}

if ($MyInvocation.InvocationName -ne '.') {
    $repositoryRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
    $buildDefinition = Test-StaticPrerequisites -RepositoryRoot $repositoryRoot
    if ($ValidateOnly) {
        Test-GeneratedStaticArtifacts
    Write-Host 'Static prerequisite validation passed. Docker and SSH were not contacted.'
        return
    }

    $sshExe = Find-Executable -Name 'ssh.exe' -FallbackPaths @((Join-Path $env:WINDIR 'System32\OpenSSH\ssh.exe'))
    $scpExe = Find-Executable -Name 'scp.exe' -FallbackPaths @((Join-Path $env:WINDIR 'System32\OpenSSH\scp.exe'))
    $keyPath = $SshKeyPath
    if (-not (Test-Path -LiteralPath $keyPath -PathType Leaf)) {
        Stop-Deployment "Expected SSH key does not exist: $keyPath. Key contents were not read."
    }

    $imageTag = "nexo-control-staging:$script:TargetSha"
    $prebuiltArtifact = $null
    if ($ImageArchivePath) {
        $prebuiltArtifact = Get-VerifiedPrebuiltImageArtifact -ArchivePath $ImageArchivePath
        $imageTag = $prebuiltArtifact.ImageTag
        Write-Host "Verified prebuilt image artifact: $($prebuiltArtifact.ArchiveSha256)"
    }

    $nginxContainers = Get-RemoteJson -SshExe $sshExe -KeyPath $keyPath `
        -RemoteCommand 'docker inspect nexo-nginx nexo-app' -Label 'Inspect NEXO proxy and backend containers'
    if ($nginxContainers.Count -ne 2) {
        Stop-Deployment 'Expected docker inspect to return nexo-nginx and nexo-app.'
    }
    $byName = @{}
    foreach ($container in $nginxContainers) {
        $name = ([string]$container.Name).TrimStart('/')
        $byName[$name] = $container
    }
    foreach ($requiredName in @('nexo-nginx', 'nexo-app')) {
        if (-not $byName.ContainsKey($requiredName)) {
            Stop-Deployment "VPS container $requiredName was not found."
        }
        if ($byName[$requiredName].State.Status -ne 'running') {
            Stop-Deployment "VPS container $requiredName is not running."
        }
    }
    $nginxNetworks = @($byName['nexo-nginx'].NetworkSettings.Networks.PSObject.Properties.Name)
    $appNetworks = @($byName['nexo-app'].NetworkSettings.Networks.PSObject.Properties.Name)
    $sharedNetworks = @($nginxNetworks | Where-Object { $appNetworks -contains $_ })
    if ($sharedNetworks.Count -eq 0) {
        Stop-Deployment 'nexo-nginx and nexo-app do not share a Docker network; refusing to guess an API path.'
    }

    $selectedNetwork = $null
    foreach ($networkName in $sharedNetworks) {
        if ($networkName -notmatch '^[A-Za-z0-9][A-Za-z0-9_.-]*$') {
            continue
        }
        $networkJson = Get-RemoteJson -SshExe $sshExe -KeyPath $keyPath `
            -RemoteCommand "docker network inspect $networkName" -Label "Inspect shared network $networkName"
        if ($networkJson.Count -ne 1 -or $networkJson[0].Driver -ne 'bridge' -or $networkJson[0].Scope -ne 'local') {
            continue
        }
        $attachedIds = @($networkJson[0].Containers.PSObject.Properties.Name)
        $networkContainerNames = @($networkJson[0].Containers.PSObject.Properties | ForEach-Object { [string]$_.Value.Name })
        if (($attachedIds -contains $byName['nexo-nginx'].Id) -and
            ($attachedIds -contains $byName['nexo-app'].Id) -and
            ($networkContainerNames -contains 'nexo-nginx') -and
            ($networkContainerNames -contains 'nexo-app')) {
            $selectedNetwork = $networkName
            break
        }
    }
    if (-not $selectedNetwork) {
        Stop-Deployment 'Could not verify a local bridge network shared by nexo-nginx and nexo-app.'
    }
    Write-Host "Verified production API network: $selectedNetwork"

    $productionLive = Invoke-Ssh -SshExe $sshExe -KeyPath $keyPath `
        -RemoteCommand "curl --silent --show-error --output /dev/null --write-out '%{http_code}' --max-time 8 http://127.0.0.1:18100/api/v1/live" `
        -Label 'Production API live check'
    if ((Get-OutputText $productionLive) -ne '200') {
        Stop-Deployment "Production API /api/v1/live did not return HTTP 200 at localhost:18100. Received: $(Get-OutputText $productionLive)"
    }

    $memoryText = Get-OutputText (Invoke-Ssh -SshExe $sshExe -KeyPath $keyPath -RemoteCommand "awk '/^MemAvailable:/ {print `$2}' /proc/meminfo" -Label 'VPS available RAM check')
    $memoryKb = 0
    if (-not [int]::TryParse($memoryText, [ref]$memoryKb) -or $memoryKb -lt $script:MinAvailableMemoryKb) {
        Stop-Deployment "VPS must have at least 512 MiB available RAM; received '$memoryText' KiB."
    }
    $diskText = Get-OutputText (Invoke-Ssh -SshExe $sshExe -KeyPath $keyPath -RemoteCommand "df -Pk /opt | tail -n 1 | awk '{print `$4}'" -Label 'VPS disk capacity check')
    $diskKb = 0
    if (-not [int]::TryParse($diskText, [ref]$diskKb) -or $diskKb -lt $script:MinAvailableDiskKb) {
        Stop-Deployment "VPS must have at least 2 GiB free on /opt filesystem; received '$diskText' KiB."
    }
    $cpuText = Get-OutputText (Invoke-Ssh -SshExe $sshExe -KeyPath $keyPath -RemoteCommand 'getconf _NPROCESSORS_ONLN' -Label 'VPS CPU capacity check')
    $cpuCount = 0
    if (-not [int]::TryParse($cpuText, [ref]$cpuCount) -or $cpuCount -lt 2) {
        Stop-Deployment "VPS must have at least 2 online CPUs; received '$cpuText'."
    }
    $loadText = Get-OutputText (Invoke-Ssh -SshExe $sshExe -KeyPath $keyPath -RemoteCommand 'cat /proc/loadavg' -Label 'VPS CPU load check')
    $loadOne = 0.0
    if (-not [double]::TryParse(($loadText -split '\s+')[0], [Globalization.NumberStyles]::Float, [Globalization.CultureInfo]::InvariantCulture, [ref]$loadOne)) {
        Stop-Deployment "Could not parse VPS load average: $loadText"
    }
    if ($loadOne -ge $cpuCount) {
        Stop-Deployment "VPS load average ($loadOne) is not below available CPU count ($cpuCount)."
    }
    Write-Host "VPS capacity verified: RAM $memoryKb KiB available, disk $diskKb KiB free, CPUs $cpuCount, load1 $loadOne"

    $listenerText = Get-OutputText (Invoke-Ssh -SshExe $sshExe -KeyPath $keyPath -RemoteCommand 'ss -H -ltn' -Label 'VPS listening port inspection')
    $portListenerPattern = '(?:^|\s)[^\s]*:18101(?:\s|$)'
    $existingStagingIds = @()
    $publishedIdsText = Get-OutputText (Invoke-Ssh -SshExe $sshExe -KeyPath $keyPath `
        -RemoteCommand 'docker ps -q --filter publish=18101' -Label 'Docker published port inspection')
    if ($publishedIdsText) {
        $existingStagingIds = @($publishedIdsText -split '\s+' | Where-Object { $_ })
        foreach ($containerId in $existingStagingIds) {
            $labels = Get-RemoteJson -SshExe $sshExe -KeyPath $keyPath `
                -RemoteCommand "docker inspect $containerId" -Label "Inspect current port 18101 container $containerId"
            $projectLabel = [string]$labels[0].Config.Labels.'com.docker.compose.project'
            if ($projectLabel -ne $script:StagingProject) {
                Stop-Deployment "Port 18101 is published by non-staging container $($labels[0].Name)."
            }
        }
    }
    if (($listenerText -match $portListenerPattern) -and ($existingStagingIds.Count -eq 0)) {
        Stop-Deployment 'TCP port 18101 is already listening on the VPS.'
    }
    Write-Host 'Staging port 18101 is unused or belongs only to this staging project.'

    $runId = [guid]::NewGuid().ToString('N')
    $runDirectory = Join-Path ([IO.Path]::GetTempPath()) "nexo-control-staging-$runId"
    New-Item -ItemType Directory -Path $runDirectory -Force | Out-Null
    $archivePath = Join-Path $runDirectory 'nexo-control-staging.tar.gz'
    $imageTarPath = Join-Path $runDirectory 'image.tar'
    $composePath = Join-Path $runDirectory 'compose.yml'
    $nginxPath = Join-Path $runDirectory 'default.conf'
    $envPath = Join-Path $runDirectory '.env'
    $remoteScriptPath = Join-Path $runDirectory 'deploy.sh'
    $remoteBundlePath = "/tmp/nexo-control-staging-$runId"

    try {
        if ($prebuiltArtifact) {
            Copy-Item -LiteralPath $prebuiltArtifact.ArchivePath -Destination $archivePath
            $imageId = $prebuiltArtifact.ImageId
            $imageSizeBytes = $prebuiltArtifact.ImageSizeBytes
            $tarSizeBytes = $prebuiltArtifact.TarSizeBytes
        } else {
            $docker = Get-DockerContextAndExecutable
            Write-Host "Building $imageTag for linux/amd64 using target $($buildDefinition.RuntimeStage)..."
            Invoke-Native -FilePath $docker.Executable -Arguments @(
                '--context', $docker.Context, 'buildx', 'build',
                '--platform', 'linux/amd64',
                '--file', $buildDefinition.Dockerfile,
                '--target', $buildDefinition.RuntimeStage,
                '--tag', $imageTag,
                '--load',
                $buildDefinition.Context
            ) -Label 'Control Center image build' | Out-Null
            $imageInspect = Invoke-Native -FilePath $docker.Executable -Arguments @(
                '--context', $docker.Context, 'image', 'inspect', $imageTag
            ) -Label 'Built image inspection'
            try {
                $imageInfo = @(ConvertFrom-Json -InputObject (Get-OutputText $imageInspect) -ErrorAction Stop)
            } catch {
                Stop-Deployment "Docker image inspect did not return valid JSON: $($_.Exception.Message)"
            }
            if ($imageInfo.Count -ne 1 -or $imageInfo[0].Id -notmatch '^sha256:[a-f0-9]{64}$') {
                Stop-Deployment 'Built image ID or size has an unexpected format.'
            }
            $imageId = [string]$imageInfo[0].Id
            $imageSizeBytes = 0L
            if (-not [long]::TryParse([string]$imageInfo[0].Size, [ref]$imageSizeBytes) -or $imageSizeBytes -le 0) {
                Stop-Deployment 'Could not determine built image size for VPS disk capacity validation.'
            }
            Invoke-Native -FilePath $docker.Executable -Arguments @(
                '--context', $docker.Context, 'save', '--output', $imageTarPath, $imageTag
            ) -Label 'Export Control Center image' | Out-Null
            $tarSizeBytes = (Get-Item -LiteralPath $imageTarPath).Length
            Compress-ImageArchive -SourcePath $imageTarPath -DestinationPath $archivePath
            Remove-Item -LiteralPath $imageTarPath -Force
        }
        $archiveHash = (Get-FileHash -LiteralPath $archivePath -Algorithm SHA256).Hash.ToLowerInvariant()
        Write-Host "Image archive SHA256: $archiveHash"

        $archiveKb = [math]::Ceiling((Get-Item -LiteralPath $archivePath).Length / 1KB)
        $tarKb = [math]::Ceiling($tarSizeBytes / 1KB)
        $imageKb = [math]::Ceiling($imageSizeBytes / 1KB)
        $requiredDiskKb = $archiveKb + $tarKb + $imageKb + $script:MinAvailableDiskKb
        if ($diskKb -lt $requiredDiskKb) {
            Stop-Deployment "VPS disk headroom is too low for the compressed archive, image tar, and loaded image. Available: $diskKb KiB; required including 2 GiB reserve: $requiredDiskKb KiB."
        }

        Set-Content -LiteralPath $composePath -Value (New-ComposeFile -ImageTag $imageTag) -Encoding Ascii
        Set-Content -LiteralPath $nginxPath -Value (New-NginxFile) -Encoding Ascii
        Set-Content -LiteralPath $envPath -Value "NEXO_UPSTREAM_NETWORK=$selectedNetwork" -Encoding Ascii
        Set-Content -LiteralPath $remoteScriptPath -Value (New-RemoteDeploymentScript `
            -RunId $runId `
            -ImageTag $imageTag `
            -ArchiveSha256 $archiveHash `
            -ArchiveBytes (Get-Item -LiteralPath $archivePath).Length `
            -TarBytes $tarSizeBytes `
            -ImageBytes $imageSizeBytes) -Encoding Ascii

        $makeBundle = Invoke-Ssh -SshExe $sshExe -KeyPath $keyPath `
            -RemoteCommand "mkdir -m 700 $remoteBundlePath" -Label 'Create unique VPS transfer directory'
        $scpArguments = @('-i', $keyPath, '-P', [string]$script:SshPort, '-o', 'IdentitiesOnly=yes', '-o', 'StrictHostKeyChecking=yes', '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=12')
        foreach ($path in @($archivePath, $composePath, $nginxPath, $envPath, $remoteScriptPath)) {
            $scpArguments += @($path, "$script:VpsUser@$script:VpsHost`:$remoteBundlePath/")
            $transfer = Invoke-Native -FilePath $scpExe -Arguments $scpArguments -Label "Transfer $([IO.Path]::GetFileName($path))"
            $scpArguments = @('-i', $keyPath, '-P', [string]$script:SshPort, '-o', 'IdentitiesOnly=yes', '-o', 'StrictHostKeyChecking=yes', '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=12')
        }

        Invoke-Ssh -SshExe $sshExe -KeyPath $keyPath `
            -RemoteCommand "sh -n $remoteBundlePath/deploy.sh" -Label 'Validate remote deployment shell syntax' | Out-Null

        $remoteDeploy = Invoke-Ssh -SshExe $sshExe -KeyPath $keyPath `
            -RemoteCommand "sh $remoteBundlePath/deploy.sh" -Label 'Run isolated staging deployment'
        foreach ($line in $remoteDeploy.Output) {
            Write-Host ([string]$line)
        }
        Write-Host "Image tag: $imageTag"
        Write-Host "Image ID: $imageId"
        Write-Host "Image archive SHA256: $archiveHash"
        Write-Host "Tunnel command: ssh -i `"$keyPath`" -p $script:SshPort -o IdentitiesOnly=yes -o StrictHostKeyChecking=yes -N -L 18101:127.0.0.1:18101 $script:VpsUser@$script:VpsHost"
        Write-Host 'Local browser URL: http://127.0.0.1:18101/login'
    } finally {
        # Remove only the unique transfer directory created by this invocation.
        Invoke-Ssh -SshExe $sshExe -KeyPath $keyPath `
            -RemoteCommand "rm -rf $remoteBundlePath" -Label 'Remove this run temporary transfer directory' -AllowFailure | Out-Null
        Remove-Item -LiteralPath $runDirectory -Recurse -Force -ErrorAction SilentlyContinue
    }
}
