[CmdletBinding()]
param(
    [switch]$ValidateOnly
)

$ErrorActionPreference = 'Stop'
$HostName = '2.188.205.35'
$SshUser = 'root'
$SshPort = 2238
$SshExe = Join-Path $env:WINDIR 'System32\OpenSSH\ssh.exe'
$ScpExe = Join-Path $env:WINDIR 'System32\OpenSSH\scp.exe'
$KeyPath = Join-Path $env:USERPROFILE '.ssh\nexo_deploy'

function Assert-Command {
    param([string]$Path, [string]$Name)
    if (-not (Test-Path -LiteralPath $Path -PathType Leaf)) {
        throw "Required executable not found: $Name ($Path)"
    }
}

Assert-Command -Path $SshExe -Name 'Windows OpenSSH ssh.exe'
Assert-Command -Path $ScpExe -Name 'Windows OpenSSH scp.exe'

# Keep the remote payload independent from application files and credentials.
$RemoteScript = @'
#!/usr/bin/env bash
set -Eeuo pipefail
trap 'rc=$?; echo "Infrastructure preparation failed at line ${LINENO} (exit ${rc})." >&2; exit "$rc"' ERR

echo '=== Read-only preflight ==='
cat /etc/os-release
printf 'architecture: '; dpkg --print-architecture
printf 'cpu count: '; nproc
free -h
swapon --show
df -h /
echo '--- listening sockets ---'
ss -tuln
echo '--- effective SSH settings ---'
if command -v sshd >/dev/null 2>&1; then sshd -T | awk '$1 ~ /^(port|passwordauthentication|pubkeyauthentication|permitrootlogin|authorizedkeysfile)$/ {print}'; else echo 'sshd binary unavailable'; fi
echo '--- Docker-related packages ---'
dpkg-query -W -f='${binary:Package} ${Status}\n' 'docker*' 'containerd*' 2>/dev/null || true
echo '--- Docker service ---'
systemctl is-active docker 2>/dev/null || true
echo '--- firewall status ---'
if command -v ufw >/dev/null 2>&1; then ufw status verbose; else echo 'ufw not installed'; fi
echo '--- failed services ---'
systemctl --failed --no-legend || true
echo '--- pending security updates (review only) ---'
apt-get -s upgrade 2>/dev/null | awk '/^Inst / {print}' | head -n 40 || true

[[ "$(. /etc/os-release; echo "${VERSION_ID:-}")" == '22.04' ]] || { echo 'STOP: expected Ubuntu 22.04.' >&2; exit 20; }
[[ "$(. /etc/os-release; echo "${VERSION_CODENAME:-}")" == 'jammy' ]] || { echo 'STOP: expected jammy.' >&2; exit 21; }
[[ "$(dpkg --print-architecture)" == 'amd64' ]] || { echo 'STOP: expected amd64.' >&2; exit 22; }
(( $(nproc) >= 2 )) || { echo 'STOP: fewer than 2 CPUs.' >&2; exit 23; }
ss -ltnH | awk '{print $4}' | grep -Eq '(^|\])\*:2238$|^0\.0\.0\.0:2238$|^\[::\]:2238$' || { echo 'STOP: SSH is not listening on TCP 2238.' >&2; exit 24; }
if ss -ltnH | awk '{print $4}' | grep -E '^(0\.0\.0\.0:|\*:|\[::\]:)' | grep -Ev '(:2238)$' | grep -q .; then
  echo 'STOP: unexpected non-SSH public TCP listener found; inspect before proceeding.' >&2; exit 25
fi

# Never adopt or overwrite an existing NEXO directory or Docker data store.
if [[ -e /opt/nexo ]]; then
  [[ -d /opt/nexo && ! -L /opt/nexo ]] || { echo 'STOP: /opt/nexo exists and is not a normal directory.' >&2; exit 26; }
  [[ -z "$(find /opt/nexo -mindepth 1 -maxdepth 1 -print -quit)" ]] || { echo 'STOP: /opt/nexo is non-empty; refusing to alter existing content.' >&2; exit 27; }
fi
if [[ -d /var/lib/docker ]] && [[ -n "$(find /var/lib/docker -mindepth 1 -maxdepth 1 -print -quit)" ]]; then
  if ! systemctl is-active --quiet docker 2>/dev/null; then echo 'STOP: Docker data exists while Docker is inactive.' >&2; exit 28; fi
  docker info >/dev/null || { echo 'STOP: Docker data exists but the active Engine is unhealthy.' >&2; exit 29; }
  echo 'Existing healthy Docker Engine detected; preserving its images and containers.'
