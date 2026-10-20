<#
NEXO direct, resumable migration orchestrator. Windows PowerShell 5.1.

Safety model:
* Old host is only queried and receives uniquely named, retained backup files.
* New host is refused if it already has NEXO containers, named volumes, or a non-empty release target.
* Source comes only from the pinned git commit; no local .env, untracked files, keys, or user profile are archived.
* No public binding is created; Compose binds only loopback port 18100.
* Background queue/scheduler are gated because the old production copy may still run scheduled side effects.
* Database restore precedes application startup. Pending migrations are reported and are not run.
#>
[CmdletBinding()]
param(
    [string]$Repository,
    [string]$OldHost = '185.186.77.210',
    [int]$OldPort = 22,
    [string]$OldUser = 'root',
    [string]$OldKey = (Join-Path $env:USERPROFILE '.ssh\pad_deploy'),
    [string]$NewHost = '2.188.205.35',
    [int]$NewPort = 2238,
    [string]$NewUser = 'root',
    [string]$NewKey = (Join-Path $env:USERPROFILE '.ssh\nexo_deploy'),
    [string]$TargetSha = '2111243fff34f724d98c7ae6a825785967fbe882',
    [string]$ResumeRunId,
    [switch]$KeepOldRedisOnly,
    [switch]$EnableBackgroundProcessing,
    [switch]$ApproveDatabasePromotion,
    [switch]$PromoteApprovedRestoreOnly,
    [string]$ApprovedRestoreDatabase,
    [switch]$ValidateOnly
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version 2
if (-not $Repository) { $Repository = Split-Path -Parent $PSScriptRoot }
$script:RunId = if ($ResumeRunId) { $ResumeRunId } else { [Guid]::NewGuid().ToString('N') }
$script:LocalWork = Join-Path ([IO.Path]::GetTempPath()) ("nexo-migrate-{0}" -f $script:RunId)
$script:Release = "/opt/nexo/releases/$TargetSha"
$script:Compose = "$($script:Release)/docker-compose.production.yml"
$script:StateFile = Join-Path $script:LocalWork 'state.json'
$script:StreamStateFile = Join-Path $script:LocalWork 'stream-state.json'
$script:LogFile = Join-Path $script:LocalWork 'run.log'
$script:LocalTunnelPort = 18100
$script:PreviousState = $null
$script:StreamState = $null

function Write-Step([string]$Message) { Write-Host ("[{0}] {1}" -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $Message) }
function Fail([string]$Message) { throw $Message }
function Require-Command([string]$Name) {
    $c = Get-Command $Name -ErrorAction SilentlyContinue
    if (-not $c) { Fail "Required command '$Name' is unavailable. Install the official Windows OpenSSH client and retry." }
    return $c.Source
}
function Invoke-Icacls([string]$Path,[string[]]$Arguments,[string]$Label) {
    $icacls = Require-Command 'icacls.exe'
    $nativeArguments = @($Path) + @($Arguments)
    Invoke-Native $icacls $nativeArguments $Label | Out-Null
}
function Set-PrivateWorkspaceAcl([string]$Path) {
    if (-not (Test-Path -LiteralPath $Path -PathType Container)) { Fail 'Private migration workspace is not an existing directory.' }
    $root = Get-Item -LiteralPath $Path -Force -ErrorAction Stop
    if ($root.Attributes -band [IO.FileAttributes]::ReparsePoint) { Fail 'Private migration workspace cannot be a reparse point.' }
    $items = @($root)
    $items += @(Get-ChildItem -LiteralPath $Path -Force -Recurse -ErrorAction Stop | Sort-Object { $_.FullName.Length })
    foreach ($item in $items) {
        if ($item.Attributes -band [IO.FileAttributes]::ReparsePoint) { Fail 'Private migration workspace contains a reparse point; refusing to change ACLs outside the workspace.' }
    }

    $identity = [Security.Principal.WindowsIdentity]::GetCurrent()
    if ($null -eq $identity.User) { Fail 'Could not determine the current Windows user SID for workspace ACLs.' }
    $allowedSids = @($identity.User.Value,'S-1-5-18','S-1-5-32-544')
    $allowedSids = @($allowedSids | Select-Object -Unique)

    # icacls edits the DACL only. Protect and grant each parent before processing
    # children, so a non-elevated owner always retains traversal during ACL repair.
    foreach ($item in $items) {
        Invoke-Icacls $item.FullName @('/inheritance:r','/Q') 'Disable inherited workspace access'
        $acl = Get-Acl -LiteralPath $item.FullName -ErrorAction Stop
        $rules = @($acl.GetAccessRules($true,$true,[Security.Principal.SecurityIdentifier]))
        $allowSids = @($rules | Where-Object { $_.AccessControlType -eq [Security.AccessControl.AccessControlType]::Allow } | ForEach-Object { $_.IdentityReference.Value } | Sort-Object -Unique)
        $denySids = @($rules | Where-Object { $_.AccessControlType -eq [Security.AccessControl.AccessControlType]::Deny } | ForEach-Object { $_.IdentityReference.Value } | Sort-Object -Unique)
        # Keep any existing current-user allow ACE until /grant:r replaces it; doing
        # so avoids a moment where a non-elevated owner loses access between icacls calls.
        foreach ($sid in $allowSids) { if ($sid -notin $allowedSids) { Invoke-Icacls $item.FullName @('/remove:g',('*' + $sid),'/Q') 'Remove previous workspace allow entry' } }
        foreach ($sid in $denySids) { Invoke-Icacls $item.FullName @('/remove:d',('*' + $sid),'/Q') 'Remove previous workspace deny entry' }

        $permissions = if ($item.PSIsContainer) { '(OI)(CI)F' } else { 'F' }
        $grantArguments = @('/grant:r')
        foreach ($sid in $allowedSids) { $grantArguments += ('*{0}:{1}' -f $sid,$permissions) }
        $grantArguments += '/Q'
        Invoke-Icacls $item.FullName $grantArguments 'Grant private workspace access'
    }

    # Confirm every existing item is protected from inheritance and grants only
    # Full Control to the current user, Local System, and built-in Administrators.
    foreach ($item in $items) {
        $acl = Get-Acl -LiteralPath $item.FullName -ErrorAction Stop
        if (-not $acl.AreAccessRulesProtected) { Fail 'Workspace ACL verification failed: inherited access is still enabled.' }
        $rules = @($acl.GetAccessRules($true,$true,[Security.Principal.SecurityIdentifier]))
        foreach ($rule in $rules) {
            if ($rule.IdentityReference.Value -notin $allowedSids -or $rule.AccessControlType -ne [Security.AccessControl.AccessControlType]::Allow) { Fail 'Workspace ACL verification failed: an unexpected principal or deny entry remains.' }
            $fullControl = [Security.AccessControl.FileSystemRights]::FullControl
            if (($rule.FileSystemRights -band $fullControl) -ne $fullControl) { Fail 'Workspace ACL verification failed: an approved principal lacks Full Control.' }
        }
        foreach ($sid in $allowedSids) {
            if (@($rules | Where-Object { $_.IdentityReference.Value -eq $sid -and $_.AccessControlType -eq [Security.AccessControl.AccessControlType]::Allow }).Count -eq 0) { Fail 'Workspace ACL verification failed: an approved principal is missing.' }
        }
    }
}
function Test-WorkspaceAclRegression {
    $source = Get-Content -LiteralPath $PSCommandPath -Raw
    if ($source -match '(?im)^\s*Set-Acl\b') { Fail 'Workspace ACL regression: Set-Acl must not be used for private migration permissions.' }
    $testRoot = Join-Path ([IO.Path]::GetTempPath()) ('nexo-acl-regression-' + [Guid]::NewGuid().ToString('N'))
    try {
        New-Item -ItemType Directory -Path $testRoot -Force | Out-Null
        $nested = Join-Path $testRoot 'nested'; New-Item -ItemType Directory -Path $nested -Force | Out-Null
        [IO.File]::WriteAllText((Join-Path $nested 'fixture.txt'),'ACL regression fixture')
        $icacls = Require-Command 'icacls.exe'
        $seed = & $icacls $testRoot '/grant' '*S-1-1-0:(OI)(CI)F' '/Q' 2>&1
        if ($LASTEXITCODE -ne 0) { Fail 'Workspace ACL regression could not seed a disposable broad-access fixture.' }
        Set-PrivateWorkspaceAcl $testRoot
        $principal = New-Object Security.Principal.WindowsPrincipal($identity = [Security.Principal.WindowsIdentity]::GetCurrent())
        $isAdminToken = $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
        if (-not $isAdminToken) { Write-Step 'DACL-only workspace ACL regression passed under a non-administrator Windows token.' }
        else { Write-Step 'DACL-only workspace ACL regression passed; the current Windows token is administrator-enabled.' }
    } finally { Remove-Item -LiteralPath $testRoot -Recurse -Force -ErrorAction SilentlyContinue }
}
function Invoke-Native([string]$Exe, [string[]]$Arguments, [string]$Label) {
    $out = & $Exe @Arguments 2>&1
    $code = $LASTEXITCODE
    if ($code -ne 0) { $detail = ($out | Out-String).Trim(); Fail "$Label failed (exit $code): $detail" }
    return ,$out
}
function Get-SshArgs([string]$Key, [int]$Port, [string]$User, [string]$HostName) {
    return @('-i', $Key, '-p', [string]$Port, '-o', 'IdentitiesOnly=yes', '-o', 'StrictHostKeyChecking=yes', '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=12', "$User@$HostName")
}
function Invoke-Remote([string]$Key, [int]$Port, [string]$User, [string]$HostName, [string]$Script, [string]$Label) {
    # Normalize Windows here-string CRLF before Bash parses it. A trailing CR can turn `fi` into `fi<CR>`.
    # Validate the exact normalized payload remotely with bash -n before executing any command.
    $ssh = Require-Command 'ssh.exe'
    $payload = $Script -replace "`r`n?", "`n"
    $invokeSshPayload = {
        param([string]$RemoteCommand)
        $psi = New-Object Diagnostics.ProcessStartInfo
        $psi.FileName = $ssh
        $sshArgs = @('-i', $Key, '-p', [string]$Port, '-o', 'IdentitiesOnly=yes', '-o', 'StrictHostKeyChecking=yes', '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=12', "$User@$HostName", $RemoteCommand)
        $psi.Arguments = (($sshArgs | ForEach-Object { '"' + ($_ -replace '(\\*)"', '$1$1\"' -replace '(\\+)$', '$1$1') + '"' }) -join ' ')
        $psi.UseShellExecute = $false; $psi.RedirectStandardInput = $true; $psi.RedirectStandardOutput = $true; $psi.RedirectStandardError = $true
        $proc = New-Object Diagnostics.Process; $proc.StartInfo = $psi
        if (-not $proc.Start()) { Fail "${Label}: unable to start ssh.exe" }
        $stdoutTask = $proc.StandardOutput.ReadToEndAsync(); $stderrTask = $proc.StandardError.ReadToEndAsync()
        $proc.StandardInput.Write($payload); $proc.StandardInput.Close(); $proc.WaitForExit()
        [pscustomobject]@{ ExitCode=$proc.ExitCode; StdOut=$stdoutTask.Result; StdErr=$stderrTask.Result }
    }
    $syntax = & $invokeSshPayload 'bash -n'
    if ($syntax.ExitCode -ne 0) { Fail "$Label remote Bash syntax validation failed (exit $($syntax.ExitCode)): $($syntax.StdErr.Trim()) $($syntax.StdOut.Trim())" }
    $result = & $invokeSshPayload 'bash -s'
    if ($result.ExitCode -ne 0) { Fail "$Label failed (exit $($result.ExitCode)): $($result.StdErr.Trim()) $($result.StdOut.Trim())" }
    if ($result.StdErr.Trim()) { Add-Content -LiteralPath $script:LogFile -Value $result.StdErr }
    return $result.StdOut.Trim()
}
function Get-StreamSha256Marker([string]$Diagnostics,[string]$Label) {
    # OpenSSH may add unrelated stderr warnings. Normalize line endings, then accept only
    # one complete, standalone marker line; never parse binary stdout as text.
    $normalized = $Diagnostics -replace "`r`n?", "`n"
    $markers = [regex]::Matches($normalized, '(?m)^STREAM_SHA256=([a-fA-F0-9]{64})[ \t]*$')
    if ($markers.Count -ne 1) { Fail "$Label completed without exactly one valid source SHA256 marker; the local stream was rejected." }
    return $markers[0].Groups[1].Value.ToLowerInvariant()
}
function Assert-StreamFileHash([string]$Path,[string]$ExpectedHash,[string]$Label) {
    if ($ExpectedHash -notmatch '^[a-f0-9]{64}$') { Fail "$Label returned an invalid source SHA256; the local stream was rejected." }
    if (-not (Test-Path -LiteralPath $Path -PathType Leaf)) { Fail "$Label did not produce a local stream file." }
    $actualHash = (Get-FileHash -LiteralPath $Path -Algorithm SHA256).Hash.ToLowerInvariant()
    if ($actualHash -ne $ExpectedHash) { Fail "$Label SHA256 did not match the source stream; the local file was rejected." }
    return $actualHash
}
function Assert-StreamCompletion([int]$ExitCode,[string]$Diagnostics,[string]$Path,[string]$Label) {
    if ($ExitCode -ne 0) { Fail "$Label failed (exit $ExitCode); the stream is incomplete and was rejected." }
    $remoteHash = Get-StreamSha256Marker $Diagnostics $Label
    return (Assert-StreamFileHash $Path $remoteHash $Label)
}
function Get-RemoteStreamPrelude {
    return @'
stream_with_sha256() {
    if command -v python3 >/dev/null 2>&1; then
        python3 - "$@" <<'PY'
import hashlib
import subprocess
import sys
import threading

def main():
    producer = None
    stderr_thread = None
    try:
        producer = subprocess.Popen(sys.argv[1:], stdout=subprocess.PIPE, stderr=subprocess.PIPE, bufsize=0)
        digest = hashlib.sha256()
        def relay_stderr():
            while True:
                chunk = producer.stderr.read(65536)
                if not chunk:
                    return
                try:
                    sys.stderr.buffer.write(chunk)
                    sys.stderr.buffer.flush()
                except Exception:
                    return
        stderr_thread = threading.Thread(target=relay_stderr)
        stderr_thread.daemon = True
        stderr_thread.start()
        while True:
            chunk = producer.stdout.read(1048576)
            if not chunk:
                break
            written = sys.stdout.buffer.write(chunk)
            if written != len(chunk):
                raise OSError("short stdout write")
            digest.update(chunk)
        sys.stdout.buffer.flush()
        status = producer.wait()
        stderr_thread.join()
        if status != 0:
            sys.stderr.write("STREAM_PRODUCER_FAILED=%d\n" % status)
            sys.stderr.flush()
            return status if status > 0 else min(255, 128 - status)
        sys.stderr.write("STREAM_SHA256=%s\n" % digest.hexdigest())
        sys.stderr.flush()
        return 0
    except Exception:
        if producer is not None:
            if producer.poll() is None:
                producer.kill()
            producer.wait()
        if stderr_thread is not None:
            stderr_thread.join()
        sys.stderr.write("STREAM_WRAPPER_FAILED\n")
        sys.stderr.flush()
        return 70

sys.exit(main())
PY
        return $?
    fi
    if command -v perl >/dev/null 2>&1 && perl -MDigest::SHA -e 'exit 0' >/dev/null 2>&1; then
        perl - "$@" <<'PERL'
use strict;
use warnings;
use Digest::SHA ();
binmode STDOUT;
binmode STDERR;
my $sha = Digest::SHA->new(256);
my $pid = open(my $source, '-|', @ARGV);
if (!defined $pid) { print STDERR "STREAM_PRODUCER_START_FAILED\n"; exit 127; }
binmode $source;
while (1) {
    my $count = read($source, my $buffer, 1048576);
    if (!defined $count) { kill 'TERM', $pid; close($source); print STDERR "STREAM_READ_FAILED\n"; exit 74; }
    last if $count == 0;
    if (!print STDOUT $buffer) { kill 'TERM', $pid; close($source); exit 74; }
    $sha->add($buffer);
}
my $closed = close($source);
my $status = $?;
if (!$closed || $status != 0) { my $code = $status >> 8; my $signal = $status & 127; $code = 128 + $signal if $signal != 0; $code = 1 if $code == 0; print STDERR "STREAM_PRODUCER_FAILED=$code\n"; exit $code; }
if (!close STDOUT) { exit 74; }
print STDERR "STREAM_SHA256=", $sha->hexdigest, "\n";
exit 0;
PERL
        return $?
    fi
    printf '%s\n' 'STREAM_WRAPPER_UNAVAILABLE: need python3 or perl with Digest::SHA' >&2
    return 69
}
'@
}
function Test-StreamHashRegression {
    $prelude = Get-RemoteStreamPrelude
    foreach ($required in @('subprocess.Popen','producer.wait()','STREAM_SHA256=','status != 0','return status if status > 0','Digest::SHA','close($source)','exit $code')) {
        if ($prelude -notmatch [regex]::Escape($required)) { Fail "Stream wrapper regression: required producer/hash safety behavior '$required' is missing." }
    }
    if ($prelude -match '\$!|\bwait\s+"') { Fail 'Stream wrapper regression: do not use Bash process-substitution PID assumptions.' }
    $source = Get-Content -LiteralPath $PSCommandPath -Raw
    if ([regex]::Matches($source,'stream_with_sha256 (?:docker exec|cat --)').Count -ne 3) { Fail 'Stream wrapper regression: PostgreSQL, storage, and environment must all use the deterministic stream wrapper.' }
    if ($source -match '(?m)^\s*(?:hash_pid=\$!|tee\s*>\()') { Fail 'Stream wrapper regression: legacy asynchronous tee/process-substitution hashing remains.' }
    $producerCheck = $prelude.IndexOf('if status != 0:')
    $pythonMarker = $prelude.IndexOf('STREAM_SHA256=%s')
    if ($producerCheck -lt 0 -or $pythonMarker -le $producerCheck) { Fail 'Stream wrapper regression: checksum marker must be emitted only after producer status is checked.' }
    $fixturePath = [IO.Path]::GetTempFileName()
    try {
        # Empty streams are valid at the transport layer; database/storage validation rejects inappropriate empty backups later.
        [IO.File]::WriteAllBytes($fixturePath,[byte[]]@())
        $emptyHash = (Get-FileHash -LiteralPath $fixturePath -Algorithm SHA256).Hash.ToLowerInvariant()
        if ((Assert-StreamCompletion 0 "STREAM_SHA256=$emptyHash`n" $fixturePath 'empty stream regression') -ne $emptyHash) { Fail 'Stream regression: empty stream completion failed.' }
        $missingMarkerRejected = $false
        try { Assert-StreamCompletion 0 '' $fixturePath 'missing marker regression' | Out-Null } catch { $missingMarkerRejected = $true }
        if (-not $missingMarkerRejected) { Fail 'Stream regression: missing marker was accepted.' }
        $failedProducerRejected = $false
        try { Assert-StreamCompletion 23 "STREAM_SHA256=$emptyHash`n" $fixturePath 'producer failure regression' | Out-Null } catch { $failedProducerRejected = $true }
        if (-not $failedProducerRejected) { Fail 'Stream regression: failed producer was accepted despite a marker.' }

        $largeStream = [IO.File]::Open($fixturePath,[IO.FileMode]::Create,[IO.FileAccess]::Write,[IO.FileShare]::None)
        try {
            $chunk = New-Object byte[] (65536)
            for ($offset=0; $offset -lt 64; $offset++) {
                for ($index=0; $index -lt $chunk.Length; $index++) { $chunk[$index] = [byte](($index + $offset) % 256) }
                $largeStream.Write($chunk,0,$chunk.Length)
            }
        } finally { $largeStream.Dispose() }
        $largeHash = (Get-FileHash -LiteralPath $fixturePath -Algorithm SHA256).Hash.ToLowerInvariant()
        if ((Assert-StreamCompletion 0 "WARNING: benign producer diagnostic`r`nSTREAM_SHA256=$largeHash`r`n" $fixturePath 'large binary stream regression') -ne $largeHash) { Fail 'Stream regression: large binary stream completion failed.' }
        $corruptBytes = [byte[]](0,13,10,255,0,65,128,10,14)
        [IO.File]::WriteAllBytes($fixturePath,$corruptBytes)
        $corruptRejected = $false
        try { Assert-StreamCompletion 0 "STREAM_SHA256=$largeHash`n" $fixturePath 'corrupted stream regression' | Out-Null } catch { $corruptRejected = $true }
        if (-not $corruptRejected) { Fail 'Stream regression: corrupted stream was accepted.' }
    } finally { Remove-Item -LiteralPath $fixturePath -Force -ErrorAction SilentlyContinue }
}
function Invoke-RemoteBinary([string]$Key,[int]$Port,[string]$User,[string]$HostName,[string]$Script,[string]$OutputPath,[string]$Label) {
    $ssh = Require-Command 'ssh.exe'
    $payload = $Script -replace "`r`n?", "`n"
    $runPayload = {
        param([string]$RemoteCommand,[bool]$BinaryOutput)
        $psi = New-Object Diagnostics.ProcessStartInfo
        $psi.FileName = $ssh
        $sshArgs = @('-i',$Key,'-p',[string]$Port,'-o','IdentitiesOnly=yes','-o','StrictHostKeyChecking=yes','-o','BatchMode=yes','-o','ConnectTimeout=12',"$User@$HostName",$RemoteCommand)
        $psi.Arguments = (($sshArgs | ForEach-Object { '"' + ($_ -replace '(\\*)"','$1$1\"' -replace '(\\+)$','$1$1') + '"' }) -join ' ')
        $psi.UseShellExecute = $false; $psi.RedirectStandardInput = $true; $psi.RedirectStandardOutput = $true; $psi.RedirectStandardError = $true
        $process = New-Object Diagnostics.Process; $process.StartInfo = $psi
        if (-not $process.Start()) { Fail "${Label}: unable to start ssh.exe" }
        $stderrTask = $process.StandardError.ReadToEndAsync()
        if ($BinaryOutput) {
            $file = [IO.File]::Create($OutputPath)
            try { $process.StandardInput.Write($payload); $process.StandardInput.Close(); $process.StandardOutput.BaseStream.CopyTo($file); $file.Flush() } finally { $file.Dispose() }
            $process.WaitForExit()
            return [pscustomobject]@{ ExitCode=$process.ExitCode; StdErr=$stderrTask.Result; StdOut='' }
        }
        $stdoutTask = $process.StandardOutput.ReadToEndAsync()
        $process.StandardInput.Write($payload); $process.StandardInput.Close(); $process.WaitForExit()
        return [pscustomobject]@{ ExitCode=$process.ExitCode; StdErr=$stderrTask.Result; StdOut=$stdoutTask.Result }
    }
    $syntax = & $runPayload 'bash -n' $false
    if ($syntax.ExitCode -ne 0) { Fail "$Label remote Bash syntax validation failed (exit $($syntax.ExitCode)): $($syntax.StdErr.Trim()) $($syntax.StdOut.Trim())" }
    $result = $null
    try {
        $result = & $runPayload 'bash -s' $true
        $localHash = Assert-StreamCompletion $result.ExitCode $result.StdErr $OutputPath $Label
        $diagnostics = $result.StdErr -replace '(?m)^STREAM_SHA256=[a-fA-F0-9]{64}[ \t]*\r?$',''
        return [pscustomobject]@{ Path=$OutputPath; Sha256=$localHash; Diagnostics=$diagnostics.Trim() }
    } catch {
        # A failed or unverifiable stream is never retained as a usable backup.
        Remove-Item -LiteralPath $OutputPath -Force -ErrorAction SilentlyContinue
        throw
    }
}
function Invoke-Scp([string]$Key, [int]$Port, [string]$Source, [string]$Destination, [string]$Label) {
    $scp = Require-Command 'scp.exe'
    $args = @('-i', $Key, '-P', [string]$Port, '-o', 'IdentitiesOnly=yes', '-o', 'StrictHostKeyChecking=yes', '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=12', $Source, $Destination)
    Invoke-Native $scp $args $Label | Out-Null
}
function Save-State([hashtable]$State) {
    $State | ConvertTo-Json -Depth 6 | Set-Content -LiteralPath $script:StateFile -Encoding UTF8
}
function Save-StreamState([hashtable]$State) {
    $temporaryPath = $script:StreamStateFile + '.tmp'
    try {
        $State | ConvertTo-Json -Depth 4 | Set-Content -LiteralPath $temporaryPath -Encoding UTF8
        Move-Item -LiteralPath $temporaryPath -Destination $script:StreamStateFile -Force
    } finally { Remove-Item -LiteralPath $temporaryPath -Force -ErrorAction SilentlyContinue }
}
function Get-StreamCheckpointHash([string]$HashProperty,[string]$Path,[string]$Label) {
    if ($null -eq $script:StreamState -or -not $script:StreamState.ContainsKey($HashProperty)) { return $null }
    $expectedHash = [string]$script:StreamState[$HashProperty]
    if ($expectedHash -notmatch '^[a-f0-9]{64}$') { Fail "$Label checkpoint has an invalid source hash; refusing to reuse it." }
    return (Assert-StreamFileHash $Path $expectedHash $Label)
}
function Get-EnvValue([string[]]$Lines, [string]$Name) {
    foreach ($line in $Lines) { if ($line -match ('^\s*' + [regex]::Escape($Name) + '\s*=\s*(.*)\s*$')) { return $Matches[1].Trim().Trim('"').Trim("'") } }
    return $null
}
function Get-LaravelSlug([string]$Value) {
    if ([string]::IsNullOrWhiteSpace($Value)) { return 'laravel' }
    $normalized = $Value.Normalize([Text.NormalizationForm]::FormD)
    $builder = New-Object Text.StringBuilder
    foreach ($character in $normalized.ToCharArray()) {
        if ([Globalization.CharUnicodeInfo]::GetUnicodeCategory($character) -ne [Globalization.UnicodeCategory]::NonSpacingMark) { [void]$builder.Append($character) }
    }
    $slug = [regex]::Replace($builder.ToString().ToLowerInvariant(), '[^a-z0-9]+', '-').Trim('-')
    if (-not $slug -or $slug -match '[^a-z0-9-]') { return $null }
    return $slug
}
function Get-RedisRuntimeConfig([string[]]$Environment) {
    $appName = Get-EnvValue $Environment 'APP_NAME'
    if (-not $appName) { $appName = 'laravel' }
    $slug = Get-LaravelSlug $appName
    if (-not $slug) { Fail 'APP_NAME cannot be converted to a safe ASCII slug; Redis keys cannot be attributed safely.' }
    $redisPrefix = Get-EnvValue $Environment 'REDIS_PREFIX'
    if ($null -eq $redisPrefix) { $redisPrefix = "$slug-database-" }
    $cachePrefix = Get-EnvValue $Environment 'CACHE_PREFIX'
    if ($null -eq $cachePrefix) { $cachePrefix = "$slug-cache-" }
    foreach ($prefix in @($redisPrefix,$cachePrefix)) {
        if ($prefix -match '[^A-Za-z0-9:_-]') { Fail 'Redis key prefix contains glob or shell metacharacters; refusing ambiguous shared-Redis attribution.' }
    }
    $redisDb = Get-EnvValue $Environment 'REDIS_DB'; if (-not $redisDb) { $redisDb = '0' }
    $cacheDb = Get-EnvValue $Environment 'REDIS_CACHE_DB'; if (-not $cacheDb) { $cacheDb = '1' }
    foreach ($db in @($redisDb,$cacheDb)) { if ($db -notmatch '^\d+$') { Fail 'Redis database index is not numeric; refusing an incomplete Redis audit.' } }
    $cacheStore = Get-EnvValue $Environment 'CACHE_STORE'; if (-not $cacheStore) { $cacheStore = 'database' }
    $sessionDriver = Get-EnvValue $Environment 'SESSION_DRIVER'; if (-not $sessionDriver) { $sessionDriver = 'database' }
    $queueConnection = Get-EnvValue $Environment 'QUEUE_CONNECTION'; if (-not $queueConnection) { $queueConnection = 'database' }
    $queueName = Get-EnvValue $Environment 'REDIS_QUEUE'; if (-not $queueName) { $queueName = 'default' }
    $cacheConnection = Get-EnvValue $Environment 'REDIS_CACHE_CONNECTION'; if (-not $cacheConnection) { $cacheConnection='cache' }
    $cacheLockConnection = Get-EnvValue $Environment 'REDIS_CACHE_LOCK_CONNECTION'; if (-not $cacheLockConnection) { $cacheLockConnection='default' }
    $queueRedisConnection = Get-EnvValue $Environment 'REDIS_QUEUE_CONNECTION'; if (-not $queueRedisConnection) { $queueRedisConnection='default' }
    $sessionConnection = Get-EnvValue $Environment 'SESSION_CONNECTION'; if (-not $sessionConnection) { $sessionConnection='default' }
    if ($cacheStore -eq 'redis' -and ($cacheConnection -notin @('default','cache') -or $cacheLockConnection -notin @('default','cache'))) { Fail 'The Redis cache store uses a custom Redis connection that this audit cannot safely attribute to the identified Redis container.' }
    if ($queueConnection -eq 'redis' -and $queueRedisConnection -notin @('default','cache')) { Fail 'The Redis queue uses a custom Redis connection that this audit cannot safely attribute to the identified Redis container.' }
    if ($sessionDriver -eq 'redis' -and $sessionConnection -notin @('default','cache')) { Fail 'Redis sessions use a custom Redis connection that this audit cannot safely attribute to the identified Redis container.' }
    if ($cacheConnection -eq 'default') { $cacheDb=$redisDb }
    return [pscustomobject]@{ RedisPrefix=$redisPrefix; CachePrefix=$cachePrefix; RedisDb=$redisDb; CacheDb=$cacheDb; CacheStore=$cacheStore; SessionDriver=$sessionDriver; QueueConnection=$queueConnection; QueueRedisConnection=$queueRedisConnection; SessionConnection=$sessionConnection; QueueName=$queueName }
}
function Get-RedisKeyCategory([string]$Key,[string]$Database,$Config,[bool]$Shared) {
    if ($Shared -and -not $Key.StartsWith($Config.RedisPrefix,[StringComparison]::Ordinal)) { return 'other_shared' }
    $logicalKey = $Key
    if ($Config.RedisPrefix -and $logicalKey.StartsWith($Config.RedisPrefix,[StringComparison]::Ordinal)) { $logicalKey = $logicalKey.Substring($Config.RedisPrefix.Length) }
    if ($logicalKey -match '(^|[:_-])(rate_limiter|throttle|limiter)([:_-]|$)') { return 'rate_limit' }
    if ($logicalKey -eq 'phase0:queue-worker-heartbeat') { return 'worker_heartbeat' }
    if ($logicalKey -eq 'nexo:{outbox}:events') { return 'critical_outbox_stream' }
    if ($logicalKey -eq 'nexo:{outbox}:events:published') { return 'critical_outbox_dedupe' }
    if ($logicalKey -match '^queues:[^:]+:delayed$') { return 'queue_delayed' }
    if ($logicalKey -match '^queues:[^:]+:reserved$') { return 'queue_reserved' }
    if ($logicalKey -match '^queues:[^:]+:notify$') { return 'queue_signal' }
    if ($logicalKey -match '^queues:[^:]+$') { return 'queue_pending' }
    if ($Config.CacheStore -eq 'redis' -and $Database -eq $Config.CacheDb -and $Config.CachePrefix -and $logicalKey.StartsWith($Config.CachePrefix,[StringComparison]::Ordinal)) { return 'disposable_cache' }
    if ($Config.SessionDriver -eq 'redis') { return 'session_or_unknown' }
    return 'unknown'
}
function Get-RedisMigrationDisposition($Counts) {
    if ([int]$Counts.unknown -gt 0 -or [int]$Counts.queue_unknown -gt 0 -or [int]$Counts.outbox_unknown_types -gt 0 -or [int]$Counts.heartbeat_bad_ttl -gt 0 -or [int]$Counts.cache_persistent -gt 0 -or [int]$Counts.rate_persistent -gt 0) { return 'stop_unclassified' }
    if ([int]$Counts.pending_jobs -gt 0 -or [int]$Counts.delayed_jobs -gt 0 -or [int]$Counts.reserved_jobs -gt 0 -or [int]$Counts.sessions -gt 0 -or [int]$Counts.outbox_stream_entries -gt 0 -or [int]$Counts.outbox_dedupe_entries -gt 0) { return 'confirm_source_retained' }
    if ([int]$Counts.cache -gt 0 -or [int]$Counts.rate_limits -gt 0) { return 'exclude_disposable' }
    return 'proceed_empty'
}
function Test-RedisClassificationRegression {
    $defaultConfig = Get-RedisRuntimeConfig @('APP_NAME="Urban AR Team Adventure"','QUEUE_CONNECTION=redis')
    if ($defaultConfig.RedisPrefix -ne 'urban-ar-team-adventure-database-' -or $defaultConfig.CachePrefix -ne 'urban-ar-team-adventure-cache-' -or $defaultConfig.RedisDb -ne '0' -or $defaultConfig.CacheDb -ne '1' -or $defaultConfig.QueueRedisConnection -ne 'default' -or $defaultConfig.SessionConnection -ne 'default') { Fail 'Redis config regression: Laravel key prefixes, connection selections, or database defaults changed unexpectedly.' }
    $config = [pscustomobject]@{ RedisPrefix='nexo-database-'; CachePrefix='nexo-cache-'; RedisDb='0'; CacheDb='1'; CacheStore='redis'; SessionDriver='database'; QueueConnection='redis'; QueueName='default' }
    $cases = @(
        [pscustomobject]@{ Key='nexo-database-queues:default'; Db='0'; Expected='queue_pending'; Shared=$false },
        [pscustomobject]@{ Key='nexo-database-queues:emails:delayed'; Db='0'; Expected='queue_delayed'; Shared=$false },
        [pscustomobject]@{ Key='nexo-database-queues:emails:reserved'; Db='0'; Expected='queue_reserved'; Shared=$false },
        [pscustomobject]@{ Key='nexo-database-queues:emails:notify'; Db='0'; Expected='queue_signal'; Shared=$false },
        [pscustomobject]@{ Key='nexo-database-phase0:queue-worker-heartbeat'; Db='0'; Expected='worker_heartbeat'; Shared=$false },
        [pscustomobject]@{ Key='nexo-database-nexo:{outbox}:events'; Db='0'; Expected='critical_outbox_stream'; Shared=$false },
        [pscustomobject]@{ Key='nexo-database-nexo:{outbox}:events:published'; Db='0'; Expected='critical_outbox_dedupe'; Shared=$false },
        [pscustomobject]@{ Key='nexo-database-laravel_cache_rate_limiter:abc'; Db='1'; Expected='rate_limit'; Shared=$false },
        [pscustomobject]@{ Key='nexo-database-nexo-cache-item'; Db='1'; Expected='disposable_cache'; Shared=$false },
        [pscustomobject]@{ Key='nexo-database-app:critical-state'; Db='0'; Expected='unknown'; Shared=$false },
        [pscustomobject]@{ Key='otherapp-queue:default'; Db='0'; Expected='other_shared'; Shared=$true },
        [pscustomobject]@{ Key='nexo-database-queues:default'; Db='0'; Expected='queue_pending'; Shared=$true }
    )
    foreach ($case in $cases) {
        $actual = Get-RedisKeyCategory $case.Key $case.Db $config $case.Shared
        if ($actual -ne $case.Expected) { Fail "Redis classification regression failed for category '$($case.Expected)' (got '$actual')." }
    }
    $sessionConfig = [pscustomobject]@{ RedisPrefix='nexo-database-'; CachePrefix='nexo-cache-'; RedisDb='0'; CacheDb='1'; CacheStore='redis'; SessionDriver='redis'; QueueConnection='redis'; QueueName='default' }
    if ((Get-RedisKeyCategory 'nexo-database/session:abc' '0' $sessionConfig $false) -ne 'session_or_unknown') { Fail 'Redis classification regression: Redis-backed sessions must be preserved as a stop condition.' }
    if ((Get-RedisKeyCategory 'nexo-database-nexo-cache-item' '0' $config $false) -ne 'unknown') { Fail 'Redis classification regression: cache keys outside the configured cache DB must not be excluded.' }
    $dispositionCases = @(
        [pscustomobject]@{ Counts=[pscustomobject]@{unknown=0;queue_unknown=0;outbox_unknown_types=0;heartbeat_bad_ttl=0;outbox_stream_entries=0;outbox_dedupe_entries=0;cache_persistent=0;rate_persistent=0;pending_jobs=0;delayed_jobs=0;reserved_jobs=0;sessions=0;cache=4;rate_limits=2}; Expected='exclude_disposable' },
        [pscustomobject]@{ Counts=[pscustomobject]@{unknown=0;queue_unknown=0;outbox_unknown_types=0;heartbeat_bad_ttl=0;outbox_stream_entries=0;outbox_dedupe_entries=0;cache_persistent=0;rate_persistent=0;pending_jobs=3;delayed_jobs=0;reserved_jobs=1;sessions=0;cache=0;rate_limits=0}; Expected='confirm_source_retained' },
        [pscustomobject]@{ Counts=[pscustomobject]@{unknown=0;queue_unknown=0;outbox_unknown_types=0;heartbeat_bad_ttl=0;outbox_stream_entries=0;outbox_dedupe_entries=0;cache_persistent=0;rate_persistent=0;pending_jobs=0;delayed_jobs=0;reserved_jobs=0;sessions=2;cache=0;rate_limits=0}; Expected='confirm_source_retained' },
        [pscustomobject]@{ Counts=[pscustomobject]@{unknown=0;queue_unknown=0;outbox_unknown_types=0;heartbeat_bad_ttl=0;outbox_stream_entries=2;outbox_dedupe_entries=4;cache_persistent=0;rate_persistent=0;pending_jobs=0;delayed_jobs=0;reserved_jobs=0;sessions=0;cache=0;rate_limits=0}; Expected='confirm_source_retained' },
        [pscustomobject]@{ Counts=[pscustomobject]@{unknown=1;queue_unknown=0;outbox_unknown_types=0;heartbeat_bad_ttl=0;outbox_stream_entries=0;outbox_dedupe_entries=0;cache_persistent=0;rate_persistent=0;pending_jobs=0;delayed_jobs=0;reserved_jobs=0;sessions=0;cache=0;rate_limits=0}; Expected='stop_unclassified' },
        [pscustomobject]@{ Counts=[pscustomobject]@{unknown=0;queue_unknown=1;outbox_unknown_types=0;heartbeat_bad_ttl=0;outbox_stream_entries=0;outbox_dedupe_entries=0;cache_persistent=0;rate_persistent=0;pending_jobs=0;delayed_jobs=0;reserved_jobs=0;sessions=0;cache=0;rate_limits=0}; Expected='stop_unclassified' },
        [pscustomobject]@{ Counts=[pscustomobject]@{unknown=0;queue_unknown=0;outbox_unknown_types=1;heartbeat_bad_ttl=0;outbox_stream_entries=0;outbox_dedupe_entries=0;cache_persistent=0;rate_persistent=0;pending_jobs=0;delayed_jobs=0;reserved_jobs=0;sessions=0;cache=0;rate_limits=0}; Expected='stop_unclassified' },
        [pscustomobject]@{ Counts=[pscustomobject]@{unknown=0;queue_unknown=0;outbox_unknown_types=0;heartbeat_bad_ttl=1;outbox_stream_entries=0;outbox_dedupe_entries=0;cache_persistent=0;rate_persistent=0;pending_jobs=0;delayed_jobs=0;reserved_jobs=0;sessions=0;cache=0;rate_limits=0}; Expected='stop_unclassified' },
        [pscustomobject]@{ Counts=[pscustomobject]@{unknown=0;queue_unknown=0;outbox_unknown_types=0;heartbeat_bad_ttl=0;outbox_stream_entries=0;outbox_dedupe_entries=0;cache_persistent=1;rate_persistent=0;pending_jobs=0;delayed_jobs=0;reserved_jobs=0;sessions=0;cache=1;rate_limits=0}; Expected='stop_unclassified' }
    )
    foreach ($case in $dispositionCases) { if ((Get-RedisMigrationDisposition $case.Counts) -ne $case.Expected) { Fail "Redis disposition regression failed; expected '$($case.Expected)'." } }
}
function Assert-LocalPrerequisites {
    if (-not (Test-Path -LiteralPath $Repository -PathType Container)) { Fail "Repository not found: $Repository" }
    Require-Command 'git.exe' | Out-Null; Require-Command 'ssh.exe' | Out-Null; Require-Command 'scp.exe' | Out-Null
    $sha = (& (Require-Command 'git.exe') -C $Repository rev-parse "$TargetSha^{commit}").Trim()
    if ($LASTEXITCODE -ne 0 -or $sha -ne $TargetSha) { Fail "Pinned application commit $TargetSha is unavailable locally." }
    $targets = Select-String -LiteralPath (Join-Path $Repository 'Dockerfile') -Pattern '^FROM\s+.*\s+AS\s+(production|production-nginx|production-control)\s*$'
    $found = @($targets | ForEach-Object { $_.Matches[0].Groups[1].Value } | Sort-Object -Unique)
    foreach ($t in @('production','production-nginx','production-control')) { if ($found -notcontains $t) { Fail "Dockerfile target missing: $t" } }
    $composePath = Join-Path $Repository 'docker-compose.production.yml'
    if (-not (Test-Path -LiteralPath $composePath)) { Fail 'Production Compose file is missing.' }
    $composeText = Get-Content -LiteralPath $composePath -Raw
    if ($composeText -notmatch '127\.0\.0\.1:\$\{NEXO_HTTP_PORT:-18100\}:80') { Fail 'Compose does not bind NEXO HTTP only to loopback port 18100; refusing deployment.' }
    if ($composeText -match '0\.0\.0\.0:|\n\s*-\s*["'']?(80|443|5432|6379):') { Fail 'Compose appears to publish a public or database port; refusing deployment.' }
    if ($ValidateOnly) { Test-DockerMetadataRegression; Test-RedisClassificationRegression; Test-StreamHashRegression; Test-WorkspaceAclRegression; Test-DestinationResumeRegression; Test-RestoreNamePayloadRegression; Test-PostgresRestoreSafetyRegression; Test-PromotionSafetyRegression; Write-Step "ValidateOnly passed: source $TargetSha, three production targets, loopback binding, metadata/Redis/stream/ACL regressions, mocked destination resume flow, strict Bash restore-name and PostgreSQL table-set regressions, and restart-safe approved promotion regression verified."; return $true }
    $keysToCheck = if ($PromoteApprovedRestoreOnly) { @($NewKey) } else { @($OldKey,$NewKey) }
    foreach ($key in $keysToCheck) { if (-not (Test-Path -LiteralPath $key -PathType Leaf)) { Fail "SSH key is not accessible: $key. Run this script in your normal Windows account; private key contents are never read or printed." } }
    return $false
}
function Get-RemoteDockerInventory([string]$HostName,[int]$Port,[string]$Key,[string]$User) {
    $script = @'
set -euo pipefail
docker info >/dev/null
ids=$(docker ps -aq)
if [ -n "$ids" ]; then docker inspect $ids; else echo '[]'; fi
'@
    $raw = Invoke-Remote $Key $Port $User $HostName $script "Docker inventory on $HostName"
    if (-not $raw) { return @() }
    try { return (ConvertFrom-Json -InputObject $raw) } catch { Fail "Could not parse Docker inventory JSON from $HostName." }
}
function Get-OptionalProperty($Object, [string]$Name) {
    if ($null -eq $Object -or [string]::IsNullOrEmpty($Name)) { return $null }
    if ($Object -is [System.Collections.IDictionary]) {
        if ($Object.Contains($Name)) { return $Object[$Name] }
        return $null
    }
    $property = $Object.PSObject.Properties[$Name]
    if ($null -eq $property) { return $null }
    return $property.Value
}
function Get-OptionalLabel($Labels, [string]$Name) {
    $value = Get-OptionalProperty $Labels $Name
    if ($null -eq $value) { return $null }
    $text = [string]$value
    if ([string]::IsNullOrWhiteSpace($text)) { return $null }
    return $text
}
function Get-ContainerNetworkNames($Container) {
    $settings = Get-OptionalProperty $Container 'NetworkSettings'
    $networks = Get-OptionalProperty $settings 'Networks'
    if ($null -eq $networks) { return @() }
    if ($networks -is [System.Collections.IDictionary]) { return @($networks.Keys | ForEach-Object { [string]$_ }) }
    return @($networks.PSObject.Properties | ForEach-Object { $_.Name })
}
function ConvertTo-Base64Utf8([string]$Value) { return [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes([string]$Value)) }
function Invoke-RedisReadOnlyAudit([string]$ContainerId,[string]$Key,[int]$Port,[string]$User,[string]$HostName,$Config,[bool]$Shared) {
    $encoded = @{
        Prefix=(ConvertTo-Base64Utf8 $Config.RedisPrefix)
        CachePrefix=(ConvertTo-Base64Utf8 $Config.CachePrefix)
        CacheStore=(ConvertTo-Base64Utf8 $Config.CacheStore)
        SessionDriver=(ConvertTo-Base64Utf8 $Config.SessionDriver)
        QueueConnection=(ConvertTo-Base64Utf8 $Config.QueueConnection)
        CacheDb=(ConvertTo-Base64Utf8 $Config.CacheDb)
        QueueName=(ConvertTo-Base64Utf8 $Config.QueueName)
    }
    $audit = @'
set -euo pipefail
decode_cfg() { printf '%s' "$1" | base64 -d; }
redis_prefix=$(decode_cfg '__PREFIX__')
cache_prefix=$(decode_cfg '__CACHE_PREFIX__')
cache_store=$(decode_cfg '__CACHE_STORE__')
session_driver=$(decode_cfg '__SESSION_DRIVER__')
queue_connection=$(decode_cfg '__QUEUE_CONNECTION__')
cache_db=$(decode_cfg '__CACHE_DB__')
queue_name=$(decode_cfg '__QUEUE_NAME__')
shared='__SHARED__'
redis_id='__REDIS_ID__'
keyspace=$(docker exec "$redis_id" redis-cli --raw INFO keyspace | tr -d '\r')
server_info=$(docker exec "$redis_id" redis-cli --raw INFO server | tr -d '\r')
persist_info=$(docker exec "$redis_id" redis-cli --raw INFO persistence | tr -d '\r')
redis_version=$(printf '%s\n' "$server_info" | sed -n 's/^redis_version://p' | head -n 1)
if [ -z "$redis_version" ] || ! printf '%s\n' "$keyspace" | grep -q '^# Keyspace'; then printf 'REDIS_AUDIT_ERROR redis_info_unavailable_or_auth_required\n'; exit 0; fi
aof_enabled=$(printf '%s\n' "$persist_info" | sed -n 's/^aof_enabled://p' | head -n 1)
rdb_active=$(printf '%s\n' "$persist_info" | sed -n 's/^rdb_bgsave_in_progress://p' | head -n 1)
aof_rewrite_active=$(printf '%s\n' "$persist_info" | sed -n 's/^aof_rewrite_in_progress://p' | head -n 1)
config_appendonly=$(docker exec "$redis_id" redis-cli --raw CONFIG GET appendonly 2>/dev/null | tail -n 1 | tr -d '\r') || config_appendonly=unknown
config_appendfsync=$(docker exec "$redis_id" redis-cli --raw CONFIG GET appendfsync 2>/dev/null | tail -n 1 | tr -d '\r') || config_appendfsync=unknown
config_save=$(docker exec "$redis_id" redis-cli --raw CONFIG GET save 2>/dev/null | tail -n +2 | tr ' ' ',' | tr '\n' ';' | tr -d '\r') || config_save=unknown
case "$config_appendonly" in yes|no) ;; *) config_appendonly=unknown ;; esac
case "$config_appendfsync" in always|everysec|no) ;; *) config_appendfsync=unknown ;; esac
db_count=0; key_count=0; cache_count=0; rate_count=0; cache_persistent=0; rate_persistent=0; pending_keys=0; delayed_keys=0; reserved_keys=0; queue_signals=0; db_summary=''
pending_jobs=0; delayed_jobs=0; reserved_jobs=0; session_count=0; unknown_count=0; queue_unknown=0; outbox_stream_keys=0; outbox_entries=0; outbox_dedupe_keys=0; outbox_dedupe_entries=0; outbox_unknown=0; heartbeat_count=0; heartbeat_bad_ttl=0
ttl_expiring=0; ttl_persistent=0; ttl_min_ms=-1; ttl_max_ms=0; vanished=0
dbs=$(printf '%s\n' "$keyspace" | sed -nE 's/^db([0-9]+):keys=([0-9]+).*/\1 \2/p')
if [ -n "$dbs" ]; then
  while read -r db declared_keys; do
    [ -n "$db" ] || continue
    db_count=$((db_count + 1))
    db_key_start=$key_count
    if [ "$shared" = true ]; then
      [ -n "$redis_prefix" ] || { printf 'REDIS_AUDIT_ERROR shared_redis_without_app_prefix\n'; exit 0; }
      scan_args=(--scan --pattern "${redis_prefix}*")
    else
      scan_args=(--scan)
    fi
    while IFS= read -r redis_key; do
      [ -n "$redis_key" ] || continue
      logical_key=$redis_key
      case "$logical_key" in "$redis_prefix"*) logical_key=${logical_key#"$redis_prefix"} ;; esac
      category=unknown
      if [[ "$logical_key" =~ (^|[:_-])(rate_limiter|throttle|limiter)([:_-]|$) ]]; then
        category=rate_limit
      elif [ "$logical_key" = 'phase0:queue-worker-heartbeat' ]; then
        category=worker_heartbeat
      elif [ "$logical_key" = 'nexo:{outbox}:events' ]; then
        category=critical_outbox_stream
      elif [ "$logical_key" = 'nexo:{outbox}:events:published' ]; then
        category=critical_outbox_dedupe
      elif [[ "$logical_key" =~ ^queues:[^:]+:delayed$ ]]; then
        category=queue_delayed
      elif [[ "$logical_key" =~ ^queues:[^:]+:reserved$ ]]; then
        category=queue_reserved
      elif [[ "$logical_key" =~ ^queues:[^:]+:notify$ ]]; then
        category=queue_signal
      elif [[ "$logical_key" =~ ^queues:[^:]+$ ]]; then
        category=queue_pending
      elif [ "$cache_store" = redis ] && [ "$db" = "$cache_db" ] && [ -n "$cache_prefix" ] && [[ "$logical_key" == "$cache_prefix"* ]]; then
        category=cache
      elif [ "$session_driver" = redis ]; then
        category=session
      fi
      key_count=$((key_count + 1))
      [ "$category" != unknown ] || unknown_count=$((unknown_count + 1))
      pttl=$(docker exec "$redis_id" redis-cli --raw -n "$db" PTTL "$redis_key")
      if [[ "$pttl" =~ ^[0-9]+$ ]]; then
        ttl_expiring=$((ttl_expiring + 1))
        [ "$ttl_min_ms" -ge 0 ] && [ "$pttl" -ge "$ttl_min_ms" ] || ttl_min_ms=$pttl
        [ "$pttl" -le "$ttl_max_ms" ] || ttl_max_ms=$pttl
      elif [ "$pttl" = -1 ]; then
        ttl_persistent=$((ttl_persistent + 1))
        if [ "$category" = cache ]; then cache_persistent=$((cache_persistent + 1)); fi
        if [ "$category" = rate_limit ]; then rate_persistent=$((rate_persistent + 1)); fi
        if [ "$category" = worker_heartbeat ]; then heartbeat_bad_ttl=$((heartbeat_bad_ttl + 1)); fi
      elif [ "$pttl" = -2 ]; then
        vanished=$((vanished + 1))
      else
        printf 'REDIS_AUDIT_ERROR pttl_query_failed\n'; exit 0
      fi
      case "$category" in
        cache) cache_count=$((cache_count + 1)) ;;
        rate_limit) rate_count=$((rate_count + 1)) ;;
        session) session_count=$((session_count + 1)) ;;
        unknown) ;;
        queue_signal) queue_signals=$((queue_signals + 1)) ;;
        worker_heartbeat)
          heartbeat_count=$((heartbeat_count + 1))
          if [[ "$pttl" =~ ^[0-9]+$ ]] && [ "$pttl" -gt 120000 ]; then heartbeat_bad_ttl=$((heartbeat_bad_ttl + 1)); fi
          ;;
        critical_outbox_stream|critical_outbox_dedupe)
          key_type=$(docker exec "$redis_id" redis-cli --raw -n "$db" TYPE "$redis_key")
          if [ "$category" = critical_outbox_stream ] && [ "$key_type" = stream ]; then
            outbox_stream_keys=$((outbox_stream_keys + 1)); count=$(docker exec "$redis_id" redis-cli --raw -n "$db" XLEN "$redis_key"); outbox_entries=$((outbox_entries + count))
          elif [ "$category" = critical_outbox_dedupe ] && [ "$key_type" = hash ]; then
            outbox_dedupe_keys=$((outbox_dedupe_keys + 1)); count=$(docker exec "$redis_id" redis-cli --raw -n "$db" HLEN "$redis_key"); outbox_dedupe_entries=$((outbox_dedupe_entries + count))
          else
            outbox_unknown=$((outbox_unknown + 1))
          fi
          ;;
        queue_pending|queue_delayed|queue_reserved)
          key_type=$(docker exec "$redis_id" redis-cli --raw -n "$db" TYPE "$redis_key")
          if [ "$category" = queue_pending ] && [ "$key_type" = list ]; then
            job_count=$(docker exec "$redis_id" redis-cli --raw -n "$db" LLEN "$redis_key"); pending_keys=$((pending_keys + 1)); pending_jobs=$((pending_jobs + job_count))
          elif [ "$category" != queue_pending ] && [ "$key_type" = zset ]; then
            job_count=$(docker exec "$redis_id" redis-cli --raw -n "$db" ZCARD "$redis_key")
            if [ "$category" = queue_delayed ]; then delayed_keys=$((delayed_keys + 1)); delayed_jobs=$((delayed_jobs + job_count)); else reserved_keys=$((reserved_keys + 1)); reserved_jobs=$((reserved_jobs + job_count)); fi
          else
            queue_unknown=$((queue_unknown + 1))
          fi
          ;;
      esac
    done < <(docker exec "$redis_id" redis-cli --raw -n "$db" "${scan_args[@]}")
    db_nexo_keys=$((key_count - db_key_start))
    db_summary="${db_summary}db${db}=${db_nexo_keys},"
  done <<< "$dbs"
