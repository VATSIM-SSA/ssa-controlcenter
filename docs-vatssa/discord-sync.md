# Discord role sync

The VATSSA Discord bot (`ssa-discord-bot`) gives members their Discord roles from Control Center and VATSIM data. Control Center tells the bot who to sync and shows staff what happened. Control Center never calls the bot; the bot asks.

## For staff

On a member's profile, under their VATSIM ID and name:

- **Discord:** "On the VATSSA Discord as @name", "Not on the VATSSA Discord", or "Unknown" when the bot has not reported in the last two hours. "Several accounts" appears if more than one Discord account claims the same VATSIM ID.
- **Sync Discord roles:** asks the bot to sync this member now instead of at its next round. Within about ten seconds the line under it changes from "Requested…, waiting for the bot" to the result, for example "Last sync: Done, 1 minute ago. added atc_twr". After five minutes with no answer it says the bot did not pick it up; the bot's regular sweep still fixes the roles.
- "Last sync (automatic)" and "Change detected" mean Control Center queued the sync itself, because something that decides a role changed.

**Who sees it:** staff with `users.manage` (admin, ATC Training Manager, Pipeline Coordinator) on every profile, and mentors on the profiles of members they teach.

## How it works

**Instant sync.** Control Center queues a member whenever something that decides a Discord role changes: an endorsement granted, revoked, expired or shortened; a role granted or removed; a training opened, closed or moved to another student; roster status; rating or division; group membership. Three nightly jobs update the roster in bulk and fire no events; the bot's 15-minute sweep covers those.

**Settling.** An automatic request is written inside the save, before that request attaches the endorsement's ratings and areas. The bot is shown it only after five seconds (`AUTO_SETTLE_SECONDS`).

**Tables:** `vatssa_discord_sync_requests` (the queue: VATSIM ID, button or automatic, the bot's result) and `vatssa_discord_presence` (which Discord account belongs to which VATSIM ID). Requests older than 30 days are pruned, never the newest row.

**API for the bot.** All under `/api/vatssa/`, all behind the bot token `VATSSA_DISCORD_BOT_TOKEN` (at least 32 characters). A Control Center API key opens none of them. Own rate limit: 600 a minute.

| Call | Does |
|---|---|
| `GET discord-sync-requests?after=<id>` | requests after the bot's cursor, plus `max_id` |
| `POST discord-sync-requests/{id}/result` | how a request ended |
| `POST discord-presence` | who is in the Discord server; a complete list replaces the table, a partial one only adds; an older complete list is refused |

**Privacy.** A permanently deleted or pseudonymised member's requests and Discord link are removed, and the bot cannot store them again. If a pseudonymised member signs in again, their link comes back at the next sweep. The privacy policy amendment is flagged for review in January 2027.

**Code:** `VatssaDiscordSyncController`, the `VatssaDiscordBotToken` middleware, `config/vatssa.php`, the listeners in `VatssaServiceProvider::registerDiscordSyncListeners`, and the view `resources/views/vatssa/user/discord-sync.blade.php`. **Tests:** `tests/Feature/VatssaDiscordSyncTest.php`.

**The bot itself** is documented in its own repository (`HOW-IT-WORKS.md`). Version 2, with rules built in Control Center, is designed in `ssa-ovh-management/docs/projects/controlcentre-v2/DISCORD-SYNC-V2.md`.

---
*VATSSA Control Center documentation © 2026 Daniël Schoonraad*