fi

swap_lines=$(awk '$1 !~ /^#/ && $3 == "swap" {n++} END {print n+0}' /etc/fstab)
active_swap=$(awk 'NR>1 {n++} END {print n+0}' /proc/swaps)
if [[ -e /swapfile && ! -f /swapfile ]]; then echo 'STOP: /swapfile exists and is not a regular file.' >&2; exit 30; fi
if [[ -e /swapfile && ( $swap_lines -eq 0 || $active_swap -eq 0 ) ]]; then echo 'STOP: existing /swapfile is not fully configured; refusing to overwrite.' >&2; exit 31; fi
if [[ ! -e /swapfile && ( $swap_lines -ne 0 || $active_swap -ne 0 ) ]]; then echo 'STOP: existing swap configuration detected; refusing to add another swap.' >&2; exit 32; fi
if [[ ! -e /swapfile ]] && [[ $(df -Pk / | awk 'NR==2 {print $4}') -lt 3145728 ]]; then echo 'STOP: less than 3 GiB free; insufficient headroom to create 2 GiB swap.' >&2; exit 33; fi
if [[ -e /etc/sysctl.d/99-nexo-swap.conf ]] && ! grep -qE '^[[:space:]]*vm\.swappiness[[:space:]]*=[[:space:]]*10([[:space:]]|$)' /etc/sysctl.d/99-nexo-swap.conf; then
  echo 'STOP: /etc/sysctl.d/99-nexo-swap.conf already exists without the required setting; refusing to overwrite it.' >&2; exit 39
fi
if grep -RhsE '^[[:space:]]*vm\.swappiness[[:space:]]*=' /etc/sysctl.conf /etc/sysctl.d 2>/dev/null | grep -Ev '=[[:space:]]*10([[:space:]]|$)' | grep -q .; then
  echo 'STOP: an existing sysctl file configures a different vm.swappiness; inspect before changing it.' >&2; exit 40
fi

conflicts=$(dpkg-query -W -f='${binary:Package}\n' docker.io docker-compose docker-compose-v2 podman-docker containerd runc 2>/dev/null || true)
if [[ -n "$conflicts" ]]; then echo "STOP: potentially conflicting packages are installed; no removal will be attempted:" >&2; echo "$conflicts" >&2; exit 34; fi
if ! systemctl is-active --quiet docker 2>/dev/null; then
  echo '=== Installing Docker Engine from the official Docker Ubuntu repository ==='
  export DEBIAN_FRONTEND=noninteractive
  apt-get update
  apt-get install -y ca-certificates curl gnupg
  install -m 0755 -d /etc/apt/keyrings
  key_tmp=$(mktemp)
  trap 'rc=$?; rm -f "$key_tmp"; echo "Infrastructure preparation failed at line ${LINENO} (exit ${rc})." >&2; exit "$rc"' ERR
  curl --fail --silent --show-error --location https://download.docker.com/linux/ubuntu/gpg -o "$key_tmp"
  actual_fingerprint=$(gpg --show-keys --with-colons "$key_tmp" | awk -F: '$1=="fpr" {print $10; exit}')
  expected_fingerprint='9DC858229FC7DD38854AE2D88D81803C0EBFCD88'
  [[ "$actual_fingerprint" == "$expected_fingerprint" ]] || { echo "STOP: Docker repository signing key fingerprint mismatch: $actual_fingerprint" >&2; exit 36; }
  install -m 0644 "$key_tmp" /etc/apt/keyrings/docker.asc
  rm -f "$key_tmp"
  printf 'Types: deb\nURIs: https://download.docker.com/linux/ubuntu\nSuites: jammy\nComponents: stable\nArchitectures: amd64\nSigned-By: /etc/apt/keyrings/docker.asc\n' > /etc/apt/sources.list.d/docker.sources
  apt-get update
  apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
  systemctl enable --now docker
else
  echo '=== Reusing existing active Docker Engine without restarting it ==='
  systemctl enable docker
fi
systemctl is-active --quiet docker
docker info >/dev/null

echo '=== Configuring 2 GiB swap ==='
if [[ ! -e /swapfile ]]; then
  fallocate -l 2G /swapfile
  chmod 600 /swapfile
  chown root:root /swapfile
  mkswap /swapfile
  swapon /swapfile
  printf '/swapfile none swap sw 0 0\n' >> /etc/fstab