fi
if [ "$shared" = true ] && [ -z "$redis_prefix" ]; then printf 'REDIS_AUDIT_ERROR shared_redis_without_app_prefix\n'; exit 0; fi
printf 'REDIS_AUDIT shared=%s redis_version=%s db_count=%s key_count=%s cache=%s rate_limits=%s pending_keys=%s pending_jobs=%s delayed_keys=%s delayed_jobs=%s reserved_keys=%s reserved_jobs=%s queue_signals=%s sessions=%s unknown=%s queue_unknown=%s ttl_expiring=%s ttl_persistent=%s ttl_min_ms=%s ttl_max_ms=%s scan_vanished=%s\n' \
  "$shared" "${redis_version:-unknown}" "$db_count" "$key_count" "$cache_count" "$rate_count" "$pending_keys" "$pending_jobs" "$delayed_keys" "$delayed_jobs" "$reserved_keys" "$reserved_jobs" "$queue_signals" "$session_count" "$unknown_count" "$queue_unknown" "$ttl_expiring" "$ttl_persistent" "$ttl_min_ms" "$ttl_max_ms" "$vanished"
printf 'REDIS_PERSISTENCE aof_enabled=%s appendonly=%s appendfsync=%s rdb_save=%s rdb_active=%s aof_rewrite_active=%s\n' "${aof_enabled:-unknown}" "${config_appendonly:-unknown}" "${config_appendfsync:-unknown}" "${config_save:-none}" "${rdb_active:-unknown}" "${aof_rewrite_active:-unknown}"
printf 'REDIS_DB_KEYS %s\n' "${db_summary%,}"
printf 'REDIS_TTL_SAFETY cache_persistent=%s rate_persistent=%s\n' "$cache_persistent" "$rate_persistent"
printf 'REDIS_OUTBOX stream_keys=%s stream_entries=%s dedupe_keys=%s dedupe_entries=%s unknown_types=%s\n' "$outbox_stream_keys" "$outbox_entries" "$outbox_dedupe_keys" "$outbox_dedupe_entries" "$outbox_unknown"
printf 'REDIS_HEARTBEAT keys=%s bad_ttl=%s\n' "$heartbeat_count" "$heartbeat_bad_ttl"
'@
    $audit = $audit.Replace('__PREFIX__',$encoded.Prefix).Replace('__CACHE_PREFIX__',$encoded.CachePrefix).Replace('__CACHE_STORE__',$encoded.CacheStore).Replace('__SESSION_DRIVER__',$encoded.SessionDriver).Replace('__QUEUE_CONNECTION__',$encoded.QueueConnection).Replace('__CACHE_DB__',$encoded.CacheDb).Replace('__QUEUE_NAME__',$encoded.QueueName).Replace('__SHARED__',([string]$Shared).ToLowerInvariant()).Replace('__REDIS_ID__',$ContainerId)
    return Invoke-Remote $Key $Port $User $HostName $audit 'Read-only Redis key classification'
}
function Get-ComposeInfo($Container) {
    $config = Get-OptionalProperty $Container 'Config'
    $labels = Get-OptionalProperty $config 'Labels'
    $name = [string](Get-OptionalProperty $Container 'Name')
    $name = $name.TrimStart('/')
    $project = Get-OptionalLabel $labels 'com.docker.compose.project'
    $service = Get-OptionalLabel $labels 'com.docker.compose.service'
    $workDir = Get-OptionalLabel $labels 'com.docker.compose.project.working_dir'
    $configFiles = Get-OptionalLabel $labels 'com.docker.compose.project.config_files'
    # The checked-in production Compose uses explicit, unique nexo-* names. They are the
    # only label-free fallback; arbitrary unlabeled containers are never inferred as NEXO.
    if (-not $service) {
        $knownNames = @{ 'nexo-app'='app'; 'nexo-postgres'='postgres'; 'nexo-redis'='redis'; 'nexo-nginx'='nginx'; 'nexo-control'='control'; 'nexo-queue'='queue'; 'nexo-scheduler'='scheduler' }
        if ($knownNames.ContainsKey($name)) { $service = $knownNames[$name] }
    }
    if (-not $project -and $service -and $name -match '^nexo-(app|postgres|redis|nginx|control|queue|scheduler)$') { $project = 'nexo' }
    if (-not $project -and -not $service -and -not $workDir -and -not $configFiles) { return $null }
    return [pscustomobject]@{ Project=$project; Service=$service; WorkDir=$workDir; ConfigFiles=$configFiles }
}
function Test-IsNexoContainer($Container, $ComposeInfo) {
    if ($null -eq $Container -or $null -eq $ComposeInfo) { return $false }
    if ($ComposeInfo.Project) { return ($ComposeInfo.Project -match '(?i)^nexo(?:[-_][a-z0-9_.-]+)?$') }
    $name = [string](Get-OptionalProperty $Container 'Name')
    return [bool]($name.TrimStart('/') -match '^nexo-(app|postgres|redis|nginx|control|queue|scheduler)$' -and $ComposeInfo.Service)
}
function Test-DockerMetadataRegression {
    $cases = @(
        [pscustomobject]@{ Name='/unrelated-db'; Config=[pscustomobject]@{ Image='postgres:16' } },
        [pscustomobject]@{ Name='/unrelated-db'; Config=[pscustomobject]@{ Image='postgres:16'; Labels=[pscustomobject]@{} } },
        [pscustomobject]@{ Name='/unrelated-db'; Config=[pscustomobject]@{ Image='postgres:16'; Labels=$null } },
        [pscustomobject]@{ Name='/nexo-postgres'; Config=[pscustomobject]@{ Image='postgis/postgis:16-3.4' } },
        [pscustomobject]@{ Name='/nexo-postgres'; Config=[pscustomobject]@{ Image='postgres:16'; Labels=[pscustomobject]@{'com.docker.compose.project'='unrelated'; 'com.docker.compose.service'='postgres'} } }
    )
    foreach ($index in 0..2) {
        $info = Get-ComposeInfo $cases[$index]
        if (Test-IsNexoContainer $cases[$index] $info) { Fail "Docker label regression case ${index}: unlabeled unrelated container was classified as NEXO." }
    }
    $knownInfo = Get-ComposeInfo $cases[3]
    if (-not (Test-IsNexoContainer $cases[3] $knownInfo) -or $knownInfo.Service -ne 'postgres') { Fail 'Docker label regression: explicit known NEXO container was not identified when labels were absent.' }
    $conflictingInfo = Get-ComposeInfo $cases[4]
    if (Test-IsNexoContainer $cases[4] $conflictingInfo) { Fail 'Docker label regression: an explicit non-NEXO project label must override a familiar container name.' }
    if ((Get-OptionalLabel $null 'com.docker.compose.project') -ne $null) { Fail 'Docker label regression: null Labels should return null.' }
    if ((Get-OptionalLabel ([pscustomobject]@{}) 'com.docker.compose.project') -ne $null) { Fail 'Docker label regression: missing label should return null.' }
    if ((Get-OptionalLabel ([pscustomobject]@{'com.docker.compose.project'=''}) 'com.docker.compose.project') -ne $null) { Fail 'Docker label regression: empty label should return null.' }
    if ((Get-OptionalLabel ([pscustomobject]@{'com.docker.compose.project'=$null}) 'com.docker.compose.project') -ne $null) { Fail 'Docker label regression: null label value should return null.' }
    $volumeCases = @(
        [pscustomobject]@{ Name='unrelated-volume' },
        [pscustomobject]@{ Name='unrelated-volume'; Labels=$null },
        [pscustomobject]@{ Name='unrelated-volume'; Labels=[pscustomobject]@{} },
        [pscustomobject]@{ Name='unrelated-volume'; Labels=[pscustomobject]@{'com.docker.compose.project'=''} }
    )
    foreach ($volume in $volumeCases) {
        $volumeLabels = Get-OptionalProperty $volume 'Labels'
        if ((Get-OptionalLabel $volumeLabels 'com.docker.compose.project') -ne $null) { Fail 'Docker volume metadata regression: missing/null/empty optional labels must be ignored.' }
    }
}
function New-DestinationBuildScript([string]$ReleasePath,[string]$PinnedSha,[string]$SourceArchiveHash) {
    if ($PinnedSha -notmatch '^[a-f0-9]{40}$' -or $SourceArchiveHash -notmatch '^[a-f0-9]{64}$' -or $ReleasePath -notmatch '^/[A-Za-z0-9/._-]+$') { Fail 'Destination build payload inputs failed validation.' }
    $buildScript = @'
set -euo pipefail
cd '__RELEASE__'
expected_marker='__TARGET_SHA__ __SOURCE_HASH__'
if [ -f .images-built ] && [ "$(cat .images-built)" = "$expected_marker" ]; then
  build_reused=true
  for image in nexo-app:production nexo-nginx:production nexo-control:production; do
    if ! docker image inspect "$image" >/dev/null 2>&1 || [ "$(docker image inspect --format '{{.Os}}/{{.Architecture}}' "$image")" != 'linux/amd64' ]; then build_reused=false; break; fi
  done
  if [ "$build_reused" = true ]; then printf 'BUILD_REUSED\n'; exit 0; fi
fi
free_kb=$(awk '/MemAvailable:/ {print $2}' /proc/meminfo)
disk_kb=$(df -Pk /opt | awk 'NR==2 {print $4}')
test "$free_kb" -ge 2097152 || { echo 'BUILD_RAM_GATE'; exit 41; }
test "$disk_kb" -ge 20971520 || { echo 'BUILD_DISK_GATE'; exit 42; }
docker build --platform linux/amd64 --target production -t nexo-app:production .
docker build --platform linux/amd64 --target production-nginx -t nexo-nginx:production .
docker build --platform linux/amd64 --target production-control -t nexo-control:production .
for image in nexo-app:production nexo-nginx:production nexo-control:production; do test "$(docker image inspect --format '{{.Os}}/{{.Architecture}}' "$image")" = 'linux/amd64'; docker image inspect --format '{{.Id}}' "$image"; done
printf '%s\n' "$expected_marker" > .images-built.tmp
mv .images-built.tmp .images-built
'@
    return $buildScript.Replace('__RELEASE__',$ReleasePath).Replace('__TARGET_SHA__',$PinnedSha).Replace('__SOURCE_HASH__',$SourceArchiveHash)
}
function Invoke-DestinationPreflight([scriptblock]$RemoteInvoker,[string]$Key,[int]$Port,[string]$User,[string]$HostName,[string]$ReleasePath,[string]$RunId,[string]$PreflightScript) {
    if ($ReleasePath -notmatch '^/[A-Za-z0-9/._-]+$' -or $RunId -notmatch '^[a-f0-9]{32}$') { Fail 'Destination preflight inputs failed validation.' }
    $checkedScript = $PreflightScript.Replace('__RELEASE__',$ReleasePath).Replace('__RUN_ID__',$RunId)
    $preflightOutput = & $RemoteInvoker $Key $Port $User $HostName $checkedScript 'Destination VPS preflight'
    if ($preflightOutput -notmatch 'PREFLIGHT_OK') { Fail "Destination preflight did not pass: $preflightOutput" }
    return $preflightOutput
}
function Invoke-DestinationBuild([scriptblock]$RemoteInvoker,[string]$Key,[int]$Port,[string]$User,[string]$HostName,[string]$ReleasePath,[string]$PinnedSha,[string]$SourceArchiveHash) {
    # A function-local payload is initialized before the remote call; no caller/dynamic-scope variable lookup.
    $buildScript = New-DestinationBuildScript $ReleasePath $PinnedSha $SourceArchiveHash
    return (& $RemoteInvoker $Key $Port $User $HostName $buildScript 'Serial destination image build')
}
function New-RestoreNameAssignments([string]$RunId) {
    if ($RunId -notmatch '^[a-f0-9]{32}$') { Fail 'Restore-name payload requires a validated Run ID.' }
    $runShort = $RunId.Substring(0,8)
    $assignments = @'
run_short='__RUN_SHORT__'
run_suffix=$(date -u +%Y%m%dT%H%M%SZ)_$$
restore_db="nexo_restore_${run_short}_${run_suffix}"
partial_db="nexo_partial_${run_short}_${run_suffix}"
list_container_path="/tmp/nexo-restore-list-${run_short}-${run_suffix}"
restore_db_prefix="nexo_restore_${run_short}_"
'@
    return $assignments.Replace('__RUN_SHORT__',$runShort)
}
function Test-RestoreNamePayloadRegression {
    $bash = Join-Path $env:ProgramFiles 'Git\bin\bash.exe'
    if (-not (Test-Path -LiteralPath $bash -PathType Leaf)) { Fail 'Strict Bash payload regression requires Git for Windows bash.exe.' }
    $assignments = New-RestoreNameAssignments 'd8e35d4dd1a14b548b121b0ef69911bf'
    $assertions = @'
case "$restore_db" in nexo_restore_d8e35d4d_*) ;; *) echo "BAD_RESTORE_DB=$restore_db"; exit 41 ;; esac
case "$partial_db" in nexo_partial_d8e35d4d_*) ;; *) echo "BAD_PARTIAL_DB=$partial_db"; exit 42 ;; esac
case "$list_container_path" in /tmp/nexo-restore-list-d8e35d4d-*) ;; *) echo "BAD_LIST_PATH=$list_container_path"; exit 43 ;; esac
test "$restore_db_prefix" = 'nexo_restore_d8e35d4d_' || { echo "BAD_RESTORE_DB_PREFIX=$restore_db_prefix"; exit 44; }
printf '%s\n' "$restore_db" "$partial_db" "$list_container_path"
'@
    $payload = "set -euo pipefail`n$assignments`n$assertions"
    $payloadPath = [IO.Path]::GetTempFileName()
    try {
        [IO.File]::WriteAllText($payloadPath,$payload,(New-Object Text.UTF8Encoding($false)))
        $output = Invoke-Native $bash @('-euo','pipefail',$payloadPath) 'Strict Bash restore-name payload regression'
        if (@($output).Count -lt 3 -or [string]$output[0] -notlike 'nexo_restore_d8e35d4d_*') { Fail "Strict Bash payload regression did not produce the expected Run ID-prefixed restore database name. Output=[$($output -join '|')]" }
    } finally { Remove-Item -LiteralPath $payloadPath -Force -ErrorAction SilentlyContinue }
    $source = Get-Content -LiteralPath $PSCommandPath -Raw
    $badReference = [string][char]96 + '$run_short' + '_'
    if ($source.Contains($badReference)) { Fail 'Bash variable-boundary regression: found an unbraced run_short reference followed by an underscore.' }
    if ($source -notmatch 'restore_db="nexo_restore_\$\{run_short\}_\$\{run_suffix\}"' -or $source -notmatch 'restore_db_prefix="nexo_restore_\$\{run_short\}_"') { Fail 'Bash variable-boundary regression: generated names must brace Bash variables before literal underscores.' }
}
function Test-DestinationResumeRegression {
    $fixtureRoot = Join-Path ([IO.Path]::GetTempPath()) ('nexo-resume-regression-' + [Guid]::NewGuid().ToString('N'))
    New-Item -ItemType Directory -Path $fixtureRoot -Force | Out-Null
    try {
        $dump = Join-Path $fixtureRoot 'nexo.dump'; $storage = Join-Path $fixtureRoot 'storage.tgz'; $envFile = Join-Path $fixtureRoot 'source.env'
        [IO.File]::WriteAllBytes($dump,[byte[]](80,71,68,77,80,1,2)); [IO.File]::WriteAllBytes($storage,[byte[]](31,139,8,0)); [IO.File]::WriteAllText($envFile,'APP_KEY=fixture-only')
        $checkpoint = @{BackupReady=$true;DumpSha256=(Get-FileHash $dump -Algorithm SHA256).Hash.ToLowerInvariant();StorageSha256=(Get-FileHash $storage -Algorithm SHA256).Hash.ToLowerInvariant();EnvSha256=(Get-FileHash $envFile -Algorithm SHA256).Hash.ToLowerInvariant()}
        foreach ($item in @(@($dump,$checkpoint.DumpSha256),@($storage,$checkpoint.StorageSha256),@($envFile,$checkpoint.EnvSha256))) { if ((Get-FileHash -LiteralPath $item[0] -Algorithm SHA256).Hash.ToLowerInvariant() -ne $item[1]) { Fail 'Resume regression rejected a valid backup checkpoint.' } }
        if (-not $checkpoint.BackupReady) { Fail 'Resume regression requires verified backup state.' }
        $calls = New-Object Collections.Generic.List[string]
        $mockRemote = {
            param($k,$p,$u,$h,$payload,$label)
            $calls.Add($label)
            if ($label -eq 'Destination VPS preflight') { if ($payload -notmatch 'PREFLIGHT_OK') { Fail 'Mock destination preflight payload is incomplete.' }; return 'PREFLIGHT_OK' }
            if ($label -eq 'Serial destination image build') { if ($payload -notmatch 'production-control' -or $payload -notmatch [regex]::Escape($checkpoint.DumpSha256)) { Fail 'Mock build omitted its pinned source/checkpoint identity.' }; return 'BUILD_REUSED' }
            Fail "Unexpected mock SSH step: $label"
        }.GetNewClosure()
        $preflight = @'
set -euo pipefail
echo PREFLIGHT_OK
'@
        Invoke-DestinationPreflight $mockRemote 'mock-key' 2238 'root' 'mock.invalid' '/opt/nexo/releases/2111243fff34f724d98c7ae6a825785967fbe882' 'd8e35d4dd1a14b548b121b0ef69911bf' $preflight | Out-Null
        $result = Invoke-DestinationBuild $mockRemote 'mock-key' 2238 'root' 'mock.invalid' '/opt/nexo/releases/2111243fff34f724d98c7ae6a825785967fbe882' '2111243fff34f724d98c7ae6a825785967fbe882' $checkpoint.DumpSha256
        if ($result -ne 'BUILD_REUSED' -or $calls.Count -ne 2 -or $calls[0] -ne 'Destination VPS preflight' -or $calls[1] -ne 'Serial destination image build') { Fail 'Mocked destination resume path did not complete in order.' }
    } finally { Remove-Item -LiteralPath $fixtureRoot -Recurse -Force -ErrorAction SilentlyContinue }
}
function Test-PostgresRestoreSafetyRegression {
    $source = Get-Content -LiteralPath $PSCommandPath -Raw
    foreach ($required in @('mem_limit: 1g','memswap_limit: 2g','CREATE EXTENSION postgis','--exit-on-error','PRESERVED_PARTIAL_DATABASE=%s','.database-restored.tmp','RESTORED_APPLICATION_TABLE_SET_MISMATCH','DUMP_POSTGRES_MAJOR_MISMATCH','pg_available_extensions','RESTORE_DATABASES_ALREADY_EXIST','POSTGIS_TOC_CANDIDATES_BEGIN','POSTGIS_TOC_FILTER_FAILED','postgis_toc_pattern=','pg_depend','pg_extension','NO_DATABASE_RENAME_PERFORMED','ApproveDatabasePromotion')) {
        if ($source -notmatch [regex]::Escape($required)) { Fail "PostgreSQL restore regression: missing required safe recovery behavior '$required'." }
    }
    # pg_restore --list may append an owner after the extension name. The old end-anchored
    # match rejected valid TOC lines and produced a false zero count.
    $tocRegex = '^[0-9]+;\s+.*\sEXTENSION - postgis(?:\s|$)'
    $legacyTocRegex = '^[0-9]+; .* EXTENSION - postgis$'
    $ownerSuffixLine = '3079;  2612  16384 EXTENSION - postgis nexo'
    $bareLine = '3079;  2612  16384 EXTENSION - postgis'
    if ($ownerSuffixLine -match $legacyTocRegex) { Fail 'PostgreSQL restore regression: fixture no longer reproduces the former end-anchored match failure.' }
    if ($ownerSuffixLine -notmatch $tocRegex -or $bareLine -notmatch $tocRegex) { Fail 'PostgreSQL restore regression: valid PostGIS TOC lines with or without owner suffix must be recognized.' }
    $zeroEntryFixture = @('; Archive created by pg_dump','; Dumped from database version: 16.4','1;  1259  16384 TABLE public users nexo')
    $zeroCount = @($zeroEntryFixture | Where-Object { $_ -match $tocRegex }).Count
    if ($zeroCount -ne 0 -or $source -notmatch 'EXPECTED_ONE_POSTGIS_EXTENSION_ENTRY_GOT=') { Fail 'PostgreSQL restore regression: the exact zero-entry TOC case must remain a hard, diagnosable failure.' }
    if ($source -match '(?im)^\s*(?:docker\s+volume\s+rm|docker\s+compose\s+[^\r\n]*down\s+-v|DROP\s+DATABASE)\b') { Fail 'PostgreSQL restore regression: destructive volume/database reset command is prohibited.' }
    if ($source -match '(?im)pg_restore[^\r\n]*(?:--jobs(?:=|\s+)|\s-j\s*\d)') { Fail 'PostgreSQL restore regression: parallel pg_restore is prohibited on the constrained VPS.' }
    $declaredMiB = 1024 + 192 + 256 + 64 + 32
    if ($declaredMiB -gt 2048) { Fail 'PostgreSQL restore regression: active container memory limits exceed the 2 GiB NEXO budget.' }
    if ($source -notmatch "\`$applicationServices\s*=\s*'app control nginx'") { Fail 'PostgreSQL restore regression: queue/scheduler must remain disabled by default.' }

    # Compare table names after removing objects owned by installed extensions. This
    # catches same-count substitutions while treating PostGIS-owned relations equally.
    $compareTables = {
        param([string[]]$Expected,[string[]]$Actual,[string[]]$ExtensionOwned)
        $owned = @{}; foreach ($name in @($ExtensionOwned)) { if ($name) { $owned[$name] = $true } }
        $expectedSet = @(@($Expected | Where-Object { $_ -and -not $owned.ContainsKey($_) } | Sort-Object -Unique))
        $actualSet = @(@($Actual | Where-Object { $_ -and -not $owned.ContainsKey($_) } | Sort-Object -Unique))
        $missing = @($expectedSet | Where-Object { $actualSet -notcontains $_ })
        $unexpected = @($actualSet | Where-Object { $expectedSet -notcontains $_ })
        [pscustomobject]@{ Expected=$expectedSet; Actual=$actualSet; Missing=$missing; Unexpected=$unexpected; IsEqual=($missing.Count -eq 0 -and $unexpected.Count -eq 0) }
    }.GetNewClosure()
    $postgisFixture = & $compareTables @('users','migrations') @('users','migrations','spatial_ref_sys') @('spatial_ref_sys')
    if (-not $postgisFixture.IsEqual -or $postgisFixture.Expected.Count -ne 2 -or $postgisFixture.Actual.Count -ne 2) { Fail 'PostgreSQL restore regression: extension-owned spatial_ref_sys must be excluded from both table sets.' }
    $mismatchFixture = & $compareTables @('users','migrations') @('users','roles') @('')
    if ($mismatchFixture.IsEqual -or $mismatchFixture.Missing -notcontains 'migrations' -or $mismatchFixture.Unexpected -notcontains 'roles') { Fail 'PostgreSQL restore regression: real application table mismatches must remain fatal.' }

    $bash = Join-Path $env:ProgramFiles 'Git\bin\bash.exe'
    if (-not (Test-Path -LiteralPath $bash -PathType Leaf)) { Fail 'PostgreSQL table-set regression requires Git for Windows bash.exe.' }
    $fixtureRoot = Join-Path ([IO.Path]::GetTempPath()) ('nexo-table-set-' + [Guid]::NewGuid().ToString('N'))
    New-Item -ItemType Directory -Path $fixtureRoot | Out-Null
    $bashPath = Join-Path $fixtureRoot 'table-set-regression.sh'
    $bashPayload = @'
set -euo pipefail
root="$1"
printf 'migrations\nusers\n' > "$root/expected"
printf 'migrations\nspatial_ref_sys\nusers\n' > "$root/actual"
printf 'postgis\tspatial_ref_sys\n' > "$root/owned"
cut -f2 "$root/owned" | sort -u > "$root/owned.names"
grep -Fvx -f "$root/owned.names" "$root/expected" > "$root/expected.app" || test "$?" -eq 1
grep -Fvx -f "$root/owned.names" "$root/actual" > "$root/actual.app" || test "$?" -eq 1
cmp -s "$root/expected.app" "$root/actual.app"
printf 'roles\nusers\n' > "$root/actual"
grep -Fvx -f "$root/owned.names" "$root/actual" > "$root/actual.app" || test "$?" -eq 1
if cmp -s "$root/expected.app" "$root/actual.app"; then echo 'REAL_TABLE_MISMATCH_WAS_ACCEPTED'; exit 1; fi
comm -23 "$root/expected.app" "$root/actual.app" | grep -qx migrations
comm -13 "$root/expected.app" "$root/actual.app" | grep -qx roles
'@
    try {
        [IO.File]::WriteAllText($bashPath,$bashPayload,(New-Object Text.UTF8Encoding($false)))
        Invoke-Native $bash @('-euo','pipefail',$bashPath,$fixtureRoot) 'PostgreSQL extension-owned/application table-set Bash regression' | Out-Null
    } finally { Remove-Item -LiteralPath $fixtureRoot -Recurse -Force -ErrorAction SilentlyContinue }
}

