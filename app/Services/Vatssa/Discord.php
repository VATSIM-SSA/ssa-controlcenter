<?php

namespace App\Services\Vatssa;

use App\Models\Vatssa\ActionLog;
use Illuminate\Support\Facades\Http;

/**
 * VATSSA: Control Center's own Discord pings.
 *
 * ## One door, so the log cannot be forgotten
 *
 * Every ping goes through `send()`, and `send()` writes to the action log
 * whether it succeeded or not. That is the entire reason this class exists
 * rather than a `Http::post()` at each call site: a second send site added
 * later would be a ping nobody can account for, and "did the examiners get
 * told?" would have no answer.
 *
 * ## Webhooks, not the bot
 *
 * The training pipeline bot has a gateway connection and could post these. It
 * is the wrong tool: the bot runs on its own schedule and this has to fire the
 * moment an exam changes hands. A webhook is one outbound POST with no
 * connection to keep alive, and Control Center already makes outbound requests.
 *
 * ## Unconfigured means silent, not broken
 *
 * No webhook URL and `send()` records that it could not post and returns false.
 * The workflow carries on -- every stage change is on the page and in the log
 * regardless, so a missing webhook costs a notification, never a step.
 *
 * ## Never raises
 *
 * Discord being down must not fail the action that triggered the ping. An
 * examiner confirming an exam and getting a 500 because Discord was slow is a
 * worse outcome than a missing message, by a distance.
 */
class Discord
{
    /**
     * @param  string  $channel  a key from config('vatssa.discord'), not a URL.
     *                           Call sites name the audience; the config maps it
     *                           to a webhook, so moving a channel is one edit.
     */
    public function send(string $channel, string $message, ?int $trainingId = null,
        ?int $userId = null): bool
    {
        $url = config("vatssa.discord.{$channel}");

        if (! $url) {
            ActionLog::noticed(
                'discord.not_configured',
                'A message for the ' . $channel . ' channel was not sent: no webhook is set up.',
                $trainingId,
                $userId,
                ['channel' => $channel, 'message' => mb_substr($message, 0, 200)],
                ActionLog::ACTOR_SYSTEM,
                mirror: false,
            );

            return false;
        }

        try {
            // Discord's own limit is 2000 characters and it rejects the whole
            // request over it, so a long message would fail entirely rather
            // than arrive truncated.
            $response = Http::timeout(5)->post($url, [
                'content' => mb_substr($message, 0, 1900),
                // No @everyone, ever, whatever the message contains. A ping
                // that can mention a role by accident is one somebody will
                // trigger at 3am.
                'allowed_mentions' => ['parse' => []],
            ]);

            $ok = $response->successful();
        } catch (\Throwable $e) {
            report($e);
            $ok = false;
        }

        if ($ok) {
            ActionLog::did(
                'discord.sent',
                'Posted to ' . $channel . ': ' . mb_substr($message, 0, 180),
                $trainingId,
                $userId,
                ['channel' => $channel],
                ActionLog::ACTOR_SYSTEM,
                mirror: false,
            );
        } else {
            // A warning, not an info row. Nobody was told, and the only way
            // anybody finds out is if this page says so.
            ActionLog::noticed(
                'discord.failed',
                'Could not post to ' . $channel . ': ' . mb_substr($message, 0, 160),
                $trainingId,
                $userId,
                ['channel' => $channel],
                ActionLog::ACTOR_SYSTEM,
                mirror: false,
            );
        }

        return $ok;
    }
}
