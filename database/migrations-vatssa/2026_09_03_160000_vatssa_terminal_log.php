<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * VATSSA: the record of what was done on VATSIM Terminal, and the catalogue of
 * comments pasted into it.
 *
 * Four Google Sheets become one log with a type column plus a template table.
 * The sheets were separate because a spreadsheet cannot filter one table into
 * three views; a database can, so the split becomes a column.
 *
 * ## Why the actor is two columns
 *
 * `actor_user_id` is whoever pressed the button, captured at the moment they
 * press it. `actor_name` is for the case that made this table necessary: the
 * action happened on Terminal, by somebody who was not in Control Center at the
 * time, and is being recorded afterwards. A dropdown of Control Center accounts
 * cannot express that, and forcing the person entering it to put their own name
 * on somebody else's action would make the log actively wrong.
 *
 * At least one of the two is always set; the model enforces it.
 *
 * ## Why `performed_at` is not `created_at`
 *
 * Half of these rows are entered after the fact. Conflating when it HAPPENED
 * with when it was TYPED would make every backfilled row look like it happened
 * the day somebody got round to recording it, which is exactly the question an
 * audit asks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vatssa_terminal_comments', function (Blueprint $table) {
            // SSA-VT-001 and friends. The code is quoted in Terminal itself, so
            // it is the key rather than a surrogate id.
            $table->string('code', 20)->primary();
            $table->string('label', 100);
            // MM, DD, ATM -- whichever team's name goes in the comment.
            $table->string('team', 20);
            $table->string('category', 30)->nullable();
            $table->text('body');
            $table->string('description', 255)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(99);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('vatssa_terminal_log', function (Blueprint $table) {
            $table->id();

            $table->string('type', 20);
            $table->string('reason', 30);

            // Who it was ABOUT.
            $table->unsignedBigInteger('user_id');

            // Who DID it. See the note above on why this is two columns.
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('actor_name', 100)->nullable();

            // Who typed the row, which is not the same question and is never
            // editable. It is the answer to "who says this happened".
            $table->unsignedBigInteger('recorded_by');

            $table->unsignedBigInteger('membership_request_id')->nullable();

            $table->string('comment_code', 20)->nullable();

            // A rating change, from and to. Null on a query, which changed
            // nothing.
            $table->unsignedInteger('rating_from_id')->nullable();
            $table->unsignedInteger('rating_to_id')->nullable();

            // A disciplinary check. `null` means this row was not one -- which
            // is different from a check that found nothing, and the difference
            // is why a clean check is worth recording at all.
            $table->boolean('discipline_found')->nullable();
            $table->text('discipline_context')->nullable();

            $table->text('notes')->nullable();

            $table->timestamp('performed_at');
            $table->timestamps();

            $table->index(['type', 'performed_at']);
            $table->index(['user_id', 'performed_at']);

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('actor_user_id')->references('id')->on('users')->nullOnDelete();
            // NOT nullOnDelete: losing the person who recorded a CERT access
            // must not lose the fact that somebody did. Restricting the delete
            // is the honest failure -- it makes the problem visible instead of
            // quietly emptying an audit column.
            $table->foreign('recorded_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('membership_request_id')->references('id')->on('vatssa_membership_requests')->nullOnDelete();
            $table->foreign('rating_from_id')->references('id')->on('ratings')->nullOnDelete();
            $table->foreign('rating_to_id')->references('id')->on('ratings')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vatssa_terminal_log');
        Schema::dropIfExists('vatssa_terminal_comments');
    }
};
