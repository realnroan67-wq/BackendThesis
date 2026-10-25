param([Parameter(ValueFromRemainingArguments = $true)][string[]]$Arguments)
$ErrorActionPreference = 'Stop'
$phpBinary = if ($env:HORIZON_PHP_BINARY) { $env:HORIZON_PHP_BINARY } else { 'C:/php-8.5.9/php.exe' }
if (!(Test-Path -LiteralPath $phpBinary)) { throw 'Set HORIZON_PHP_BINARY to PHP 8.3+ with PostgreSQL extension DLLs.' }
$configurationPath = Join-Path $PSScriptRoot '.runtime/php'
if (!(Test-Path -LiteralPath (Join-Path $configurationPath 'horizon.ini'))) {
    New-Item -ItemType Directory -Path $configurationPath -Force | Out-Null
    $extensionsPath = Join-Path (Split-Path $phpBinary) 'ext'
    [IO.File]::WriteAllText((Join-Path $configurationPath 'horizon.ini'), "extension_dir=`"$extensionsPath`"`nextension=fileinfo`nextension=zip`nextension=pdo_pgsql`nextension=pgsql`n")
}
$previousScan = $env:PHP_INI_SCAN_DIR
$previousPath = $env:PATH
try {
    $env:PHP_INI_SCAN_DIR = $configurationPath
    $env:PATH = (Split-Path $phpBinary) + ';' + $previousPath
    & $phpBinary @Arguments
    $code = $LASTEXITCODE
} finally {
    $env:PHP_INI_SCAN_DIR = $previousScan
    $env:PATH = $previousPath
}
exit $code