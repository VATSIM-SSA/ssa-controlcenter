<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('feedback', function (Blueprint $table) {
            // The controller's session the feedback is about, picked from their recent sessions.
            $table->string('session_callsign', 20)->nullable()->after('area_id');
            $table->timestamp('session_started_at')->nullable()->after('session_callsign');
            $table->timestamp('session_ended_at')->nullable()->after('session_started_at');

            // Staff review.
            $table->string('status', 16)->default('open')->index();
            $table->string('sentiment', 16)->nullable();
            $table->unsignedBigInteger('closed_by_user_id')->nullable();
            $table->timestamp('closed_at')->nullable();

            // The version the controller currently has, if any: the original text or a
            // rewritten one. Withdrawing clears it; the timeline keeps the history.
            $table->string('sent_version', 16)->nullable();
            $table->text('sent_message')->nullable();
            $table->unsignedBigInteger('sent_by_user_id')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('discord_posted_at')->nullable();
            $table->string('discord_message_id', 32)->nullable();

            // The feedback's message in the staff Discord channel, edited as the review goes on.
            $table->string('staff_discord_message_id', 32)->nullable();

            $table->foreign('closed_by_user_id')->references('id')->on('users')->onUpdate('CASCADE')->onDelete('SET NULL');
            $table->foreign('sent_by_user_id')->references('id')->on('users')->onUpdate('CASCADE')->onDelete('SET NULL');
        });

        // The old flag was never written by the application; carry it over in case an installation did.
        DB::table('feedback')->where('forwarded', true)->update([
            'status' => 'closed',
            'sent_version' => 'original',
            'sent_at' => DB::raw('updated_at'),
        ]);

        Schema::table('feedback', function (Blueprint $table) {
            $table->dropColumn('forwarded');
        });

        Schema::create('feedback_activities', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('feedback_id');
            $table->unsignedBigInteger('triggered_by_id')->nullable();
            $table->string('type', 16);
            $table->json('data')->nullable();
            $table->text('comment')->nullable();
            $table->timestamps();

            $table->index(['feedback_id', 'created_at']);
            $table->foreign('feedback_id')->references('id')->on('feedback')->onUpdate('CASCADE')->onDelete('CASCADE');
            $table->foreign('triggered_by_id')->references('id')->on('users')->onUpdate('CASCADE')->onDelete('SET NULL');
        });

        // Notices a member must confirm they have read. Generic, so other notices can use it later.
        Schema::create('acknowledgements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->morphs('subject');
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'acknowledged_at']);
            $table->foreign('user_id')->references('id')->on('users')->onUpdate('CASCADE')->onDelete('CASCADE');
        });

        // Discord webhooks: one public channel for shared feedback, one staff channel for every submission.
        $settings = Config::get('settings.table');
        foreach (['feedbackDiscordWebhook', 'feedbackStaffDiscordWebhook'] as $key) {
            if (! DB::table($settings)->where('key', $key)->exists()) {
                DB::table($settings)->insert(['key' => $key, 'value' => '']);
            }
        }
    }

    public function down(): void
    {
        DB::table(Config::get('settings.table'))->whereIn('key', ['feedbackDiscordWebhook', 'feedbackStaffDiscordWebhook'])->delete();

        Schema::dropIfExists('acknowledgements');
        Schema::dropIfExists('feedback_activities');

        Schema::table('feedback', function (Blueprint $table) {
            $table->boolean('forwarded')->default(false);
        });

        DB::table('feedback')->whereNotNull('sent_at')->update(['forwarded' => true]);

        Schema::table('feedback', function (Blueprint $table) {
            $table->dropForeign(['closed_by_user_id']);
            $table->dropForeign(['sent_by_user_id']);
            $table->dropIndex(['status']);
            $table->dropColumn([
                'session_callsign', 'session_started_at', 'session_ended_at',
                'status', 'sentiment', 'closed_by_user_id', 'closed_at',
                'sent_version', 'sent_message', 'sent_by_user_id', 'sent_at', 'discord_posted_at', 'discord_message_id',
                'staff_discord_message_id',
            ]);
        });
    }
};
