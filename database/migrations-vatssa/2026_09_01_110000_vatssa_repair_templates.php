<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * VATSSA: the seeded templates and the bot disagreed about what T16 and T17 are.
 *
 * ## The bug
 *
 * The templates admin page was editing two emails that do not exist, and could
 * not edit two that do.
 *
 *   Control Center said     The bot actually sends
 *   ------------------      ----------------------
 *   T16  staff digest       T16  "you are not on our Discord"
 *   T17  mentor index       T17  "your training closes in 7 days"
 *
 * Control Center's T16 and T17 are the bot's S1 and P2 under the wrong keys.
 * The seed was written from an older numbering and never re-checked against
 * `config/templates/`, which is the only place the numbering is real -- the bot
 * loads a file per key and never asks Control Center what it thinks.
 *
 * The visible cost: somebody rewording the Discord chase in Control Center
 * changed nothing at all, and the bot went on sending the old text. Silent, and
 * exactly the kind of thing that gets discovered by a student quoting an email
 * back at you.
 *
 * ## Four other things this fixes
 *
 * **T8 and T9 are deleted.** T8 "Mentor assigned" duplicates upstream's
 * `TrainingMentorNotification` and T9 "Are you still with us" duplicates
 * `TrainingInterestNotification`. Both are sent by Control Center already. The
 * bot never wired either -- correctly, because it would have meant two emails
 * saying the same thing -- but they sat in the table looking like live,
 * editable messages. A template nothing sends is worse than a missing one: it
 * gets edited, and the edit does nothing.
 *
 * **The mentor index row is deleted.** Its channel was `thread`, and student
 * threads were dropped on 2026-08-27. `mentorindex.py` builds its body in code.
 *
 * **The staff digest moves to `S1`**, which is what the bot calls it, and stops
 * saying "S2 pipeline". It serves S1, S2, S3 and C1; the S2 wording is left
 * over from when this was one coordinator's tool and reads to everybody else as
 * an email meant for somebody else.
 *
 * **`{days_left}` in T6's subject** stays, but the body text no longer implies
 * a fixed 90 days -- the window differs by rating.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The two that were wrong. These bodies are copied from
        // `config/templates/T16.md` and `T17.md` in the bot, which is the
        // authority: it loads a file per key at boot and never asks Control
        // Center. Control Center's copy is what the ADMIN PAGE edits, and the
        // bridge is what pushes an edit back -- so they have to start identical
        // or the first edit looks like a revert.
        $this->put('T16', [
            'name' => 'Not on Discord — chase',
            'subject' => 'VATSSA ATC Training — you are not on our Discord',
            'channel' => 'email',
            'description' => 'Sent when a student in training leaves the Discord server. '
                . 'The first step of the ladder, before the closure warning.',
            'body' => <<<'TXT'
{name}, you are no longer on VATSSA's Discord server, and your {rating} training cannot continue without it.

Mentoring sessions run in its voice channels, your mentor reaches you there, and exams are booked and conducted there. Everything else in your training is still exactly where you left it — this is the one thing standing in the way.

Rejoin at {invite_link}. It takes a minute and nothing else changes.

If something is stopping you from using Discord, reply to this email and tell us. There is a way around it, but only if we know.
TXT,
        ]);

        $this->put('T17', [
            'name' => 'Not on Discord — closing in 7 days',
            'subject' => 'VATSSA ATC Training — your training closes in 7 days',
            'channel' => 'email',
            'description' => 'The last email before a training is closed for leaving Discord. '
                . 'Says what is lost and what is not, because the fear of losing a theory pass '
                . 'is what actually gets people back.',
            'body' => <<<'TXT'
{name}, we have asked several times and you are still not on VATSSA's Discord server.

Your {rating} training will be closed on {date} — that is 7 days from now.

Rejoin at {invite_link} before then and nothing happens. Your theory result, your sessions and your mentor's notes all stay exactly as they are.

If you rejoin after it closes you will have to apply again and wait for a mentor from the start, which is usually the longest part.

If something is stopping you from using Discord, reply to this email today. We can hold the training open, but not after it has closed.
TXT,
        ]);

        // The staff digest, under the key the bot uses, and no longer claiming
        // to be about S2.
        $this->put('S1', [
            'name' => 'Daily staff digest',
            'subject' => 'VATSSA ATC Training — action needed ({date})',
            'channel' => 'staff',
            'description' => 'The once-a-day list of everything the pipeline could not do by '
                . 'itself. Goes to the training staff address, not to a student. '
                . 'The subject line is built in the bot when items are counted, so editing '
                . 'it here changes the fallback only.',
            'body' => <<<'TXT'
Action needed on the ATC training pipeline.

{action}
TXT,
        ]);

        // Sent by Control Center itself, so a second copy from the bot would be
        // two emails saying one thing. Never wired; only ever editable.
        DB::table('vatssa_message_templates')->whereIn('key', ['T8', 'T9'])->delete();

        // Threads were dropped on 2026-08-27. The mentor index is built in code
        // by mentorindex.py and posted to the mentor's own thread, which is the
        // one thread that survived -- it has no template and does not need one.
        DB::table('vatssa_message_templates')->where('channel', 'thread')->delete();
    }

    public function down(): void
    {
        // Not reversible in any useful sense: the previous state was wrong, and
        // restoring two templates that address the wrong emails would put the
        // admin page back to editing text nothing sends.
        //
        // Rolling back leaves the corrected rows in place deliberately.
    }

    /**
     * Upsert by key, preserving `updated_by` so a repair does not look like
     * somebody edited the template by hand.
     */
    private function put(string $key, array $values): void
    {
        $values['key'] = $key;
        $values['updated_at'] = now();

        $exists = DB::table('vatssa_message_templates')->where('key', $key)->exists();

        if ($exists) {
            DB::table('vatssa_message_templates')->where('key', $key)->update($values);

            return;
        }

        $values['created_at'] = now();
        DB::table('vatssa_message_templates')->insert($values);
    }
};
