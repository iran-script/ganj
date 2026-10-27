param(
    [string]$OutputPath = "release-candidate-manifest.json"
)

$ErrorActionPreference = 'Stop'
$repositoryRoot = Resolve-Path (Join-Path $PSScriptRoot '..')
Push-Location $repositoryRoot
try {
    $branch = (git branch --show-current).Trim()
    $head = (git rev-parse HEAD).Trim()
    $upstream = 'origin/main'
    $commitSubject = (git log -1 --format=%s HEAD).Trim()
    $committedPaths = @(git diff --name-only "$upstream...HEAD" --diff-filter=ACMRT)
    $workingPaths = @(git diff --name-only HEAD --diff-filter=ACMRT)
    $stagedPaths = @(git diff --cached --name-only --diff-filter=ACMRT)
    $untrackedPaths = @(git ls-files --others --exclude-standard)
    $committed = [System.Collections.Generic.HashSet[string]]::new([System.StringComparer]::OrdinalIgnoreCase)
    $working = [System.Collections.Generic.HashSet[string]]::new([System.StringComparer]::OrdinalIgnoreCase)
    foreach ($path in $committedPaths) { if ($path) { [void]$committed.Add($path) } }
    foreach ($path in @($workingPaths) + @($stagedPaths) + @($untrackedPaths)) { if ($path) { [void]$working.Add($path) } }

    $allPaths = [System.Collections.Generic.HashSet[string]]::new([System.StringComparer]::OrdinalIgnoreCase)
    foreach ($path in $committed) { [void]$allPaths.Add($path) }
    foreach ($path in $working) { [void]$allPaths.Add($path) }
    [void]$allPaths.Remove($OutputPath.Replace('\\', '/'))

    $files = foreach ($path in ($allPaths | Sort-Object)) {
        $fullPath = Join-Path $repositoryRoot $path
        if (-not (Test-Path -LiteralPath $fullPath -PathType Leaf)) { continue }
        $scope = 'review-required'
        if ($path -match 'GameBooking|BookingParticipant|Payment(Order|Refund|Transaction)|2026_10_19_174509_create_game_booking|scripts/(deploy|migrate|prepare)-|\.bak$|mobile/curl\.exe|mobile/nexo-room-log|mobile/ui_preview') {
            $scope = 'excluded-from-this-security-release-pending-separate-review'
        } elseif ($path -match 'Team|team|RequireControlCenterAccess|IdentityService|routes/api\.php|control-center/(app/api|app/login|middleware|lib/backend|tests/control-auth)|docker-compose\.release|nginx-release|nginx-rollback|AppConfig|connectivity_config|auth_flow') {
            $scope = 'candidate-security-and-team-lobby-scope'
        }
        $status = @()
        if ($committed.Contains($path)) { $status += 'committed-ahead-of-origin/main' }
        if ($working.Contains($path)) { $status += 'working-tree-or-untracked' }
        [pscustomobject]@{
            path = $path.Replace('\\', '/')
            status = $status -join ' + '
            releaseScope = $scope
            sha256 = (Get-FileHash -LiteralPath $fullPath -Algorithm SHA256).Hash.ToLowerInvariant()
        }
    }

    $migrationPath = 'database/migrations/2026_10_20_202643_create_team_join_requests_table.php'
    $migration = if (Test-Path -LiteralPath $migrationPath) {
        [pscustomobject]@{ path = $migrationPath; sha256 = (Get-FileHash -LiteralPath $migrationPath -Algorithm SHA256).Hash.ToLowerInvariant(); productionStatus = 'read-only preflight: not applied; table absent' }
    } else { $null }

    $manifest = [ordered]@{
        generatedAtUtc = [DateTime]::UtcNow.ToString('yyyy-MM-ddTHH:mm:ssZ')
        repository = [ordered]@{
            branch = $branch
            head = $head
            commitSubject = $commitSubject
            upstream = $upstream
            ahead = [int](git rev-list --count "$upstream..HEAD")
            behind = [int](git rev-list --count "HEAD..$upstream")
            committedChangedPathCount = $committed.Count
            localChangedPathCount = $working.Count
            noCommitOrPush = $true
        }
        productionPreflight = [ordered]@{
            host = '2.188.205.35:2238'
            checkedServices = @(
                @{ name = 'nexo-app'; image = 'nexo-app:release-20261020T184103Z'; imageId = 'sha256:1e2e723928763d7c48e14a5473137906a15ab3002d712dc2877d250e4b235c44'; status = 'healthy' },
                @{ name = 'nexo-postgres'; image = 'postgis/postgis:16-3.4'; imageId = 'sha256:44126d872ac91993766c341e369c539e8196614321765d36a6f1bab0419a5fa5'; status = 'healthy' },
                @{ name = 'nexo-redis'; image = 'redis:7-alpine'; imageId = 'sha256:858f009f9709ce576febc734aa78b8f6d624b82571f9ddb6bda4377c833b3499'; status = 'healthy' },
                @{ name = 'nexo-control'; image = 'nexo-control:login-fix-final-20261018T123000Z'; imageId = 'sha256:61bdf3c12ae61efa5c5db2ad9ec785276a8cc5519cc117c72095846462146be5'; status = 'healthy' },
                @{ name = 'nexo-nginx'; image = 'nexo-nginx:df6c803'; imageId = 'sha256:f38dad5031010e3659bda21263aa048d82553bb13d95db238182a66fd54f36cb'; status = 'healthy' }
            )
            database = 'nexo_restore_d8e35d4d_20261017T213125Z_38265'
            backupPath = '/opt/nexo/pre-release-backups/nexo-nexo_restore_d8e35d4d_20261017T213125Z_38265-20261021T222729Z.dump'
            backupBytes = 529986
            backupSha256 = 'c7a744227e4b8d6440f396aa4998ba6c64c88e723d13072b5ae9727600251684'
            publicHttpOnlyAtLastReadOnlyAudit = $true
            publishedApiPort = '18100 on all interfaces at last read-only audit'
            composeState = 'services came from multiple historical Compose projects; preserve current configs'
            volumes = 'PostgreSQL and Redis volumes are external to the candidate Compose file and must remain untouched'
        }
        migration = $migration
        releaseFiles = @($files)
        decision = 'NO-GO until source scope is isolated, domain/DNS/certificate are verified, TLS edge and rollback edge are validated, and rollout handoff for the existing public 18100 listener is approved'
    }
    $json = $manifest | ConvertTo-Json -Depth 10
    $destination = Join-Path $repositoryRoot $OutputPath
    [System.IO.File]::WriteAllText($destination, $json, [System.Text.UTF8Encoding]::new($false))
    Write-Output $destination
} finally {
    Pop-Location
}
