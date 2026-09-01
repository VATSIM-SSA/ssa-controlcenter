<?php

namespace Tests\Unit;

use App\Helpers\TrainingStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class TrainingStatusTest extends TestCase
{
    #[Test]
    public function label_returns_human_readable_text(): void
    {
        // VATSSA: renamed. "In queue" said nothing about WHICH queue, and
        // there are two -- intake, and the mentor waiting list after theory.
        // A student reading "in queue" while sitting a Moodle course had no way
        // to tell which one they were in.
        $this->assertSame('Awaiting theory', TrainingStatus::IN_QUEUE->label());
        $this->assertSame('Active training', TrainingStatus::ACTIVE_TRAINING->label());
        $this->assertSame('Closed by system', TrainingStatus::CLOSED_BY_SYSTEM->label());
        $this->assertSame('Awaiting exam', TrainingStatus::AWAITING_EXAM->label());
    }

    #[Test]
    public function color_returns_bootstrap_color_class(): void
    {
        $this->assertSame('danger', TrainingStatus::CLOSED_BY_SYSTEM->color());
        $this->assertSame('success', TrainingStatus::COMPLETED->color());
        $this->assertSame('warning', TrainingStatus::IN_QUEUE->color());
        $this->assertSame('info', TrainingStatus::PRE_TRAINING->color());
    }

    #[Test]
    public function icon_returns_fa_class_string(): void
    {
        $this->assertSame('fas fa-graduation-cap', TrainingStatus::AWAITING_EXAM->icon());
        $this->assertSame('fas fa-check', TrainingStatus::COMPLETED->icon());
    }

    #[Test]
    public function is_assignable_by_staff_returns_false_for_system_closed_statuses(): void
    {
        $this->assertFalse(TrainingStatus::CLOSED_BY_SYSTEM->isAssignableByStaff());
        $this->assertFalse(TrainingStatus::CLOSED_BY_STUDENT->isAssignableByStaff());
    }

    #[Test]
    public function is_assignable_by_staff_returns_true_for_staff_assignable_statuses(): void
    {
        $this->assertTrue(TrainingStatus::CLOSED_BY_STAFF->isAssignableByStaff());

        // VATSSA: the pipeline owns these outright, so staff cannot hand-set
        // them. Awaiting theory, theory phase and awaiting mentor follow from
        // facts the bot holds -- a Moodle enrolment, a theory pass, a mentor
        // being assigned -- and setting one by hand asserts something that is
        // not true, which the next cycle then undoes. That looks like a bug
        // rather than a rule, which is why it is a rule.
        //
        // `isAssignableFrom()` grants the two moves that ARE wanted: back to
        // awaiting-mentor when a mentor is lost, and back to active training
        // from awaiting-exam. See TrainingStatusAssignabilityTest.
        $this->assertFalse(TrainingStatus::IN_QUEUE->isAssignableByStaff());
        $this->assertFalse(TrainingStatus::ACTIVE_TRAINING->isAssignableByStaff());

        $this->assertTrue(TrainingStatus::ACTIVE_TRAINING->isAssignableFrom(TrainingStatus::AWAITING_EXAM));
        $this->assertTrue(TrainingStatus::AWAITING_MENTOR->isAssignableFrom(TrainingStatus::ACTIVE_TRAINING));
    }
}
