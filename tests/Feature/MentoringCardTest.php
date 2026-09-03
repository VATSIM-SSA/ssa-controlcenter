<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vatssa\MentorCeiling;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * VATSSA: one Mentoring card on a member's page, not two.
 *
 * Upstream had a "Mentoring" card listing a mentor's students; the fork added a
 * "Mentoring" card carrying their ceiling, load and teachable ratings. Same
 * heading, same subject, same page, and neither answered a question on its own.
 * They are one card now, and this pins the merge so a future absorb that
 * reintroduces upstream's copy shows up as a failure rather than as a page with
 * two identical headings on it.
 */
class MentoringCardTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->roleAssignments()->create(['role' => 'admin', 'area_id' => null]);

        return $admin;
    }

    #[Test]
    public function a_mentor_profile_carries_exactly_one_mentoring_heading(): void
    {
        $mentor = User::factory()->create();
        $mentor->roleAssignments()->create(['role' => 'mentor', 'area_id' => null]);

        $html = $this->actingAs($this->admin())->get(route('user.show', $mentor))->getContent();

        $this->assertSame(1, substr_count($html, '>Mentoring<'));
        // The merged card keeps both halves: the summary and the list's button.
        $this->assertStringContainsString('May teach', $html);
        $this->assertStringContainsString('See reports', $html);
    }

    #[Test]
    public function a_ceiling_renders_load_over_the_limit(): void
    {
        $mentor = User::factory()->create();
        $mentor->roleAssignments()->create(['role' => 'mentor', 'area_id' => null]);
        MentorCeiling::create(['user_id' => $mentor->id, 'total_limit' => 5]);

        $html = $this->actingAs($this->admin())->get(route('user.show', $mentor))->getContent();

        $this->assertStringContainsString('0 / 5', $html);
    }

    #[Test]
    public function a_non_mentor_profile_carries_no_mentoring_card_at_all(): void
    {
        $member = User::factory()->create();

        $html = $this->actingAs($this->admin())->get(route('user.show', $member))->getContent();

        $this->assertStringNotContainsString('>Mentoring<', $html);
    }

    #[Test]
    public function the_mentor_report_shows_load_against_the_ceiling(): void
    {
        $mentor = User::factory()->create();
        $mentor->roleAssignments()->create(['role' => 'mentor', 'area_id' => null]);
        MentorCeiling::create(['user_id' => $mentor->id, 'total_limit' => 4]);

        $unlimited = User::factory()->create();
        $unlimited->roleAssignments()->create(['role' => 'mentor', 'area_id' => null]);

        $html = $this->actingAs($this->admin())->get(route('reports.mentors'))->getContent();

        $this->assertStringContainsString('0 / 4', $html);
        // No ceiling is UNLIMITED, and must never render as a zero denominator.
        $this->assertStringContainsString('no limit', $html);
        $this->assertStringNotContainsString('/ 0', $html);
    }
}
