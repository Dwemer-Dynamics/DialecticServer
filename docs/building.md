# Developing DialecticServer

This is a PHP application, not a native DLL build. Start with
[AGENTS.md](../AGENTS.md) and [the runtime guide](agent-guide.md).

## Prerequisites and setup

Use PHP 8.3 or newer for the PHPUnit 11 development suite, Composer, and a
UTF-8 PostgreSQL database. The database setup uses `pg_trgm` and `vector`
extensions. Enable PHP PostgreSQL and cURL support; archive features need
ZipArchive, and PHPUnit needs its usual XML/DOM and mbstring extensions.
Verify connector-specific services against the configured provider.

DwemerDistro is the normal installed environment. A checkout does not provide
PostgreSQL, speech/model services or their credentials. The root README's WSL
setup script targets the fixed `dialectic` database and assumes an existing
`dwemer` role and WSL services; it is not an isolated test database generator.
Its `-Reset` option destroys that database. Do not run it on a user's active data.

For local web development, name the checkout directory `DialecticServer`:
`start-dev.ps1` serves its parent directory and prints `/DialecticServer/` URLs.
Configure a disposable database and local configuration first, then run from
the checkout root:

```powershell
powershell -ExecutionPolicy Bypass -File ./start-dev.ps1
```

The default is loopback port 8085. This is a development web server, not a
complete production deployment or proof that the external services are ready.

## Validation

From the repository root, stop on failures:

```powershell
powershell -ExecutionPolicy Bypass -File ./tools/audit-release-tree.ps1
```

The audit checks tracked files, lints PHP, validates JSON and provider mappings.
For a small PHP change, start with `php -l path/to/changed.php`. For unit tests:

```text
cd unittests
composer install
php vendor/phpunit/phpunit/phpunit
```

Dependencies stay in ignored `unittests/vendor/`; do not commit them. Inspect
the selected tests' fixtures and database requirements before running against
an environment with real credentials. Use disposable databases for migrations,
playthrough operations and plugin installs. Runtime workers and game behavior
need separate validation; lint or PHPUnit success does not establish either.

## Distribution

Keep root `AGENTS.md`, `README.md` and `docs/` in source archives and deployed
application trees. Do not distribute local configuration, API keys, databases,
logs, uploads, caches, voice samples or test dependencies. Maintainer WSL sync
automation is external to this public repository; do not present those scripts
as standalone-clone commands. Verify the final archive and destination retain
the guides and preserve installation data before reporting a deployment.
