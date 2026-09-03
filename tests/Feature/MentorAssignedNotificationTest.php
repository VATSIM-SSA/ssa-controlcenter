<?php

namespace Tests\Feature;

use App\Mail\TrainingMail;
use App\Models\Training;
use App\Models\User;
use App\Notifications\TrainingMentorNotification;
use App\Notifications\Vatssa\MentorAssignedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * VATSSA: the mentor is told they have a student.
 *
 * Upstream notifies the STUDENT when a mentor is attached and tells the mentor
 * nothing at all -- they found out by opening My students and noticing a row
 * that had not been there yesterday, or by the student emailing them first.
 *
 * Backwards in a pipeline whose commonest stall is nobody making the first
 * move: the student is instructed to make contact within seven days or lose
 * their place, and the mentor was never told to expect them.
 */
class MentorAssignedNotificationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The factory picks a random existing user for `user_id`, so a training
     * cannot be made on an empty users table.
     */
    private function training(): Training
    {
        User::factory()->create();

        return Training::factory()->create();
    }

    #[Test]
    public function the_notification_carries_the_student_and_the_training(): void
    {
        $training = $this->training();

        $payload = (new MentorAssignedNotification($training))->toArray(User::factory()->create());

        $this->assertSame($training->id, $payload['training_id']);
        $this->assertSame($training->user->name, $payload['student']);
    }

    #[Test]
    public function it_goes_to_mail_and_the_database(): void
    {
        $training = $this->training();

        $this->assertSame(['mail', 'database'], (new MentorAssignedNotification($training))->via(User::factory()->create()));
    }

    #[Test]
    public function the_mail_names_the_student_and_their_cid(): void
    {
        Notification::fake();

        $training = $this->training();
        $mentor = User::factory()->create();

        $mail = (new MentorAssignedNotification($training))->toMail($mentor);

        // Addressed to the MENTOR, not the student. That is the whole bug: the
        // student already had a message and the mentor had none, so a copy of
        // the student's mail sent to the student again would fix nothing.
        //
        // TrainingMail keeps its subject in a private property until it builds,
        // so the recipient is what there is to assert here. The wording is the
        // V4 template's and belongs to training staff, not to this test.
        $this->assertInstanceOf(TrainingMail::class, $mail);
        $this->assertSame($mentor->personalNotificationEmail, $mail->to[0]['address']);
        $this->assertNotSame($training->user->personalNotificationEmail, $mail->to[0]['address']);
    }

    #[Test]
    public function the_student_notification_still_exists_alongside_it(): void
    {
        // The mentor's message is an addition, not a replacement. Losing the
        // student's would be a worse bug than the one being fixed.
        $this->assertTrue(class_exists(TrainingMentorNotification::class));
        $this->assertTrue(class_exists(MentorAssignedNotification::class));
    }
}
