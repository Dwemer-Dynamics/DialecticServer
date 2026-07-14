# DialecticServer

DialecticServer is the Fallout: New Vegas backend for Dialectic.

This server owns the Fallout: New Vegas runtime, UI, database schema, prompts, and connector configuration used by the Dialectic xNVSE plugin.

## Current State

- `main.php` is the JSON endpoint used by the FNV/xNVSE plugin.
- Runtime game events are stored in Dialectic's `eventlog` and related Fallout-specific context tables.
- `main_dialectic_pipeline.php` runs the active dialogue, rechat, actions, memories, STT, and TTS flow.
- DialecticServer owns its config, UI, connectors, profiles, eventlog, processors, services, and database updates.
- Vision connector support is currently disabled.
- The dev server uses port `8085`.

## Run Locally

From this directory:

```powershell
powershell -ExecutionPolicy Bypass -File .\start-dev.ps1
```

Client config should point at:

```text
Host=127.0.0.1
Port=8085
DialecticServer/main.php
```

The UI is available at:

```text
http://127.0.0.1:8085/DialecticServer/ui/index.php
```

## Quickstart

After DialecticServer and its database are running, open:

```text
http://127.0.0.1:8085/DialecticServer/ui/quickstart.php
```

The Quickstart Setup page follows the HerikaServer onboarding flow and configures
the settings needed for a first in-game response:

1. Enter an OpenRouter API key, or enable the local Player2 connector.
2. Select the initial TTS connector.
3. Select Parakeet or Deepgram for microphone STT.
4. Enter a Deepgram key when Deepgram is selected.
5. Review the Standard, Fast, Powerful, and Experimental LLM presets.
6. Click **Save and Continue**. The page validates every save step before opening the home page.

The player name is detected from Fallout: New Vegas and is not entered manually.
Advanced connector configuration remains available from the Configuration Hub.

## Database

DialecticServer uses its own PostgreSQL database named `dialectic` on the local WSL PostgreSQL service.

Default connection settings:

```text
host=127.0.0.1
dbname=dialectic
user=dwemer
password=dwemer
```

Create or update the database with:

```powershell
powershell -ExecutionPolicy Bypass -File .\tools\create-dialectic-db-wsl.ps1
```

Reset and rebuild the database from the Dialectic baseline plus update chain with:

```powershell
powershell -ExecutionPolicy Bypass -File .\tools\create-dialectic-db-wsl.ps1 -Reset
```

The script imports `data/database_default.sql` when the baseline schema is missing, then runs `tools/bootstrap-database.php`, which applies `debug/db_updates.php`.

## Release Audit

Validate the tracked server tree before publishing a release:

```powershell
powershell -ExecutionPolicy Bypass -File .\tools\audit-release-tree.ps1
```

The audit lints tracked PHP, validates JSON, checks required entry points, verifies
that every selectable TTS/STT driver has an implementation, and rejects generated
runtime files or vendored test dependencies in Git.

Unit-test dependencies are intentionally not committed. Install and run them with:

```bash
cd unittests
composer install
./vendor/bin/phpunit
```

## Development Flow

Changes move through `feature/* -> unstable -> dev -> dialectic`. Open normal
pull requests against `unstable`; promotion pull requests move tested changes
from `unstable` to `dev`, then from `dev` to the default release branch
`dialectic`. See [CONTRIBUTING.md](CONTRIBUTING.md) for the full policy.

## Planning Docs

- [JSON data transfer audit](docs/json-data-transfer-audit.md)
