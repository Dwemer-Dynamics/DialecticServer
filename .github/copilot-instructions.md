# DialecticServer Repository Instructions

- DialecticServer is the backend and web UI for the Dialectic FNV/xNVSE client.
- Use the branch flow `feature/* -> unstable -> dev -> dialectic`.
- `dialectic` is the default release branch; normal pull requests target
  `unstable`.
- Keep plugin/server contracts structured as JSON with explicit schema names.
- Database changes must be represented in the baseline or migration chain as
  appropriate and remain safe for existing installs.
- Do not commit runtime configuration, secrets, logs, caches, generated speech,
  database dumps, or vendored test dependencies.
- Preserve Dialectic-specific paths and naming; do not introduce HerikaServer
  runtime paths into active code.
- Run `tools/audit-release-tree.ps1` before publishing changes.
