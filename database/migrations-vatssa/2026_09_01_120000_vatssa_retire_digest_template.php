<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * VATSSA: the staff digest is not an editable template, so stop offering it.
 *
 * ## What was wrong
 *
 * The `S1` row said "S2 pipeline — action needed ({date})" and was editable on
 * the templates admin page. Two things were false about that.
 *
 * **Nothing read it.** `digest.py` builds its own subject and its own body from
 * the day's outcome -- sections that appear only when they have content -- and
 * never opens a template. Somebody rewording this row changed nothing, which is
 * the same silent failure the T16/T17 repair was about.
 *
 * **It named the wrong rating.** One digest covers S1, S2, S3 and C1, and every
 * one of those students appears in the same email. A coordinator working an S3
 * read a subject line telling them it was somebody else's pipeline. The bot now
 * calls it "ATC training pipeline" in all six places it used to say S2.
 *
 * The template file `config/templates/S1.md` is deleted on the bot side in the
 * same change, along with `P2.md`, `T8.md` and `T9.md`.
 *
 * ## Why delete rather than fix the wording
 *
 * A template row is a promise that editing it changes an email. The digest's
 * body is structured data, not prose -- it cannot come from a markdown file
 * without losing the thing that makes it readable. Better no row than a row
 * that lies.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('vatssa_message_templates')->where('key', 'S1')->delete();
    }

    public function down(): void
    {
        // Deliberately not restored. Putting the row back would put an
        // uneditable email back on the editable page.
    }
};
