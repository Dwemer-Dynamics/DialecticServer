param(
    [switch]$Reset
)

$ErrorActionPreference = "Stop"

$database = "dialectic"
$owner = "dwemer"
$password = "dwemer"
$serverRoot = Resolve-Path (Join-Path $PSScriptRoot "..")
$baselineWin = Resolve-Path (Join-Path $serverRoot "data\database_default.sql")
$bootstrapWin = Resolve-Path (Join-Path $serverRoot "tools\bootstrap-database.php")
$baselineLinux = (& wsl.exe -- wslpath -a $baselineWin.Path).Trim()
$bootstrapLinux = (& wsl.exe -- wslpath -a $bootstrapWin.Path).Trim()

Write-Host "Ensuring WSL PostgreSQL database '$database' exists with owner '$owner'."

if ($Reset) {
    Write-Host "Reset requested; dropping '$database'."
    & wsl.exe -- sudo -n -u postgres dropdb --if-exists $database
    if ($LASTEXITCODE -ne 0) {
        throw "Failed to drop PostgreSQL database '$database'."
    }
}

$databaseExists = (& wsl.exe -- sudo -n -u postgres psql -Atqc "SELECT 1 FROM pg_database WHERE datname='$database';").Trim()
if ($databaseExists -ne "1") {
    & wsl.exe -- sudo -n -u postgres createdb -O $owner $database
    if ($LASTEXITCODE -ne 0) {
        throw "Failed to create PostgreSQL database '$database'."
    }
} else {
    Write-Host "Database '$database' already exists."
}

& wsl.exe -- sudo -n -u postgres psql -d $database -v ON_ERROR_STOP=1 `
    -c "CREATE EXTENSION IF NOT EXISTS pg_trgm;" `
    -c "CREATE EXTENSION IF NOT EXISTS vector;" `
    -c "ALTER DATABASE $database OWNER TO $owner;"

if ($LASTEXITCODE -ne 0) {
    throw "Failed to prepare PostgreSQL database '$database'."
}

$eventlogExists = (& wsl.exe -- env "PGPASSWORD=$password" psql -h 127.0.0.1 -U $owner -d $database -Atqc "SELECT to_regclass('public.eventlog') IS NOT NULL;").Trim()
if ($eventlogExists -ne "t") {
    Write-Host "Importing Dialectic baseline schema into '$database'."
    & wsl.exe -- env "PGPASSWORD=$password" psql -h 127.0.0.1 -U $owner -d $database -v ON_ERROR_STOP=1 -f $baselineLinux
    if ($LASTEXITCODE -ne 0) {
        throw "Failed to import baseline schema into '$database'."
    }
    & wsl.exe -- env "PGPASSWORD=$password" psql -h 127.0.0.1 -U $owner -d $database -v ON_ERROR_STOP=1 -c "TRUNCATE TABLE public.worldknowledge RESTART IDENTITY;"
    if ($LASTEXITCODE -ne 0) {
        throw "Failed to clear legacy WorldKnowledge rows from '$database'."
    }
}

Write-Host "Running Dialectic database updates."
& wsl.exe -- php $bootstrapLinux
if ($LASTEXITCODE -ne 0) {
    throw "Dialectic database updates failed."
}

Write-Host "Database '$database' is ready."
