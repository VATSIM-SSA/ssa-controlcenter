<?php

namespace App\Services;

use App\Helpers\TrainingStatus;
use App\Models\Endorsement;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * VATSSA: who may control what. The roster is global, so being allowed in one area is
 * being allowed everywhere. Positions stack: a rating covers everything below it.
 */
class VatssaControlAuthorisation
{
    public const FACILITIES = [1 => 'FSS', 2 => 'DEL', 3 => 'GND', 4 => 'TWR', 5 => 'APP', 6 => 'CTR'];

    private const LADDER = ['DEL' => 1, 'GND' => 2, 'TWR' => 3, 'APP' => 4, 'CTR' => 5];

    /** The highest rung each rating name reaches, for the rating on a visiting endorsement. */
    private const RATING_TOP = ['OBS' => 2, 'S1' => 2, 'S2' => 3, 'S3' => 4, 'C1' => 5, 'C3' => 5, 'I1' => 5, 'I3' => 5, 'SUP' => 5, 'ADM' => 5];

    /** Standard and fast-track: the only training types that may control one rating higher. */
    private const STEP_UP_TYPES = [1, 4];

    /**
     * Why this person may not control this position, or null if they may. An empty type
     * checks only that they may control at all.
     */
    public function problem(?User $user, string $type = ''): ?string
    {
        if (! $user) {
            return 'No Control Center account';
        }
        $held = $user->endorsements->filter(fn (Endorsement $e) => ! $e->revoked && ! $e->expired
            && (! $e->valid_to || Carbon::parse($e->valid_to)->isFuture()));
        $solo = $held->contains('type', 'SOLO');
        $training = $user->trainings->filter(fn ($t) => in_array($t->status, [TrainingStatus::ACTIVE_TRAINING, TrainingStatus::AWAITING_EXAM], true));
        $visiting = $held->where('type', 'VISITING');

        if (! $solo && $training->isEmpty() && $visiting->isEmpty() && ! $user->isAtcActive()) {
            return 'Not on the roster, with no visiting or solo endorsement or active training';
        }
        if ($type === 'FSS') {
            return $this->holdsFss($held) ? null : 'FSS needs an FSS endorsement';
        }
        if (! isset(self::LADDER[$type])) {
            return null;
        }
        $rating = (int) $user->getRawOriginal('rating');
        $cap = $user->division === config('app.owner_code') ? null : $this->visitingCap($visiting);
        $stepUp = $training->contains(fn ($t) => in_array((int) $t->type, self::STEP_UP_TYPES, true));
        if (self::LADDER[$type] <= $this->highest($rating, $cap, $stepUp)) {
            return null;
        }

        return ($cap !== null ? 'Visiting endorsement' : ($user->rating_short ?: 'This') . ' rating') . ' does not cover ' . $type;
    }

    /**
     * The highest rung a visitor's endorsements allow, or null if none names a rating.
     *
     * @param  Collection<int, Endorsement>  $visiting
     */
    private function visitingCap(Collection $visiting): ?int
    {
        return $visiting->flatMap(fn (Endorsement $e) => $e->ratings->pluck('name'))
            ->map(fn ($name) => self::RATING_TOP[strtoupper((string) $name)] ?? null)->filter()->max();
    }

    /**
     * OBS and S1 reach GND, S2 TWR, S3 APP, C1 and above CTR. A visitor stops at their
     * endorsement. Standard or fast-track training then lifts S1 and above one step.
     */
    private function highest(int $rating, ?int $cap, bool $stepUp): int
    {
        $base = match (true) {
            $rating >= 5 => 5,
            $rating === 4 => 4,
            $rating === 3 => 3,
            $rating >= 1 => 2,
            default => 0,
        };

        $base = $cap === null ? $base : min($base, $cap);

        return $stepUp && $rating >= 2 ? min($base + 1, 5) : $base;
    }

    /**
     * @param  Collection<int, Endorsement>  $held
     */
    private function holdsFss(Collection $held): bool
    {
        return $held->where('type', 'FACILITY')->contains(fn (Endorsement $e) => $e->ratings->contains(fn ($r) => str_contains(strtoupper((string) $r->name), 'FSS')));
    }
}
