<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * VATSSA: V4 — the mentor is told they have a student.
 *
 * ## The gap
 *
 * `TrainingController` notifies the STUDENT when a mentor is attached and tells
 * the mentor nothing at all. The mentor found out by opening My students and
 * noticing a row that had not been there yesterday, or by the student emailing
 * them first.
 *
 * Backwards in a pipeline whose commonest stall is nobody making the first
 * move: the student is told to make contact within seven days or lose their
 * place, and the mentor is not told to expect them.
 *
 * Editable from day one, like V1 to V3, so the wording is training staff's
 * rather than whoever holds the repository.
 */
return new class extends Migration
{
    public function up(): void
    {
        $key = 'V4';

        if (DB::table('vatssa_message_templates')->where('key', $key)->exists()) {
            // Never overwrite a body somebody has already edited.
            return;
        }

        DB::table('vatssa_message_templates')->insert([
            'key' => $key,
            'name' => 'Mentor assigned — to the mentor',
            'subject' => 'A new student for you',
            'channel' => 'email',
            'description' => 'Sent to a mentor when a student is assigned to them. '
                . 'Placeholders: {name}, {student}, {cid}, {rating}. {rating} is empty '
                . 'on a training with no rating attached, so do not write a sentence '
                . 'that needs it.',
            'body' => <<<'TXT'
**{student} ({cid})** has been assigned to you for their {rating} training.

They have been told to contact you within seven days. If they do not, say so on the training rather than waiting it out — the request is closed from there, not by silence.

Their theory result, previous sessions and any notes are on the training page.
TXT,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('vatssa_message_templates')->where('key', 'V4')->delete();
    }
};
