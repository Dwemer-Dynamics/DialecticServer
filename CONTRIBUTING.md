# Contributing to DialecticServer

## Branch Flow

DialecticServer uses the following promotion chain:

```text
feature/* -> unstable -> dev -> dialectic
```

- Create work on a focused `feature/*` or fix branch.
- Open normal pull requests against `unstable`.
- Promote tested batches with a pull request from `unstable` to `dev`.
- Promote release candidates with a pull request from `dev` to `dialectic`.
- `dialectic` is the default and release branch. Do not target it directly with
  feature work.

Do not force-push or delete the `unstable`, `dev`, or `dialectic` branches.

## Validation

Run the release-tree audit before opening a pull request:

```powershell
powershell -ExecutionPolicy Bypass -File .\tools\audit-release-tree.ps1
```

Run focused PHPUnit coverage for changed runtime paths when dependencies are
installed. Do not commit runtime configuration, credentials, logs, caches,
generated audio, database dumps, or test dependencies.