function Get-PromotionRecoveryAction([bool]$RestoreExists,[bool]$NexoExists,[string]$NexoComment,[string]$ExpectedComment,[string[]]$PartialDatabases) {
    $partials = @($PartialDatabases | Where-Object { $_ })
    if ($partials.Count -gt 1) { return 'stop-ambiguous-partials' }
    if ($RestoreExists) {
        if ($NexoExists -and $NexoComment -eq $ExpectedComment) { return 'stop-duplicate-promoted-and-restore' }
        if ($NexoExists -and $partials.Count -eq 0) { return 'preserve-nexo-then-promote' }
        if (-not $NexoExists -and $partials.Count -eq 1) { return 'promote-after-first-rename' }
        return 'stop-unexpected-database-state'
    }
    if ($NexoExists -and $NexoComment -eq $ExpectedComment) { return 'already-promoted' }
    return 'stop-restore-missing-or-unmarked'
}

function New-ApprovedPromotionPayload([string]$RunId,[string]$PinnedSha,[string]$RestoreDatabase,[string]$DumpHash,[string]$StorageHash) {
    if ($RunId -ne 'd8e35d4dd1a14b548b121b0ef69911bf' -or $PinnedSha -ne '2111243fff34f724d98c7ae6a825785967fbe882' -or $RestoreDatabase -ne 'nexo_restore_d8e35d4d_20261017T213125Z_38265') { Fail 'Approved promotion is restricted to the explicitly authorized Run ID, source SHA, and restore database.' }
    foreach ($hash in @($DumpHash,$StorageHash)) { if ($hash -notmatch '^[a-f0-9]{64}$') { Fail 'Approved promotion requires valid retained SHA256 checkpoints.' } }
    $payload = @'
set -euo pipefail
run_id='__RUN_ID__'
run_short='__RUN_SHORT__'
target_sha='__TARGET_SHA__'
restore_db='__RESTORE_DB__'
dump_hash='__DUMP_HASH__'
storage_hash='__STORAGE_HASH__'
release='/opt/nexo/releases/__TARGET_SHA__'
backup_dump="/opt/nexo/backups/nexo-${run_id}.dump"
backup_storage="/opt/nexo/backups/nexo-${run_id}-storage.tgz"
restore_comment="nexo-migration:${run_id}:${target_sha}:${dump_hash}"
partial_prefix="nexo_partial_${run_short}_"

source /etc/os-release
test "${VERSION_CODENAME:-}" = jammy || { echo 'DESTINATION_OS_IDENTITY_MISMATCH'; exit 101; }
test "$(uname -m)" = x86_64 || { echo 'DESTINATION_ARCHITECTURE_MISMATCH'; exit 102; }
test "$(id -un)" = root || { echo 'DESTINATION_USER_MISMATCH'; exit 103; }
read -r ssh_peer_ip ssh_peer_port ssh_server_ip ssh_server_port <<< "${SSH_CONNECTION:-}"
test "${ssh_server_port:-}" = 2238 || { echo "DESTINATION_SSH_PORT_MISMATCH=${ssh_server_port:-unknown}"; exit 103; }
printf 'DESTINATION_HOSTNAME=%s SSH_LOCAL_ENDPOINT=%s:%s\n' "$(hostname -f 2>/dev/null || hostname)" "$ssh_server_ip" "$ssh_server_port"
test -f "$release/.migration_run_id" && test "$(cat "$release/.migration_run_id")" = "$run_id" || { echo 'DESTINATION_RELEASE_RUN_ID_MISMATCH'; exit 104; }
test -f "$release/.env" || { echo 'DESTINATION_ENVIRONMENT_MISSING'; exit 105; }
test "$(stat -c '%a' "$release/.env")" = 600 || { echo 'DESTINATION_ENVIRONMENT_PERMISSIONS_UNSAFE'; exit 105; }
test -f "$release/.images-built" || { echo 'DESTINATION_IMAGE_CHECKPOINT_MISSING'; exit 106; }
read -r image_sha image_source_hash < "$release/.images-built"
test "$image_sha" = "$target_sha" && [[ "$image_source_hash" =~ ^[a-f0-9]{64}$ ]] || { echo 'DESTINATION_IMAGE_CHECKPOINT_MISMATCH'; exit 106; }
for image in nexo-app:production nexo-nginx:production nexo-control:production; do
  docker image inspect "$image" >/dev/null 2>&1 || { echo "DESTINATION_IMAGE_MISSING=$image"; exit 107; }
  test "$(docker image inspect --format '{{.Os}}/{{.Architecture}}' "$image")" = linux/amd64 || { echo "DESTINATION_IMAGE_PLATFORM_MISMATCH=$image"; exit 107; }
done
docker compose -p nexo -f "$release/docker-compose.production.yml" -f "$release/docker-compose.migration.yml" config --quiet
docker compose -p nexo -f "$release/docker-compose.production.yml" -f "$release/docker-compose.migration.yml" config --format json | python3 -c 'import json,sys; cfg=json.load(sys.stdin); ports=[(svc,str(p.get("host_ip","")),str(p.get("published","")),str(p.get("target",""))) for svc,item in cfg.get("services",{}).items() for p in (item.get("ports") or [])]; expected=[("nginx","127.0.0.1","18100","80")]; print("COMPOSE_PORT_BINDINGS="+repr(ports)); sys.exit(0 if ports == expected else 1)' || { echo 'COMPOSE_PUBLIC_OR_UNEXPECTED_PORT_BINDING'; exit 107; }

assert_service() {
  name="$1"; service="$2"; expected_running="$3"
  docker inspect "$name" >/dev/null 2>&1 || { [ "$expected_running" = optional ] && return 0; echo "EXPECTED_CONTAINER_MISSING=$name"; return 1; }
  project=$(docker inspect --format '{{index .Config.Labels "com.docker.compose.project"}}' "$name")
  actual_service=$(docker inspect --format '{{index .Config.Labels "com.docker.compose.service"}}' "$name")
  test "$project" = nexo && test "$actual_service" = "$service" || { echo "CONTAINER_OWNERSHIP_MISMATCH=$name"; return 1; }
}
assert_service nexo-postgres postgres required
assert_service nexo-redis redis required
test "$(docker inspect --format '{{.State.Running}}' nexo-postgres)" = true || { echo 'POSTGRES_NOT_RUNNING'; exit 108; }
test "$(docker inspect --format '{{.State.Health.Status}}' nexo-postgres)" = healthy || { echo 'POSTGRES_NOT_HEALTHY'; exit 108; }
test "$(docker inspect --format '{{.Config.Image}}' nexo-postgres)" = postgis/postgis:16-3.4 || { echo 'POSTGRES_IMAGE_IDENTITY_MISMATCH'; exit 109; }
pg_volume=$(docker inspect --format '{{range .Mounts}}{{if eq .Destination "/var/lib/postgresql/data"}}{{.Name}}{{end}}{{end}}' nexo-postgres)
test "$pg_volume" = nexo_postgres_data || { echo 'POSTGRES_VOLUME_IDENTITY_MISMATCH'; exit 110; }
assert_service nexo-redis redis required
test "$(docker inspect --format '{{.State.Running}}' nexo-redis)" = true && test "$(docker inspect --format '{{.State.Health.Status}}' nexo-redis)" = healthy || { echo 'REDIS_NOT_HEALTHY'; exit 111; }
redis_volume=$(docker inspect --format '{{range .Mounts}}{{if eq .Destination "/data"}}{{.Name}}{{end}}{{end}}' nexo-redis)
test "$redis_volume" = nexo_redis_data || { echo 'REDIS_VOLUME_IDENTITY_MISMATCH'; exit 111; }
docker volume inspect nexo_app_storage >/dev/null 2>&1 || { echo 'APP_STORAGE_VOLUME_MISSING'; exit 112; }
storage_project=$(docker volume inspect --format '{{index .Labels "com.docker.compose.project"}}' nexo_app_storage)
test "$storage_project" = nexo || { echo 'APP_STORAGE_VOLUME_OWNERSHIP_MISMATCH'; exit 112; }
printf '%s  %s\n' "$dump_hash" "$backup_dump" | sha256sum -c -
printf '%s  %s\n' "$storage_hash" "$backup_storage" | sha256sum -c -
docker exec nexo-postgres pg_isready -U nexo -d postgres >/dev/null || { echo 'POSTGRES_NOT_READY'; exit 113; }
server_major=$(docker exec nexo-postgres postgres --version | sed -E 's/.* ([0-9]+)(\..*)?/\1/')
test "$server_major" = 16 || { echo "POSTGRES_MAJOR_MISMATCH=$server_major"; exit 114; }
available_postgis=$(docker exec nexo-postgres psql -U nexo -d postgres -v ON_ERROR_STOP=1 -Atqc "SELECT default_version FROM pg_available_extensions WHERE name='postgis'")
case "$available_postgis" in 3.4.*) ;; *) echo "POSTGIS_UNAVAILABLE_OR_INCOMPATIBLE=$available_postgis"; exit 115 ;; esac

