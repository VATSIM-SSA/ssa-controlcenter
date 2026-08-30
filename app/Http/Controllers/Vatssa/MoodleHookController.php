<?php

namespace App\Http\Controllers\Vatssa;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Vatssa\ActionLog;
use App\Models\Vatssa\UserPlatform;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * VATSSA: Moodle telling us the moment somebody registers.
 *
 * ## Why this exists at all
 *
 * Moodle presence used to be discovered by the bot's sweep, half an hour or a
 * day later. That was fine while it was a nice-to-have. It stopped being fine
 * when training applications started REQUIRING a Moodle account: a student who
 * signed up two minutes ago and is told they have no account has nothing to do
 * but wait, and waiting is exactly the experience a gate must not produce.
 *
 * ## Why it lands here rather than on the bot
 *
 * The bot's container is sealed. It makes outbound connections and accepts
 * none -- no inbound HTTPS, no Caddy route, no public hostname. That is a
 * deliberate part of the design and worth more than instant bot knowledge.
 *
 * Control Center is already public and is the thing that gates the
 * application, so this is where the fact needs to be current. The bot picks it
 * up on its next sweep, which is soon enough for anything the bot does.
 *
 * ## Its own secret, not the bridge token
 *
 * The bridge is 403'd at Caddy precisely so it is unreachable from the
 * internet, and Moodle is on the internet. Giving Moodle the bridge token
 * would mean opening the bridge, which would undo that. `VATSSA_MOODLE_SECRET`
 * is a separate credential with one endpoint behind it, so a leak from Moodle
 * costs one write path rather than the whole bridge.
 *
 * ## It only ever writes platform presence
 *
 * Not enrolments, not statuses, not grades. Everything else Moodle knows is
 * read by the bot, which can verify it against Moodle itself. A webhook is an
 * assertion from outside; the narrower the thing it may assert, the less a
 * forged one is worth.
 */
class MoodleHookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $secret = config('vatssa.moodle_secret');

        // Unset means CLOSED, not open. An unconfigured secret must never mean
        // "let everyone in" -- that is the property that makes it safe to ship
        // this route before Moodle has been set up.
        if (! $secret || ! hash_equals($secret, (string) $request->bearerToken())) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $data = $request->validate([
            'event' => ['required', 'in:user_created,user_deleted'],
            'cid' => 'nullable|integer',
            'email' => 'nullable|email',
            'moodle_user_id' => 'nullable|integer',
        ]);

        $user = $this->resolve($data);

        if ($user === null) {
            // Recorded rather than swallowed. A Moodle account nobody can match
            // to a CID is the exact shape of a student who is about to be stuck
            // and cannot explain why, and it is invisible unless something says
            // so out loud.
            ActionLog::noticed(
                'moodle.unmatched_account',
                'Moodle reported a ' . str_replace('_', ' ', $data['event'])
                    . ' that could not be matched to a Control Center account.',
                null,
                null,
                ['email' => $data['email'] ?? null, 'moodle_user_id' => $data['moodle_user_id'] ?? null],
                ActionLog::ACTOR_BOT,
            );

            // 200, not 404. Moodle will retry a failure, and retrying will not
            // make an unmatched account match. The row above is the follow-up.
            return response()->json(['status' => 'unmatched']);
        }

        $created = $data['event'] === 'user_created';

        UserPlatform::updateOrCreate(
            ['user_id' => $user->id],
            array_filter([
                'on_moodle' => $created,
                'moodle_user_id' => $data['moodle_user_id'] ?? null,
                'checked_at' => now(),
            ], fn ($value) => $value !== null)
        );

        ActionLog::did(
            $created ? 'moodle.account_created' : 'moodle.account_deleted',
            $user->name . ($created
                ? ' created a Moodle account.'
                : ' no longer has a Moodle account.'),
            null,
            $user->id,
            ['moodle_user_id' => $data['moodle_user_id'] ?? null],
            ActionLog::ACTOR_BOT,
        );

        return response()->json(['status' => 'ok', 'cid' => $user->id]);
    }

    /**
     * Which Control Center account this is.
     *
     * CID first, because it is the only identifier that cannot be changed by
     * the person holding it. Email last, and only exactly: a LIKE would let
     * somebody claim an account by registering a lookalike address.
     */
    private function resolve(array $data): ?User
    {
        if (! empty($data['cid'])) {
            return User::find($data['cid']);
        }

        if (! empty($data['email'])) {
            return User::where('email', $data['email'])->first();
        }

        return null;
    }
}