fi
if [[ ! -e /etc/sysctl.d/99-nexo-swap.conf ]]; then
  printf 'vm.swappiness=10\n' > /etc/sysctl.d/99-nexo-swap.conf
else
  grep -qE '^[[:space:]]*vm\.swappiness[[:space:]]*=[[:space:]]*10([[:space:]]|$)' /etc/sysctl.d/99-nexo-swap.conf || { echo 'STOP: conflicting swappiness setting in NEXO sysctl file.' >&2; exit 37; }
fi
chmod 644 /etc/sysctl.d/99-nexo-swap.conf
sysctl -w vm.swappiness=10

echo '=== Preparing empty NEXO directory structure ==='
install -d -o root -g root -m 0750 /opt/nexo
for d in compose config backups artifacts logs; do install -d -o root -g root -m 0750 "/opt/nexo/$d"; done

echo '=== Final validation ==='
docker --version
docker compose version
systemctl is-active docker
docker info --format '{{.OSType}} {{.Architecture}}'
free -h
swapon --show
sysctl vm.swappiness
df -h /
ss -tuln
systemctl --failed --no-legend || true
printf 'NEXO application containers: '
docker ps -a --format '{{.Names}}' | grep -Eiq 'nexo' && { echo 'FOUND (inspect manually)'; exit 38; } || echo 'none'
echo 'Infrastructure preparation completed; no NEXO application containers were started.'
'@

# Validate the embedded Bash payload locally before any network operation.
$bashCheck = Get-Command bash.exe -ErrorAction SilentlyContinue | Where-Object { $_.Source -notlike "$env:WINDIR\System32\bash.exe" }
if ($bashCheck) {
    $payloadPath = Join-Path $env:TEMP ("nexo-infra-validate-{0}.sh" -f [guid]::NewGuid().ToString('N'))
    try {
        [System.IO.File]::WriteAllText($payloadPath, $RemoteScript, [System.Text.UTF8Encoding]::new($false))
        & $bashCheck.Source -n $payloadPath
        if ($LASTEXITCODE -ne 0) { throw "Embedded Bash syntax check failed (exit $LASTEXITCODE)." }
    } finally {
        Remove-Item -LiteralPath $payloadPath -Force -ErrorAction SilentlyContinue
    }
} elseif (-not $ValidateOnly) {
    Write-Warning 'bash.exe is unavailable locally; the remote script will run bash -n before execution.'
}

if ($ValidateOnly) {
    Write-Output 'ValidateOnly passed: PowerShell parsed, SSH/SCP paths resolved, and embedded Bash payload is prepared.'
    return
}

Write-Output "Destination: $SshUser@$HostName`:$SshPort"
if (-not (Test-Path -LiteralPath $KeyPath -PathType Leaf)) {
    throw "SSH key not found: $KeyPath. The key contents were not read."
}
Write-Output 'SSH host-key checking is strict. If OpenSSH rejects the host key, stop and verify its fingerprint through the VPS provider console; do not disable host-key checks.'
Write-Output 'The remote script does not change SSH or firewall settings, reboot, build images, or deploy application containers.'

$remoteName = "nexo-infra-prepare-$([guid]::NewGuid().ToString('N')).sh"
$localPath = Join-Path $env:TEMP $remoteName
$remotePath = "/tmp/$remoteName"
$sshOptions = @('-i',$KeyPath,'-o','IdentitiesOnly=yes','-o','StrictHostKeyChecking=yes','-o','BatchMode=yes','-p',"$SshPort")
$scpOptions = @('-i',$KeyPath,'-o','IdentitiesOnly=yes','-o','StrictHostKeyChecking=yes','-o','BatchMode=yes','-P',"$SshPort")

try {
    [System.IO.File]::WriteAllText($localPath, $RemoteScript, [System.Text.UTF8Encoding]::new($false))
    & $ScpExe @scpOptions -- $localPath "${SshUser}@${HostName}:$remotePath"
    if ($LASTEXITCODE -ne 0) { throw "SCP failed with exit code $LASTEXITCODE. Check network, host key, and interactive authentication." }

    $remoteCommand = "chmod 600 '$remotePath' && bash -n '$remotePath' && bash '$remotePath'; rc=`$?; rm -f '$remotePath'; exit `$rc"
    & $SshExe @sshOptions "${SshUser}@${HostName}" $remoteCommand
    if ($LASTEXITCODE -ne 0) { throw "Remote preparation failed with exit code $LASTEXITCODE. No application deployment was attempted." }
} finally {
    Remove-Item -LiteralPath $localPath -Force -ErrorAction SilentlyContinue
}
