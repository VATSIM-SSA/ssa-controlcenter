<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * VATSSA: the pipeline's messages and the Moodle course map, seeded.
 *
 * A migration rather than a seeder, for the same reason the reference data is:
 * this is not fixture data. The templates are the real emails students receive,
 * and both admin pages are empty and useless until the rows exist. It has to
 * run in production too.
 *
 * ## Control Center now owns the templates
 *
 * They came from `config/templates/*.md` in `ssa-training-pipeline`, and the
 * bot read them off its own disk -- which meant a wording change was a rebuild
 * and a restart. From here the bot reads them back through the bridge, and this
 * is the source of truth. The files stay in the bot as the fallback for when
 * the bridge is unreachable.
 *
 * ## It never overwrites an edit
 *
 * `insertOrIgnore`, deliberately. Re-running must not silently undo somebody's
 * wording change -- and this migration will be re-run, because dev and staging
 * are rebuilt from scratch regularly.
 *
 * ## The course ids are 0, and that is the correct starting state
 *
 * Nobody has read them out of Moodle yet. A rating whose ids are 0 is dropped
 * from the map, so it visibly needs no theory -- which gets noticed and fixed.
 * Inventing ids would give every student no attempts, which is
 * indistinguishable from a room full of failures.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        foreach ($this->templates() as $key => $template) {
            DB::table('vatssa_message_templates')->insertOrIgnore([
                'key' => $key,
                'name' => $template['name'],
                'subject' => $template['subject'],
                'body' => $template['body'],
                'channel' => $template['channel'],
                'description' => $template['description'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // Every rating VATSSA trains for. The ids are filled in from Moodle,
        // on the admin page, once somebody has read them off the courses.
        foreach (['S1', 'S2', 'S3', 'C1'] as $rating) {
            DB::table('vatssa_moodle_courses')->insertOrIgnore([
                'rating' => $rating,
                'course_id' => 0,
                'exam_quiz_id' => 0,
                'pass_mark' => 75,
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('vatssa_message_templates')->whereIn('key', array_keys($this->templates()))->delete();
        DB::table('vatssa_moodle_courses')->whereIn('rating', ['S1', 'S2', 'S3', 'C1'])->delete();
    }

    /**
     * The seventeen messages, verbatim from the pipeline as of 2026-08-29.
     *
     * Placeholders in braces are filled at send time. The bot RAISES rather
     * than emailing a raw brace when a template asks for something it cannot
     * supply, so adding a placeholder here that the pipeline does not know
     * stops that message going out entirely.
     */
    private function templates(): array
    {
        return [
            'T1' => [
                'name' => 'Welcome and Moodle enrolment',
                'subject' => 'VATSSA ATC Training — you\'re enrolled',
                'channel' => 'email',
                'body' => <<<'BODY'
                    {name}, welcome to VATSSA's ATC Training Program.

                    You've been enrolled in the {rating} Training Course on VATSSA's Training Platform at {platform_url}. It covers the rules and policies, the software and tools, and the full theory for your rating, with the Theory Exam available inside the course itself once you've worked through everything (and it's important that you go through the entire course).

                    You have 90 days to complete the theory, until {date}. Once you've passed the Theory Exam, you'll be placed on the waiting list for a mentor and move on to practical training. Mentoring sessions and exams run in the voice channels on our Discord server, so make sure you're there too.

                    Should you have any questions, just reply to this email.

                    Welcome and have fun!
                    BODY,
            ],
            'T2' => [
                'name' => 'Register on the training platform',
                'subject' => 'VATSSA ATC Training — register on the Training Platform',
                'channel' => 'email',
                'body' => <<<'BODY'
                    {name}, welcome to VATSSA's ATC Training Program.

                    Before we can assign you the {rating} Training Course, we need you to log in to VATSSA's Training Platform at {platform_url} with your VATSIM account. You have not registered there yet — please do so now, then reply to this email and we'll enrol you straight away.
                    BODY,
            ],
            'T3' => [
                'name' => 'Join the Discord server',
                'subject' => 'VATSSA ATC Training — please join our Discord',
                'channel' => 'email',
                'body' => <<<'BODY'
                    {name}, welcome to VATSSA's ATC Training Program.

                    You've been enrolled in the {rating} Training Course on VATSSA's Training Platform at {platform_url}.

                    You also need to be on our Discord server — mentoring sessions run in its voice channels, exams are conducted there, and student announcements are posted there. Please join at {invite_link} and reply to this email once you're in.

                    You have 90 days to complete the theory course, until {date}. Good luck!
                    BODY,
            ],
            'T4' => [
                'name' => 'Two steps to get started',
                'subject' => 'VATSSA ATC Training — two steps to get started',
                'channel' => 'email',
                'body' => <<<'BODY'
                    {name}, welcome to VATSSA's ATC Training Program.

                    Before we can start your training, we need two things from you:

                    1. Log in to VATSSA's Training Platform at {platform_url} with your VATSIM account, so we can enrol you in the {rating} Training Course.
                    2. Join our Discord server at {invite_link}, where mentoring sessions, exams and announcements all happen.

                    Reply to this email once you've done both, and we'll get you set up straight away.
                    BODY,
            ],
            'T5' => [
                'name' => 'Action needed within five days',
                'subject' => 'VATSSA ATC Training — action needed within 5 days',
                'channel' => 'email',
                'body' => <<<'BODY'
                    {name}, please acknowledge our previous message within the next 5 days, otherwise we will have to suspend you from ATC Training and you'll need to sign up again.
                    BODY,
            ],
            'T6' => [
                'name' => 'Theory deadline reminder',
                'subject' => 'VATSSA ATC Training — {days_left} days left on your course',
                'channel' => 'email',
                'description' => 'Sent at the reminder points inside the 90-day theory window. {days_left} counts down.',
                'body' => <<<'BODY'
                    {name}, we've recently reviewed student progress on the {rating} Training Course.

                    We've noticed you haven't yet completed all the required activities. A reminder that the course must be completed within 90 days of enrolment — you have {days_left} days left, until {date}. If it isn't completed by then, we'll have to suspend you from ATC Training, which means signing up again and possibly a waiting period.

                    Do you have any questions? Just reply to this email.
                    BODY,
            ],
            'T7' => [
                'name' => 'Theory passed, mentor waiting list',
                'subject' => 'VATSSA ATC Training — theory passed',
                'channel' => 'email',
                'description' => 'Sent the moment the pipeline sees a theory pass in Moodle. Placing somebody on the mentor waiting list is what this announces.',
                'body' => <<<'BODY'
                    {name}, your {rating} Theory Exam grade has been confirmed. Well done!

                    You've been placed on the waiting list for the {rating} Mentoring Program. As soon as a mentor becomes available, we'll assign you one and let you know by email.
                    BODY,
            ],
            'T8' => [
                'name' => 'Mentor assigned',
                'subject' => 'VATSSA ATC Training — your mentor',
                'channel' => 'email',
                'body' => <<<'BODY'
                    {name}, we've been able to assign you a mentor — thank you for your patience.

                    {mentor} will be your mentor through this journey. You'll go through mentoring sessions together and, once you're ready, complete solo time and a CPT to earn the rating.

                    Your mentor will contact you by email to arrange sessions, which run in the voice channels on our Discord server. Anything you need along the way — an absence, a question about your progress — goes through your mentor, who logs it on Control Center.

                    Welcome and have fun!
                    BODY,
            ],
            'T9' => [
                'name' => 'Are you still with us',
                'subject' => 'VATSSA ATC Training — are you still with us?',
                'channel' => 'email',
                'description' => 'The inactivity chase. Going unanswered is what eventually closes a training.',
                'body' => <<<'BODY'
                    {name}, are you still available for ATC Training?

                    We haven't seen progress on your training for a while. Just reply to this email either way, so we know whether to keep your place.
                    BODY,
            ],
            'T10' => [
                'name' => 'Congratulations on the CPT',
                'subject' => null,
                'channel' => 'staff',
                'description' => 'Posted in the kudos channel, not emailed to the student.',
                'body' => <<<'BODY'
                    Congratulations to {name} on passing their {rating} CPT and earning the rating! We look forward to seeing you on the network.
                    BODY,
            ],
            'T11' => [
                'name' => 'Training closed',
                'subject' => 'VATSSA ATC Training — training suspended',
                'channel' => 'email',
                'body' => <<<'BODY'
                    {name}, your {rating} ATC Training has been suspended.

                    Reason: {reason}.

                    Your access to the course has been suspended and you've been removed from the training list. Your exam attempts and results are kept on record. You're welcome to sign up again on the website when you're ready to commit to the programme; a waiting period may apply.
                    BODY,
            ],
            'T12' => [
                'name' => 'Leave of absence logged',
                'subject' => 'VATSSA ATC Training — leave of absence logged',
                'channel' => 'email',
                'body' => <<<'BODY'
                    {name}, your leave of absence has been logged from {date}.

                    If you're back within 14 days, you keep your mentor and carry on where you left off. If it's longer, your mentor slot is released and you'll be placed at the front of the mentor waiting list when you return.

                    Any solo endorsement you held has been revoked for the duration, so the days aren't spent while you're away — a new one can be arranged when you're back.

                    Reply to this email when you're ready to continue.
                    BODY,
            ],
            'T13' => [
                'name' => 'Welcome back from leave',
                'subject' => 'VATSSA ATC Training — welcome back',
                'channel' => 'email',
                'body' => <<<'BODY'
                    {name}, welcome back.

                    {action}

                    If anything has changed about your availability, just reply and let us know.
                    BODY,
            ],
            'T14' => [
                'name' => 'Mentor changed',
                'subject' => 'VATSSA ATC Training — your mentor has changed',
                'channel' => 'email',
                'body' => <<<'BODY'
                    {name}, your mentor has changed: {new_mentor} takes over from {old_mentor} from {date}.

                    Nothing else about your training changes — your progress and reports carry over, and {new_mentor} will contact you by email to arrange your next session.
                    BODY,
            ],
            'T15' => [
                'name' => 'CPT result',
                'subject' => 'VATSSA ATC Training — CPT result',
                'channel' => 'email',
                'body' => <<<'BODY'
                    {name}, unfortunately your CPT was not successful this time. It happens, and it is part of training.

                    Your mentor will go through the examiner's feedback with you and continue your sessions. Where needed, a new solo endorsement can be arranged within the VATSIM limits, and your CPT will be rescheduled as soon as you and your mentor agree you're ready. Nothing about your place in the programme changes.
                    BODY,
            ],
            'S1' => [
                'name' => 'Staff action needed',
                'subject' => 'S2 pipeline — action needed ({date})',
                'channel' => 'staff',
                'description' => 'Emailed to the coordinator and pinged in the staff channel. Never sent to a student.',
                'body' => <<<'BODY'
                    Action needed on the S2 pipeline.

                    {action}
                    BODY,
            ],
            'P2' => [
                'name' => 'Mentor student index',
                'subject' => null,
                'channel' => 'thread',
                'description' => 'The pinned index in each mentor\'s thread. The bot rewrites it in place rather than posting again.',
                'body' => <<<'BODY'
                    {mentor}, this is your student index. The bot keeps it updated.

                    Your current students are listed below, each with a link to their training on Control Center. Resources, capacity requests and your dashboard are on the mentor portal.
                    BODY,
            ],
        ];
    }
};
