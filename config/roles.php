<?php

// VATSSA config/roles.php — drafted 2026-08-26 against upstream v7.0.0.
// This is the ONLY upstream file VATSSA modifies. Everything else is an addition.
// Diverges from upstream in three places, all marked VATSSA:
//   1. the roles catalogue          (six VATSSA roles replace upstream's eight)
//   2. the roles.*.manage entries   (renamed to match the VATSSA role keys)
//   3. the matrix                   (VATSSA's own grants)
// The permission catalogue is otherwise upstream's, verbatim. Keep it that way:
// a permission must be listed to exist, and adding one here does not create a gate.

return [
    'roles' => [
        'admin' => [
            'name' => 'Administrator',
            'description' => 'System-wide administrator, assignable only via the user:makeadmin CLI command',
            'scope' => 'global',
        ],
        'atc-training-manager' => [
            'name' => 'ATC Training Manager',
            'description' => 'Division training authority',
            'scope' => 'both',
        ],
        'pipeline-coordinator' => [
            'name' => 'Pipeline Coordinator',
            'description' => 'Day-to-day running of the training pipeline',
            'scope' => 'both',
        ],
        'mentor' => [
            'name' => 'Mentor',
            'description' => 'Training mentor',
            'scope' => 'area',
        ],
        'nav-editor' => [
            'name' => 'Navigational Editor',
            'description' => 'Editor of navigational and operationally relevant sector data',
            'scope' => 'area',
        ],
        'feedback-team' => [
            'name' => 'Feedback Team',
            'description' => 'Reviews controller feedback',
            'scope' => 'global',
        ],
    ],

    'permissions' => [
        // Training
        'training.view',
        'training.create',
        'training.update',
        'training.delete',
        'training.mentor',
        'training.mentor-dashboard.view',
        'training.ratings.manage',
        'training.reports.view',
        'training.reports.create',
        'training.reports.update',
        'training.reports.delete',
        'training.reports.one-time-link',
        'training.attachments.view-hidden',
        'training.activities.view',
        'training.statistics.view',
        'training.notifications.receive',

        // Examinations
        'examinations.manage',
        'examinations.create',

        // Endorsements
        'endorsements.solo.manage',
        'endorsements.solo.delete',
        'endorsements.visiting.manage',
        'endorsements.visiting.delete',
        'endorsements.examiner.manage',
        'endorsements.examiner.delete',

        // FIR operations
        'fir.positions.view',
        'fir.positions.manage',
        'fir.management.reports.view',

        // Users
        'users.manage',
        'users.access.view',
        'users.workmail.use',

        // Tasks
        'tasks.manage',
        'tasks.suggested-recipient',

        // Files
        'files.manage',
        'files.upload',

        // Feedback
        'feedback.correlated.view',
        'feedback.uncorrelated.view',
        'feedback.update',

        // Bookings
        'bookings.bypass-restrictions',
        'bookings.manage',
        'bookings.sweatbox.use',
        'bookings.sweatbox.manage',

        // Notifications
        'notifications.inactivity.receive',
        'notifications.templates.manage',

        // System
        'system.health.view',
        'system.settings.manage',
        'system.votes.manage',
        'system.activity-log.view',

        // VATSSA: grant authority, one per grantable role. Renamed from upstream's
        // director/moderator/training-staff/staff/buddy to the VATSSA role keys.
        // UserPolicy::updateRole builds "roles.{$requestedRole}.manage", so these
        // MUST stay spelled exactly like the keys above or nobody can grant anything.
        'roles.atc-training-manager.manage',
        'roles.pipeline-coordinator.manage',
        'roles.nav-editor.manage',
        'roles.mentor.manage',
        'roles.feedback-team.manage',
    ],

    'matrix' => [
        // Everything, no denies. Upstream withholds one-time-link and
        // attachments.view-hidden from admin; VATSSA grants them (2026-07-17).
        'admin' => [
            '**',
        ],

        // Training authority. MUST remain a superset of pipeline-coordinator.
        'atc-training-manager' => [
            'training.**',
            '!training.delete',                 // admin only
            '!training.ratings.manage',         // admin only
            'examinations.**',                  // create BYPASSES the examiner-endorsement check — knowingly granted
            'endorsements.**',                  // solo + visiting + examiner; only role besides admin with examiner
            'fir.positions.view',               // read-only; fir.positions.manage stays Nav Team + admin
            'fir.management.reports.view',      // ALSO the training-request queue — never remove
            'users.**',                         // manage + access.view + workmail.use
            'notifications.inactivity.receive', // NOT notifications.templates.manage
            'tasks.**',
            'files.**',
            'bookings.**',
            'roles.mentor.manage',              // the only role ATM may grant
        ],

        // Day-to-day pipeline.
        'pipeline-coordinator' => [
            'training.**',
            '!training.delete',                 // admin only
            '!training.reports.delete',         // ATM + admin only
            '!training.ratings.manage',         // admin only
            'examinations.manage',
            'endorsements.solo.*',
            'fir.positions.view',
            'fir.management.reports.view',      // ALSO the training-request queue — never remove
            'users.manage',                     // UserPolicy::view/index require it; without it
                                                // users.access.view is unreachable and a Pipeline
                                                // Coordinator cannot open a member profile at all
            'users.access.view',
            'users.workmail.use',
            'tasks.**',
            'files.**',
            'bookings.**',
        ],

        // Mentors: their own students only; cannot create training for others.
        'mentor' => [
            'training.mentor',
            'training.mentor-dashboard.view',
            'training.reports.one-time-link',
            'training.attachments.view-hidden',
            'tasks.manage',
            'files.upload',
            'bookings.bypass-restrictions',
            'bookings.sweatbox.use',
        ],

        'nav-editor' => [
            'fir.positions.*',                  // now expands to view + manage
        ],

        'feedback-team' => [
            'feedback.**',                      // now includes the new feedback.update
        ],
    ],
];
