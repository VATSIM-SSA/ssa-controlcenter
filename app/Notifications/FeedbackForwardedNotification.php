<?php

namespace App\Notifications;

use App\Mail\MentorNoticeMail;
use App\Models\Feedback;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Somebody left feedback about you, and staff have decided you should see it.
 *
 * ## Anonymous, and that is the feature
 *
 * The submitter is never named. Feedback is given on the understanding that it
 * goes to staff, and a controller who learns exactly who complained about them
 * has been told something the submitter did not agree to tell them. Staff keep
 * the full record; the controller gets the substance.
 *
 * The position and the date are included, because feedback about "a session"
 * with no idea which one is not something anybody can act on.
 *
 * ## Only for forwarded feedback
 *
 * Nothing sends this except a staff member choosing "read and forward". Open
 * feedback has not been looked at, and closed feedback was looked at and
 * deliberately not passed on -- sending either would make the two outcomes the
 * same outcome.
 */
class FeedbackForwardedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private Feedback $feedback) {}

    /**
     * @return array<int, string>
     */
    public function via($notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable)
    {
        $position = $this->feedback->referencePosition?->callsign;

        $textLines = [
            'A member of the community left feedback about you, and our staff have passed it on.',
            '___',
            '**About**: ' . ($position ? 'your session on ' . $position : 'one of your sessions'),
            '**Received**: ' . $this->feedback->created_at->toEuropeanDate(),
            '___',
            '**Feedback**',
            $this->feedback->feedback,
        ];

        // No reply-to. The submitter is not named here and a reply address
        // would name them, which is the one thing this message must not do.
        return (new MentorNoticeMail('Feedback about your controlling', $textLines))
            ->to($notifiable->personalNotificationEmail, $notifiable->name);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray($notifiable): array
    {
        return [
            'feedback_id' => $this->feedback->id,
        ];
    }
}