list_file="$release/promotion-${run_id}.list"
expected_raw="$release/promotion-${run_id}-expected.raw"
expected_app="$release/promotion-${run_id}-expected.app"
actual_app="$release/promotion-${run_id}-actual.app"
owned_file="$release/promotion-${run_id}-extension-owned.txt"
docker run --rm --network none -v /opt/nexo/backups:/backup:ro postgis/postgis:16-3.4 pg_restore --list "/backup/nexo-${run_id}.dump" > "$list_file"
dump_major=$(sed -n 's/^; *Dumped from database version: \([0-9][0-9]*\).*/\1/p' "$list_file" | head -n 1)
test "$dump_major" = "$server_major" || { echo "DUMP_POSTGRES_MAJOR_MISMATCH=$dump_major"; exit 116; }
awk '$4 == "TABLE" && $5 == "public" { if ($6 ~ /^"/) { print "QUOTED_TABLE_IDENTIFIER_UNSUPPORTED=" $0 > "/dev/stderr"; exit 2 } print $6 }' "$list_file" | sort -u > "$expected_raw"
test -s "$expected_raw" || { echo 'DUMP_APPLICATION_TABLE_LIST_EMPTY'; exit 117; }

comment_for_db() {
  docker exec nexo-postgres psql -U nexo -d postgres -v ON_ERROR_STOP=1 -Atqc "SELECT COALESCE(shobj_description(oid,'pg_database'),'') FROM pg_database WHERE datname='$1'"
}
db_exists() {
  [ "$(docker exec nexo-postgres psql -U nexo -d postgres -Atqc "SELECT count(*) FROM pg_database WHERE datname='$1'")" = 1 ]
}
validate_database() {
  db="$1"
  postgis=$(docker exec nexo-postgres psql -U nexo -d "$db" -v ON_ERROR_STOP=1 -Atqc "SELECT extversion FROM pg_extension WHERE extname='postgis'")
  case "$postgis" in 3.4.*) ;; *) echo "POSTGIS_EXTENSION_VERSION_MISMATCH database=$db version=$postgis"; return 1 ;; esac
  full_version=$(docker exec nexo-postgres psql -U nexo -d "$db" -v ON_ERROR_STOP=1 -Atqc 'SELECT postgis_full_version()')
  test -n "$full_version" || { echo "POSTGIS_FULL_VERSION_FAILED database=$db"; return 1; }
  docker exec nexo-postgres psql -U nexo -d "$db" -v ON_ERROR_STOP=1 -Atqc "SELECT e.extname || E'\\t' || c.relname FROM pg_depend d JOIN pg_extension e ON e.oid=d.refobjid JOIN pg_class c ON d.classid='pg_class'::regclass AND d.objid=c.oid JOIN pg_namespace n ON n.oid=c.relnamespace WHERE d.refclassid='pg_extension'::regclass AND d.deptype='e' AND n.nspname='public' AND c.relkind IN ('r','p') ORDER BY e.extname,c.relname" > "$owned_file"
  docker exec nexo-postgres psql -U nexo -d "$db" -v ON_ERROR_STOP=1 -Atqc "SELECT c.relname FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='public' AND c.relkind IN ('r','p') AND NOT EXISTS (SELECT 1 FROM pg_depend d JOIN pg_extension e ON e.oid=d.refobjid WHERE d.classid='pg_class'::regclass AND d.objid=c.oid AND d.refclassid='pg_extension'::regclass AND d.deptype='e') ORDER BY c.relname" > "$actual_app"
  if [ -s "$owned_file" ]; then cut -f2 "$owned_file" | sort -u > "$release/promotion-${run_id}-owned.names"; grep -Fvx -f "$release/promotion-${run_id}-owned.names" "$expected_raw" > "$expected_app" || [ "$?" -eq 1 ]; else cp "$expected_raw" "$expected_app"; fi
  expected_count=$(wc -l < "$expected_app" | tr -d ' ')
  actual_count=$(wc -l < "$actual_app" | tr -d ' ')
  test "$expected_count" -eq 72 && test "$actual_count" -eq 72 || { echo "APPLICATION_TABLE_COUNT_MISMATCH database=$db expected=$expected_count actual=$actual_count"; return 1; }
  cmp -s "$expected_app" "$actual_app" || { echo "APPLICATION_TABLE_SET_MISMATCH database=$db"; comm -23 "$expected_app" "$actual_app" | sed 's/^/MISSING_TABLE=/' ; comm -13 "$expected_app" "$actual_app" | sed 's/^/UNEXPECTED_TABLE=/' ; return 1; }
  migrations=$(docker exec nexo-postgres psql -U nexo -d "$db" -v ON_ERROR_STOP=1 -Atqc "SELECT count(*) FROM public.migrations")
  test "$migrations" -gt 0 || { echo "MIGRATION_HISTORY_EMPTY database=$db"; return 1; }
  printf 'DATABASE_VALIDATED=%s APPLICATION_TABLES=%s MIGRATION_ROWS=%s POSTGIS=%s\n' "$db" "$actual_count" "$migrations" "$postgis"
}

restore_exists=false; nexo_exists=false
if db_exists "$restore_db"; then restore_exists=true; fi
if db_exists nexo; then nexo_exists=true; fi
partial_dbs=$(docker exec nexo-postgres psql -U nexo -d postgres -Atqc "SELECT datname FROM pg_database WHERE left(datname,length('$partial_prefix'))='$partial_prefix' ORDER BY datname")
partial_count=$(printf '%s\n' "$partial_dbs" | awk 'NF {n++} END {print n+0}')
nexo_comment=''
if [ "$nexo_exists" = true ]; then nexo_comment=$(comment_for_db nexo); fi
recovery_action=''
if [ "$restore_exists" = true ]; then
  if [ "$nexo_exists" = true ] && [ "$nexo_comment" = "$restore_comment" ]; then echo 'PROMOTION_STATE_DUPLICATE_RESTORE_AND_PROMOTED_DB'; exit 118; fi
  if [ "$nexo_exists" = true ] && [ "$partial_count" -eq 0 ]; then recovery_action=preserve-nexo-then-promote
  elif [ "$nexo_exists" = false ] && [ "$partial_count" -eq 1 ]; then recovery_action=promote-after-first-rename
  else echo "PROMOTION_STATE_AMBIGUOUS nexo=$nexo_exists partials=$partial_count"; exit 118; fi
  restore_owner=$(docker exec nexo-postgres psql -U nexo -d postgres -Atqc "SELECT pg_get_userbyid(datdba) FROM pg_database WHERE datname='$restore_db'")
  test "$restore_owner" = nexo || { echo "RESTORE_DATABASE_OWNER_MISMATCH=$restore_owner"; exit 119; }
  if [ "$nexo_exists" = true ]; then
    nexo_owner=$(docker exec nexo-postgres psql -U nexo -d postgres -Atqc "SELECT pg_get_userbyid(datdba) FROM pg_database WHERE datname='nexo'")
    test "$nexo_owner" = nexo || { echo "INCOMPLETE_DATABASE_OWNER_MISMATCH=$nexo_owner"; exit 119; }
    nexo_tables=$(docker exec nexo-postgres psql -U nexo -d nexo -Atqc "SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='public' AND c.relkind IN ('r','p')")
    nexo_postgis=$(docker exec nexo-postgres psql -U nexo -d nexo -Atqc "SELECT COALESCE((SELECT extversion FROM pg_extension WHERE extname='postgis'),'not-installed')")
    printf 'INCOMPLETE_DATABASE_IDENTITY=nexo OWNER=%s PUBLIC_TABLES=%s POSTGIS=%s\n' "$nexo_owner" "$nexo_tables" "$nexo_postgis"
  elif [ "$partial_count" -eq 1 ]; then
    preserved_db=$(printf '%s\n' "$partial_dbs" | awk 'NF {print; exit}')
    preserved_owner=$(docker exec nexo-postgres psql -U nexo -d postgres -Atqc "SELECT pg_get_userbyid(datdba) FROM pg_database WHERE datname='$preserved_db'")
    test "$preserved_owner" = nexo || { echo "PRESERVED_PARTIAL_DATABASE_OWNER_MISMATCH=$preserved_owner"; exit 119; }
  fi
  restore_existing_comment=$(comment_for_db "$restore_db")
  test -z "$restore_existing_comment" || test "$restore_existing_comment" = "$restore_comment" || { echo 'RESTORE_DATABASE_IDENTITY_COMMENT_MISMATCH'; exit 119; }
  validate_database "$restore_db"
  if [ -z "$restore_existing_comment" ]; then docker exec nexo-postgres psql -U nexo -d postgres -v ON_ERROR_STOP=1 -c "COMMENT ON DATABASE \"$restore_db\" IS '$restore_comment'" >/dev/null; fi
