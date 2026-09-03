<?php

namespace App\Http\Controllers;

use anlutro\LaravelSettings\Facade as Setting;
use App\Helpers\InterestStatus;
use App\Helpers\VatsimRating;
use App\Models\TrainingInterest;
use App\Models\TrainingReport;
use App\Models\User;
use App\Models\Vote;
use App\Services\Vatssa\MembershipCheck;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Controller for the dashboard
 */
class DashboardController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('auth');
    }

    /**
     * Show the application dashboard.
     *
     * @return View
     */
    public function index()
    {
        $user = Auth::user();

        $report = TrainingReport::whereIn('training_id', $user->trainings->pluck('id'))->latest()->first();

        $subdivision = $user->subdivision;
        if (empty($subdivision)) {
            $subdivision = 'No subdivision';
        }

        $data = [
            'rating' => $user->rating_long,
            'rating_short' => $user->rating_short,
            'division' => $user->division,
            'subdivision' => $subdivision,
            'report' => $report,
        ];

        $trainings = $user->trainings;
        $types = TrainingController::$types;

        $dueInterestRequest = TrainingInterest::whereIn('training_id', $user->trainings->pluck('id'))->where('expired', InterestStatus::NOT_EXPIRED)->first();

        // If the user belongs to our subdivision, doesn't have any training requests, has S2+ rating and is marked as inactive -> show notice
        $allowedSubDivisions = explode(',', Setting::get('trainingSubDivisions'));
        $atcInactiveMessage = (
            (
                (config('app.mode') == 'subdivision' && in_array($user->subdivision, $allowedSubDivisions) && $allowedSubDivisions != null)
                || (config('app.mode') == 'division' && $user->division == config('app.owner_code'))
            )
            && ! $user->hasActiveTrainings(true) && $user->rating->isGreaterThan(VatsimRating::OBS) && ! $user->isAtcActive() && ! $user->hasRecentlyCompletedTraining()
        );
        $completedTrainingMessage = $user->hasRecentlyCompletedTraining();

        $workmailRenewal = (isset($user->setting_workmail_expire)) ? (Carbon::parse($user->setting_workmail_expire)->diffInDays(Carbon::now(), false) > -7) : false;

        // Check if there's an active vote running to advertise
        $activeVote = Vote::where('closed', 0)->first();

        $atcHours = ($user->atcActivity->count()) ? $user->atcActivity->sum('hours') : null;

        $studentTrainings = \Auth::user()->mentoringTrainings();

        // VATSSA: the production-only gate is gone.
        //
        // Upstream shows this warning only when APP_ENV is exactly
        // 'production'. So on dev and staging -- the two places somebody is
        // actually looking at the dashboard while setting the box up -- a dead
        // scheduler is invisible.
        //
        // That is not hypothetical. `control-center-tasks.service` ran
        // `docker exec ... control-center`, and no container has that name;
        // ours are cc-prod, cc-staging and cc-dev. So the scheduler failed
        // every minute since it was installed and NOTHING scheduled ever ran --
        // no roster warnings, no mentor watch, no member sync, no endorsement
        // cleanup. This banner is the detector for exactly that, and it was
        // switched off in every environment where it could have been seen.
        //
        // It is still gated on system.health.view, so a member never sees it.
        $cronJobError = $user->hasPermission('system.health.view')
            && Carbon::parse(Setting::get('_lastCronRun', '2000-01-01')) <= Carbon::now()->subMinutes(5);

        $oudatedVersionWarning = $user->hasPermission('system.health.view') && Setting::get('_updateAvailable');

        // VATSSA: the requirement list, resolved HERE rather than in the view.
        //
        // It was three separate `MembershipCheck::for()` calls inside blade
        // files. A view doing database work hides its cost, cannot be tested
        // without rendering, and runs again on every render -- and this one
        // is about half a dozen queries.
        $vatssaRequirements = MembershipCheck::for(Auth::user());

        return view('dashboard', compact('data', 'trainings', 'types', 'dueInterestRequest', 'atcInactiveMessage', 'completedTrainingMessage', 'activeVote', 'atcHours', 'workmailRenewal', 'studentTrainings', 'cronJobError', 'oudatedVersionWarning', 'vatssaRequirements'));
    }

    /**
     * Show the training apply view
     *
     * @return View
     */
    public function apply()
    {
        return view('trainingapply');
    }

    /**
     * Show member endorsements view
     *
     * @return View
     */
    public function endorsements()
    {
        $members = User::has('ratings')->orderBy('first_name')->orderBy('last_name')->get();

        return view('endorsements', compact('members'));
    }
}
