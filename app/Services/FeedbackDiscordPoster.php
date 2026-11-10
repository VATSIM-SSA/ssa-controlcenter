<?php

namespace App\Services;

use anlutro\LaravelSettings\Facade as Setting;
use App\Helpers\FeedbackSentiment;
use App\Models\Feedback;
use Illuminate\Support\Str;

/**
 * Posts sent feedback to a public Discord channel through a webhook, for
 * example to share compliments. Only the version sent to the controller is
 * posted, and the submitter is never named. For the staff channel, which
 * sees every submission, see FeedbackStaffDiscord.
 */
class FeedbackDiscordPoster
{
    /** Discord's limit on an embed description. */
    private const DESCRIPTION_LIMIT = 4096;

    public function __construct(private DiscordWebhook $discord) {}

    public function isConfigured(): bool
    {
        return $this->webhookUrl() !== null;
    }

    /**
     * @return bool whether Discord accepted the post
     */
    public function post(Feedback $feedback): bool
    {
        if (! $this->isConfigured() || ! $feedback->wasSent()) {
            return false;
        }

        $feedback->loadMissing(['referenceUser', 'referencePosition']);
        $position = $feedback->sessionLabel() ?? $feedback->referencePosition?->callsign ?? $feedback->session_callsign;

        $messageId = $this->discord->post($this->webhookUrl(), [
            'username' => config('app.owner_name_short') . ' Feedback',
            'allowed_mentions' => ['parse' => []],
            'embeds' => [[
                'title' => 'Feedback for ' . ($feedback->referenceUser?->name ?? 'a controller'),
                'description' => Str::limit((string) $feedback->sentText(), self::DESCRIPTION_LIMIT - 3),
                'color' => $feedback->sentiment === FeedbackSentiment::POSITIVE ? 0x2E7D32 : 0x607D8B,
                'fields' => $position ? [['name' => 'Position', 'value' => $position, 'inline' => true]] : [],
                'timestamp' => $feedback->created_at->toIso8601String(),
            ]],
        ]);

        if ($messageId === null) {
            return false;
        }

        $feedback->update(['discord_posted_at' => now(), 'discord_message_id' => $messageId]);

        return true;
    }

    /**
     * Delete the feedback's Discord post, for a withdrawal.
     *
     * @return bool whether the post is no longer on Discord
     */
    public function remove(Feedback $feedback): bool
    {
        if ($feedback->discord_message_id === null || ! $this->isConfigured()) {
            return false;
        }

        if (! $this->discord->delete($this->webhookUrl(), $feedback->discord_message_id)) {
            return false;
        }

        $feedback->update(['discord_posted_at' => null, 'discord_message_id' => null]);

        return true;
    }

    private function webhookUrl(): ?string
    {
        $url = Setting::get('feedbackDiscordWebhook');

        return is_string($url) && $url !== '' ? $url : null;
    }
}
