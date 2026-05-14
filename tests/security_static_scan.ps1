param(
    [string]$Path = (Join-Path $PSScriptRoot '..\litegig.php')
)

$source = Get-Content -LiteralPath $Path -Raw
$failures = @()

function Assert-Contains {
    param([string]$Needle, [string]$Message)
    if ($source -notmatch [regex]::Escape($Needle)) {
        $script:failures += $Message
    }
}

function Assert-NotMatch {
    param([string]$Pattern, [string]$Message)
    if ($source -match $Pattern) {
        $script:failures += $Message
    }
}

Assert-Contains 'function htmlEscape' 'htmlEscape() helper is missing.'
Assert-Contains 'Content-Security-Policy' 'CSP header is missing.'
Assert-Contains 'session_regenerate_id(true)' 'Login/register should regenerate the session id.'
Assert-Contains 'CREATE TABLE IF NOT EXISTS rate_limits' 'SQLite rate limit table is missing.'
Assert-Contains 'function can_view_request' 'Request object authorization helper is missing.'
Assert-Contains 'function action_download_attachment' 'Private attachment download handler is missing.'

Assert-NotMatch 'innerHTML\s*=\s*html' 'DOM helper still writes caller-provided HTML through innerHTML.'
Assert-NotMatch 'insertAdjacentHTML' 'Dynamic JavaScript still uses insertAdjacentHTML.'
Assert-NotMatch '\$sql\s*\.=' 'SQL is still assembled with $sql .= fragments.'
Assert-NotMatch 'uploads/\s*''?\s*\.' 'Attachments should not be linked directly from uploads/.'

if ($failures.Count -gt 0) {
    Write-Host "Security static scan failed:" -ForegroundColor Red
    foreach ($failure in $failures) {
        Write-Host " - $failure" -ForegroundColor Red
    }
    exit 1
}

Write-Host "Security static scan passed." -ForegroundColor Green
