<?php

use App\Http\Controllers\Vatssa\ActionLogController;
use App\Http\Controllers\Vatssa\AvailabilityController;
use App\Http\Controllers\Vatssa\InternalNoteController;
use App\Http\Controllers\Vatssa\MembershipAdminController;
use App\Http\Controllers\Vatssa\MembershipRequestController;
use App\Http\Controllers\Vatssa\RequestActionController;
use App\Http\Controllers\Vatssa\SettingsController;
use App\Http\Controllers\Vatssa\TaskEditController;
use App\Http\Controllers\Vatssa\TrainingSetupController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| VATSSA web routes
|--------------------------------------------------------------------------
|
| Loaded by VatssaServiceProvider with the same middleware upstream's own
| authenticated pages use, so `routes/web.php` stays verbatim and can never
| conflict.
|
| Both pages exist for the same reason: values that change more often than the
| code should not need a deploy to change.
|
*/

/*
| Requests: edit the text, move the desk, reopen a closed one, or raise one that
| is not about a training at all. Upstream can do none of these -- see
| TaskEditController for why each one pushes work back into Discord.
*/
Route::middleware(['web', 'auth', 'activity', 'suspended'])
    ->prefix('vatssa/requests')
    ->name('vatssa.requests.')
    ->group(function () {
        Route::post('/', [TaskEditController::class, 'store'])->name('store');
        Route::patch('/{task}', [TaskEditController::class, 'update'])->name('update');
        Route::post('/{task}/reopen', [TaskEditController::class, 'reopen'])->name('reopen');
        // Do the thing and close the request together, so a paused
        // training and an open request cannot disagree.
        Route::post('/{task}/pause/{mode}', [RequestActionController::class, 'pause'])
            ->whereIn('mode', ['pause', 'resume'])->name('pause');
    });

/*
| Practical exams: REMOVED 2026-09-02.
|
| The nine-stage CPT workflow is gone. It was half a workflow -- authorise,
| collect availability, clear it against plans that are not public, take it,
| publish it -- and half of it lived in people's heads anyway, so the page
| told a story the division was not actually following.
|
| What it was really providing was the availability grid, and that is now a
| tool in its own right rather than a stage of an exam. See vatssa/availability
| below.
|
| The `vatssa_exams` table is left in place by its migration. Dropping it is a
| separate decision about data, not about code.
*/

/*
| Availability.
|
| The first pages built on Tailwind rather than Bootstrap. They use
| layouts/vatssa.blade.php, which loads neither app.scss nor the upstream
| chrome -- so nothing here can conflict with an upstream release, and the
| whole experiment reverts by deleting a handful of added files.
|
| No permission gate: everybody is asked when they are free at some point,
| and a scheduling tool the people being scheduled cannot open is a
| scheduling tool nobody uses.
*/
Route::middleware(['web', 'auth', 'activity', 'suspended'])
    ->prefix('vatssa/availability')
    ->group(function () {
        Route::get('/', [AvailabilityController::class, 'index'])->name('vatssa.availability');
        // Permission AND throttle, and they stop different things.
        //
        // The permission stops arbitrary members putting a titled item on other
        // people's pages -- a poll is named, it appears in a group's list, and
        // attached to a training it shows on that student's record. The
        // throttle stops somebody who legitimately holds the permission filling
        // the table by accident or in a temper.
        //
        // ANSWERING a poll is deliberately ungated. The student being scheduled
        // is the point of the tool and holds no permission at all.
        Route::post('/', [AvailabilityController::class, 'store'])
            ->middleware(['can:availability.polls.create', 'throttle:10,1'])
            ->name('vatssa.availability.store');
        Route::get('/{poll}', [AvailabilityController::class, 'show'])->name('vatssa.availability.show');

        // Adding people afterwards. Ungated by permission on purpose: the
        // controller checks that this is YOUR poll (or that you work the
        // queue), which is a narrower question than any permission could ask.
        Route::post('/{poll}/participants', [AvailabilityController::class, 'addParticipants'])
            ->middleware('throttle:20,1')
            ->name('vatssa.availability.participants');

        // Same gate as adding people, for the same reason: whether this is
        // YOUR poll is a narrower question than a permission can ask.
        Route::post('/{poll}/visibility', [AvailabilityController::class, 'updateVisibility'])
            ->middleware('throttle:20,1')
            ->name('vatssa.availability.visibility');
    });

