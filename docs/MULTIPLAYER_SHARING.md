# Optional multiplayer dialogue sharing

This beta relays ordinary NPC speech from one Dialectic player to other players.
It requires the matching Dialectic plugin change. It does not integrate with NVMP's
entity ownership, synchronize followers, or let listening players submit AI requests.
The dialogue host may be a different PC from the NVMP dedicated server.

## Server setup

1. Copy `conf/multiplayer.example.php` to `conf/multiplayer.php`.
2. Set a session name using letters, numbers, underscores or hyphens (1-64 characters).
3. Generate two different random keys, one for the host and one for listeners. For each:

   ```sh
   php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
   ```

4. Set `host_key` and `listen_key` in the local file. Blank keys disable the endpoint.
5. Give listeners only the listener key and the URL ending in `/multiplayer.php`.

The relay must run in the same DialecticServer installation that generates the host's
speech. It reuses existing `soundcache` files and never calls an AI or TTS provider.
The PHP account needs a writable system temporary directory. No database migration,
worker service or playthrough storage change is required.

For internet use, expose **only** `multiplayer.php` through an HTTPS reverse proxy
that preserves the `Authorization` header. Do not expose the control panel, game-data
endpoints, configuration or the full soundcache for sharing. Configure proxy request
limits normally; the endpoint also caps authenticated host/listener requests.
Direct HTTP is supported by the plugin only for loopback and private IPv4 addresses.

## Plugin setup on each PC

Add this section to `Data/NVSE/Plugins/dialectic_custom.ini` on the dialogue host:

```ini
[Multiplayer]
Mode=1
URL=http://192.168.1.20:8085/DialecticServer/multiplayer.php
Session=friends
Key=PASTE_HOST_KEY_HERE
```

On each listener, use the same URL/session, `Mode=2`, and the **listener** key.
Keep the host's normal DialecticServer connection configured as before. Listeners
use only the sharing URL. Restart the game after initial configuration, or close
MCM after changing its Tools > (Beta) Multiplayer Dialogue Sharing setting to reload the INI.

The setting uses the existing numeric MCM control: **0 Off**, **1 Host**, **2 Listen**.
Off is the shipped default. A listener remains a listener when disconnected; it
does not fall back to running its own AI. Set Off to resume ordinary Dialectic use.
Do not combine listening with a Discord stream of the same speech or it will echo.

## Behavior and limits

- One active dialogue host per configured session, with multiple independent listeners.
- Ordinary NPC speech is published only after the host starts playing it. Narrator
  and Player TTS/head voices are excluded. Text-only fallbacks are not shared.
- Listeners play centered PCM audio with passive subtitles and their local voice
  volume. No actor lookup, facing, lip sync, NPC actions, rechat, world-state uploads
  or authoritative delivery acknowledgments are performed for shared speech.
- Audio follows the host with network/download delay. Playback is ordered, not
  sample-synchronized. Host pause/resume and cancellation are relayed. Local menus
  also respect the listener's existing pause setting.
- Joining, rejoining, a host reset, or missing history starts at live speech. An
  already-started line is not replayed for a new listener.
- Host mode changes and leaving the game send a best-effort close. A crash or process
  exit expires through the 15-second host lease. No automatic host election occurs.
- Listener polling is at most twice per second, with one request task in flight.
  Failed connections back off to eight seconds. Host idle heartbeats run every three
  seconds. No relay tasks are submitted when Off.
- The relay exposes at most 128 events from the last 120 seconds; each poll returns
  one event. It caps host requests at 20/second and all listener requests combined
  at 64/second. Each WAV is capped at 16 MiB. Listeners retain at most two waiting
  WAVs and drop waiting audio after 30 seconds; unavailable audio is skipped.
- Session data lives in one private system-temporary file per installation. Expired
  events are pruned on the next successful request. The file can remain while idle;
  it is never a conversation-history or playthrough-save store. Keys are not stored
  in it. Normal soundcache retention is unchanged.

## Verification before claiming NVMP compatibility

Use two PCs with the same supported plugin/server feature versions. Confirm speech
and subtitles arrive once, only the host generates AI/TTS requests, and listeners
do not upload game state or execute actor commands. Exercise pause/resume, interruption,
load/save, cell changes, host quit, listener reconnect, missing WAVs and invalid keys.
Verify Off retains ordinary single-player behavior. Test JIP recruitment/dismissal
and NVMP follower ownership separately; successful audio sharing does not prove those.

For transport problems, retain both plugin logs and the server's PHP log. Sharing
keys must be removed from any configuration submitted with a report.
