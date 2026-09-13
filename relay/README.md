# Public dialogue relay (beta)

This is a standalone HTTPS service for Dialectic. Host and listener plugins both make
outbound requests. The host uploads the WAV already being played; the service never
connects back to a player's PC, uses their AI credentials, or generates AI/TTS requests.
It does not need a DialecticServer database or a running local game server.

## Deploy once as the operator

Use PHP 8.1+ with the standard JSON/hash/file extensions, a PHP-FPM web server and TLS.
No Composer packages, SQLite extension, database migration or external queue is needed.
The files to install are `relay/index.php` and `lib/multiplayer_relay.php`:

```text
/opt/dialectic-relay/public/index.php       # copy relay/index.php here
/opt/dialectic-relay/lib/multiplayer_relay.php
/var/lib/dialectic-relay/                  # private writable state, outside web root
```

1. Create the state directory owned by the PHP service account, mode `0700`.
2. Set `DIALECTIC_RELAY_STORAGE=/var/lib/dialectic-relay` in the PHP-FPM pool environment.
   An absent/invalid directory disables the endpoint with HTTP 503.
3. Expose only `/index.php` on a dedicated HTTPS hostname, pointing to the `public`
   directory above. Do not expose the full DialecticServer checkout. Use a valid trusted
   certificate, preserve `Authorization`, and accept POST bodies up to 4,210,692 bytes.
4. Apply proxy connection/request limits and body-read timeouts. Use the actual client
   address as PHP's `REMOTE_ADDR`; only a trusted proxy may rewrite it. The service
   deliberately ignores arbitrary `X-Forwarded-For` headers. Do not log POST bodies or
   authorization headers. Codes and bearer credentials must not appear in access logs.
5. Run the following every minute, as the same account that runs PHP, using your scheduler:

   ```sh
   DIALECTIC_RELAY_STORAGE=/var/lib/dialectic-relay php /opt/dialectic-relay/public/index.php --clean
   ```

6. Set `PublicRelayURL=https://YOUR-RELAY-HOST/index.php` in the shipped plugin default
   INI's `[Multiplayer]` section. This is operator/release configuration, not something
   each player edits. No real domain has been selected or deployed in this PR.
7. Verify create/join, upload/playback and expiry through the actual HTTPS proxy before
   distributing that build. PHP's development server is for local verification only.

For local testing, use a scratch directory and start PHP with the same environment
variable, e.g. `php -S 127.0.0.1:8090 -t relay` from the repository. Set a test client's
PublicRelayURL to `http://127.0.0.1:8090/index.php`. Public HTTP URLs are rejected by the
plugin; only loopback and private IPv4 HTTP are accepted for development/LAN use.

## Player experience

Host selects Host session in Tools, closes MCM, then uses Copy join code to invite
friends. Friends copy the 12-character code, select Join session, and close MCM.
No player files, local inbound ports or AI-provider keys are required. Disconnect ends
hosting or leaves the session. Public credentials are ephemeral and not persisted by
the plugin; game restart returns to Off. Hosting shares ordinary NPC speech only.

## Protocol and limits

- `create`: client supplies a cryptographically random 64-hex-character bearer token;
  retries with that token return the same room. Reply: `dialectic.session.v1`, room ID,
  role token and code, separated by tabs. `join` accepts the code in JSON and returns
  the distinct listener token. The code contains 48 random bits and grants listening.
- Authenticated reset/heartbeat/publish/poll/audio/pause/resume/cancel/close reuse the
  existing relay protocol. Publish uses `application/octet-stream`: four little-endian
  bytes giving JSON metadata length, that JSON (at most 16 KiB), then the WAV.
- Only the host can upload audio or end the room. Audio reads require the listener
  token plus a live epoch and published event sequence. `end` revokes keys/code and
  deletes room data. Cancellation/close/reset remove stored media.
- Rooms expire after six hours or 180 seconds without successful host traffic. A
  15-second playback lease detects a missing host sooner. Audio older than 120 seconds
  is removed on requests and by the minute cleanup job. Expiry does not depend on
  somebody making another request when that scheduler is installed.
- Beta capacity: 16 rooms globally, two rooms per source IP; 4 MiB per WAV,
  16 MiB and 128 WAV files per room, 128 recent events. Maximum stored audio is 256 MiB.
- Per-IP setup limit: 12 create/join attempts per minute. Traffic: 128 requests/second
  per IP, 256 globally; existing per-room role caps also apply. Rate state is bounded
  to 1,024 buckets. Full/busy responses fail closed; clients back off. A small beta
  deployment should also have proxy-level bandwidth and connection limits.
- Room metadata and role tokens are stored in the private service directory, never
  in game databases. Media filenames are validated and existing audio is immutable.
  Do not back up this ephemeral directory. No public listing/search endpoint exists.

## Validation and limits

Scratch probes run two independent Win32 processes compiled against the production
plugin transport/state machine and real PHP HTTP service. They cover create/join,
outbound WAV upload, once-only playback dispatch, end/revocation and idle Off. Endpoint
probes cover roles, session isolation, upload limits, cancellation, join throttling and
scheduled cleanup. Game/audio/TaskManager boundaries in those probes are stubs.

Actual game UI/input and XAudio2 playback, two-PC NVMP, WAN delay, TLS proxy forwarding,
real concurrent-user load and hosting cost remain unverified. This service has not been
publicly deployed. The original local `multiplayer.php` remains separate and unchanged
except for an internal server-selected state-directory option in its shared library.

## Operator diagnostics

The PHP error log records `[public-relay]` for successful create/join/publish and control
operations. Match `session=` and `line=` with the client `Sharing:` logs: both use the
first 16 hexadecimal characters of SHA-256 over their identifiers. Entries contain the
operation, HTTP status and upload byte count, never tokens, codes, text or raw IDs.
Successful idle poll/audio/heartbeat requests are not logged here; clients record audio
download outcomes. Classified failures reaching the request dispatcher are limited to
ten diagnostic entries per minute globally. Early malformed requests and storage/lock
failures still rely on proxy access/error logs. These remote logs are not part of either
player's local DwemerDistro debugging bundle.