/*
|--------------------------------------------------------------------------
| Membership: transfers and visits
|--------------------------------------------------------------------------
|
| NOT public, and deliberately. Somebody outside the division can already log
| in -- VATSIM OAuth through Handover creates the account and `isMember()` is
| simply false for them -- so asking them to sign in with the CID the request
| is about avoids both a spam surface and an identity problem.
|
| Ungated by permission for the same reason: these two routes exist FOR the
| people who hold no permissions at all. The controller refuses anybody who is
| already a member, already visiting, or already has one open.
*/
// 'web' and 'suspended' matter here. Without 'web' there is no session, so
// ShareErrorsFromSession never runs and the form cannot show a validation
// error; without 'suspended' a suspended account could ask to join us, which
// is one of the four things that genuinely blocks a request.
//
// NOT 'activity': that middleware is about controlling activity in the
// division, and somebody who is not in the division yet has none by
// definition.
/*
| The membership DESK. Staff side, gated per action rather than per group:
| `requests.view` opens the queue, `.manage` changes anything, and
| `terminal.log` records the CERT check. The ATC training manager holds only
| the first -- they may assign a visiting endorsement on the strength of the
| membership team's check, so they must see whether it came back clean, without
| being able to decide the request.
*/
Route::middleware(['web', 'auth', 'activity', 'suspended'])
    ->prefix('vatssa/membership')
    ->group(function () {
        Route::get('/admin/{queue?}', [MembershipAdminController::class, 'index'])
            ->name('vatssa.membership.index');
        Route::post('/admin', [MembershipAdminController::class, 'store'])
            ->name('vatssa.membership.admin.store');
        Route::get('/admin/request/{membershipRequest}', [MembershipAdminController::class, 'show'])
            ->name('vatssa.membership.show');
        Route::post('/admin/request/{membershipRequest}/check', [MembershipAdminController::class, 'recordCheck'])
            ->name('vatssa.membership.check');
        Route::post('/admin/request/{membershipRequest}/state', [MembershipAdminController::class, 'transition'])
            ->name('vatssa.membership.transition');
    });

Route::middleware(['web', 'auth', 'suspended'])
    ->prefix('vatssa/membership')
    ->group(function () {
        Route::get('/request', [MembershipRequestController::class, 'create'])->name('vatssa.membership.create');
        Route::post('/request', [MembershipRequestController::class, 'store'])
            ->middleware('throttle:10,1')
            ->name('vatssa.membership.store');
    });

/*
| The automation log. A division report rather than an admin page, so it sits
| beside the other reports and uses the permission coordinators already hold.
*/
Route::middleware(['web', 'auth', 'activity', 'suspended'])
    ->get('/vatssa/automation', [ActionLogController::class, 'index'])
    ->name('vatssa.action-log');

/*
| Internal notes. Not under admin/ -- they are written from a member profile and
| from a training, which is where the context is. Each action authorises on its
| own SCOPE permission, so a training-note permission can never delete a member
| note that happened to be listed nearby.
*/
Route::middleware(['web', 'auth', 'activity', 'suspended'])
    ->prefix('vatssa/notes')
    ->name('vatssa.notes.')
    ->group(function () {
        Route::post('/user/{user}', [InternalNoteController::class, 'storeUserNote'])->name('user');
        Route::post('/training/{training}', [InternalNoteController::class, 'storeTrainingNote'])->name('training');
        Route::delete('/{note}', [InternalNoteController::class, 'destroy'])->name('destroy');
    });

Route::middleware(['web', 'auth', 'activity', 'suspended'])
    ->prefix('admin/vatssa')
    ->name('vatssa.admin.')
    ->group(function () {
        Route::get('/templates', [SettingsController::class, 'templates'])->name('templates');
        Route::patch('/templates/{template}', [SettingsController::class, 'updateTemplate'])->name('templates.update');

        Route::get('/mentorship', [SettingsController::class, 'mentorship'])->name('mentorship');
        Route::post('/mentorship', [SettingsController::class, 'updateMentorship'])->name('mentorship.update');

        // VATSSA: the Request routing GRID is gone. Desks are set on the
        // member whose desks they are, from the access box on their profile,
        // and read back from the access report -- which is where "what access
        // does this person have" was always going to be asked.
        //
        // The grid rebuilt every desk in the division on every save, which is
        // why it needed a guard against a browser omitting an empty
        // multi-select and emptying all of them at once. Per member, that whole
        // class of accident stops existing.
        Route::post('/desks/{user}', [SettingsController::class, 'updateDesks'])->name('desks.update');

        Route::get('/moodle-courses', [SettingsController::class, 'courses'])->name('courses');

        /*
        | Training setup: ratings, endorsements, training types, request desks.
        |
        | The three lists that used to be a database table with no interface, a
        | static array in an upstream controller, and a class constant. All of
        | them describe how VATSSA runs training this year, and none of them
        | should have needed a developer to change.
        */
        Route::get('/training-setup', [TrainingSetupController::class, 'index'])->name('setup');
        Route::post('/training-setup/ratings', [TrainingSetupController::class, 'storeRating'])->name('setup.ratings.store');
        Route::patch('/training-setup/ratings/{rating}', [TrainingSetupController::class, 'updateRating'])->name('setup.ratings.update');
        Route::post('/training-setup/types', [TrainingSetupController::class, 'storeType'])->name('setup.types.store');
        Route::patch('/training-setup/types/{type}', [TrainingSetupController::class, 'updateType'])->name('setup.types.update');
        Route::post('/training-setup/desks', [TrainingSetupController::class, 'storeDesk'])->name('setup.desks.store');
        Route::patch('/training-setup/desks/{desk}', [TrainingSetupController::class, 'updateDesk'])->name('setup.desks.update');

        // VATSSA: compliment, complaint, bug report. A table for the same
        // reason training types and desks are one -- it describes how the
        // division organises itself, which changes more often than the code.
        Route::post('/training-setup/feedback-types', [TrainingSetupController::class, 'storeFeedbackType'])->name('setup.feedback-types.store');
        Route::patch('/training-setup/feedback-types/{feedbackType}', [TrainingSetupController::class, 'updateFeedbackType'])->name('setup.feedback-types.update');
        Route::post('/moodle-courses', [SettingsController::class, 'updateCourses'])->name('courses.update');
    });
