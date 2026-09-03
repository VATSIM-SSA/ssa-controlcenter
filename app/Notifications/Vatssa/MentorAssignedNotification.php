<?php

namespace App\Notifications\Vatssa;

use App\Mail\TrainingMail;
use App\Models\Training;
use App\Models\Vatssa\MessageTemplate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * VATSSA: you have a new student.
 *
 * ## The gap this closes
 *
 * `TrainingController` notifies the STUDENT when a mentor is attached —
 * `$training->user->notify(new TrainingMentorNotification(...))` — and tells the
 * mentor nothing at all. The mentor found out by opening My students and
 * noticing a row that was not there yesterday, or by the student emailing them
 * first.
 *
 * That is backwards in a pipeline whose commonest stall is nobody making the
 * first move. The student is told to contact their mentor within seven days or
 * lose their place; the mentor is not told to expect them.
 *
 * ## Deliberately short
 *
 * Who, what rating, and a link. Everything else is on the training page, and a
 * mentor who gets four paragraphs about a student they have not met yet reads
 * the first line and archives it.
 *
 * ## One per mentor, not one per training
 *
 * `Training::mentors()` is a belongsToMany and several mentors on one training
 * is normal, so the caller sends this to each newly attached mentor rather than
 * to "the mentor". A co-mentor added a month later gets the same message.
 */
class MentorAssignedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private Training $training) {}

    public function via($notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable)
    {
        $rating = $this->training->ratings->pluck('name')->join(' + ');
        $student = $this->training->user;

        $textLines = [
            '**' . $student->name . ' (' . $student->id . ')** has been assigned to you'
                . ($rating ? ' for their ' . $rating . ' training' : '') . '.',
            'They have been told to contact you within seven days. If they do not, '
                . 'say so on the training rather than waiting it out — the request '
                . 'is closed from there, not by silence.',
            'Their theory result, previous sessions and any notes are on the '
                . 'training page.',
        ];

        $subject = 'A new student for you';

        // Editable if somebody has edited it, compiled text if not. A missing
        // row must never mean a missing email -- see MessageTemplate::compose().
        $composed = MessageTemplate::compose(MessageTemplate::MENTOR_ASSIGNED, [
            'name' => $notifiable->name,
            'student' => $student->name,
            'cid' => (string) $student->id,
            'rating' => $rating,
        ]);

        if ($composed !== null) {
            $subject = $composed['subject'] ?: $subject;
            $textLines = $composed['lines'];
        }

        return (new TrainingMail(
            $subject,
            $this->training,
            $textLines,
            null,
            $this->training->path(),
            'Open the training',
        ))->to($notifiable->personalNotificationEmail, $notifiable->name);
    }

    public function toArray($notifiable): array
    {
        return [
            'training_id' => $this->training->id,
            'student' => $this->training->user->name,
        ];
    }
}
