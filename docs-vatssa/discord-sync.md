# Discord role sync

The VATSSA Discord bot (`ssa-discord-bot`) gives members their Discord roles. Control Center holds the data and the queue. Control Center never calls the bot; the bot asks.

## For staff

On a member's profile, under their VATSIM ID and name:

- **Discord:** "On the VATSSA Discord as @name", "Left the VATSSA Discord", "Not on the VATSSA Discord", or "Unknown" when the bot has not reported in the last two hours.
- **Sync Discord roles:** syncs this member now and asks VATSIM for their latest details. The line under it changes from "Requested…, waiting for the bot" to the result, for example "Last sync: Done, 1 minute ago. added atc_twr". After five minutes with no answer it says the bot did not pick it up; the bot's regular sweep still fixes the roles.
- **Discord history:** joined, left, linked, unlinked, and role changes.

**Who sees it:** staff with `users.manage` (admin, ATC Training Manager, Pipeline Coordinator) on every profile, and mentors on the profiles of members they teach.

**Discord members** (sidebar) lists every account in the server with its CID, Not VATSIM accounts, bots and people who left. Permission: `vatssa.discord.members.view`, admin by default.

## How it works

**The queue.** One row per VATSIM ID. A member is queued on login, when staff open their profile (at most once a minute), on the button, when anything that decides a role changes, and in a daily round at 06:00. The bot claims rows for two minutes. If it crashes, the row comes back. If the member changes during a sync, the row goes round again.

**VATSIM data.** Control Center owns rating and division, in `vatssa_vatsim_members`. The bot asks VATSIM only which CID a Discord account belongs to.

- **04:30:** the whole SSA list arrives with `VATSIM_CORE_API_TOKEN`. A failed, malformed or much shorter list (over 20% down) changes nothing.
- **Anyone who says SSA but is not on the list** (usually an expired login) is checked individually. Their old details stay until VATSIM answers.
- **Other divisions:** a live CC login supplies the details. Anyone else, including people with no CC account, is checked individually and again after a day.
- **Checks** run every minute, about one a second, and stop on a rate limit. No answer never changes anything.
- `users` is never written: upstream closes trainings and removes mentors when `users.division` changes.

**One Discord account per CID.** The database enforces it. If two accounts claim a CID, the bot asks VATSIM about both, and the stale one loses it.

**API for the bot.** All under `/api/vatssa/`, all behind the bot token `VATSSA_DISCORD_BOT_TOKEN` (at least 32 characters). A Control Center API key opens none of them. Own rate limit: 600 a minute.

| Call | Does |
|---|---|
| `POST discord-queue/claim` | leases the next rows |
| `POST discord-queue/{cid}/result` | how a row ended |
| `POST discord-members` | who is in the server; a complete list marks anyone missing as left, an older complete list is refused |
| `POST vatsim-facts` | rating and division for a list of CIDs; unknown ones go in line for a check |

**Privacy.** A deleted or pseudonymised member's queue row, Discord link, history and VATSIM details are removed, and the bot cannot link them again. CIDs are stored against Discord accounts only for CC users. If a pseudonymised member signs in again, their link comes back at the next sweep. The privacy policy amendment is flagged for review in January 2027.

**Code:** `VatssaDiscordSyncController`, `VatssaVatsimFacts`, the commands `vatssa:vatsim-pull` and `vatssa:discord-daily`, the `VatssaDiscordBotToken` middleware, the listeners in `VatssaServiceProvider::registerDiscordSyncListeners`, and the views in `resources/views/vatssa/user/discord-sync.blade.php` and `resources/views/vatssa/discord/`. **Tests:** `VatssaDiscordSyncTest`, `VatssaVatsimFactsTest`.

**The bot itself** is documented in its own repository. Rules built in Control Center are the next phase, designed in `ssa-ovh-management/docs/projects/controlcentre-v2/DISCORD-SYNC-V2.md`.

---
*VATSSA Control Center documentation © 2026 Daniël Schoonraad*
