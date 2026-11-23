# Discord role sync

The VATSSA Discord bot (`ssa-discord-bot`) applies members' Discord roles. **Control Center decides them, through rules built on the Discord rules page.** Control Center never calls the bot; the bot asks.

## For staff

On a member's profile, under their VATSIM ID and name:

- **Discord:** "On the VATSSA Discord as @name", "Left the VATSSA Discord", "Not on the VATSSA Discord", or "Unknown" when the bot has not reported in the last two hours.
- **Sync Discord roles:** syncs this member now and asks VATSIM for their latest details. The line under it changes from "Requested…, waiting for the bot" to the result, for example "Last sync: Done, 1 minute ago. added atc_twr". After five minutes with no answer it says the bot did not pick it up; the bot's regular sweep still fixes the roles.
- **Discord history:** joined, left, linked, unlinked, and role changes.

**Who sees it:** staff with `users.manage` (admin, ATC Training Manager, Pipeline Coordinator) on every profile, and mentors on the profiles of members they teach.

**Discord members** (sidebar) lists every account in the server with its CID, Not VATSIM accounts, bots and people who left. Permission: `vatssa.discord.members.view`, admin by default.

**Discord rules** (sidebar): a rule is a name, conditions that must all hold, and the Discord roles it gives. A role is given if any enabled rule gives it. Conditions: VATSIM division or rating, CC role, endorsement, roster, CC group, in training (not the waiting list or familiarisation), Not VATSIM Member, each of which can be negated. Every change is in the rule history and can be restored. Permissions: `vatssa.discord.rules.view` and `vatssa.discord.rules.manage`, admin by default. `php artisan vatssa:discord-seed-rules` creates the starting rules once.

## How it works

**The queue.** One row per VATSIM ID. A member is queued when they join Discord, on login, when staff open their profile (at most once a minute), on the button, when anything that decides a role changes, and in a daily round at 06:00. The bot claims rows for two minutes. If it crashes, the row comes back. If the member changes during a sync, the row goes round again.

**VATSIM data.** Control Center owns rating and division: in `users` for anyone with a CC account, and in `vatssa_vatsim_members` for everyone the sync needs. The bot asks VATSIM only which CID a Discord account belongs to.

- **04:30:** the whole SSA list arrives with `VATSIM_CORE_API_TOKEN`. A failed, malformed or much shorter list (over 20% down) changes nothing.
- **Anyone who says SSA but is not on the list** (usually an expired login) is checked individually. Their old details stay until VATSIM answers. Only an answer naming another division counts as leaving.
- **`VATSSA_VATSIM_WRITE_USERS`** is off by default: VATSIM's answers are stored but `users` does not change. `php artisan vatssa:vatsim-leavers` lists who would leave and what they would lose.
- **With it on, a confirmed leaver** has `users` updated, and upstream's 05:00 job closes their training, removes mentor roles and takes them off the roster. At most 45 leavers are applied a day; the rest wait for the next day and `#tech-status` is told.
- **Other divisions:** a live CC login supplies the details. Anyone else, including people with no CC account, is checked individually and again after a day.
- **Checks** run three a minute, and not while the ATC hours job runs, because VATSIM allows about 10 calls a minute per server and CC's other jobs share that. After a rate limit, none run until VATSIM's `Retry-After` has passed. No answer never changes anything.

**One Discord account per CID.** The database enforces it. If two accounts claim a CID, the bot asks VATSIM about both, and the stale one loses it.

**API for the bot.** All under `/api/vatssa/`, all behind the bot token `VATSSA_DISCORD_BOT_TOKEN` (at least 32 characters). A Control Center API key opens none of them. Own rate limit: 3000 a minute, counted after the token check.

| Call | Does |
|---|---|
| `POST discord-queue/claim` | leases the next rows |
| `POST discord-queue/{cid}/result` | how a row ended |
| `POST discord-members` | who is in the server; a complete list marks anyone missing as left, an older complete list is refused |
| `POST discord-roles` | the roles CC decided for each account, and which roles it manages; an account with no VATSIM data yet comes back as unknown |
| `POST discord-guild-roles` | the server's roles, for the rule page |
| `POST controller-authorisation` | which online controllers may not be on their position. Send `{"controllers": [{"callsign": "FACT_TWR", "cid": 1234567, "type": "TWR"}]}`, at most 200. Returns `{"problems": {"FACT_TWR": "reason"}}` |
| `POST controller-names` | full names for the Online ATC board. Send `{"cids": [1234567]}`, at most 200 positive integers. Returns `{"names": {"1234567": "First Last"}}`. A CID with no usable name, or a pseudonymised member, is left out |

**Privacy.** A deleted or pseudonymised member's queue row, Discord link, history and VATSIM details are removed, including their CID in another account's history. A pseudonymised member gets no managed roles, and the bot cannot link or queue them again. The CID of every linked Discord account is stored, including people with no CC account, but never for a pseudonymised member. If a pseudonymised member signs in again, their link comes back at the next sweep. A permanently deleted user leaves no record to check against, so if they are still on VATSIM's SSA list, their CID, rating and division return at the next nightly pull, with no name or Discord link. The privacy policy amendment is flagged for review in January 2027.

**Code:** `VatssaDiscordSyncController`, `VatssaDiscordRuleController`, the Livewire components `VatssaDiscordRuleList`, `VatssaDiscordRuleEditor` and `VatssaDiscordMembers`, `VatssaDiscordRules`, `VatssaDiscordRuleStore`, `VatssaVatsimFacts`, the commands `vatssa:vatsim-pull` and `vatssa:discord-daily`, the `VatssaDiscordBotToken` middleware, the listeners in `VatssaServiceProvider::registerDiscordSyncListeners`, and the views in `resources/views/vatssa/user/discord-sync.blade.php` and `resources/views/vatssa/discord/`. **Tests:** `VatssaDiscordSyncTest`, `VatssaDiscordRulesTest`, `VatssaVatsimFactsTest`.

**The bot itself** is documented in its own repository. The full walkthrough is `ssa-ovh-management/docs/projects/controlcentre-v2/DISCORD-SYNC-EXPLAINED.md`.

---
*VATSSA Control Center documentation © 2026 Daniël Schoonraad*
