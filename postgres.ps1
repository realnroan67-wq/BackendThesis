param([ValidateSet('start', 'stop', 'status')][string]$Action = 'status')
$ErrorActionPreference = 'Stop'
$pgBin = if ($env:HORIZON_PG_BIN) { $env:HORIZON_PG_BIN } else { 'C:/Program Files/PostgreSQL/18/bin' }
$cluster = Join-Path $PSScriptRoot '.local/postgres'
$pgCtl = Join-Path $pgBin 'pg_ctl.exe'
if (!(Test-Path -LiteralPath $pgCtl)) { throw 'Set HORIZON_PG_BIN to the PostgreSQL binary directory.' }
if (!(Test-Path -LiteralPath (Join-Path $cluster 'PG_VERSION'))) { throw 'Project-local cluster is not initialized; see README.md.' }
if ($Action -eq 'start') {
    & $pgCtl -D $cluster status
    if ($LASTEXITCODE -eq 0) { exit 0 }
    # Quote paths for pg_ctl when the project directory contains spaces.
    $process = Start-Process -FilePath $pgCtl -ArgumentList @('-D', ('"' + $cluster + '"'), '-l', ('"' + (Join-Path $PSScriptRoot '.local/postgres.log') + '"'), '-w', 'start') -WindowStyle Hidden -PassThru
    # Retain the process handle so Windows PowerShell can retrieve its exit code.
    $processHandle = $process.Handle
    if (!$process.WaitForExit(20000)) { throw 'PostgreSQL startup timed out; inspect .local/postgres.log and run status.' }
    if ($process.ExitCode -ne 0) { throw 'PostgreSQL startup failed; inspect .local/postgres.log.' }
    & $pgCtl -D $cluster status
} elseif ($Action -eq 'stop') {
    & $pgCtl -D $cluster -m fast -w stop
} else {
    & $pgCtl -D $cluster status
}
exit $LASTEXITCODE