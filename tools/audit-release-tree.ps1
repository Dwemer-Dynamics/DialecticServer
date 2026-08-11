param(
    [string]$PhpExecutable = "php"
)

$ErrorActionPreference = "Stop"
$root = Split-Path -Parent $PSScriptRoot

function Fail([string]$Message) {
    throw "DIALECTIC Server release audit failed: $Message"
}

Push-Location $root
try {
    $tracked = @(git ls-files)
    if ($LASTEXITCODE -ne 0 -or $tracked.Count -eq 0) {
        Fail "unable to read tracked files"
    }

    $forbiddenTrackedPatterns = @(
        '^unittests/vendor/',
        '^connector/vendor/',
        '^uploads/',
        '^data/tmp/',
        '^ui/tmp/(?!\.placeholder$)',
        '^soundcache/(?!\.gitkeep$)',
        '^logs/',
        '^log/(?!\.gitkeep$)',
        '^conf/conf\.php$',
        '\.(?:bak|orig|swp|swo|tmp|log)$'
    )

    foreach ($pattern in $forbiddenTrackedPatterns) {
        $matches = @($tracked | Where-Object { $_ -match $pattern })
        if ($matches.Count -gt 0) {
            Fail "generated or vendored files are tracked for '$pattern': $($matches -join ', ')"
        }
    }

    $obsoleteFiles = @(
        'data/.placeholder',
        'ui/lib/ui/bootstrap/bootstrap.bundle.min.js.map',
        'ui/lib/ui/bootstrap/bootstrap.min.css.map',
        'tts/composer.json',
        'tts/data/put_your_json_voices_here.txt'
    )
    foreach ($obsoleteFile in $obsoleteFiles) {
        if ($obsoleteFile -in $tracked) {
            Fail "obsolete file remains tracked: $obsoleteFile"
        }
    }

    $requiredFiles = @(
        'main.php',
        'main_dialectic_pipeline.php',
        'gamedata.php',
        'csv_import.php',
        'stt.php',
        'vsx.php',
        'player_rewrite.php',
        'prompt.includes.php',
        'conf/conf_loader.php',
        'conf/conf_schema.json',
        'debug/db_updates.php',
        'data/database_default.sql',
        'ui/home.php',
        'ui/core/config_hub.php',
        'ui/tools/server_version.php'
    )
    foreach ($requiredFile in $requiredFiles) {
        if ($requiredFile -notin $tracked -or -not (Test-Path -LiteralPath $requiredFile -PathType Leaf)) {
            Fail "required runtime file is missing: $requiredFile"
        }
    }

    $schema = Get-Content -Raw -LiteralPath 'conf/conf_schema.json' | ConvertFrom-Json
    $ttsDrivers = @($schema.TTSFUNCTION.values | Where-Object { $_ -ne 'none' })
    $sttDrivers = @($schema.STTFUNCTION.values | Where-Object { $_ -ne 'none' })

    foreach ($driver in $ttsDrivers) {
        $driverFile = "tts/tts-$driver.php"
        if ($driverFile -notin $tracked -or -not (Test-Path -LiteralPath $driverFile -PathType Leaf)) {
            Fail "selectable TTS driver '$driver' has no tracked implementation at $driverFile"
        }
    }
    foreach ($driver in $sttDrivers) {
        $driverFile = "stt/stt-$driver.php"
        if ($driverFile -notin $tracked -or -not (Test-Path -LiteralPath $driverFile -PathType Leaf)) {
            Fail "selectable STT driver '$driver' has no tracked implementation at $driverFile"
        }
    }

    $phpFiles = @($tracked | Where-Object { $_ -like '*.php' })
    foreach ($phpFile in $phpFiles) {
        & $PhpExecutable -l $phpFile | Out-Null
        if ($LASTEXITCODE -ne 0) {
            Fail "PHP lint failed: $phpFile"
        }
    }

    $jsonFiles = @($tracked | Where-Object { $_ -like '*.json' })
    foreach ($jsonFile in $jsonFiles) {
        try {
            Get-Content -Raw -LiteralPath $jsonFile | ConvertFrom-Json | Out-Null
        } catch {
            Fail "invalid JSON: $jsonFile ($($_.Exception.Message))"
        }
    }

    Write-Host "DIALECTIC Server release audit passed"
    Write-Host "Tracked files: $($tracked.Count)"
    Write-Host "PHP files linted: $($phpFiles.Count)"
    Write-Host "JSON files validated: $($jsonFiles.Count)"
    Write-Host "TTS drivers verified: $($ttsDrivers.Count)"
    Write-Host "STT drivers verified: $($sttDrivers.Count)"
} finally {
    Pop-Location
}
