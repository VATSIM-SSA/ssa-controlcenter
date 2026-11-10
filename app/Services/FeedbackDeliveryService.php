<?php

namespace App\Services;

use App\Helpers\FeedbackActivityType;
use App\Helpers\FeedbackDelivery;
use App\Models\Acknowledgement;
use App\Models\Feedback;
use App\Models\User;
use App\Notifications\FeedbackSentNotification;
use LogicException;

/**
 * Sends feedback to the controller it is about, and withdraws it again.
 *
 * A controller holds at most one version of a feedback at a time. Sending
 * e-mails them, asks them to acknowledge it on their dashboard and can post it
 * to Discord. Withdrawing takes it off their profile and dashboard and deletes
 * the Discord post; the e-mail cannot be recalled. To change the version,
 * withdraw it and send again.
 */
class FeedbackDeliveryService
{
    public function __construct(private FeedbackDiscordPoster $discord) {}

    /**
     * @return bool|null whether the Discord post succeeded, or null when none was asked for
     */
    public function send(Feedback $feedback, User $by, FeedbackDelivery $version, ?string $rewritten = null, bool $postToDiscord = false): ?bool
    {
        $controller = $feedback->referenceUser ?? throw new LogicException('This feedback has no controller to send it to.');

        // Claim the send in one conditional update, so a double-click or two
        // staff sending at once cannot e-mail the controller twice.
        $claimed = Feedback::whereKey($feedback->getKey())->whereNull('sent_at')->update([
            'sent_version' => $version->value,
            'sent_message' => $version === FeedbackDelivery::REWRITTEN ? $rewritten : null,
            'sent_by_user_id' => $by->id,
            'sent_at' => now(),
            'updated_at' => now(),
        ]);

        if ($claimed === 0) {
            throw new LogicException('This feedback was already sent. Withdraw it before sending another version.');
        }

        $feedback->refresh();

        $controller->notify(new FeedbackSentNotification($feedback));
        Acknowledgement::request($controller, $feedback);

        $posted = $postToDiscord ? $this->discord->post($feedback) : null;

        $feedback->recordActivity(
            FeedbackActivityType::SENT,
            $by,
            ['delivery' => $version->value, 'discord' => (bool) $posted],
            $version === FeedbackDelivery::REWRITTEN ? $rewritten : null,
        );

        return $posted;
    }

    /**
     * Take back what the controller was sent.
     *
     * @return bool|null whether the Discord post was deleted, or null when there was none
     */
    public function withdraw(Feedback $feedback, User $by): ?bool
    {
        $version = $feedback->sent_version;

        // Claim the withdrawal the same way as a send, so it happens once.
        $claimed = Feedback::whereKey($feedback->getKey())->whereNotNull('sent_at')->update([
            'sent_version' => null,
            'sent_message' => null,
            'sent_by_user_id' => null,
            'sent_at' => null,
            'updated_at' => now(),
        ]);

        if ($claimed === 0 || $version === null) {
            throw new LogicException('Nothing has been sent to withdraw.');
        }

        $feedback->refresh();
        $feedback->acknowledgements()->pending()->delete();

        $removed = $feedback->discord_message_id !== null ? $this->discord->remove($feedback) : null;

        $feedback->recordActivity(FeedbackActivityType::WITHDRAWN, $by, ['delivery' => $version->value, 'discord_removed' => (bool) $removed]);

        return $removed;
    }
}
