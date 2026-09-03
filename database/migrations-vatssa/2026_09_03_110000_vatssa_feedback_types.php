<?php

use App\Models\Vatssa\FeedbackType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * VATSSA: what kind of feedback this is, as a table rather than a constant.
 *
 * Upstream's feedback is one undifferentiated stream, so a compliment, a
 * complaint and a bug report arrive in the same queue and read the same. Three
 * different jobs with three different urgencies.
 *
 * The type lives in a `vatssa_`-prefixed column on upstream's own table, so
 * upstream can never collide with it, and it is NULLABLE: every row that exists
 * today was submitted before there was a question to answer, and backfilling
 * them with a guess would be inventing a record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vatssa_feedback_types', function (Blueprint $table) {
            $table->string('key', 40)->primary();
            $table->string('label', 80);
            $table->string('hint', 255)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(99);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        $order = 0;
        foreach (FeedbackType::FALLBACK as $key => $type) {
            FeedbackType::create([
                'key' => $key,
                'label' => $type['label'],
                'hint' => $type['hint'],
                'sort_order' => $order += 10,
                'active' => true,
            ]);
        }

        Schema::table('feedback', function (Blueprint $table) {
            $table->string('vatssa_type', 40)->nullable()->after('feedback');
        });
    }

    public function down(): void
    {
        Schema::table('feedback', function (Blueprint $table) {
            $table->dropColumn('vatssa_type');
        });

        Schema::dropIfExists('vatssa_feedback_types');
    }
};
