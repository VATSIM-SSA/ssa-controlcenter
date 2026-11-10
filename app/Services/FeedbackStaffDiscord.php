<?php

namespace App\Services;

use anlutro\LaravelSettings\Facade as Setting;
use App\Helpers\FeedbackDelivery;
use App\Helpers\FeedbackSentiment;
use App\Models\Feedback;
use Illuminate\Container\Attributes\Scoped;

/**
 * Keeps one message per feedback in a staff Discord channel: posted when the
 * feedback is submitted, then edited whenever it changes, so the channel is a
 * live overview of status, tone and what the controller has.
 *
 * The feedback text itself never goes to Discord: the message says there is
 * feedback, with its details and state, and links to it in Control Center.
 * It does name the submitter, so the channel should be staff-only.
 *
 * Changes are collected during a request and each message is updated once,
 * after the response is sent, so closing and commenting in one go is a single
 * edit and a slow Discord never delays the page.
 */
#[Scoped]
class FeedbackStaffDiscord
{
    /** @var array<int, true> ids of feedback to update after the response */
    private array $pending = [];

    public function __construct(private DiscordWebhook $discord) {}

    public function isConfigured(): bool
    {
        return $this->webhookUrl() !== null;
    }

    /**
     * Update the feedback's staff message once the current request is done.
     */
    public function refreshLater(int $feedbackId): void
    {
        if (! $this->isConfigured()) {
            return;
        }

        if ($this->pending === []) {
            app()->terminating(fn () => $this->flush());
        }

        $this->pending[$feedbackId] = true;
    }

    /**
     * Post the feedback's staff message, or edit it if it exists. A message
     * that has gone (deleted, or posted through an earlier webhook) is posted
     * again, so the channel always has a current one.
     */
    public function refresh(Feedback $feedback): void
    {
        $url = $this->webhookUrl();

        if ($url === null) {
            return;
        }

        $payload = $this->payload($feedback);

        if ($feedback->staff_discord_message_id !== null
            && $this->discord->edit($url, $feedback->staff_discord_message_id, $payload) !== null) {
            return;
        }

        $messageId = $this->discord->post($url, $payload);

        if ($messageId !== null) {
            // Quietly: this bookkeeping is not a change staff made.
            Feedback::whereKey($feedback->getKey())->update(['staff_discord_message_id' => $messageId]);
            $feedback->staff_discord_message_id = $messageId;
        }
    }

    private function flush(): void
    {
        $ids = array_keys($this->pending);
        $this->pending = [];

        Feedback::with(['submitter', 'referenceUser', 'referencePosition.area', 'explicitArea', 'closedBy', 'sentBy', 'latestAcknowledgement', 'activities.triggeredBy'])
            ->whereKey($ids)
            ->get()
            ->each(fn (Feedback $feedback) => $this->refresh($feedback));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Feedback $feedback): array
    {
        $feedback->loadMissing(['submitter', 'referenceUser', 'referencePosition.area', 'explicitArea', 'closedBy', 'sentBy', 'latestAcknowledgement', 'activities.triggeredBy']);

        $person = fn ($user): string => $user ? "{$user->name} ({$user->id})" : '—';
        $latest = $feedback->activities->sortByDesc('id')->first();

        $fields = [
            ['name' => 'Controller', 'value' => $person($feedback->referenceUser), 'inline' => true],
            ['name' => 'Position', 'value' => $feedback->sessionLabel() ?? $feedback->referencePosition?->callsign ?? '—', 'inline' => true],
            ['name' => 'Area', 'value' => $feedback->area?->name ?? '—', 'inline' => true],
            ['name' => 'Submitter', 'value' => $person($feedback->submitter), 'inline' => true],
            ['name' => 'Flight', 'value' => $feedback->flightLabel() ?? '—', 'inline' => true],
            ['name' => 'Received', 'value' => $feedback->created_at->toEuropeanDateTime(), 'inline' => true],
        ];

        if ($latest) {
            $fields[] = ['name' => 'Latest', 'value' => $latest->summary() . ' · ' . ($latest->triggeredBy?->name ?? 'System') . ', ' . $latest->created_at->toEuropeanDateTime()];
        }

        // The state first, as a heading. The feedback text itself is never sent
        // to Discord: staff read it in Control Center through the link.
        $state = '### ' . implode(' · ', [$this->reviewLine($feedback), $this->toneLine($feedback), $this->deliveryLine($feedback)]);

        return [
            'username' => config('app.owner_name_short') . ' Feedback',
            'allowed_mentions' => ['parse' => []],
            // Empty, so an edit also clears text an earlier version put above the card.
            'content' => '',
            'embeds' => [[
                'title' => 'Feedback #' . $feedback->id . ($feedback->referenceUser ? ' about ' . $feedback->referenceUser->name : ''),
                'url' => route('feedback.show', $feedback),
                'description' => $state . "\nRead it in Control Center: [open feedback #{$feedback->id}](" . route('feedback.show', $feedback) . ')',
                'color' => $this->color($feedback),
                'fields' => $fields,
                'footer' => ['text' => 'Updates as staff review it'],
                'timestamp' => now()->toIso8601String(),
            ]],
        ];
    }

    private function reviewLine(Feedback $feedback): string
    {
        if ($feedback->isOpen()) {
            return '🟡 Open';
        }

        return '✅ Closed' . ($feedback->closedBy ? ' by ' . $feedback->closedBy->name : '');
    }

    private function toneLine(Feedback $feedback): string
    {
        return match ($feedback->sentiment) {
            FeedbackSentiment::POSITIVE => '👍 Positive',
            FeedbackSentiment::NEGATIVE => '👎 Negative',
            null => 'Untagged',
        };
    }

    private function deliveryLine(Feedback $feedback): string
    {
        if (! $feedback->wasSent()) {
            return 'Not sent';
        }

        $version = $feedback->sent_version === FeedbackDelivery::REWRITTEN ? 'Sent rewritten' : 'Sent as written';

        return $feedback->latestAcknowledgement?->acknowledged_at
            ? "{$version}, acknowledged"
            : "{$version}, not acknowledged yet";
    }

    /**
     * Amber while open, green once something was sent, grey when closed
     * without sending. A negative tag shows red while still open.
     */
    private function color(Feedback $feedback): int
    {
        return match (true) {
            $feedback->isOpen() && $feedback->sentiment === FeedbackSentiment::NEGATIVE => 0xC62828,
            $feedback->isOpen() => 0xF0AD4E,
            $feedback->wasSent() => 0x2E7D32,
            default => 0x6C757D,
        };
    }

    private function webhookUrl(): ?string
    {
        $url = Setting::get('feedbackStaffDiscordWebhook');

        return is_string($url) && $url !== '' ? $url : null;
    }
}
