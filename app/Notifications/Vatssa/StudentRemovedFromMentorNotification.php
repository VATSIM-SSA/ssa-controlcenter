<?php

namespace App\Notifications\Vatssa;

use App\Mail\MentorNoticeMail;
use App\Models\Training;
use App\Models\Vatssa\MessageTemplate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * VATSSA: this student is no longer yours.
 *
 * The mentor half. A mentor is detached by `UpdateMemberDetails`, by
 * `UserDelete`, or by somebody editing the training -- and none of those tells
 * them. A mentor who thinks they still have four students and has three keeps a
 * slot reserved for somebody who is not coming back, which is a slot the next
 * student in the queue cannot have.
 *
 * ## Sent even when they did it themselves
 *
 * Mostly the mentor already knows: they resigned, or they asked to drop the
 * student. This is still worth sending, because "I asked to be taken off" and
 * "it has actually been done" are different facts, and the gap between them is
 * where a student gets forgotten by both sides.
 *
 * ## Not an accusation
 *
 * Mentors leave for good reasons and a system that sounds accusing about it is
 * one they stop volunteering for. This states what happened and what it means
 * for their capacity, and nothing else.
 */
class StudentRemovedFromMentorNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private Training $training,
        private string $studentName,
        private ?string $reason = null,
    ) {}

    public function via($notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable)
    {
        $rating = $this->training->ratings->pluck('name')->join(' + ');

        $textLines = [
            '**' . $this->studentName . '** is no longer assigned to you'
                . ($rating ? ' for their ' . $rating . ' training' : '') . '.',
            $this->reason
                ? $this->reason
                : 'They have been returned to the mentor queue and a coordinator '
                    . 'is finding them somebody else.',
            'Your capacity has gone up by one, so you may be offered another '
                . 'student sooner than you expected.',
            'If this is wrong — you are still working with them, or you meant to '
                . 'keep them — tell your pipeline coordinator and it can be put '
                . 'straight in one click.',
        ];

        $subject = 'A student has been taken off your list';

        $composed = MessageTemplate::compose(MessageTemplate::STUDENT_REMOVED, [
            'name' => $notifiable->name,
            'student' => $this->studentName,
            'rating' => $rating,
            'reason' => $this->reason,
        ]);

        if ($composed !== null) {
            $subject = $composed['subject'] ?: $subject;
            $textLines = $composed['lines'];
        }

        return (new MentorNoticeMail(
            $subject,
            $textLines,
            $this->training->path(),
            'Open the training',
        ))->to($notifiable->personalNotificationEmail, $notifiable->name);
    }

    public function toArray($notifiable): array
    {
        return [
            'training_id' => $this->training->id,
            'student' => $this->studentName,
        ];
    }
}
