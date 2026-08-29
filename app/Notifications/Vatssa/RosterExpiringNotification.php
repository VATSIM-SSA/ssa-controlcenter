<?php

namespace App\Notifications\Vatssa;

use App\Mail\WarningMail;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * VATSSA: your roster place lapses in a week.
 *
 * Upstream's `AtcSoonInactiveNotification` warns per AREA when hours fall below
 * a threshold and the grace period is two-thirds gone -- roughly four months
 * out on a twelve-month grace. This is the other message: the last week, once,
 * with the date on it.
 *
 * Four months out, a controller means to fix it and forgets. Seven days out
 * with a date, they book a session. That is the whole reason this exists.
 *
 * Deliberately specific about consequence. "Your ATC active status will expire"
 * does not tell somebody they will need refresher training to come back, and
 * that is the part that makes people act.
 */
class RosterExpiringNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private Carbon $expiresOn,
        private float $hours,
        private float $requirement,
    ) {}

    public function via($notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable)
    {
        $days = max(0, (int) now()->diffInDays($this->expiresOn, false));

        $textLines = [
            'Your place on the VATSSA controller roster lapses on **'
                . $this->expiresOn->toEuropeanDate() . '** — that is '
                . ($days === 1 ? 'tomorrow' : "in {$days} days") . '.',
            '**' . sprintf(
                'You have %.1f hours. The requirement is %s.',
                $this->hours,
                $this->requirement
            ) . '**',
            'Controlling before that date keeps your roster place. If it lapses '
                . 'you will need refresher training before you can control again, '
                . 'which usually means waiting for a mentor.',
            'If something is stopping you from controlling, reply to this email '
                . 'and tell us — a leave of absence is far easier to arrange than '
                . 'a refresher.',
        ];

        return (new WarningMail('Your roster place lapses in a week', $notifiable, $textLines))
            ->to($notifiable->personalNotificationEmail, $notifiable->name);
    }

    public function toArray($notifiable): array
    {
        return [
            'expires_on' => $this->expiresOn->toDateString(),
            'hours' => $this->hours,
        ];
    }
}
