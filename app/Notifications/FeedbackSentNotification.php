<?php

namespace App\Notifications;

use App\Mail\WarningMail;
use App\Models\Feedback;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * E-mails a controller the feedback staff sent them: the original words or
 * the rewritten version. The submitter is never named.
 */
class FeedbackSentNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private Feedback $feedback) {}

    /**
     * @return array<int, string>
     */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    /**
     * The notification is queued; if staff withdrew the feedback before the
     * queue got to it, nothing is sent.
     */
    public function shouldSend(mixed $notifiable, string $channel): bool
    {
        return $this->feedback->fresh()?->wasSent() ?? false;
    }

    public function toMail(mixed $notifiable): WarningMail
    {
        $context = $this->feedback->sessionLabel() ?? $this->feedback->referencePosition?->callsign;

        $textLines = [
            'You have received feedback' . ($context ? ' about your session on ' . $context : '') . '. Our staff have reviewed it and are passing it on to you.',
            '___',
            (string) $this->feedback->sentText(),
            '___',
            'Please [acknowledge it on your dashboard](' . route('dashboard') . '). All feedback sent to you is also listed on your [profile](' . route('user.show', $notifiable) . ').',
        ];

        return (new WarningMail('You have received feedback', $notifiable, $textLines))
            ->to($notifiable->personalNotificationEmail, $notifiable->name);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(mixed $notifiable): array
    {
        return ['feedback_id' => $this->feedback->id];
    }
}