else
  [ "$nexo_exists" = true ] && [ "$nexo_comment" = "$restore_comment" ] || { echo 'RESTORE_DATABASE_MISSING_OR_PROMOTED_DATABASE_UNMARKED'; exit 120; }
  [ "$partial_count" -le 1 ] || { echo 'PROMOTION_PARTIAL_DATABASES_AMBIGUOUS'; exit 120; }
  recovery_action=already-promoted
  validate_database nexo
fi
printf 'PROMOTION_RECOVERY_ACTION=%s\n' "$recovery_action"

for pair in 'nexo-app app' 'nexo-control control' 'nexo-nginx nginx' 'nexo-queue queue' 'nexo-scheduler scheduler'; do
  set -- $pair; name="$1"; service="$2"
  if docker inspect "$name" >/dev/null 2>&1; then assert_service "$name" "$service" optional; fi
done
for name in nexo-app nexo-control nexo-nginx nexo-queue nexo-scheduler; do
  if docker inspect "$name" >/dev/null 2>&1 && [ "$(docker inspect --format '{{.State.Running}}' "$name")" = true ]; then docker stop --time 30 "$name" >/dev/null; fi
done
for name in nexo-queue nexo-scheduler; do
  if docker inspect "$name" >/dev/null 2>&1 && [ "$(docker inspect --format '{{.State.Running}}' "$name")" = true ]; then echo "BACKGROUND_SERVICE_STILL_RUNNING=$name"; exit 121; fi
done

if [ "$recovery_action" = preserve-nexo-then-promote ]; then
  active=$(docker exec nexo-postgres psql -U nexo -d postgres -Atqc "SELECT count(*) FROM pg_stat_activity WHERE datname IN ('nexo','$restore_db') AND pid <> pg_backend_pid()")
  test "$active" -eq 0 || { echo "DATABASE_RENAME_BLOCKED_BY_ACTIVE_CONNECTIONS=$active; no sessions were terminated"; exit 122; }
  partial_db="${partial_prefix}$(date -u +%Y%m%dT%H%M%SZ)_$$"
  if db_exists "$partial_db"; then partial_db="${partial_db}_retry"; fi
  test "$(db_exists "$partial_db" && echo yes || echo no)" = no || { echo 'PARTIAL_DATABASE_NAME_COLLISION'; exit 123; }
  docker exec nexo-postgres psql -U nexo -d postgres -v ON_ERROR_STOP=1 -c "ALTER DATABASE nexo RENAME TO \"$partial_db\""
  printf 'PRESERVED_PARTIAL_DATABASE=%s\n' "$partial_db"
  recovery_action=promote-after-first-rename
fi
if [ "$recovery_action" = promote-after-first-rename ]; then
  active=$(docker exec nexo-postgres psql -U nexo -d postgres -Atqc "SELECT count(*) FROM pg_stat_activity WHERE datname='$restore_db' AND pid <> pg_backend_pid()")
  test "$active" -eq 0 || { echo "RESTORE_DATABASE_RENAME_BLOCKED_BY_ACTIVE_CONNECTIONS=$active; no sessions were terminated"; exit 124; }
  docker exec nexo-postgres psql -U nexo -d postgres -v ON_ERROR_STOP=1 -c "ALTER DATABASE \"$restore_db\" RENAME TO nexo"
fi

if [ "$(comment_for_db nexo)" != "$restore_comment" ]; then echo 'PROMOTED_DATABASE_MARKER_MISMATCH'; exit 125; fi
validate_database nexo
printf '%s\n' "$target_sha $dump_hash" > "$release/.database-restored.tmp"
chmod 600 "$release/.database-restored.tmp"
mv -f "$release/.database-restored.tmp" "$release/.database-restored"

storage_marker="$release/.storage-restored"
if [ -f "$storage_marker" ]; then
  test "$(cat "$storage_marker")" = "$storage_hash" || { echo 'STORAGE_RESTORE_MARKER_MISMATCH'; exit 126; }
else
  storage_empty=$(docker run --rm --network none --entrypoint sh -v nexo_app_storage:/restore nexo-app:production -c 'test -z "$(find /restore -mindepth 1 -print -quit)" && echo empty || echo nonempty')
  test "$storage_empty" = empty || { echo 'STORAGE_VOLUME_NONEMPTY_WITHOUT_VERIFIABLE_RESTORE_MARKER'; exit 127; }
  tar -tzf "$backup_storage" > "$release/promotion-${run_id}-storage.list"
  test -s "$release/promotion-${run_id}-storage.list" || { echo 'STORAGE_ARCHIVE_EMPTY'; exit 127; }
  while IFS= read -r path; do case "$path" in /*|..|../*|*/../*|*/..) echo "UNSAFE_STORAGE_ARCHIVE_PATH=$path"; exit 127 ;; esac; done < "$release/promotion-${run_id}-storage.list"
  docker run --rm --network none --entrypoint tar -v nexo_app_storage:/restore -v /opt/nexo/backups:/backup:ro nexo-app:production -xzf "/backup/nexo-${run_id}-storage.tgz" -C /restore
  printf '%s\n' "$storage_hash" > "$storage_marker.tmp"; chmod 600 "$storage_marker.tmp"; mv -f "$storage_marker.tmp" "$storage_marker"
fi

