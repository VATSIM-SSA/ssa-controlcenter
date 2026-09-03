<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * VATSSA: the Terminal comment catalogue, dummy-filled.
 *
 * Seeded by a MIGRATION rather than a seeder, for the same reason the message
 * templates are: this is real content the admin page is empty without, in
 * production as well as on dev. A seeder is for fixtures.
 *
 * ## These are placeholders for the real sixteen
 *
 * VATSSA has `SSA-VT-001` to `016` written down somewhere else. These are the
 * SHAPE, not the wording -- enough to build against and to see the copy button
 * working, and every one of them is editable on the Training setup page without
 * a deploy. Replacing the body of a row is the intended way to make this real.
 *
 * ## One entry per KIND
 *
 * The rating update is a single row covering every upgrade through `{from}` and
 * `{to}`, not one row per rating pair. Sixteen rows differing by two characters
 * is a list nobody can scan, and it goes stale the moment a rating is added.
 * Every other entry follows that shape.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $order = 0;

        $comments = [
            ['SSA-VT-001', 'Rating update', 'MM', 'change',
                'Rating updated from {from} to {to} following successful examination on {date}.',
                'Covers every upgrade. Placeholders: {from}, {to}, {date}.'],

            ['SSA-VT-002', 'Rating update — fast track', 'MM', 'change',
                'Rating updated from {from} to {to} under the fast-track provision, authorised by {authorised_by}.',
                'Placeholders: {from}, {to}, {authorised_by}.'],

            ['SSA-VT-003', 'Instructor rating removed', 'MM', 'change',
                'Instructor rating {rating} removed on request of the division.',
                'Placeholders: {rating}.'],

            ['SSA-VT-004', 'Transfer accepted', 'MM', 'transfer-in',
                'Transfer into VATSSA accepted under TVCP. Familiarisation to follow.',
                'No placeholders.'],

            ['SSA-VT-005', 'Transfer denied — TVCP 5.4', 'MM', 'transfer-in',
                'Transfer request declined under TVCP 5.4: {ground}.',
                'One of the three grounds 5.4 allows. Placeholders: {ground}.'],

            ['SSA-VT-006', 'Visiting endorsement issued', 'MM', 'change',
                'Visiting controller status granted for {positions} following familiarisation.',
                'Placeholders: {positions}.'],

            ['SSA-VT-007', 'TVCP eligibility check', 'MM', 'query',
                'CERT accessed to verify eligibility under TVCP for a {request_type} request.',
                'Placeholders: {request_type}.'],

            ['SSA-VT-008', 'Staff eligibility check', 'DD', 'query',
                'CERT accessed to verify eligibility for a division staff appointment.',
                'No placeholders.'],

            ['SSA-VT-009', 'Duplicate account check', 'MM', 'query',
                'CERT accessed to investigate a suspected duplicate account.',
                'No placeholders.'],

            ['SSA-VT-010', 'Disciplinary history check', 'MM', 'query',
                'CERT accessed to check disciplinary history within the preceding twelve months.',
                'Used for the TVCP 5.4 check. No placeholders.'],
        ];

        foreach ($comments as [$code, $label, $team, $category, $body, $description]) {
            // Never overwrite a body somebody has already edited. This
            // migration ships wording nobody has approved, so the moment it is
            // replaced it must stay replaced.
            if (DB::table('vatssa_terminal_comments')->where('code', $code)->exists()) {
                continue;
            }

            DB::table('vatssa_terminal_comments')->insert([
                'code' => $code,
                'label' => $label,
                'team' => $team,
                'category' => $category,
                'body' => $body,
                'description' => $description,
                'sort_order' => $order += 10,
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('vatssa_terminal_comments')->where('code', 'like', 'SSA-VT-%')->delete();
    }
};
