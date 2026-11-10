<?php

namespace App\Helpers;

/**
 * Kinds of entry on a feedback's internal timeline.
 */
enum FeedbackActivityType: string
{
    case COMMENT = 'comment';
    case SENTIMENT = 'sentiment';
    case EDITED = 'edited';
    case CLOSED = 'closed';
    case REOPENED = 'reopened';
    case SENT = 'sent';
    case WITHDRAWN = 'withdrawn';
    case ACKNOWLEDGED = 'acknowledged';

    public function icon(): string
    {
        return match ($this) {
            self::COMMENT => 'fa-comment',
            self::SENTIMENT => 'fa-tag',
            self::EDITED => 'fa-pen',
            self::CLOSED => 'fa-check-double',
            self::REOPENED => 'fa-rotate-left',
            self::SENT => 'fa-paper-plane',
            self::WITHDRAWN => 'fa-reply',
            self::ACKNOWLEDGED => 'fa-user-check',
        };
    }
}
