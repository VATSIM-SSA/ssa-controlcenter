<?php

// VATSSA config/roles.php — drafted 2026-08-26 against upstream v7.0.0.
// This is the ONLY upstream file VATSSA modifies. Everything else is an addition.
// Diverges from upstream in three places, all marked VATSSA:
//   1. the roles catalogue          (eight VATSSA roles replace upstream's eight)
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
            // Not offered in the interface and refused by the policy. The only
            // way in is `user:makeadmin`, which is the point: the role that can
            // do everything should need a shell, not a dropdown.
            'grantable' => false,
        ],

        /*
        | RETIRED ROLES.
        |
        | VATSSA replaced these two with atc-training-manager and
        | pipeline-coordinator; see the remap_moderator_buddy_roles migration.
        | They are listed here, and nowhere else, for one reason: RoleAssignment
        | validates against this list, so removing them turned 103 upstream
        | tests into errors rather than failures -- and a suite that is
        | permanently red hides real bugs in its own noise. One of mine sat in
        | there for a day.
        |
        | 'grantable' => false is what keeps that honest. They do not appear in
        | the role picker and UserPolicy::grantRole refuses them outright, so no
        | route, form or crafted POST can put one on a member. They are a name
        | the validator recognises, not a role anybody can hold.
        |
        | NOT enforced in RoleAssignment's own boot guard, deliberately: the
        | upstream tests construct these assignments directly, which is exactly
        | what a test does, and refusing at the model would put the suite back
        | where it started.
        |
        | Deletable once upstream's tests stop referencing them.
        */
        'moderator' => [
            'name' => 'Moderator (retired)',
            'description' => 'Replaced by ATC Training Manager. Recognised so upstream tests run; cannot be granted.',
            'scope' => 'both',
            'grantable' => false,
        ],
        'director' => [
            'name' => 'Director (retired)',
            'description' => 'Replaced by Pipeline Coordinator. Recognised so upstream tests run; cannot be granted.',
            'scope' => 'global',
            'grantable' => false,
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
        'membership-manager' => [
            'name' => 'Membership Manager',
            'description' => 'Rating upgrades, visiting endorsements and member standing',
            'scope' => 'global',
        ],
        'events-team' => [
            'name' => 'Events Team',
            'description' => 'Event and examination bookings, and nothing else',
            'scope' => 'global',
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
        // VATSSA: creating a training for somebody else, by hand. Upstream gates
        // this on fir.management.reports.view, which ALSO gates the request queue
        // -- so restricting manual creation there would take the queue away from
        // the coordinators who work out of it. A separate permission instead.
        'training.create.manual',
        // VATSSA: apply for training on somebody's behalf, or for
        // yourself, when they are not on Discord or Moodle yet.
        'training.platform-requirement.override',
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
        // VATSSA: theory results, in two tiers. `view` is every attempt and
        // whether each passed; `grades` is the actual marks. The split is
        // deliberate -- a coordinator needs to know somebody failed twice, and
        // does not need to know they got 62%.
        'training.results.view',
        'training.results.grades',
        // VATSSA: internal notes, at two scopes with two audiences.
        // Disciplinary history, why somebody was removed or refused, complaints
        // -- things that must be recorded and must not be visible to the person
        // they are about. A training note is for the ATC training manager; a
        // member note outlives every training and is admin-only.
        'training.notes.view',

        // Examinations
        'examinations.manage',
        // VATSSA: clearing the calendar for a practical exam, and publishing it
        // afterwards. SEPARATE FROM examinations.manage, which examiners also
        // hold -- the events team clearing times and an examiner taking one are
        // two different jobs, and a workflow where the same person can do both
        // steps is a status field with extra clicking.
        'events.exams.manage',
        'examinations.create',

        // Endorsements
        // VATSSA: the three endorsement roster pages. Upstream leaves them
        // open to any logged-in member -- indexSolos, indexExaminers and
        // indexVisitors carry no authorize() call at all -- and who holds an
        // examiner endorsement is not something the division publishes.
        'endorsements.rosters.view',
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
        // VATSSA: SPLIT OUT OF fir.management.reports.view.
        //
        // Upstream uses that one permission for four unrelated things: the
        // training request queue, the mentor index, manual training creation
        // and the ACCESS REPORT -- who holds which role across the division.
        // The first three are daily coordinator work; the last is an audit
        // surface. Taking the access report away from the ATC training manager
        // was impossible while they shared a permission, because it would have
        // taken the queue with it.
        'fir.management.access.view',

        // Users
        'users.manage',
        'users.access.view',
        // Admin only. Deliberately NOT inside users.** for anybody else --
        // see the denies on the ATC training manager below.
        'users.notes.view',
        'users.workmail.use',

        // Tasks
        'tasks.manage',
        'tasks.suggested-recipient',
        // VATSSA: see every task, not only your own and your area's.
        'tasks.overview',

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
        'roles.membership-manager.manage',
        'roles.events-team.manage',
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

        // The two retired roles grant NOTHING, which is the honest entry for a
        // role nobody can be given. They are here because every role in the
        // catalogue needs a matrix entry; see the note beside them above.
        'moderator' => [],
        'director' => [],

        // Training authority. MUST remain a superset of pipeline-coordinator.
        'atc-training-manager' => [
            'training.**',
            '!training.delete',                 // admin only
            '!training.ratings.manage',         // admin only
            'examinations.**',                  // create BYPASSES the examiner-endorsement check — knowingly granted
            'endorsements.**',                  // solo + visiting + examiner; only role besides admin with examiner
            'fir.management.reports.view',      // the training-request queue and mentor index — never remove
            '!fir.management.access.view',      // the access report is an audit surface, not training work
            'users.**',                         // manage + workmail.use
            '!users.access.view',               // who holds what role is admin business
            '!users.notes.view',                // member notes are admin-only, by design
            'notifications.inactivity.receive', // NOT notifications.templates.manage
            'tasks.**',                         // includes tasks.overview — see the note below
            'files.**',
            'bookings.**',
            'roles.mentor.manage',              // the only role ATM may grant
            'events.exams.manage',              // the fallback when events are short
        ],

        // Day-to-day pipeline.
        'pipeline-coordinator' => [
            'training.**',
            '!training.delete',                 // admin only
            '!training.reports.delete',         // ATM + admin only
            '!training.ratings.manage',         // admin only
            '!training.create.manual',          // ATM + admin only
            '!training.platform-requirement.override', // ATM + admin only; the gate is
            // pointless if the people who see
            // it every day can wave it through
            '!training.results.grades',         // ATM + admin only; pass/fail is enough
            'examinations.manage',
            'endorsements.solo.*',
            'endorsements.rosters.view',        // the three roster pages
            '!training.notes.view',             // training notes are ATM and admin
            'fir.management.reports.view',      // the training-request queue and mentor index — never remove
            // fir.positions.view and users.access.view were BOTH removed here
            // when they came off the ATC training manager. A coordinator
            // holding something their own manager does not is backwards, and
            // the superset check in tools/expand.py refuses it outright --
            // which is exactly what caught this.
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

        // Rating upgrades arrive on the membership desk, so this role is who
        // works that queue. Visiting endorsements are theirs too: whether
        // somebody may control here at all is a membership question, not a
        // training one.
        'membership-manager' => [
            'users.manage',
            'endorsements.visiting.*',
            'endorsements.rosters.view',
            'tasks.**',                         // includes the overview
            'training.view',                    // context for an upgrade, nothing more
            'fir.management.reports.view',       // the request queue they work from
        ],

        // Deliberately narrow. Bookings and examinations, and nothing else --
        // an events volunteer has no reason to see a training record.
        'events-team' => [
            'bookings.**',
            'examinations.manage',
            'events.exams.manage',      // clear the calendar, then publish
        ],

        'nav-editor' => [
            'fir.positions.*',                  // now expands to view + manage
        ],

        'feedback-team' => [
            'feedback.**',                      // now includes the new feedback.update
        ],
    ],
];
