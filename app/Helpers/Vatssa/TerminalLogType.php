<?php

namespace App\Helpers\Vatssa;

/**
 * VATSSA: what kind of thing was done on VATSIM Terminal.
 *
 * Three, replacing four Google Sheets. The sheets were separate because a
 * spreadsheet cannot filter one table into three views; a database can, so the
 * split becomes a column.
 *
 * ## QUERY is the one that matters most
 *
 * Reading somebody's CERT record is an access that has to be justifiable after
 * the fact. That is an audit obligation rather than a convenience, which is why
 * a query row records a REASON from a fixed list and not free text: "because I
 * needed to" is not an answer, and a list of five is short enough that picking
 * the right one is easier than typing the wrong one.
 */
enum TerminalLogType: string
{
    case TRANSFER_IN = 'transfer-in';

    case CHANGE = 'change';

    case QUERY = 'query';

    public function label(): string
    {
        return match ($this) {
            self::TRANSFER_IN => 'Transfer in',
            self::CHANGE => 'Terminal change',
            self::QUERY => 'Terminal query',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::TRANSFER_IN => 'fa-right-to-bracket',
            self::CHANGE => 'fa-pen-to-square',
            self::QUERY => 'fa-magnifying-glass',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::TRANSFER_IN => 'primary',
            self::CHANGE => 'warning',
            self::QUERY => 'info',
        };
    }

    /**
     * Whether a row of this kind describes a rating moving.
     *
     * Only a CHANGE carries a from/to pair. Asking a query for one would be
     * asking what a read changed, which is nothing.
     */
    public function carriesRatingChange(): bool
    {
        return $this === self::CHANGE;
    }
}
