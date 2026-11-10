<?php

namespace App\Contracts;

use App\Models\Acknowledgement;

/**
 * Something a member must confirm they have read. The dashboard lists every
 * pending acknowledgement through this contract, so a new kind of notice only
 * needs to implement it.
 */
interface RequiresAcknowledgement
{
    /**
     * Heading of the dashboard notice, e.g. "You have received feedback".
     */
    public function acknowledgementTitle(): string;

    /**
     * A short line of context under the heading, or null.
     */
    public function acknowledgementContext(): ?string;

    /**
     * The plain-text body the member is asked to read.
     */
    public function acknowledgementBody(): string;

    /**
     * Called once the member has acknowledged, e.g. to record it for staff.
     */
    public function acknowledged(Acknowledgement $acknowledgement): void;
}
