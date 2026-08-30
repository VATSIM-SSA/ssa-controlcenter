<?php

namespace App\Notifications\Vatssa;

use App\Mail\TrainingMail;
use App\Models\Training;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * VATSSA: you are back on the waiting list, and here is why.
 *
 * The student half of the lost-mentor problem. Their mentor was detached --
 * they left the division, their account was deleted, somebody removed them --
 * and the student found out by noticing that nothing had happened for a month.
 *
 * ## What this message has to do
 *
 * Say what changed, say it was not their fault, and say what happens next. A
 * status that silently moves backwards reads as a punishment, and a student
 * who thinks they have been dropped stops asking.
 *
 * It deliberately does NOT promise a date. There may be no mentor free for
 * that rating for weeks, and a promise the division cannot keep costs more
 * trust than the silence it replaced.
 */
class MentorLostNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private Training $training,
        private ?string $mentorName = null,
    ) {}

    public function via($notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable)
    {
        $rating = $this->training->ratings->pluck('name')->join(' + ');

        $textLines = [
            'You have been placed back on the waiting list for your '
                . ($rating ? $rating . ' ' : '') . 'training.',
            $this->mentorName
                ? '**' . $this->mentorName . ' is no longer able to mentor you.** '
                    . 'That is nothing to do with you or with your progress.'
                : '**Your mentor is no longer able to continue with you.** That '
                    . 'is nothing to do with you or with your progress.',
            'We are finding you a new mentor. Everything you have already done '
                . 'still counts — your theory result, your sessions and your '
                . 'notes all stay on your training, and your new mentor picks '
                . 'up where the last one left off.',
            // No date. There may be no mentor free for this rating for weeks,
            // and a promise the division cannot keep costs more than silence.
            'We cannot say yet how long that will take. If you have not heard '
                . 'anything in a few weeks, reply to this email and ask — '
                . 'chasing it is welcome, not a nuisance.',
        ];

        return (new TrainingMail(
            'You are back on the waiting list',
            $this->training,
            $textLines,
            null,
            $this->training->path(),
            'Open your training',
        ))->to($notifiable->personalNotificationEmail, $notifiable->name);
    }

    public function toArray($notifiable): array
    {
        return [
            'training_id' => $this->training->id,
            'mentor' => $this->mentorName,
        ];
    }
}
