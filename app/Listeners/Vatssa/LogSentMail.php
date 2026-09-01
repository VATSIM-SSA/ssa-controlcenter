<?php

namespace App\Listeners\Vatssa;

use App\Models\User;
use App\Models\Vatssa\ActionLog;
use App\Models\Vatssa\MessageLog;
use Illuminate\Mail\Events\MessageSent;

/**
 * VATSSA: every email this application sends, written down.
 *
 * ## Why an event listener and not a call at each send site
 *
 * There are nineteen notification classes, six mailables and a handful of
 * direct sends. Adding a log line to each would mean nineteen upstream files
 * modified, a conflict on every release, and -- the part that actually matters
 * -- a guarantee that the twentieth one somebody adds is not logged, and nobody
 * finds out until a member asks what they were told and the answer is missing.
 *
 * `MessageSent` fires for EVERY mail Laravel delivers, upstream's included, and
 * this file is added rather than modified. Nothing has to remember to opt in.
 *
 * ## Two places, on purpose
 *
 * `vatssa_message_log` is the per-member record: what was this person told, and
 * when. It is what the bot already writes into from Brevo, and the training
 * page reads it. Only mail with a recognised recipient lands there.
 *
 * `vatssa_action_log` is the division-wide feed. Everything lands there,
 * including mail to an address that matches no member -- which is itself worth
 * seeing, because it usually means somebody's address changed.
 *
 * ## Subjects only, never bodies
 *
 * The log answers "what was this student told, and when". The email itself is
 * the detail, and storing bodies would put personal correspondence in a table
 * that half the staff can read.
 *
 * ## It never raises
 *
 * A logging listener that throws would fail the SEND it is describing, and mail
 * is the one thing in this application that must not be lost to bookkeeping.
 */
class LogSentMail
{
    public function handle(MessageSent $event): void
    {
        try {
            $message = $event->message;
            $subject = $message->getSubject() ?: '(no subject)';

            $to = collect($message->getTo() ?? [])
                ->map(fn ($address) => $address->getAddress())
                ->filter()
                ->values();

            if ($to->isEmpty()) {
                return;
            }

            // Laravel's own message id, so a resend of the same mail does not
            // become a second row. The bot deduplicates its Brevo poll on the
            // same column.
            $messageId = trim((string) $message->getId(), '<>') ?: null;

            foreach ($to as $address) {
                $this->record($address, $subject, $messageId);
            }
        } catch (\Throwable $e) {
            // Swallowed on purpose. See the note above: a log write that breaks
            // the send it describes turns observability into an outage, and
            // this one sits in the mail path of the whole application.
            report($e);
        }
    }

    private function record(string $address, string $subject, ?string $messageId): void
    {
        // Both columns Control Center can hold an address in. `email` is the
        // VATSIM one; `setting_workmail_address` is the staff address a member
        // can set, and getWorkNotificationEmailAttribute sends there when it is
        // present. Matching only `email` would file every staff notice as
        // unmatched.
        //
        // There is no `email_personal` column, despite the accessor being
        // called personalNotificationEmail -- it returns `email`.
        $user = User::where('email', $address)
            ->orWhere('setting_workmail_address', $address)
            ->first();

        ActionLog::did(
            'mail.sent',
            ($user?->name ?? $address) . ' was emailed: ' . $subject,
            null,
            $user?->id,
            ['subject' => $subject, 'to' => $address, 'message_id' => $messageId],
            ActionLog::ACTOR_SYSTEM,
            // The per-training timeline gets it through MessageLog below, which
            // knows which training it belongs to. Mirroring here as well would
            // put every email on the timeline twice.
            mirror: false,
        );

        if ($user === null) {
            return;
        }

        // The training this most likely concerns: their open one. Upstream's
        // mailables do not carry the training id, and guessing the newest open
        // training is right in every case that matters -- a member has one at a
        // time, and a member with none gets a row with no training, which is
        // still the truth.
        $training = $user->trainings()
            ->orderByDesc('id')
            ->first();

        MessageLog::updateOrCreate(
            [
                'user_id' => $user->id,
                'message_id' => $messageId ?: substr(sha1($subject . now()->toDateString()), 0, 40),
            ],
            [
                'training_id' => $training?->id,
                'subject' => mb_substr($subject, 0, 255),
                'kind' => 'notification',
                'source' => 'control-center',
                'sent_at' => now(),
            ],
        );
    }
}
