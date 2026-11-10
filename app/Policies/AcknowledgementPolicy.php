<?php

namespace App\Policies;

use App\Models\Acknowledgement;
use App\Models\User;

class AcknowledgementPolicy
{
    /**
     * Only the member a notice was sent to may acknowledge it.
     */
    public function acknowledge(User $user, Acknowledgement $acknowledgement): bool
    {
        return (int) $acknowledgement->user_id === (int) $user->id;
    }
}
