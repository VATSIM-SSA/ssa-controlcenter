<?php

namespace Tests\Feature;

use App\Models\Training;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * VATSSA: the training page renders as a masthead plus tabs.
 *
 * The same two properties the member profile protects, for the same reasons:
 * the page still renders for each kind of reader, and a restructure has not
 * quietly moved a permission boundary.
 *
 * Existing coverage in TrainingsTest already asserts what individual panels
 * SAY. This file is only about the shell around them.
 */
class TrainingPageTabsTest extends TestCase
{
    use RefreshDatabase;

    private function withRole(string $role): User
    {
        $user = User::factory()->create();
        $user->roleAssignments()->create(['role' => $role, 'area_id' => null]);

        return $user;
    }

    /**
     * A training with a student of its own.
     *
     * TrainingFactory reaches for `User::inRandomOrder()->first()`, so a
     * training built before any user exists dies on a null. The house pattern
     * in TrainingsTest passes the student explicitly; this does the same.
     */
    private function training(): Training
    {
        return Training::factory()->create([
            'user_id' => User::factory()->create()->id,
        ]);
    }

    private function pageFor(User $viewer): string
    {
        return $this->actingAs($viewer)
            ->get(route('training.show', $this->training()))
            ->assertOk()
            ->getContent();
    }

    #[Test]
    public function an_admin_sees_every_tab(): void
    {
        $html = $this->pageFor($this->withRole('admin'));

        foreach ([
            'Timeline',
            'Reports',
            'Application',
            'Theory',
            'Tasks',
            'Messages',
            'Internal notes',
            'Manage',
        ] as $tab) {
            $this->assertStringContainsString($tab, $html, "the {$tab} tab is missing");
        }
    }

    #[Test]
    public function a_student_can_still_open_their_own_training(): void
    {
        // Most of this page is staff-only, so the student is the reader most
        // likely to hit a variable that was only ever resolved for staff.
        $training = $this->training();

        $this->actingAs($training->user)
            ->get(route('training.show', $training))
            ->assertOk();
    }

    #[Test]
    public function every_tab_has_a_pane_and_every_pane_has_a_tab(): void
    {
        // The invariant the single tab list exists to hold. A pane with no tab
        // is content rendered for a reader who was never meant to reach it.
        $html = $this->pageFor($this->withRole('admin'));

        preg_match_all('/data-bs-target="#pane-([a-z]+)"/', $html, $tabs);
        preg_match_all('/id="pane-([a-z]+)"/', $html, $panes);

        $this->assertNotEmpty($tabs[1], 'no tabs rendered at all');
        $this->assertEqualsCanonicalizing($tabs[1], $panes[1]);
    }

    #[Test]
    public function exactly_one_tab_opens_on_load(): void
    {
        $html = $this->pageFor($this->withRole('admin'));

        preg_match_all('/class="tab-pane fade[^"]*\bshow active\b/', $html, $open);

        $this->assertCount(1, $open[0]);
    }

    #[Test]
    public function the_page_closes_every_div_it_opens(): void
    {
        // Three columns of cards became one box of panes by moving markup
        // around by hand. A stray closing tag does not error -- it renders half
        // the page inside the wrong box, which only a person looking at it
        // would catch.
        $html = $this->pageFor($this->withRole('admin'));

        $this->assertSame(
            substr_count($html, '<div'),
            substr_count($html, '</div>'),
            'the training page opens and closes a different number of divs'
        );
    }

    #[Test]
    public function the_manage_tab_belongs_to_people_who_may_change_the_training(): void
    {
        // It is the only tab that writes. A student must not get it.
        $training = $this->training();

        $html = $this->actingAs($training->user)
            ->get(route('training.show', $training))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('pane-manage', $html);
    }

    #[Test]
    public function the_notes_tab_keeps_its_audience(): void
    {
        // Training notes are the ATC training manager and admins. Moving them
        // into a tab must not widen that.
        $training = $this->training();

        $this->assertStringNotContainsString(
            'pane-notes',
            $this->actingAs($training->user)->get(route('training.show', $training))->getContent()
        );

        $this->assertStringContainsString(
            'pane-notes',
            $this->actingAs($this->withRole('atc-training-manager'))
                ->get(route('training.show', $training))->getContent()
        );
    }

    #[Test]
    public function the_deadlines_stay_out_of_the_tabs(): void
    {
        // A deadline filed behind a tab is a deadline nobody sees until it has
        // passed, which is the one outcome that panel exists to prevent. It has
        // to render ahead of the tab strip, not inside a pane.
        $html = $this->pageFor($this->withRole('admin'));

        $strip = strpos($html, 'role="tablist"');
        $alerts = strpos($html, 'confirmation');

        $this->assertNotFalse($strip);

        if ($alerts !== false) {
            $this->assertLessThan($strip, $alerts, 'the deadlines rendered inside the tabs');
        }
    }
}