available_kb=$(awk '/MemAvailable:/ {print $2}' /proc/meminfo)
test "$available_kb" -ge 786432 || { echo "APPLICATION_START_RAM_GATE_KB=$available_kb required_at_least=786432"; exit 128; }
docker compose -p nexo -f "$release/docker-compose.production.yml" -f "$release/docker-compose.migration.yml" up -d --no-build --no-deps app control
wait_healthy() {
  name="$1"
  for i in $(seq 1 60); do
    state=$(docker inspect --format '{{.State.Status}} {{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}' "$name")
    [ "$state" = 'running healthy' ] && return 0
    [ "$(docker inspect --format '{{.State.Status}}' "$name")" = running || { echo "CONTAINER_NOT_RUNNING=$name state=$state"; return 1; }
    sleep 2
  done
    echo "CONTAINER_HEALTH_TIMEOUT=$name state=$state; container logs intentionally not emitted to avoid exposing environment values"; return 1
}
wait_healthy nexo-app
wait_healthy nexo-control
docker compose -p nexo -f "$release/docker-compose.production.yml" -f "$release/docker-compose.migration.yml" up -d --no-build --no-deps nginx
wait_healthy nexo-nginx
for i in $(seq 1 30); do curl -fsS http://127.0.0.1:18100/api/v1/live >/dev/null && curl -fsS http://127.0.0.1:18100/login >/dev/null && break; sleep 2; done
live_code=$(curl -sS -o /dev/null -w '%{http_code}' http://127.0.0.1:18100/api/v1/live)
login_page=$(curl -fsS http://127.0.0.1:18100/login)
test "$live_code" = 200 || { echo "API_LIVE_HTTP_STATUS=$live_code"; exit 129; }
printf '%s' "$login_page" | grep -q '<' || { echo 'LOGIN_RESPONSE_EMPTY'; exit 129; }
asset=$(printf '%s' "$login_page" | grep -oE '/_next/static/[^" ]+\.(css|js)' | head -n 1 || true)
test -n "$asset" || { echo 'NEXT_STATIC_ASSET_REFERENCE_MISSING'; exit 129; }
asset_code=$(curl -sS -o /dev/null -w '%{http_code}' "http://127.0.0.1:18100$asset")
test "$asset_code" = 200 || { echo "NEXT_STATIC_ASSET_HTTP_STATUS=$asset_code"; exit 129; }
auth_code=$(curl -sS -o /dev/null -w '%{http_code}' http://127.0.0.1:18100/api/v1/me)
test "$auth_code" = 401 || { echo "UNAUTHENTICATED_ME_HTTP_STATUS=$auth_code"; exit 129; }
port_map=$(docker port nexo-nginx 80/tcp)
test "$port_map" = '127.0.0.1:18100' || { echo "NGINX_PORT_BINDING_UNEXPECTED=$port_map"; exit 130; }
for name in nexo-app nexo-control nexo-nginx nexo-postgres nexo-redis; do
  state=$(docker inspect --format '{{.State.Status}} {{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}' "$name")
  test "$state" = 'running healthy' || { echo "UNHEALTHY_CONTAINER=$name:$state"; exit 131; }
done
for name in nexo-queue nexo-scheduler; do
  if docker inspect "$name" >/dev/null 2>&1 && [ "$(docker inspect --format '{{.State.Running}}' "$name")" = true ]; then echo "BACKGROUND_SERVICE_RUNNING=$name"; exit 132; fi
done
printf 'PROMOTION_COMPLETE=verified\nAPI_LIVE_HTTP=%s\nLOGIN_HTTP=200\nAUTH_ME_HTTP=%s\nSTATIC_ASSET_HTTP=%s ASSET_PATH=%s\nNGINX_BINDING=%s\n' "$live_code" "$auth_code" "$asset_code" "$asset" "$port_map"
docker compose -p nexo -f "$release/docker-compose.production.yml" -f "$release/docker-compose.migration.yml" ps
docker stats --no-stream --format '{{.Name}} {{.MemUsage}} {{.CPUPerc}}'
free -h; df -h /opt
'@
    return $payload.Replace('__RUN_ID__',$RunId).Replace('__RUN_SHORT__',$RunId.Substring(0,8)).Replace('__TARGET_SHA__',$PinnedSha).Replace('__RESTORE_DB__',$RestoreDatabase).Replace('__DUMP_HASH__',$DumpHash).Replace('__STORAGE_HASH__',$StorageHash)
}

function Assert-ApprovedPromotionCheckpoints($State,[string]$RunId,[string]$RepositoryPath,[string]$WorkspacePath) {
    if ($RunId -ne 'd8e35d4dd1a14b548b121b0ef69911bf') { Fail 'Promotion-only mode is restricted to the explicitly approved Run ID.' }
    if ($null -eq $State -or -not [bool]$State.BackupReady -or -not [bool]$State.ImagesBuilt -or $State.RunId -ne $RunId -or $State.TargetSha -ne '2111243fff34f724d98c7ae6a825785967fbe882' -or $State.OldHost -ne '185.186.77.210' -or $State.NewHost -ne '2.188.205.35' -or $State.Database -ne 'nexo' -or $State.DatabaseUser -ne 'nexo') { Fail 'Saved migration state does not prove the approved Run ID, hosts, source, image build, and NEXO database identity.' }
    foreach ($property in @('DumpSha256','StorageSha256','EnvSha256')) { if ([string]$State.$property -notmatch '^[a-f0-9]{64}$') { Fail "Saved migration state has no valid $property checkpoint." } }
    $work = if ($WorkspacePath) { $WorkspacePath } else { Join-Path ([IO.Path]::GetTempPath()) ("nexo-migrate-{0}" -f $RunId) }
    $files = @(@((Join-Path $work 'nexo.dump'),[string]$State.DumpSha256,'PostgreSQL backup'),@((Join-Path $work 'storage.tgz'),[string]$State.StorageSha256,'Storage backup'),@((Join-Path $work 'source.env'),[string]$State.EnvSha256,'Environment backup'))
    foreach ($entry in $files) {
        if (-not (Test-Path -LiteralPath $entry[0] -PathType Leaf)) { Fail "Retained $($entry[2]) checkpoint file is missing." }
        $actual = (Get-FileHash -LiteralPath $entry[0] -Algorithm SHA256).Hash.ToLowerInvariant()
        if ($actual -ne $entry[1]) { Fail "Retained $($entry[2]) SHA256 differs from saved checkpoint." }
    }
    return [pscustomobject]@{DumpHash=[string]$State.DumpSha256;StorageHash=[string]$State.StorageSha256;Work=$work}
}

function Assert-ApprovedPromotionScope([string]$RunId,[string]$HostName,[int]$Port,[string]$User,[string]$PinnedSha,[string]$RestoreDatabase,[bool]$ApprovalFlag,[bool]$PromotionOnly,[bool]$EnableWorkers) {
    if (-not $ApprovalFlag -or -not $PromotionOnly -or $EnableWorkers -or $RunId -ne 'd8e35d4dd1a14b548b121b0ef69911bf' -or $HostName -ne '2.188.205.35' -or $Port -ne 2238 -or $User -ne 'root' -or $PinnedSha -ne '2111243fff34f724d98c7ae6a825785967fbe882' -or $RestoreDatabase -ne 'nexo_restore_d8e35d4d_20261017T213125Z_38265') { Fail 'Promotion is not within the exact approved destination, Run ID, source, restore database, and disabled-background scope.' }
}

function Test-PromotionSafetyRegression {
    $expected = 'nexo-migration:d8e35d4dd1a14b548b121b0ef69911bf:2111243fff34f724d98c7ae6a825785967fbe882:' + ('a' * 64)
    $cases = @(
        @{Restore=$true;Nexo=$true;Comment='';Partials=@();Want='preserve-nexo-then-promote'},
        @{Restore=$true;Nexo=$false;Comment='';Partials=@('nexo_partial_d8e35d4d_20261018T010000Z_22');Want='promote-after-first-rename'},
        @{Restore=$false;Nexo=$true;Comment=$expected;Partials=@('nexo_partial_d8e35d4d_20261018T010000Z_22');Want='already-promoted'},
        @{Restore=$true;Nexo=$false;Comment='';Partials=@();Want='stop-unexpected-database-state'},
        @{Restore=$true;Nexo=$true;Comment='';Partials=@('one','two');Want='stop-ambiguous-partials'},
        @{Restore=$false;Nexo=$true;Comment='wrong';Partials=@();Want='stop-restore-missing-or-unmarked'}
    )
    foreach ($case in $cases) {
        $actual = Get-PromotionRecoveryAction $case.Restore $case.Nexo $case.Comment $expected $case.Partials
        if ($actual -ne $case.Want) { Fail "Promotion recovery regression failed: expected $($case.Want), got $actual." }
    }
    Assert-ApprovedPromotionScope 'd8e35d4dd1a14b548b121b0ef69911bf' '2.188.205.35' 2238 'root' '2111243fff34f724d98c7ae6a825785967fbe882' 'nexo_restore_d8e35d4d_20261017T213125Z_38265' $true $true $false
    $scopeRejected=$false
    try { Assert-ApprovedPromotionScope 'd8e35d4dd1a14b548b121b0ef69911bf' '185.186.77.210' 2238 'root' '2111243fff34f724d98c7ae6a825785967fbe882' 'nexo_restore_d8e35d4d_20261017T213125Z_38265' $true $true $false } catch { $scopeRejected=$true }
    if (-not $scopeRejected) { Fail 'Promotion scope regression accepted the old VPS as a destination.' }
    $workerRejected=$false
    try { Assert-ApprovedPromotionScope 'd8e35d4dd1a14b548b121b0ef69911bf' '2.188.205.35' 2238 'root' '2111243fff34f724d98c7ae6a825785967fbe882' 'nexo_restore_d8e35d4d_20261017T213125Z_38265' $true $true $true } catch { $workerRejected=$true }
    if (-not $workerRejected) { Fail 'Promotion scope regression accepted queue/scheduler activation.' }
    $fixture = Join-Path ([IO.Path]::GetTempPath()) ('nexo-promotion-checkpoints-' + [Guid]::NewGuid().ToString('N'))
    New-Item -ItemType Directory -Path $fixture -Force | Out-Null
    try {
        $dumpPath=Join-Path $fixture 'nexo.dump'; $storagePath=Join-Path $fixture 'storage.tgz'; $envPath=Join-Path $fixture 'source.env'
        [IO.File]::WriteAllBytes($dumpPath,[byte[]](80,71,68,77,80,1)); [IO.File]::WriteAllBytes($storagePath,[byte[]](31,139,8)); [IO.File]::WriteAllText($envPath,'fixture-secret-never-display')
        $state=[pscustomobject]@{RunId='d8e35d4dd1a14b548b121b0ef69911bf';TargetSha='2111243fff34f724d98c7ae6a825785967fbe882';OldHost='185.186.77.210';NewHost='2.188.205.35';Database='nexo';DatabaseUser='nexo';BackupReady=$true;ImagesBuilt=$true;DumpSha256=(Get-FileHash $dumpPath -Algorithm SHA256).Hash.ToLowerInvariant();StorageSha256=(Get-FileHash $storagePath -Algorithm SHA256).Hash.ToLowerInvariant();EnvSha256=(Get-FileHash $envPath -Algorithm SHA256).Hash.ToLowerInvariant()}
        $checkpoint=Assert-ApprovedPromotionCheckpoints $state $state.RunId 'fixture-repository' $fixture
        if ($checkpoint.DumpHash -ne $state.DumpSha256 -or $checkpoint.StorageHash -ne $state.StorageSha256) { Fail 'Approved promotion checkpoint regression did not verify the retained backup hashes.' }
    } finally { Remove-Item -LiteralPath $fixture -Recurse -Force -ErrorAction SilentlyContinue }
    $payload = New-ApprovedPromotionPayload 'd8e35d4dd1a14b548b121b0ef69911bf' '2111243fff34f724d98c7ae6a825785967fbe882' 'nexo_restore_d8e35d4d_20261017T213125Z_38265' ('a' * 64) ('b' * 64)
    $bash = Join-Path $env:ProgramFiles 'Git\bin\bash.exe'
    if (-not (Test-Path -LiteralPath $bash -PathType Leaf)) { Fail 'Promotion payload regression requires Git for Windows bash.exe.' }
    $payloadPath = [IO.Path]::GetTempFileName()
    try {
        [IO.File]::WriteAllText($payloadPath,$payload,(New-Object Text.UTF8Encoding($false)))
        $syntax = Invoke-Native $bash @('-n',$payloadPath) 'Approved promotion Bash syntax regression'
        $mockCalls = New-Object Collections.Generic.List[string]
        $mock = { param($key,$port,$user,$host,$script,$label) $mockCalls.Add($label); if ($script -notmatch 'nexo_restore_d8e35d4d_20261017T213125Z_38265' -or $script -notmatch 'APPLICATION_TABLE_COUNT_MISMATCH' -or $script -notmatch 'pg_stat_activity' -or $script -match 'pg_terminate_backend|DROP DATABASE|FLUSHALL|FLUSHDB') { Fail 'Mock promotion execution payload failed a safety assertion.' }; return 'PROMOTION_COMPLETE=verified' }.GetNewClosure()
        $mockResult = & $mock 'fixture-key' 2238 'root' 'fixture.invalid' $payload 'Approved destination-only promotion'
        if ($mockResult -ne 'PROMOTION_COMPLETE=verified' -or $mockCalls.Count -ne 1) { Fail 'Mocked approved promotion execution path failed.' }
    } finally { Remove-Item -LiteralPath $payloadPath -Force -ErrorAction SilentlyContinue }
}

function Start-VerifiedPrivateTunnel([string]$Key,[int]$Port,[string]$User,[string]$HostName,[int]$RemotePort) {
    $localPort = $RemotePort
    $listener = New-Object Net.Sockets.TcpListener([Net.IPAddress]::Loopback,$localPort)
    try { $listener.Start(); $listener.Stop() } catch { $localPort = $RemotePort + 1; try { $listener.Stop() } catch {} }
    $sshExe = Require-Command 'ssh.exe'
    $tunnelArgs = @('-N','-i',$Key,'-p',[string]$Port,'-o','IdentitiesOnly=yes','-o','StrictHostKeyChecking=yes','-o','BatchMode=yes','-L',"${localPort}:127.0.0.1:${RemotePort}","$User@$HostName")
    $psi = New-Object Diagnostics.ProcessStartInfo; $psi.FileName=$sshExe; $psi.UseShellExecute=$false; $psi.CreateNoWindow=$true
    $psi.Arguments = (($tunnelArgs | ForEach-Object { '"' + ($_ -replace '(\\*)"', '$1$1\"' -replace '(\\+)$', '$1$1') + '"' }) -join ' ')
    $process = New-Object Diagnostics.Process; $process.StartInfo=$psi; [void]$process.Start(); Start-Sleep -Seconds 2
    if ($process.HasExited) { Fail 'SSH tunnel exited before verification; check strict known_hosts and destination key access.' }
    $ok = $false
    for ($i=0; $i -lt 20; $i++) { try { $page=Invoke-WebRequest -UseBasicParsing -Uri "http://127.0.0.1:$localPort/login" -TimeoutSec 3; if ($page.StatusCode -eq 200) { $ok=$true; break } } catch {}; Start-Sleep -Milliseconds 500 }
    if (-not $ok) { try { $process.Kill() } catch {}; Fail 'Local SSH tunnel failed the /login HTTP 200 verification.' }
    Write-Host "Tunnel is running (PID $($process.Id)): ssh -i `"`$env:USERPROFILE\.ssh\nexo_deploy`" -p $Port -o IdentitiesOnly=yes -o StrictHostKeyChecking=yes -L ${localPort}:127.0.0.1:${RemotePort} $User@$HostName"
    Write-Host "Private URL: http://127.0.0.1:$localPort/login"
    return $process
}

try {
    if ($ResumeRunId -and -not (Test-Path -LiteralPath $script:LocalWork -PathType Container)) { Fail 'Cannot resume: the local workspace for this Run ID does not exist.' }
    New-Item -ItemType Directory -Path $script:LocalWork -Force | Out-Null
    # Restrict the root and every existing resume artifact before reading or writing secrets.
    try { Set-PrivateWorkspaceAcl $script:LocalWork } catch { Fail "Could not restrict permissions on private migration workspace using DACL-only ACL operations: $($_.Exception.Message)" }
    if ($ResumeRunId) {
        if ($ResumeRunId -notmatch '^[a-f0-9]{32}$') { Fail 'ResumeRunId must be the 32-character Run ID from the prior run.' }
        if (Test-Path -LiteralPath $script:StateFile -PathType Leaf) {
            $script:PreviousState = Get-Content -LiteralPath $script:StateFile -Raw | ConvertFrom-Json
            if ($script:PreviousState.TargetSha -ne $TargetSha -or $script:PreviousState.OldHost -ne $OldHost -or $script:PreviousState.NewHost -ne $NewHost -or -not $script:PreviousState.BackupReady) { Fail 'Saved state does not match this source/host pair or has no verified backup.' }
        } else {
            # A failed initial stream can occur before state.json is written. Reuse the
            # private run directory, but regenerate and reverify every source stream;
            # without saved source hashes, existing files are not trusted as checkpoints.
            Write-Step 'Resuming this run before its final backup checkpoint; each retained stream will be checksum-verified and only missing streams will be repeated.'
        }
    }
    $script:StreamState = @{RunId=$script:RunId;TargetSha=$TargetSha;OldHost=$OldHost;NewHost=$NewHost}
    if ($ResumeRunId -and (Test-Path -LiteralPath $script:StreamStateFile -PathType Leaf)) {
        $savedStreamState = Get-Content -LiteralPath $script:StreamStateFile -Raw | ConvertFrom-Json
        if ($savedStreamState.RunId -ne $script:RunId -or $savedStreamState.TargetSha -ne $TargetSha -or $savedStreamState.OldHost -ne $OldHost -or $savedStreamState.NewHost -ne $NewHost) { Fail 'Stream checkpoint does not match this Run ID, source, or host pair.' }
        $script:StreamState = @{}
        foreach ($property in $savedStreamState.PSObject.Properties) { $script:StreamState[$property.Name] = $property.Value }
    }
    if (Assert-LocalPrerequisites) { exit 0 }
    Write-Step "Run ID $script:RunId; application source pinned to $TargetSha. Local secrets and untracked files are excluded."
    if ($PromoteApprovedRestoreOnly) {
        Assert-ApprovedPromotionScope $script:RunId $NewHost $NewPort $NewUser $TargetSha $ApprovedRestoreDatabase ([bool]$ApproveDatabasePromotion) $true ([bool]$EnableBackgroundProcessing)
        $checkpoint = Assert-ApprovedPromotionCheckpoints $script:PreviousState $script:RunId $Repository
        $promotionPayload = New-ApprovedPromotionPayload $script:RunId $TargetSha $ApprovedRestoreDatabase $checkpoint.DumpHash $checkpoint.StorageHash
        Write-Step 'Running destination-only promotion checks; no old-VPS SSH, Redis audit, backup, restore, migration, seeder, or background job will run.'
        $promotionResult = Invoke-Remote $NewKey $NewPort $NewUser $NewHost $promotionPayload 'Approved destination database promotion and private staging smoke tests'
        if ($promotionResult -notmatch '(?m)^PROMOTION_COMPLETE=verified$') { Fail "Destination did not return the complete promotion/smoke-test marker. Output: $promotionResult" }
        Write-Host $promotionResult
        $updatedState = @{}
        foreach ($property in $script:PreviousState.PSObject.Properties) { $updatedState[$property.Name] = $property.Value }
        $updatedState.DatabasePromoted = $true; $updatedState.PromotedDatabase = 'nexo'; $updatedState.PrivateSmokeTests = $true; $updatedState.PromotionCompleted = (Get-Date).ToString('o')
        Save-State $updatedState
        [void](Start-VerifiedPrivateTunnel $NewKey $NewPort $NewUser $NewHost 18100)
        Write-Step 'Destination promotion and server-side checks passed; the SSH tunnel also returned HTTP 200 for /login.'
        exit 0
    }
    if ($ApproveDatabasePromotion -or $ApprovedRestoreDatabase) { Fail 'Database promotion approval is accepted only in the exact -PromoteApprovedRestoreOnly mode.' }
    Write-Step 'Checking strict SSH access and collecting read-only old-server Docker inventory.'
    $oldInventory = @(Get-RemoteDockerInventory $OldHost $OldPort $OldKey $OldUser)
    $oldCandidates = @()
    foreach ($c in $oldInventory) {
        $ci = Get-ComposeInfo $c
        if (Test-IsNexoContainer $c $ci) { $oldCandidates += [pscustomobject]@{Container=$c;Info=$ci} }
    }
    $pg = @($oldCandidates | Where-Object { $_.Info.Service -match '(?i)(postgres|db|database)' -or (Get-OptionalProperty (Get-OptionalProperty $_.Container 'Config') 'Image') -match '(?i)(postgres|postgis)' })
    $redis = @($oldCandidates | Where-Object { $_.Info.Service -match '(?i)redis' -or (Get-OptionalProperty (Get-OptionalProperty $_.Container 'Config') 'Image') -match '(?i)redis' })
    $apps = @($oldCandidates | Where-Object { $_.Info.Service -match '^(app|backend|api)$' })
    if ($pg.Count -ne 1 -or $redis.Count -ne 1 -or $apps.Count -lt 1) { Fail "Old server inventory did not identify exactly one NEXO PostgreSQL container, one Redis container, and at least one app container (Postgres=$($pg.Count), Redis=$($redis.Count), app=$($apps.Count)). No backup or deployment was attempted; inspect ownership/service labels first." }
    $pgContainer = $pg[0].Container; $appContainer = $apps[0].Container; $oldProject = $pg[0].Info.Project
    $redisContainer = $redis[0].Container
    if ($redis[0].Info.Project -ne $oldProject) { Fail 'NEXO PostgreSQL and Redis are not owned by the same Compose project; refusing to assume the Redis instance belongs exclusively to this database.' }
    $redisNetworks = @(Get-ContainerNetworkNames $redisContainer)
    $redisShared = ($redisNetworks.Count -eq 0)
    $nexoContainerIds = @{}
    foreach ($candidate in $oldCandidates) { $candidateId = [string](Get-OptionalProperty $candidate.Container 'Id'); if ($candidateId -and $candidate.Info.Project -eq $oldProject) { $nexoContainerIds[$candidateId] = $true } }
    foreach ($container in $oldInventory) {
        $containerId = [string](Get-OptionalProperty $container 'Id')
        if ($nexoContainerIds.ContainsKey($containerId)) { continue }
        $containerNetworks = @(Get-ContainerNetworkNames $container)
        foreach ($networkName in $redisNetworks) { if ($containerNetworks -contains $networkName) { $redisShared = $true; break } }
        if ($redisShared) { break }
    }
    if ($redisNetworks.Count -gt 0 -and -not $redisShared) { Write-Step "Redis ownership: dedicated to Compose project '$oldProject' on its attached network(s)." } else { Write-Step 'Redis ownership: shared or uncertain; audit will match only the NEXO-specific Redis prefix and will not inspect/restore unrelated data.' }
    $pgEnv = @(Get-OptionalProperty (Get-OptionalProperty $pgContainer 'Config') 'Env')
    $dbName = Get-EnvValue $pgEnv 'POSTGRES_DB'; $dbUser = Get-EnvValue $pgEnv 'POSTGRES_USER'
    if (-not $dbName -or -not $dbUser) { Fail 'Could not safely determine NEXO database/user from the identified PostgreSQL container environment.' }
    if ($dbName -ne 'nexo' -or $dbUser -ne 'nexo') { Fail "The checked-in production Compose fixes database/user to nexo; detected '$dbName'/'$dbUser'. Do not override this mismatch automatically." }
    if ($dbName -notmatch '^[A-Za-z0-9_]+$' -or $dbUser -notmatch '^[A-Za-z0-9_]+$') { Fail 'Database identity contains unexpected characters; refusing remote shell interpolation.' }
    $appEnv = @(Get-OptionalProperty (Get-OptionalProperty $appContainer 'Config') 'Env')
    $appKey = Get-EnvValue $appEnv 'APP_KEY'
    if (-not $appKey -or $appKey -notmatch '^base64:[A-Za-z0-9+/=]+$') { Fail 'Could not securely identify a valid APP_KEY from the old NEXO app container. Preserve the original application key before continuing.' }
    $runtimeRedisUrl = Get-EnvValue $appEnv 'REDIS_URL'; $runtimeRedisHost = Get-EnvValue $appEnv 'REDIS_HOST'; $runtimeRedisPort = Get-EnvValue $appEnv 'REDIS_PORT'; $runtimeRedisUser = Get-EnvValue $appEnv 'REDIS_USERNAME'; $runtimeRedisPassword = Get-EnvValue $appEnv 'REDIS_PASSWORD'
    if ($runtimeRedisUrl -and $runtimeRedisUrl -notin @('null','')) { Fail 'The running NEXO app uses REDIS_URL; the Compose Redis container cannot be assumed to be its active datastore. Resolve the endpoint read-only before migration.' }
    if (-not $runtimeRedisHost) { $runtimeRedisHost='127.0.0.1' }; if (-not $runtimeRedisPort) { $runtimeRedisPort='6379' }
    if ($runtimeRedisHost -ne 'nexo-redis' -or $runtimeRedisPort -ne '6379') { Fail 'The running NEXO app does not point to the identified nexo-redis:6379 service; refusing to audit or omit the wrong Redis instance.' }
    if (($runtimeRedisUser -and $runtimeRedisUser -notin @('null','')) -or ($runtimeRedisPassword -and $runtimeRedisPassword -notin @('null',''))) { Fail 'The active Redis connection requires authentication. This read-only redis-cli audit cannot safely pass credentials without exposing them to process arguments; use a credential-safe client method before continuing.' }
    $redisConfig = Get-RedisRuntimeConfig $appEnv
    Write-Step ("Read-only Redis classification started (CACHE_STORE={0}, SESSION_DRIVER={1}, QUEUE_CONNECTION={2}; configured Redis DBs {3}/{4}). Key names and values will not be printed." -f $redisConfig.CacheStore,$redisConfig.SessionDriver,$redisConfig.QueueConnection,$redisConfig.RedisDb,$redisConfig.CacheDb)
    Write-Step ("Attribution namespaces: REDIS_PREFIX='{0}', CACHE_PREFIX='{1}'. Only aggregate counts will be reported." -f $redisConfig.RedisPrefix,$redisConfig.CachePrefix)
    $redisAudit = Invoke-RedisReadOnlyAudit ([string](Get-OptionalProperty $redisContainer 'Id')) $OldKey $OldPort $OldUser $OldHost $redisConfig $redisShared
    if ($redisAudit -match '(?m)^REDIS_AUDIT_ERROR\s+(\S+)') { Fail "Redis audit could not safely classify data ($($Matches[1])). No keys were modified or copied." }
    $auditLine = @($redisAudit -split "`n" | Where-Object { $_ -match '^REDIS_AUDIT ' } | Select-Object -Last 1)
    if ($auditLine.Count -ne 1) { Fail 'Read-only Redis audit returned no complete classification record; no Redis data was modified or copied.' }
    $redisCounts = @{}
    foreach ($field in ($auditLine[0] -replace '^REDIS_AUDIT\s+','' -split '\s+')) { if ($field -match '^([a-z_]+)=(.*)$') { $redisCounts[$Matches[1]] = $Matches[2] } }
    $persistenceLine = @($redisAudit -split "`n" | Where-Object { $_ -match '^REDIS_PERSISTENCE ' } | Select-Object -Last 1)
    if ($persistenceLine.Count -ne 1) { Fail 'Read-only Redis audit omitted persistence status.' }
    $databaseKeyLine = @($redisAudit -split "`n" | Where-Object { $_ -match '^REDIS_DB_KEYS(?:\s|$)' } | Select-Object -Last 1)
    if ($databaseKeyLine.Count -ne 1) { Fail 'Read-only Redis audit omitted per-database key counts.' }
    $databaseKeySummary = ($databaseKeyLine[0] -replace '^REDIS_DB_KEYS\s*','')
    if (-not $databaseKeySummary) { $databaseKeySummary='none' }
    $ttlSafetyLine = @($redisAudit -split "`n" | Where-Object { $_ -match '^REDIS_TTL_SAFETY ' } | Select-Object -Last 1)
    if ($ttlSafetyLine.Count -ne 1) { Fail 'Read-only Redis audit omitted cache TTL safety counts.' }
    $redisTtlSafety = @{}
    foreach ($field in ($ttlSafetyLine[0] -replace '^REDIS_TTL_SAFETY\s+','' -split '\s+')) { if ($field -match '^([a-z_]+)=(.*)$') { $redisTtlSafety[$Matches[1]] = $Matches[2] } }
    foreach ($required in @('cache_persistent','rate_persistent')) { if (-not $redisTtlSafety.ContainsKey($required)) { Fail "Redis TTL audit omitted '$required'." } }
    $redisCounts['cache_persistent'] = $redisTtlSafety.cache_persistent
    $redisCounts['rate_persistent'] = $redisTtlSafety.rate_persistent
    $outboxLine = @($redisAudit -split "`n" | Where-Object { $_ -match '^REDIS_OUTBOX ' } | Select-Object -Last 1)
    $heartbeatLine = @($redisAudit -split "`n" | Where-Object { $_ -match '^REDIS_HEARTBEAT ' } | Select-Object -Last 1)
    if ($outboxLine.Count -ne 1 -or $heartbeatLine.Count -ne 1) { Fail 'Redis audit omitted NEXO outbox or worker-heartbeat classification.' }
    foreach ($field in ($outboxLine[0] -replace '^REDIS_OUTBOX\s+','' -split '\s+')) { if ($field -match '^([a-z_]+)=(.*)$') { $redisCounts["outbox_$($Matches[1])"] = $Matches[2] } }
    foreach ($field in ($heartbeatLine[0] -replace '^REDIS_HEARTBEAT\s+','' -split '\s+')) { if ($field -match '^([a-z_]+)=(.*)$') { $redisCounts["heartbeat_$($Matches[1])"] = $Matches[2] } }
    foreach ($required in @('outbox_stream_keys','outbox_stream_entries','outbox_dedupe_keys','outbox_dedupe_entries','outbox_unknown_types','heartbeat_keys','heartbeat_bad_ttl')) { if (-not $redisCounts.ContainsKey($required)) { Fail "Redis data classification omitted '$required'." } }
    $redisPersistence = @{}
    foreach ($field in ($persistenceLine[0] -replace '^REDIS_PERSISTENCE\s+','' -split '\s+')) { if ($field -match '^([a-z_]+)=(.*)$') { $redisPersistence[$Matches[1]] = $Matches[2] } }
    foreach ($required in @('aof_enabled','appendonly','appendfsync','rdb_save','rdb_active','aof_rewrite_active')) { if (-not $redisPersistence.ContainsKey($required)) { Fail "Redis persistence audit omitted '$required'." } }
    foreach ($required in @('shared','redis_version','db_count','key_count','cache','rate_limits','pending_jobs','delayed_jobs','reserved_jobs','queue_signals','sessions','unknown','queue_unknown','ttl_expiring','ttl_persistent','ttl_min_ms','ttl_max_ms')) { if (-not $redisCounts.ContainsKey($required)) { Fail "Redis audit omitted required field '$required'; no Redis data was modified or copied." } }
    Write-Step ("Redis audit: shared={0}, version={1}, DBs={2} [{3}], NEXO keys={4}, cache={5} (persistent={6}), rate-limit={7} (persistent={8}), pending jobs={9}, delayed jobs={10}, reserved jobs={11}, queue notification signals={12}, sessions/other={13}, unknown={14}, unknown queue types={15}, TTL expiring/persistent={16}/{17}, TTL range ms={18}..{19}." -f $redisCounts.shared,$redisCounts.redis_version,$redisCounts.db_count,$databaseKeySummary,$redisCounts.key_count,$redisCounts.cache,$redisCounts.cache_persistent,$redisCounts.rate_limits,$redisCounts.rate_persistent,$redisCounts.pending_jobs,$redisCounts.delayed_jobs,$redisCounts.reserved_jobs,$redisCounts.queue_signals,$redisCounts.sessions,$redisCounts.unknown,$redisCounts.queue_unknown,$redisCounts.ttl_expiring,$redisCounts.ttl_persistent,$redisCounts.ttl_min_ms,$redisCounts.ttl_max_ms)
    Write-Step ("NEXO Redis outbox state: stream keys={0}, stream entries={1}, dedupe hashes={2}, dedupe markers={3}, unexpected types={4}; queue heartbeat keys={5}, invalid heartbeat TTL={6}." -f $redisCounts.outbox_stream_keys,$redisCounts.outbox_stream_entries,$redisCounts.outbox_dedupe_keys,$redisCounts.outbox_dedupe_entries,$redisCounts.outbox_unknown_types,$redisCounts.heartbeat_keys,$redisCounts.heartbeat_bad_ttl)
    Write-Step "NEXO-owned Redis key counts by database: $databaseKeySummary."
    $redisVolumeMounts = @((Get-OptionalProperty $redisContainer 'Mounts') | ForEach-Object { $mountType=[string](Get-OptionalProperty $_ 'Type'); $mountName=[string](Get-OptionalProperty $_ 'Name'); $mountDestination=[string](Get-OptionalProperty $_ 'Destination'); if ($mountType -eq 'volume') { '{0}:{1}->{2}' -f $mountType,$mountName,$mountDestination } elseif ($mountDestination) { '{0}->{1}' -f $mountType,$mountDestination } })
    Write-Step ("Redis persistence: INFO AOF enabled={0}, CONFIG appendonly={1}, appendfsync={2}, RDB save rules={3}, RDB save active={4}, AOF rewrite active={5}, mounts={6}." -f $redisPersistence.aof_enabled,$redisPersistence.appendonly,$redisPersistence.appendfsync,$redisPersistence.rdb_save,$redisPersistence.rdb_active,$redisPersistence.aof_rewrite_active,(@($redisVolumeMounts) -join ','))
    $redisDisposition = Get-RedisMigrationDisposition $redisCounts
    if ($redisDisposition -eq 'stop_unclassified') { Fail "Old NEXO Redis audit found unclassified=$($redisCounts.unknown), unexpected_queue_type=$($redisCounts.queue_unknown), outbox_type_mismatch=$($redisCounts.outbox_unknown_types), invalid_heartbeat_ttl=$($redisCounts.heartbeat_bad_ttl), cache_without_TTL=$($redisCounts.cache_persistent), rate_limit_without_TTL=$($redisCounts.rate_persistent). These entries cannot be treated as disposable; identify their semantics before continuing. No key names/values were displayed or modified." }
    $nonCacheRedis = ([int]$redisCounts.pending_jobs + [int]$redisCounts.delayed_jobs + [int]$redisCounts.reserved_jobs + [int]$redisCounts.sessions + [int]$redisCounts.outbox_stream_entries + [int]$redisCounts.outbox_dedupe_entries)
    if ($redisDisposition -eq 'confirm_source_retained') {
        Write-Host 'Redis data that will remain unchanged on the old server:'
        Write-Host ("  Queue work: pending={0}, delayed={1}, reserved={2}. These jobs will not be copied to staging; copying a live queue can duplicate or lose work. The new queue worker and scheduler stay stopped." -f $redisCounts.pending_jobs,$redisCounts.delayed_jobs,$redisCounts.reserved_jobs)
        Write-Host ("  Session/other keys={0}. These keys will remain on the old server; the private staging copy will start without those Redis sessions/state." -f $redisCounts.sessions)
        Write-Host ("  Outbox state: {0} stream event(s) and {1} deduplication marker(s) remain on the old server. The destination database includes its outbox table snapshot, but its scheduler remains stopped so it cannot republish until cutover reconciliation." -f $redisCounts.outbox_stream_entries,$redisCounts.outbox_dedupe_entries)
        Write-Host '  The old Redis service and data remain untouched; staging will use a separate empty Redis volume.'
        if (-not $KeepOldRedisOnly) {
            $redisApproval = Read-Host 'To proceed with private staging while retaining these Redis keys only on the old server, type KEEP-OLD-REDIS-ONLY (anything else stops)'
            if ($redisApproval -cne 'KEEP-OLD-REDIS-ONLY') { Fail 'Redis preservation decision was not approved; no backups, transfers, or deployment were started.' }
        } else { Write-Step 'Using the explicit -KeepOldRedisOnly approval: Redis keys remain unchanged on the old server and are excluded from staging.' }
    } elseif ($redisDisposition -eq 'exclude_disposable') {
        Write-Step 'Only disposable cache/rate-limit entries were found; they will be excluded from the new instance. Existing source keys remain untouched.'
    } else {
        Write-Step 'No Redis data requiring migration was found. The destination Redis starts empty; queue and scheduler remain stopped.'
    }
    if ($EnableBackgroundProcessing) { Fail 'Initial migration staging must keep queue and scheduler disabled. Remove -EnableBackgroundProcessing; background processing requires a separately approved cutover.' }
    $filesDisk = Get-EnvValue $appEnv 'FILESYSTEM_DISK'; if (-not $filesDisk) { $filesDisk='local' }
    $mediaDisk = Get-EnvValue $appEnv 'MEDIA_DISK'; if (-not $mediaDisk) { $mediaDisk='local' }
    if ($filesDisk -ne 'local' -or $mediaDisk -ne 'local') { Fail "NEXO uses external storage (FILESYSTEM_DISK=$filesDisk, MEDIA_DISK=$mediaDisk). This orchestrator will not silently omit remote objects; prepare a verified storage migration first." }
    $appMount = @( (Get-OptionalProperty $appContainer 'Mounts') | Where-Object { (Get-OptionalProperty $_ 'Destination') -match '/storage$' })
    if ($appMount.Count -ne 1) { Fail "Could not uniquely identify the NEXO persistent storage mount on the old app container (found $($appMount.Count))." }
    $workingDir = $pg[0].Info.WorkDir
    $configPath = $pg[0].Info.ConfigFiles -split ',' | Select-Object -First 1
    if (-not $configPath) { Fail 'Old Compose config path is unavailable; cannot identify the matching NEXO .env safely.' }
    $oldEnvPath = ($configPath -replace '/[^/]+$','') + '/.env'
    $oldPgId = Get-OptionalProperty $pgContainer 'Id'; $oldAppId = Get-OptionalProperty $appContainer 'Id'; $storagePath = Get-OptionalProperty $appMount[0] 'Destination'
    if (-not $oldPgId -or -not $oldAppId -or -not $storagePath) { Fail 'Required Docker container ID or storage mount metadata is missing.' }
    foreach ($containerId in @($oldPgId,$oldAppId,(Get-OptionalProperty $redisContainer 'Id'))) { if ([string]$containerId -notmatch '^[a-f0-9]{12,64}$') { Fail 'Old-server Docker container ID is malformed; refusing remote command interpolation.' } }
    $sourceVersionScript = @"
set -euo pipefail
docker exec '$oldPgId' psql -U '$dbUser' -d '$dbName' -At -F '|' -v ON_ERROR_STOP=1 -c "SELECT (current_setting('server_version_num')::int / 10000), postgis_lib_version()"
"@
    $sourceVersionOutput = Invoke-Remote $OldKey $OldPort $OldUser $OldHost $sourceVersionScript 'Read-only PostgreSQL/PostGIS source compatibility check'
    $sourceVersionMatch = [regex]::Match($sourceVersionOutput,'(?m)^([0-9]+)\|([0-9]+\.[0-9]+(?:\.[0-9]+)?)$')
    if (-not $sourceVersionMatch.Success) { Fail 'Could not determine source PostgreSQL/PostGIS versions with a read-only query.' }
    $sourcePgMajor = $sourceVersionMatch.Groups[1].Value
    $sourcePostgisVersion = $sourceVersionMatch.Groups[2].Value
    if ($sourcePgMajor -ne '16' -or $sourcePostgisVersion -notmatch '^3\.4(?:\.|$)') { Fail "Source PostgreSQL/PostGIS ($sourcePgMajor/$sourcePostgisVersion) does not match the destination postgis/postgis:16-3.4 compatibility target; stop for an explicit compatibility review." }
    $sourcePostgisSeries = ($sourcePostgisVersion.Split('.')[0..1] -join '.')
    foreach ($remotePath in @($storagePath,$oldEnvPath)) { if ([string]$remotePath -notmatch '^/[A-Za-z0-9/._-]+$') { Fail 'A discovered old-server path contains unsupported characters; refusing remote command interpolation.' } }
    $localDump = Join-Path $script:LocalWork 'nexo.dump'; $localStorage = Join-Path $script:LocalWork 'storage.tgz'; $localEnv = Join-Path $script:LocalWork 'source.env'
    $reuseBackup = $false
    if ($script:PreviousState -and $script:PreviousState.BackupReady) {
        foreach ($backupFile in @($localDump,$localStorage,$localEnv)) { if (-not (Test-Path -LiteralPath $backupFile -PathType Leaf)) { Fail 'Saved migration state exists but a retained local backup file is missing; refusing to recreate or overwrite a checkpoint implicitly.' } }
        $sourceDumpHash = (Get-FileHash -LiteralPath $localDump -Algorithm SHA256).Hash.ToLowerInvariant()
        $sourceStorageHash = (Get-FileHash -LiteralPath $localStorage -Algorithm SHA256).Hash.ToLowerInvariant()
        $sourceEnvHash = (Get-FileHash -LiteralPath $localEnv -Algorithm SHA256).Hash.ToLowerInvariant()
        if ($sourceDumpHash -ne $script:PreviousState.DumpSha256 -or $sourceStorageHash -ne $script:PreviousState.StorageSha256) { Fail 'Retained local backup checksum differs from the verified migration checkpoint.' }
        if ($script:PreviousState.PSObject.Properties['EnvSha256'] -and $sourceEnvHash -ne $script:PreviousState.EnvSha256) { Fail 'Retained local environment backup checksum differs from the verified migration checkpoint.' }
        $reuseBackup = $true
        Write-Step 'Reusing the previously verified local PostgreSQL/storage backup checkpoint; no second source backup will be created.'
    } else {
        $streamPrelude = Get-RemoteStreamPrelude
        $streamRuntimeCheck = @'
set -euo pipefail
__STREAM_PRELUDE__
if command -v python3 >/dev/null 2>&1 && python3 -c 'import hashlib, subprocess, sys, threading' >/dev/null 2>&1; then
  stream_runtime=python3
elif command -v perl >/dev/null 2>&1 && perl -MDigest::SHA -e 'exit 0' >/dev/null 2>&1; then
  stream_runtime=perl
else
  printf '%s\n' 'No supported byte-stream wrapper (python3 or perl with Digest::SHA) is available.' >&2
  exit 69
fi
empty_marker=$(stream_with_sha256 true 2>&1 >/dev/null)
[ "$empty_marker" = 'STREAM_SHA256=e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855' ]
large_marker=$(stream_with_sha256 head -c 4194304 /dev/zero 2>&1 >/dev/null)
[[ "$large_marker" =~ ^STREAM_SHA256=[a-f0-9]{64}$ ]]
failure_marker=''
failure_status=0
if failure_marker=$(stream_with_sha256 sh -c 'printf x; exit 23' 2>&1 >/dev/null); then
  printf '%s\n' 'Stream wrapper accepted a failing producer.' >&2
  exit 73
else
  failure_status=$?
fi
[ "$failure_status" -eq 23 ]
if printf '%s\n' "$failure_marker" | grep -q '^STREAM_SHA256='; then
  printf '%s\n' 'Stream wrapper emitted a success marker for a failing producer.' >&2
  exit 74
fi
printf 'STREAM_RUNTIME=%s\nSTREAM_WRAPPER_REGRESSION=PASS\n' "$stream_runtime"
'@
        $streamRuntimeCheck = $streamRuntimeCheck.Replace('__STREAM_PRELUDE__',$streamPrelude)
        $streamRuntime = Invoke-Remote $OldKey $OldPort $OldUser $OldHost $streamRuntimeCheck 'Check read-only source streaming runtime'
        $streamRuntimeMatch = [regex]::Match($streamRuntime,'(?m)^STREAM_RUNTIME=(python3|perl)$')
        if (-not $streamRuntimeMatch.Success -or $streamRuntime -notmatch '(?m)^STREAM_WRAPPER_REGRESSION=PASS$') { Fail 'Old server did not pass the deterministic stream wrapper regression checks.' }
        Write-Step "Verified deterministic source streaming wrapper: $($streamRuntimeMatch.Groups[1].Value)."
        $dumpScript = @'
set -euo pipefail
__STREAM_PRELUDE__
stream_with_sha256 docker exec '__PG_ID__' pg_dump -Fc -U '__DB_USER__' -d '__DB_NAME__'
'@
        $dumpScript = $dumpScript.Replace('__STREAM_PRELUDE__',$streamPrelude).Replace('__PG_ID__',$oldPgId).Replace('__DB_USER__',$dbUser).Replace('__DB_NAME__',$dbName)
        $storageScript = @'
set -euo pipefail
__STREAM_PRELUDE__
stream_with_sha256 docker exec '__APP_ID__' tar -czf - -C '__STORAGE_PATH__' .
'@
        $storageScript = $storageScript.Replace('__STREAM_PRELUDE__',$streamPrelude).Replace('__APP_ID__',$oldAppId).Replace('__STORAGE_PATH__',$storagePath)
        $envScript = @'
set -euo pipefail
__STREAM_PRELUDE__
stream_with_sha256 cat -- '__ENV_PATH__'
'@
        $envScript = $envScript.Replace('__STREAM_PRELUDE__',$streamPrelude).Replace('__ENV_PATH__',$oldEnvPath)
        Write-Step "Streaming read-only PostgreSQL, storage, and environment backups from old NEXO project '$oldProject' to the private local workspace. No backup files will be written on the old VPS."
        $sourceDumpHash = Get-StreamCheckpointHash 'DumpSha256' $localDump 'NEXO PostgreSQL backup'
        if (-not $sourceDumpHash) {
            $dumpResult = Invoke-RemoteBinary $OldKey $OldPort $OldUser $OldHost $dumpScript $localDump 'Stream NEXO PostgreSQL backup'
            $sourceDumpHash = $dumpResult.Sha256; $script:StreamState.DumpSha256 = $sourceDumpHash; Save-StreamState $script:StreamState
        } else { Write-Step 'Reusing checksum-verified PostgreSQL stream checkpoint.' }
        $sourceStorageHash = Get-StreamCheckpointHash 'StorageSha256' $localStorage 'NEXO storage backup'
        if (-not $sourceStorageHash) {
            $storageResult = Invoke-RemoteBinary $OldKey $OldPort $OldUser $OldHost $storageScript $localStorage 'Stream NEXO storage backup'
            $sourceStorageHash = $storageResult.Sha256; $script:StreamState.StorageSha256 = $sourceStorageHash; Save-StreamState $script:StreamState
        } else { Write-Step 'Reusing checksum-verified storage stream checkpoint.' }
        $sourceEnvHash = Get-StreamCheckpointHash 'EnvSha256' $localEnv 'NEXO environment backup'
        if (-not $sourceEnvHash) {
            $envResult = Invoke-RemoteBinary $OldKey $OldPort $OldUser $OldHost $envScript $localEnv 'Stream NEXO environment for private transformation'
            $sourceEnvHash = $envResult.Sha256; $script:StreamState.EnvSha256 = $sourceEnvHash; Save-StreamState $script:StreamState
        } else { Write-Step 'Reusing checksum-verified environment stream checkpoint.' }
    }
    $localDumpHash = (Get-FileHash -LiteralPath $localDump -Algorithm SHA256).Hash.ToLowerInvariant()
    $localStorageHash = (Get-FileHash -LiteralPath $localStorage -Algorithm SHA256).Hash.ToLowerInvariant()
    $localEnvHash = (Get-FileHash -LiteralPath $localEnv -Algorithm SHA256).Hash.ToLowerInvariant()
    if ($localDumpHash -ne $sourceDumpHash -or $localStorageHash -ne $sourceStorageHash -or $localEnvHash -ne $sourceEnvHash) { Fail 'A streamed source backup checksum did not match its local copy.' }
    if ((Get-Item -LiteralPath $localDump).Length -lt 5 -or (Get-Item -LiteralPath $localStorage).Length -lt 2 -or (Get-Item -LiteralPath $localEnv).Length -eq 0) { Fail 'A streamed backup is empty or too short to be valid.' }
    $dumpStream = [IO.File]::OpenRead($localDump); $dumpHeader = New-Object byte[] 5; [void]$dumpStream.Read($dumpHeader,0,5); $dumpStream.Dispose()
    if ([Text.Encoding]::ASCII.GetString($dumpHeader) -ne 'PGDMP') { Fail 'PostgreSQL custom-format dump header is invalid.' }
    $storageStream = [IO.File]::OpenRead($localStorage); $storageHeader = New-Object byte[] 2; [void]$storageStream.Read($storageHeader,0,2); $storageStream.Dispose()
    if ($storageHeader[0] -ne 31 -or $storageHeader[1] -ne 139) { Fail 'Application storage stream is not a valid gzip archive.' }
    $envLines = @(Get-Content -LiteralPath $localEnv)
    $sourceKey = Get-EnvValue $envLines 'APP_KEY'
    if ($sourceKey -ne $appKey) { Fail 'Downloaded .env APP_KEY does not match the running old NEXO app key.' }
    $sourceDb = Get-EnvValue $envLines 'DB_DATABASE'; $sourceUser = Get-EnvValue $envLines 'DB_USERNAME'
    if ($sourceDb -and $sourceDb -ne $dbName) { Fail 'Old .env database name disagrees with the running PostgreSQL environment.' }
    if ($sourceUser -and $sourceUser -ne $dbUser) { Fail 'Old .env database user disagrees with the running PostgreSQL environment.' }
    $deploymentStarted = $false
    if ($script:PreviousState) { $deploymentStarted = [bool]$script:PreviousState.DeploymentStarted }
    $state = @{RunId=$script:RunId;TargetSha=$TargetSha;OldHost=$OldHost;NewHost=$NewHost;Database=$dbName;DatabaseUser=$dbUser;DumpSha256=$localDumpHash;StorageSha256=$localStorageHash;EnvSha256=$localEnvHash;OldProject=$oldProject;BackupReady=$true;BackupReused=$reuseBackup;RedisAudit=$auditLine[0];RedisPersistence=$persistenceLine[0];RedisDataRetainedOnOld=($nonCacheRedis -gt 0);RedisOutboxEntries=$redisCounts.outbox_stream_entries;RedisOutboxDedupeEntries=$redisCounts.outbox_dedupe_entries;ImagesBuilt=$false;DeploymentStarted=$deploymentStarted;Created=(Get-Date).ToString('o')}
    Save-State $state

    Write-Step 'Checking destination identity, capacity, Docker state, existing NEXO resources and loopback port.'
    $newPreflight = @'
set -euo pipefail
source /etc/os-release
test "${VERSION_CODENAME:-}" = jammy
test "$(uname -m)" = x86_64
docker info >/dev/null
test "$(df -Pk /opt | awk 'NR==2 {print $4}')" -ge 10485760
test "$(awk '/MemAvailable:/ {print $2}' /proc/meminfo)" -ge 1572864
resume_ok=false
if [ -f '__RELEASE__/.migration_run_id' ] && [ "$(cat '__RELEASE__/.migration_run_id')" = '__RUN_ID__' ]; then resume_ok=true; fi
if ss -ltn | awk '{print $4}' | grep -Eq '(^|:)18100$'; then
  [ "$resume_ok" = true ] || { echo 'PORT_18100_BUSY'; exit 21; }
  docker ps --format '{{.Names}} {{.Ports}}' | grep -Eq '^nexo-nginx .*127\.0\.0\.1:18100->' || { echo 'PORT_18100_BUSY_NOT_OWNED_BY_THIS_RELEASE'; exit 21; }
fi
if [ -e '__RELEASE__' ] && [ "$(find '__RELEASE__' -mindepth 1 -maxdepth 1 2>/dev/null | wc -l)" -gt 0 ] && [ "$resume_ok" != true ]; then echo 'RELEASE_NOT_EMPTY'; exit 24; fi
if docker ps -a --format '{{.Names}}' | grep -Eq '^(nexo-|nexo$)'; then [ "$resume_ok" = true ] || { echo 'NEXO_CONTAINERS_EXIST'; exit 22; }; fi
if docker ps -aq --filter label=com.docker.compose.project=nexo | grep -q .; then [ "$resume_ok" = true ] || { echo 'NEXO_CONTAINERS_EXIST'; exit 22; }; fi
if docker volume ls --format '{{.Name}}' | grep -Eq '^nexo_(postgres_data|app_storage|redis_data)$'; then [ "$resume_ok" = true ] || { echo 'NEXO_VOLUMES_EXIST'; exit 23; }; fi
printf 'PREFLIGHT_OK\n'
'@

    # Create immutable source tar from the exact commit, never from the dirty working tree.
    $sourceTar = Join-Path $script:LocalWork 'source.tar'
    $paths = @('.dockerignore','Dockerfile','artisan','composer.json','composer.lock','app','bootstrap','config','database','docker','public','resources','routes','control-center','docker-compose.production.yml')
    $git = Require-Command 'git.exe'
    # Use git archive as a temporary file to preserve binary bytes on Windows PowerShell 5.1.
    $archivePath = Join-Path $script:LocalWork 'source.git.tar'
    $psiGit = New-Object Diagnostics.ProcessStartInfo; $psiGit.FileName=$git; $psiGit.Arguments="-C `"$Repository`" archive --format=tar $TargetSha -- " + ($paths -join ' '); $psiGit.UseShellExecute=$false; $psiGit.RedirectStandardOutput=$true; $psiGit.RedirectStandardError=$true
    $pgit=New-Object Diagnostics.Process; $pgit.StartInfo=$psiGit; [void]$pgit.Start(); $fs=[IO.File]::Create($archivePath); $pgit.StandardOutput.BaseStream.CopyTo($fs); $fs.Dispose(); $gitErr=$pgit.StandardError.ReadToEnd(); $pgit.WaitForExit(); if($pgit.ExitCode -ne 0){Fail "Pinned git archive failed: $gitErr"}
    $sourceHash = (Get-FileHash -LiteralPath $archivePath -Algorithm SHA256).Hash.ToLowerInvariant()
    $preflightOut = Invoke-DestinationPreflight { param($k,$p,$u,$h,$payload,$label) Invoke-Remote $k $p $u $h $payload $label } $NewKey $NewPort $NewUser $NewHost $script:Release $script:RunId $newPreflight
    $newRemoteDir = "$script:Release"
    $transferScript = @"
set -euo pipefail
install -d -m 700 '$newRemoteDir'
printf '%s\n' '$script:RunId' > '$newRemoteDir/.migration_run_id'
chmod 600 '$newRemoteDir/.migration_run_id'
"@
    Invoke-Remote $NewKey $NewPort $NewUser $NewHost $transferScript 'Create isolated destination release directory' | Out-Null
    Invoke-Scp $NewKey $NewPort $archivePath "$NewUser@$NewHost`:$newRemoteDir/source.tar" 'Transfer pinned application source'
    Invoke-Scp $NewKey $NewPort $localDump "$NewUser@$NewHost`:$newRemoteDir/nexo.dump" 'Transfer verified PostgreSQL backup'
    Invoke-Scp $NewKey $NewPort $localStorage "$NewUser@$NewHost`:$newRemoteDir/storage.tgz" 'Transfer verified application storage'
    $envOutPath = Join-Path $script:LocalWork 'destination.env'
    $newDbPassword = $null
    if ($ResumeRunId -and (Test-Path -LiteralPath $envOutPath -PathType Leaf)) { $newDbPassword = Get-EnvValue (Get-Content -LiteralPath $envOutPath) 'DB_PASSWORD' }
    if (-not $newDbPassword) { $newDbPassword = [Convert]::ToBase64String((1..48 | ForEach-Object { [byte](Get-Random -Minimum 0 -Maximum 256) })) }
    # Rewrite only known connection values in a private copy; preserve APP_KEY and unrelated app integrations.
    $outEnv = New-Object Collections.Generic.List[string]
    $replaced = @{}
    foreach ($line in $envLines) {
        if ($line -match '^\s*(DB_HOST|DB_PORT|DB_DATABASE|DB_USERNAME|DB_PASSWORD|DB_URL|REDIS_HOST|REDIS_PORT|REDIS_URL|REDIS_USERNAME|REDIS_PASSWORD|NEXO_HTTP_PORT)\s*=') { $keyName=$Matches[1]; if ($replaced.ContainsKey($keyName)) { continue }; $replaced[$keyName]=$true
            switch ($keyName) {
                'DB_HOST' { $outEnv.Add('DB_HOST=nexo-postgres') }
                'DB_PORT' { $outEnv.Add('DB_PORT=5432') }
                'DB_DATABASE' { $outEnv.Add("DB_DATABASE=$dbName") }
                'DB_USERNAME' { $outEnv.Add("DB_USERNAME=$dbUser") }
                'DB_PASSWORD' { $outEnv.Add("DB_PASSWORD=$newDbPassword") }
                'DB_URL' { $outEnv.Add('DB_URL=') }
                'REDIS_HOST' { $outEnv.Add('REDIS_HOST=nexo-redis') }
                'REDIS_PORT' { $outEnv.Add('REDIS_PORT=6379') }
                'REDIS_URL' { $outEnv.Add('REDIS_URL=null') }
                'REDIS_USERNAME' { $outEnv.Add('REDIS_USERNAME=null') }
                'REDIS_PASSWORD' { $outEnv.Add('REDIS_PASSWORD=null') }
                'NEXO_HTTP_PORT' { $outEnv.Add('NEXO_HTTP_PORT=18100') }
            }
        } else { $outEnv.Add($line) }
    }
    foreach ($pair in @(@('DB_HOST','nexo-postgres'),@('DB_PORT','5432'),@('DB_DATABASE',$dbName),@('DB_USERNAME',$dbUser),@('DB_PASSWORD',$newDbPassword),@('REDIS_HOST','nexo-redis'),@('REDIS_PORT','6379'),@('REDIS_URL','null'),@('REDIS_USERNAME','null'),@('REDIS_PASSWORD','null'),@('NEXO_HTTP_PORT','18100'))) { if (-not $replaced.ContainsKey($pair[0])) { $outEnv.Add("$($pair[0])=$($pair[1])") } }
    if (-not $replaced.ContainsKey('DB_URL')) { $outEnv.Add('DB_URL=') }
    [IO.File]::WriteAllLines($envOutPath,$outEnv,(New-Object Text.UTF8Encoding($false)))
    Invoke-Scp $NewKey $NewPort $envOutPath "$NewUser@$NewHost`:$newRemoteDir/.env" 'Transfer protected destination environment'
    $remoteDeploy = @"
set -euo pipefail
cd '$newRemoteDir'
printf '%s  %s\n' '$sourceHash' source.tar | sha256sum -c -
printf '%s  %s\n' '$localDumpHash' nexo.dump | sha256sum -c -
printf '%s  %s\n' '$localStorageHash' storage.tgz | sha256sum -c -
tar -xf source.tar
chmod 600 .env
install -d -m 700 /opt/nexo/backups /opt/nexo/logs
install -m 600 nexo.dump '/opt/nexo/backups/nexo-$($script:RunId).dump'
install -m 600 storage.tgz '/opt/nexo/backups/nexo-$($script:RunId)-storage.tgz'
cat > docker-compose.migration.yml <<'YAML'
services:
  postgres:
    mem_limit: 1g
    memswap_limit: 2g
YAML
chmod 600 docker-compose.migration.yml
if ! docker image inspect postgis/postgis:16-3.4 >/dev/null 2>&1; then docker pull postgis/postgis:16-3.4; fi
docker run --rm --network none -v "${newRemoteDir}:/backup:ro" postgis/postgis:16-3.4 pg_restore --list /backup/nexo.dump >/dev/null
docker compose -p nexo -f docker-compose.production.yml -f docker-compose.migration.yml config --quiet
"@
    # Image building and deployment follow only after source/archive validation; all builds are serial.
    if ($EnableBackgroundProcessing) { Write-Step 'Background processing explicitly enabled. Queue/scheduler may duplicate old-instance scheduled side effects until cutover.' } else { Write-Step 'Queue and scheduler will remain stopped by default to prevent duplicate background side effects while the old instance remains active.' }
    Invoke-Remote $NewKey $NewPort $NewUser $NewHost $remoteDeploy 'Validate and prepare destination source/data' | Out-Null
    $buildResult = Invoke-DestinationBuild { param($k,$p,$u,$h,$payload,$label) Invoke-Remote $k $p $u $h $payload $label } $NewKey $NewPort $NewUser $NewHost $newRemoteDir $TargetSha $sourceHash
    if ($buildResult -match 'BUILD_REUSED') { Write-Step 'Reused the completed image-build checkpoint; no Docker build was repeated.' }
    $state.ImagesBuilt=$true
    $state.DeploymentStarted=$true; Save-State $state

    # Compose uses the just-built image tags; --no-build prevents implicit, unbounded rebuilds.
    if (-not $EnableBackgroundProcessing) {
        $applicationServices = 'app control nginx'
        $backgroundServices = ''
    } else { $applicationServices = 'app control nginx'; $backgroundServices = 'queue scheduler' }
    $startScript = @"
set -euo pipefail
cd '$script:Release'
docker compose -p nexo -f docker-compose.production.yml -f docker-compose.migration.yml config --quiet
for name in nexo-app nexo-control nexo-nginx nexo-queue nexo-scheduler; do
  if docker inspect "`$name" >/dev/null 2>&1 && [ "`$(docker inspect --format '{{.State.Running}}' "`$name")" = true ]; then echo "REFUSE_RUNNING_APPLICATION_SERVICE=`$name"; exit 87; fi
done
if docker inspect nexo-postgres >/dev/null 2>&1; then
  old_project=`$(docker inspect --format '{{index .Config.Labels "com.docker.compose.project"}}' nexo-postgres)
  old_volume=`$(docker inspect --format '{{range .Mounts}}{{if eq .Destination "/var/lib/postgresql/data"}}{{.Name}}{{end}}{{end}}' nexo-postgres)
  test "`$old_project" = nexo || { echo 'REFUSE_UNKNOWN_POSTGRES_CONTAINER'; exit 71; }
  test "`$old_volume" = nexo_postgres_data || { echo 'REFUSE_UNKNOWN_POSTGRES_VOLUME'; exit 72; }
fi
available_kb=`$(awk '/MemAvailable:/ {print `$2}' /proc/meminfo)
swap_free_kb=`$(awk '/SwapFree:/ {print `$2}' /proc/meminfo)
test "`$available_kb" -ge 1572864 || { echo "RESTORE_HOST_RAM_GATE=`$available_kb"; exit 70; }
test "`$swap_free_kb" -ge 524288 || { echo "RESTORE_HOST_SWAP_GATE=`$swap_free_kb"; exit 70; }
docker compose -p nexo -f docker-compose.production.yml -f docker-compose.migration.yml up -d --no-build postgres redis
for i in `$(seq 1 60); do docker exec nexo-postgres pg_isready -U '$dbUser' -d '$dbName' >/dev/null 2>&1 && break; sleep 2; done
docker exec nexo-postgres pg_isready -U '$dbUser' -d '$dbName' >/dev/null
memory_bytes=`$(docker inspect --format '{{.HostConfig.Memory}}' nexo-postgres)
swap_bytes=`$(docker inspect --format '{{.HostConfig.MemorySwap}}' nexo-postgres)
test "`$memory_bytes" -eq 1073741824 || { echo "POSTGRES_MEMORY_LIMIT_UNEXPECTED=`$memory_bytes"; exit 73; }
test "`$swap_bytes" -eq 2147483648 || { echo "POSTGRES_MEMORY_SWAP_LIMIT_UNEXPECTED=`$swap_bytes"; exit 74; }
server_major=`$(docker exec nexo-postgres postgres --version | sed -E 's/.* ([0-9]+)(\..*)?/\1/')
test "`$server_major" = 16 || { echo "POSTGRES_MAJOR_VERSION_MISMATCH=`$server_major"; exit 75; }
restore_marker='$TargetSha $localDumpHash'
if [ -f .database-restored ] && [ "`$(cat .database-restored)" != "`$restore_marker" ]; then echo 'RESTORE_MARKER_MISMATCH'; exit 76; fi
if [ ! -f .database-restored ]; then
  dump='/opt/nexo/backups/nexo-$($script:RunId).dump'
  list_file='$script:Release/restore-$($script:RunId).list'
__RESTORE_NAME_ASSIGNMENTS__
  promotion_approved='__DATABASE_PROMOTION_APPROVED__'
  prior_restore_dbs=`$(docker exec nexo-postgres psql -U '$dbUser' -d postgres -Atqc "SELECT datname FROM pg_database WHERE left(datname, length('`$restore_db_prefix')) = '`$restore_db_prefix' ORDER BY datname")
  prior_restore_count=`$(printf '%s\n' "`$prior_restore_dbs" | awk 'NF { n++ } END { print n+0 }')
  if [ "`$prior_restore_count" -gt 1 ]; then echo 'MULTIPLE_RESTORE_DATABASES_REQUIRE_MANUAL_SELECTION'; printf 'RESTORE_DATABASE=%s\n' "`$prior_restore_dbs"; exit 90; fi
  restore_reuse=false
  if [ "`$prior_restore_count" -eq 1 ]; then
    case "`$prior_restore_dbs" in "`$restore_db_prefix"[A-Za-z0-9_]*) ;; *) echo "RESTORE_DATABASE_NAME_UNEXPECTED=`$prior_restore_dbs"; exit 90 ;; esac
    restore_db="`$prior_restore_dbs"
    restore_reuse=true
    echo "REUSING_EXISTING_RESTORE_DATABASE=`$restore_db"
  fi
  postgres_image=`$(docker inspect --format '{{.Config.Image}}' nexo-postgres)
  test "`$postgres_image" = 'postgis/postgis:16-3.4' || { echo "POSTGRES_IMAGE_MISMATCH=`$postgres_image"; exit 91; }
  destination_image_id=`$(docker image inspect --format '{{.Id}}' postgis/postgis:16-3.4)
  postgres_container_image_id=`$(docker inspect --format '{{.Image}}' nexo-postgres)
  test "`$postgres_container_image_id" = "`$destination_image_id" || { echo "POSTGRES_CONTAINER_IMAGE_ID_MISMATCH container=`$postgres_container_image_id tagged=`$destination_image_id"; exit 91; }
  destination_image_platform=`$(docker image inspect --format '{{.Os}}/{{.Architecture}}' postgis/postgis:16-3.4)
  test "`$destination_image_platform" = 'linux/amd64' || { echo "POSTGIS_IMAGE_PLATFORM_MISMATCH=`$destination_image_platform"; exit 92; }
  docker exec nexo-postgres pg_isready -U '$dbUser' -d postgres >/dev/null || { echo 'POSTGRES_NOT_READY_BEFORE_POSTGIS_PREFLIGHT'; exit 93; }
  available_sql="SELECT default_version, COALESCE(installed_version, '') FROM pg_available_extensions WHERE name = 'postgis'"
  if available_postgis=`$(docker exec nexo-postgres psql -U '$dbUser' -d postgres -v ON_ERROR_STOP=1 -At -F '|' -c "`$available_sql" 2>&1); then available_rc=0; else available_rc=`$?; fi
  printf 'POSTGIS_IMAGE_ID=%s\nPOSTGIS_IMAGE_PLATFORM=%s\nPOSTGIS_AVAILABLE_SQL=%s\nPOSTGIS_AVAILABLE_EXIT=%s\nPOSTGIS_AVAILABLE_RESULT=%s\n' "`$destination_image_id" "`$destination_image_platform" "`$available_sql" "`$available_rc" "`$available_postgis"
  test "`$available_rc" -eq 0 || { echo 'POSTGIS_UNAVAILABLE_CATALOG_QUERY_FAILED'; exit 94; }
  available_series=`$(printf '%s' "`$available_postgis" | head -n 1 | cut -d '|' -f 1 | cut -d '.' -f 1-2)
  test -n "`$available_series" || { echo 'POSTGIS_UNAVAILABLE_IN_DESTINATION_IMAGE'; exit 95; }
  test "`$available_series" = '$sourcePostgisSeries' || { echo "POSTGIS_AVAILABLE_SERIES_MISMATCH source=$sourcePostgisSeries destination=`$available_series"; exit 96; }
  installed_sql="SELECT extversion FROM pg_extension WHERE extname = 'postgis'"
  if installed_postgis=`$(docker exec nexo-postgres psql -U '$dbUser' -d '$dbName' -v ON_ERROR_STOP=1 -At -c "`$installed_sql" 2>&1); then installed_rc=0; else installed_rc=`$?; fi
  printf 'POSTGIS_APP_DATABASE=%s\nPOSTGIS_INSTALLED_SQL=%s\nPOSTGIS_INSTALLED_EXIT=%s\nPOSTGIS_INSTALLED_RESULT=%s\n' '$dbName' "`$installed_sql" "`$installed_rc" "`$installed_postgis"
  list_stderr='$script:Release/pg-restore-list-$($script:RunId).stderr'
  pg_restore_list_command='docker run --rm --network none -v /opt/nexo/backups:/backup:ro postgis/postgis:16-3.4 pg_restore --list /backup/nexo-$($script:RunId).dump'
  if docker run --rm --network none -v '/opt/nexo/backups:/backup:ro' postgis/postgis:16-3.4 pg_restore --list "/backup/nexo-$($script:RunId).dump" > "`$list_file" 2> "`$list_stderr"; then pg_restore_list_rc=0; else pg_restore_list_rc=`$?; fi
  printf 'PG_RESTORE_LIST_COMMAND=%s\nPG_RESTORE_LIST_EXIT=%s\n' "`$pg_restore_list_command" "`$pg_restore_list_rc"
  if [ "`$pg_restore_list_rc" -ne 0 ]; then echo 'PG_RESTORE_LIST_STDERR_BEGIN'; sed -E 's/(password|secret|token|key)[^=]*=.*/\1=<redacted>/Ig' "`$list_stderr" | tail -n 30; echo 'PG_RESTORE_LIST_STDERR_END'; exit 97; fi
  dump_major=`$(sed -n 's/^; *Dumped from database version: \([0-9][0-9]*\).*/\1/p' "`$list_file" | head -n 1)
  test "`$dump_major" = "`$server_major" || { echo "DUMP_POSTGRES_MAJOR_MISMATCH=`$dump_major"; exit 77; }
  postgis_toc_pattern='^[0-9]+;[[:space:]]+.*[[:space:]]EXTENSION - postgis([[:space:]]|$)'
  postgis_entries=`$(grep -Ec "`$postgis_toc_pattern" "`$list_file" || true)
  if [ "`$postgis_entries" -ne 1 ]; then
    echo "EXPECTED_ONE_POSTGIS_EXTENSION_ENTRY_GOT=`$postgis_entries"
    echo "RESTORE_ARCHIVE=`$dump DATABASE=nexo DESTINATION_IMAGE=postgis/postgis:16-3.4"
    echo "PG_RESTORE_LIST_COMMAND=`$pg_restore_list_command"
    echo "PG_RESTORE_LIST_EXIT=`$pg_restore_list_rc"
    echo 'PG_RESTORE_LIST_STDERR_BEGIN'
    sed -E 's/(password|secret|token|key)[^=]*=.*/\1=<redacted>/Ig' "`$list_stderr" | tail -n 30 || true
    echo 'PG_RESTORE_LIST_STDERR_END'
    echo 'POSTGIS_TOC_CANDIDATES_BEGIN'
    grep -Ein 'EXTENSION[[:space:]]+-[[:space:]]+postgis|COMMENT[[:space:]]+-[[:space:]]+EXTENSION[[:space:]]+postgis' "`$list_file" | head -n 20 || true
    echo 'POSTGIS_TOC_CANDIDATES_END'
    echo 'POSTGRES_READINESS_SQL=SELECT 1; DATABASE=postgres'
    if ready_result=`$(docker exec nexo-postgres psql -U '$dbUser' -d postgres -v ON_ERROR_STOP=1 -Atqc 'SELECT 1' 2>&1); then ready_rc=0; else ready_rc=`$?; fi
    printf 'POSTGRES_READINESS_EXIT=%s RESULT=%s\n' "`$ready_rc" "`$ready_result"
    echo "POSTGIS_AVAILABLE_SQL=`$available_sql DATABASE=postgres"
    echo "POSTGIS_INSTALLED_SQL=`$installed_sql DATABASE=$dbName"
    echo 'POSTGRES_LOG_EXCERPTS_BEGIN'
    docker logs --tail 150 nexo-postgres 2>&1 | grep -Ei 'postgis|extension|fatal|error|oom|killed|ready to accept' | sed -E 's/(password|secret|token|app[_-]?key|api[_-]?key|authorization|connection[_-]?string)[[:space:]]*[:=][[:space:]]*[^ ,;]+/\1=<redacted>/Ig' | tail -n 40 || true
    echo 'POSTGRES_LOG_EXCERPTS_END'
    exit 78
  fi
  sed -E -i '/^[0-9]+;[[:space:]]+.*[[:space:]]EXTENSION - postgis([[:space:]]|$)/d; /^[0-9]+;[[:space:]]+.*[[:space:]]COMMENT - EXTENSION postgis([[:space:]]|$)/d' "`$list_file"
  if grep -Eq '^[0-9]+;[[:space:]]+.*[[:space:]](EXTENSION - postgis|COMMENT - EXTENSION postgis)([[:space:]]|$)' "`$list_file"; then echo 'POSTGIS_TOC_FILTER_FAILED'; exit 79; fi
  expected_tables_file='$script:Release/restore-$($script:RunId)-expected-tables.txt'
  expected_app_file='$script:Release/restore-$($script:RunId)-expected-app-tables.txt'
  actual_app_file='$script:Release/restore-$($script:RunId)-actual-app-tables.txt'
  extension_owned_file='$script:Release/restore-$($script:RunId)-extension-owned-tables.txt'
  awk '`$4 == "TABLE" && `$5 == "public" { if (`$6 ~ /^"/) { print "QUOTED_TABLE_IDENTIFIER_UNSUPPORTED=" `$0 > "/dev/stderr"; exit 2 } print `$6 }' "`$list_file" | sort -u > "`$expected_tables_file" || { echo 'DUMP_TABLE_LIST_PARSE_FAILED'; exit 80; }
  table_entries=`$(wc -l < "`$expected_tables_file" | tr -d ' ')
  test "`$table_entries" -gt 0 || { echo 'DUMP_HAS_NO_PUBLIC_TABLES'; exit 80; }
  if [ "`$restore_reuse" != true ]; then
    test "`$(docker exec nexo-postgres psql -U '$dbUser' -d postgres -Atqc "SELECT count(*) FROM pg_database WHERE datname='`$restore_db'")" -eq 0 || { echo 'RESTORE_DATABASE_NAME_COLLISION'; exit 81; }
    docker exec nexo-postgres psql -U '$dbUser' -d postgres -v ON_ERROR_STOP=1 -c "CREATE DATABASE \"`$restore_db\" OWNER $dbUser TEMPLATE template0"
    docker exec nexo-postgres psql -U '$dbUser' -d "`$restore_db" -v ON_ERROR_STOP=1 -c 'CREATE EXTENSION postgis'
    postgis_version=`$(docker exec nexo-postgres psql -U '$dbUser' -d "`$restore_db" -v ON_ERROR_STOP=1 -Atqc 'SELECT postgis_full_version()')
    test -n "`$postgis_version" || { echo 'POSTGIS_EXTENSION_PREFLIGHT_FAILED'; exit 82; }
    destination_postgis_series=`$(docker exec nexo-postgres psql -U '$dbUser' -d "`$restore_db" -v ON_ERROR_STOP=1 -Atqc "SELECT split_part(postgis_lib_version(), '.', 1) || '.' || split_part(postgis_lib_version(), '.', 2)")
    test "`$destination_postgis_series" = '$sourcePostgisSeries' || { echo "POSTGIS_SERIES_MISMATCH source=$sourcePostgisSeries destination=`$destination_postgis_series"; exit 89; }
    docker cp "`$list_file" "nexo-postgres:`$list_container_path" >/dev/null
    # pg_restore runs serially by default. --exit-on-error rejects the first SQL failure.
    docker exec -i nexo-postgres pg_restore --exit-on-error --no-owner --no-acl --use-list="`$list_container_path" -U '$dbUser' -d "`$restore_db" < "`$dump"
  fi
  restored_postgis=`$(docker exec nexo-postgres psql -U '$dbUser' -d "`$restore_db" -v ON_ERROR_STOP=1 -Atqc "SELECT extversion FROM pg_extension WHERE extname='postgis'")
  test -n "`$restored_postgis" || { echo 'RESTORED_POSTGIS_MISSING'; exit 84; }
  ownership_sql="SELECT e.extname || E'\t' || c.relname FROM pg_depend d JOIN pg_extension e ON e.oid=d.refobjid JOIN pg_class c ON d.classid='pg_class'::regclass AND d.objid=c.oid JOIN pg_namespace n ON n.oid=c.relnamespace WHERE d.refclassid='pg_extension'::regclass AND d.deptype='e' AND n.nspname='public' AND c.relkind IN ('r','p') ORDER BY e.extname,c.relname"
  actual_tables_sql="SELECT c.relname FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='public' AND c.relkind IN ('r','p') AND NOT EXISTS (SELECT 1 FROM pg_depend d JOIN pg_extension e ON e.oid=d.refobjid WHERE d.classid='pg_class'::regclass AND d.objid=c.oid AND d.refclassid='pg_extension'::regclass AND d.deptype='e') ORDER BY c.relname"
  docker exec nexo-postgres psql -U '$dbUser' -d "`$restore_db" -v ON_ERROR_STOP=1 -Atqc "`$ownership_sql" > "`$extension_owned_file"
  docker exec nexo-postgres psql -U '$dbUser' -d "`$restore_db" -v ON_ERROR_STOP=1 -Atqc "`$actual_tables_sql" > "`$actual_app_file"
  if [ -s "`$extension_owned_file" ]; then cut -f2 "`$extension_owned_file" | sort -u > "`$extension_owned_file.names"; grep -Fvx -f "`$extension_owned_file.names" "`$expected_tables_file" > "`$expected_app_file" || [ "`$?" -eq 1 ]; else cp "`$expected_tables_file" "`$expected_app_file"; fi
  expected_app_count=`$(wc -l < "`$expected_app_file" | tr -d ' ')
  actual_app_count=`$(wc -l < "`$actual_app_file" | tr -d ' ')
  extension_owned_count=`$(wc -l < "`$extension_owned_file" | tr -d ' ')
  actual_total_count=`$(docker exec nexo-postgres psql -U '$dbUser' -d "`$restore_db" -v ON_ERROR_STOP=1 -Atqc "SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='public' AND c.relkind IN ('r','p')")
  printf 'RESTORE_TABLE_COUNTS database=%s toc_tables=%s destination_public_tables=%s expected_application=%s actual_application=%s extension_owned=%s postgis_version=%s\n' "`$restore_db" "`$table_entries" "`$actual_total_count" "`$expected_app_count" "`$actual_app_count" "`$extension_owned_count" "`$restored_postgis"
  echo 'EXTENSION_OWNED_TABLES_BEGIN'; cat "`$extension_owned_file"; echo 'EXTENSION_OWNED_TABLES_END'
  echo 'EXPECTED_APPLICATION_TABLES_BEGIN'; cat "`$expected_app_file"; echo 'EXPECTED_APPLICATION_TABLES_END'
  echo 'RESTORED_APPLICATION_TABLES_BEGIN'; cat "`$actual_app_file"; echo 'RESTORED_APPLICATION_TABLES_END'
  if ! cmp -s "`$expected_app_file" "`$actual_app_file"; then
    echo "RESTORED_APPLICATION_TABLE_SET_MISMATCH database=`$restore_db expected=`$expected_app_count actual=`$actual_app_count"
    echo 'APPLICATION_TABLES_MISSING_BEGIN'; comm -23 "`$expected_app_file" "`$actual_app_file"; echo 'APPLICATION_TABLES_MISSING_END'
    echo 'APPLICATION_TABLES_UNEXPECTED_BEGIN'; comm -13 "`$expected_app_file" "`$actual_app_file"; echo 'APPLICATION_TABLES_UNEXPECTED_END'
    echo 'EXTENSION_OWNED_TABLES_BEGIN'; cat "`$extension_owned_file"; echo 'EXTENSION_OWNED_TABLES_END'
    exit 83
  fi
  migration_table=`$(docker exec nexo-postgres psql -U '$dbUser' -d "`$restore_db" -v ON_ERROR_STOP=1 -Atqc "SELECT to_regclass('public.migrations')")
  test "`$migration_table" = migrations || { echo 'LARAVEL_MIGRATION_HISTORY_MISSING'; exit 85; }
  migration_rows=`$(docker exec nexo-postgres psql -U '$dbUser' -d "`$restore_db" -v ON_ERROR_STOP=1 -Atqc 'SELECT count(*) FROM public.migrations')
  test "`$migration_rows" -gt 0 || { echo "LARAVEL_MIGRATION_HISTORY_MISSING_OR_EMPTY=`$migration_rows"; exit 85; }
  printf 'LARAVEL_MIGRATION_ROWS=%s\n' "`$migration_rows"
  if [ "`$promotion_approved" != true ]; then
    echo "VALIDATED_RESTORE_DATABASE=`$restore_db"
    echo 'DATABASE_PROMOTION_APPROVAL_REQUIRED=true'
    echo 'NO_DATABASE_RENAME_PERFORMED'
    exit 98
  fi
  echo 'PROMOTION_REQUIRES_APPROVED_DESTINATION_ONLY_MODE'
  exit 98
fi
docker exec nexo-postgres psql -U '$dbUser' -d '$dbName' -v ON_ERROR_STOP=1 -Atqc 'select postgis_version()' >/dev/null
promoted_tables=`$(docker exec nexo-postgres psql -U '$dbUser' -d '$dbName' -v ON_ERROR_STOP=1 -Atqc "SELECT count(*) FROM information_schema.tables WHERE table_schema='public' AND table_type='BASE TABLE'")
test "`$promoted_tables" -gt 0 || { echo 'PROMOTED_DATABASE_HAS_NO_TABLES'; exit 88; }
if [ ! -f .storage-restored ]; then docker run --rm --network none -v nexo_app_storage:/restore postgis/postgis:16-3.4 sh -lc 'tar -xzf - -C /restore' < '/opt/nexo/backups/nexo-$($script:RunId)-storage.tgz'; touch .storage-restored; fi
docker compose -p nexo -f docker-compose.production.yml -f docker-compose.migration.yml run --rm --no-deps app php artisan migrate:status --no-interaction > migration-status.txt
if grep -qi 'Pending' migration-status.txt; then echo 'PENDING_MIGRATIONS'; cat migration-status.txt; exit 52; fi
docker compose -p nexo -f docker-compose.production.yml -f docker-compose.migration.yml up -d --no-build $applicationServices $backgroundServices
for i in `$(seq 1 60); do docker exec nexo-control node -e "fetch('http://127.0.0.1:3000/login').then(r=>process.exit(r.ok?0:1)).catch(()=>process.exit(1))" >/dev/null 2>&1 && break; sleep 2; done
docker exec nexo-control node -e "fetch('http://127.0.0.1:3000/login').then(r=>process.exit(r.ok?0:1)).catch(()=>process.exit(1))"
docker compose -p nexo -f docker-compose.production.yml -f docker-compose.migration.yml up -d --no-build nginx
for i in `$(seq 1 60); do curl -fsS http://127.0.0.1:18100/api/v1/live >/dev/null && curl -fsS http://127.0.0.1:18100/login >/dev/null && break; sleep 2; done
"@
    $restoreNameAssignments = New-RestoreNameAssignments $script:RunId
    $promotionApproved = if ($ApproveDatabasePromotion) { 'true' } else { 'false' }
    $startScript = $startScript.Replace('__DATABASE_PROMOTION_APPROVED__',$promotionApproved)
    $startScript = $startScript.Replace('__RESTORE_NAME_ASSIGNMENTS__',$restoreNameAssignments.TrimEnd())
    Invoke-Remote $NewKey $NewPort $NewUser $NewHost $startScript 'Restore data and start NEXO services' | Out-Null

    $verifyScript = @"
set -euo pipefail
cd '$script:Release'
docker compose -p nexo -f docker-compose.production.yml -f docker-compose.migration.yml ps
docker ps --filter label=com.docker.compose.project=nexo --format '{{.Names}} {{.Status}}'
curl --fail --silent --show-error http://127.0.0.1:18100/api/v1/live >/dev/null
page=`$(curl --fail --silent --show-error http://127.0.0.1:18100/login)
asset=`$(printf '%s' "`$page" | grep -oE '/_next/static/[^" ]+\.css' | head -n 1)
test -n "`$asset"
curl --fail --silent --show-error "http://127.0.0.1:18100`$asset" >/dev/null
auth_code=`$(curl --silent --output /dev/null --write-out '%{http_code}' http://127.0.0.1:18100/api/v1/me)
test "`$auth_code" = 401
docker exec nexo-postgres psql -U '$dbUser' -d '$dbName' -Atqc 'select postgis_version()' >/dev/null
docker exec nexo-redis redis-cli ping | grep -q PONG
ss -ltn | awk '{print $4}' | grep -Eq '^127\.0\.0\.1:18100$'
docker compose -p nexo -f docker-compose.production.yml -f docker-compose.migration.yml ps
for c in nexo-app nexo-control nexo-nginx nexo-postgres nexo-redis; do
  state=`$(docker inspect --format '{{.State.Status}} {{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}' "`$c")
  test "`$state" = 'running healthy' || { echo "UNHEALTHY_CONTAINER `$c: `$state"; exit 61; }
done
docker stats --no-stream --format '{{.Name}} {{.MemUsage}} {{.CPUPerc}}'
free -h; df -h /opt
"@
    $verified = Invoke-Remote $NewKey $NewPort $NewUser $NewHost $verifyScript 'Runtime smoke checks'
    Write-Host $verified
    if ($EnableBackgroundProcessing) {
    $bgCheck = Invoke-Remote $NewKey $NewPort $NewUser $NewHost "set -e; docker compose -p nexo -f '$script:Compose' -f '$script:Release/docker-compose.migration.yml' ps queue scheduler" 'Background service status'
        Write-Host $bgCheck
    }
    $listener = New-Object Net.Sockets.TcpListener([Net.IPAddress]::Loopback,$script:LocalTunnelPort)
    try { $listener.Start(); $listener.Stop() } catch { $script:LocalTunnelPort=18101; try { $listener.Stop() } catch {} }
    $sshExe = Require-Command 'ssh.exe'
    $tunnelArgs = @('-N','-i',$NewKey,'-p',[string]$NewPort,'-o','IdentitiesOnly=yes','-o','StrictHostKeyChecking=yes','-o','BatchMode=yes','-L',"$($script:LocalTunnelPort):127.0.0.1:18100","$NewUser@$NewHost")
    $tunnelPsi = New-Object Diagnostics.ProcessStartInfo; $tunnelPsi.FileName=$sshExe; $tunnelPsi.UseShellExecute=$false; $tunnelPsi.CreateNoWindow=$true
    $tunnelPsi.Arguments = (($tunnelArgs | ForEach-Object { '"' + ($_ -replace '(\\*)"', '$1$1\"' -replace '(\\+)$', '$1$1') + '"' }) -join ' ')
    $tunnelProcess = New-Object Diagnostics.Process; $tunnelProcess.StartInfo=$tunnelPsi; [void]$tunnelProcess.Start(); Start-Sleep -Seconds 2
    if ($tunnelProcess.HasExited) { Fail 'SSH tunnel process exited before verification; inspect known_hosts and SSH diagnostics.' }
    $tunnelOk=$false
    for ($i=0; $i -lt 20; $i++) { try { $localPage=Invoke-WebRequest -UseBasicParsing -Uri "http://127.0.0.1:$($script:LocalTunnelPort)/login" -TimeoutSec 3; if ($localPage.StatusCode -eq 200) { $tunnelOk=$true; break } } catch {}; Start-Sleep -Milliseconds 500 }
    if (-not $tunnelOk) { try { $tunnelProcess.Kill() } catch {}; Fail 'SSH tunnel was not verified by a local HTTP 200 response.' }
    Write-Step 'Deployment, private server smoke checks, and local SSH tunnel verification completed. Migrations were not applied.'
    Write-Host "Tunnel is running (PID $($tunnelProcess.Id)): ssh -i `"`$env:USERPROFILE\.ssh\nexo_deploy`" -p 2238 -o IdentitiesOnly=yes -o StrictHostKeyChecking=yes -L $($script:LocalTunnelPort):127.0.0.1:18100 root@2.188.205.35"
    Write-Host "Private URL: http://127.0.0.1:$($script:LocalTunnelPort)/login"
    Write-Host "Local backup workspace (retain until acceptance): $script:LocalWork"
} catch {
    $message = $_.Exception.Message
    try { Add-Content -LiteralPath $script:LogFile -Value ("FAILED: " + $message) } catch {}
    Write-Host "ERROR: $message"
    Write-Host "Run ID: $script:RunId"
    Write-Host "Local diagnostic directory: $script:LocalWork"
    Write-Host 'No automatic rollback or volume deletion was performed. The old server was never stopped.'
    exit 1
}
