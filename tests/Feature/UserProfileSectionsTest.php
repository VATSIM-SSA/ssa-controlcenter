<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * VATSSA: the profile page renders as sections, and each one is gated.
 *
 * The page is now a masthead plus eight named sections rather than a grid of
 * cards. What is worth protecting is not the layout -- that will keep moving --
 * but the two things a restructure most easily breaks:
 *
 *  1. It still renders at all, for each kind of reader. A blade this size with
 *     eight includes fails on the one variable somebody forgot to pass, and it
 *     fails at request time rather than at build time.
 *  2. A section that is gated stays gated. Moving a card into a named section
 *     is exactly the change that quietly widens who can see it.
 */
class UserProfileSectionsTest extends TestCase
{
    use RefreshDatabase;

    private function withRole(string $role): User
    {
        $user = User::factory()->create();
        $user->roleAssignments()->create(['role' => $role, 'area_id' => null]);

        return $user;
    }

    private function profileOf(User $subject, User $viewer): string
    {
        return $this->actingAs($viewer)
            ->get(route('user.show', $subject))
            ->assertOk()
            ->getContent();
    }

    // ------------------------------------------------------------ it renders

    #[Test]
    public function an_admin_sees_every_section(): void
    {
        $html = $this->profileOf(User::factory()->create(), $this->withRole('admin'));

        foreach ([
            'Divisional history',
            'Training',
            'Roster',
            'Feedback',
            'Internal notes',
            'Visiting',
            'Terminal log',
            'Access',
        ] as $section) {
            $this->assertStringContainsString($section, $html, "the {$section} section is missing");
        }
    }

    #[Test]
    public function a_member_can_still_open_their_own_profile(): void
    {
        // The include list is long and most of it is staff-only. A member
        // opening their own profile is the path most likely to hit a variable
        // that was only ever resolved for staff.
        $member = User::factory()->create();

        $this->actingAs($member)
            ->get(route('user.show', $member))
            ->assertOk();
    }

    #[Test]
    public function the_masthead_carries_the_standing_and_the_roster_answer(): void
    {
        // Both axes, always. A member has an answer to each at once, and the
        // roster line has to appear even when the answer is no -- absence would
        // read as "not checked".
        $html = $this->profileOf(User::factory()->create(), $this->withRole('admin'));

        $this->assertStringContainsString('Divisional standing', $html);
        $this->assertStringContainsString('Not on the roster', $html);
    }

    // ------------------------------------------------------------- the tabs

    #[Test]
    public function every_tab_has_a_pane_and_every_pane_has_a_tab(): void
    {
        // The invariant the whole tab list exists to hold. Two hand-kept lists
        // drift, and the two ways they drift are both bad: a tab that opens
        // nothing, or a pane with no tab -- which is content rendered for a
        // reader who was never meant to reach it.
        $html = $this->profileOf(User::factory()->create(), $this->withRole('admin'));

        preg_match_all('/data-bs-target="#pane-([a-z]+)"/', $html, $tabs);
        preg_match_all('/id="pane-([a-z]+)"/', $html, $panes);

        $this->assertNotEmpty($tabs[1], 'no tabs rendered at all');
        $this->assertEqualsCanonicalizing($tabs[1], $panes[1]);
    }

    #[Test]
    public function the_page_closes_every_div_it_opens(): void
    {
        // This page is a dozen includes inside a tab pane inside a card. Every
        // assertion in this file passes just as happily against markup with a
        // stray </div> in it, and the symptom of that is not an error -- it is
        // half the page rendering inside the wrong box, which only a person
        // looking at it would notice.
        $html = $this->profileOf(User::factory()->create(), $this->withRole('admin'));

        $this->assertSame(
            substr_count($html, '<div'),
            substr_count($html, '</div>'),
            'the profile opens and closes a different number of divs'
        );
    }

    #[Test]
    public function exactly_one_tab_opens_on_load(): void
    {
        // Zero leaves the reader looking at an empty box and wondering whether
        // the page failed; two is a Bootstrap state that renders both panes
        // stacked, which is the layout this replaced.
        $html = $this->profileOf(User::factory()->create(), $this->withRole('admin'));

        preg_match_all('/class="tab-pane fade[^"]*\bshow active\b/', $html, $open);

        $this->assertCount(1, $open[0]);
    }

    #[Test]
    public function a_reader_who_loses_the_first_tab_still_opens_on_something(): void
    {
        // `$firstTab` is whichever survived the gating, not a hard-coded name.
        // A reader for whom the first few tabs are gated away must still land
        // on an open pane rather than a blank body.
        $html = $this->profileOf(User::factory()->create(), $this->withRole('pipeline-coordinator'));

        $this->assertStringContainsString('show active', $html);
    }

    // -------------------------------------------------------- it stays gated

    #[Test]
    public function the_terminal_section_is_absent_for_everybody_but_membership_staff(): void
    {
        // It carries CERT queries and disciplinary findings. Not even the ATC
        // training manager, who can read a membership request.
        //
        // Both roles here can already OPEN a profile -- that is the point. A
        // role that cannot reach the page proves nothing about what the page
        // shows, and `moderator` holds no permissions at all.
        foreach (['atc-training-manager', 'pipeline-coordinator'] as $role) {
            $html = $this->profileOf(User::factory()->create(), $this->withRole($role));

            $this->assertStringNotContainsString('Terminal log', $html, "{$role} can see the Terminal section");
        }
    }

    #[Test]
    public function the_terminal_section_is_present_for_membership_staff_even_with_nothing_in_it(): void
    {
        // "Nothing has been done about this member" and "you may not see what
        // was done" are different answers and must not look alike. The section
        // is gated on the permission, not on whether there are rows.
        $html = $this->profileOf(User::factory()->create(), $this->withRole('membership-manager'));

        $this->assertStringContainsString('Terminal log', $html);
        $this->assertStringContainsString('Nothing recorded on Terminal', $html);
    }

    #[Test]
    public function the_collected_training_notes_keep_the_training_note_audience(): void
    {
        // Collecting notes somewhere new must not widen who can read them.
        //
        // The pipeline coordinator is the case that matters: config/roles.php
        // denies them `training.notes.view` explicitly, and they CAN open a
        // profile. So they are exactly the reader a careless include would have
        // started leaking training notes to.
        $subject = User::factory()->create();

        $this->assertStringNotContainsString(
            'Training notes, collected',
            $this->profileOf($subject, $this->withRole('pipeline-coordinator'))
        );

        $this->assertStringContainsString(
            'Training notes, collected',
            $this->profileOf($subject, $this->withRole('atc-training-manager'))
        );
    }
}
