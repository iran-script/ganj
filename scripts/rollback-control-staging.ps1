[CmdletBinding()]
param()

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$sshCommand = Get-Command 'ssh.exe' -CommandType Application -ErrorAction SilentlyContinue | Select-Object -First 1
if (-not $sshCommand) {
    $sshFallback = Join-Path $env:WINDIR 'System32\OpenSSH\ssh.exe'
    if (Test-Path -LiteralPath $sshFallback -PathType Leaf) {
        $sshPath = $sshFallback
    } else {
        throw 'Could not find ssh.exe. Install Windows OpenSSH Client or add ssh.exe to PATH.'
    }
} else {
    $sshPath = $sshCommand.Source
}

$keyPath = Join-Path $env:USERPROFILE '.ssh\pad_deploy'
if (-not (Test-Path -LiteralPath $keyPath -PathType Leaf)) {
    throw "Expected SSH key does not exist: $keyPath. Key contents were not read."
}

$remoteScript = @'
set -eu
staging_dir=/opt/nexo-control-staging
marker=nexo-control-staging-v1
[ -f "$staging_dir/.nexo-staging-owned" ] || { echo 'Staging ownership marker is missing; refusing rollback.' >&2; exit 1; }
[ "$(cat "$staging_dir/.nexo-staging-owned")" = "$marker" ] || { echo 'Staging ownership marker does not match; refusing rollback.' >&2; exit 1; }
cd "$staging_dir"
docker compose --project-name nexo-control-staging -f compose.yml down
remaining=$(docker ps -aq --filter label=com.docker.compose.project=nexo-control-staging)
[ -z "$remaining" ] || { echo 'Staging containers remain after rollback.' >&2; exit 1; }
echo 'Isolated NEXO Control Center staging project stopped and removed. Image and staging files were retained.'
'@
$remoteEncoded = [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($remoteScript.Trim()))
$remoteCommand = "printf %s $remoteEncoded | base64 -d | sh"
$arguments = @(
    '-i', $keyPath,
    '-o', 'BatchMode=yes',
    '-o', 'ConnectTimeout=12',
    '-o', 'ServerAliveInterval=10',
    '-o', 'ServerAliveCountMax=3',
    'root@185.186.77.210',
    $remoteCommand
)
$output = @(& $sshPath @arguments 2>&1)
$exitCode = $LASTEXITCODE
$output | ForEach-Object { Write-Output ([string]$_) }
if ($exitCode -ne 0) {
    throw "Staging-only rollback failed with exit code $exitCode."
}
