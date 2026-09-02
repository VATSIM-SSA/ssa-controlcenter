<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * VATSSA: the fork's own three emails become editable.
 *
 * ## Why they were not
 *
 * `MentorLostNotification`, `StudentRemovedFromMentorNotification` and
 * `RosterExpiringNotification` are ours rather than upstream's, and they were
 * written as PHP arrays inside the notification class. Every other message in
 * the pipeline could be reworded on a page; these three needed a developer, a
 * commit and a deploy to change a sentence.
 *
 * That is the wrong split. Nothing about these three is more delicate than T16,
 * which tells a student they are about to lose their training — and the people
 * who know what they should say are the training staff, not whoever holds the
 * repository.
 *
 * ## How it works
 *
 * `MessageTemplate::compose()` fills the placeholders and splits the body on
 * blank lines into paragraphs, because a blank line is what somebody typing
 * into a textarea means by one.
 *
 * **A missing row falls back to the compiled text.** That is deliberate and it
 * is what makes this safe: a deleted row, an unmigrated database or a typo in a
 * key must never turn into a member receiving nothing at all. A worse-worded
 * email beats a missing one.
 *
 * ## The bodies below are the compiled text, verbatim
 *
 * Seeded as they are so that turning them into rows changes nothing on the day
 * it runs. Anybody comparing an email received before and after this migration
 * should see the same words.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->put('V1', [
            'name' => 'Mentor lost — to the student',
            'subject' => 'You are back on the waiting list',
            'channel' => 'email',
            'description' => 'Sent by Control Center when a training loses its mentor. '
                . 'Placeholders: {name}, {mentor}, {rating}. {mentor} is empty when '
                . 'the mentor cannot be named, so do not write a sentence that needs it.',
            'body' => <<<'TXT'
You have been placed back on the waiting list for your {rating} training.

**{mentor} is no longer able to mentor you.** That is nothing to do with you or with your progress.

We are finding you a new mentor. Everything you have already done still counts — your theory result, your sessions and your notes all stay on your training, and your new mentor picks up where the last one left off.

We cannot say yet how long that will take. If you have not heard anything in a few weeks, reply to this email and ask — chasing it is welcome, not a nuisance.
TXT,
        ]);

        $this->put('V2', [
            'name' => 'Student removed — to the mentor',
            'subject' => 'A student has been taken off your list',
            'channel' => 'email',
            'description' => 'Sent by Control Center when a student comes off a mentor\'s list. '
                . 'Placeholders: {name}, {student}, {rating}, {reason}. {reason} is empty '
                . 'unless somebody gave one.',
            'body' => <<<'TXT'
**{student}** is no longer assigned to you for their {rating} training.

They have been returned to the mentor queue and a coordinator is finding them somebody else.

Your capacity has gone up by one, so you may be offered another student sooner than you expected.

If this is wrong — you are still working with them, or you meant to keep them — tell your pipeline coordinator and it can be put straight in one click.
TXT,
        ]);

        $this->put('V3', [
            'name' => 'Roster place lapsing',
            'subject' => 'Your roster place lapses in a week',
            'channel' => 'email',
            'description' => 'Sent by Control Center before a controller falls off the roster. '
                . 'Placeholders: {name}, {date}, {days_left}, {hours}, {requirement}.',
            'body' => <<<'TXT'
Your place on the VATSSA controller roster lapses on **{date}** — that is in {days_left} days.

**You have {hours} hours. The requirement is {requirement}.**

Controlling before that date keeps your roster place. If it lapses you will need refresher training before you can control again, which usually means waiting for a mentor.

If something is stopping you from controlling, reply to this email and tell us — a leave of absence is far easier to arrange than a refresher.
TXT,
        ]);
    }

    public function down(): void
    {
        // Safe to reverse: the notifications fall back to their compiled text
        // when the row is gone, so removing these returns the wording to what
        // it was before rather than breaking a send.
        DB::table('vatssa_message_templates')->whereIn('key', ['V1', 'V2', 'V3'])->delete();
    }

    private function put(string $key, array $values): void
    {
        $values['key'] = $key;
        $values['updated_at'] = now();

        if (DB::table('vatssa_message_templates')->where('key', $key)->exists()) {
            // Never overwrite a body somebody has already edited. Re-running a
            // migration must not undo an evening's wording.
            return;
        }

        $values['created_at'] = now();
        DB::table('vatssa_message_templates')->insert($values);
    }
};
