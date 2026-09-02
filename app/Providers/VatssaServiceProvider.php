<?php

namespace App\Providers;

use App\Http\Controllers\TrainingController;
use App\Http\Middleware\VatssaBridgeToken;
use App\Listeners\Vatssa\LogSentMail;
use App\Models\Task;
use App\Models\Vatssa\TrainingType;
use App\Observers\VatssaTaskObserver;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * VATSSA: everything this fork adds, wired up in one place.
 *
 * The point of doing it here rather than in the files upstream owns is that
 * `config/app.php` gains one line and nothing else is touched. The middleware
 * alias, the routes and the observer all register from here, so
 * `app/Http/Kernel.php`, `routes/api.php` and `AppServiceProvider` stay
 * verbatim upstream and can never conflict.
 */
class VatssaServiceProvider extends ServiceProvider
{
    public function boot(Router $router): void
    {
        // database/migrations-vatssa is a separate directory so upstream never
        // meets a VATSSA migration in a conflict. Registering it here is what
        // makes `php artisan migrate` -- and RefreshDatabase in the test suite
        // -- pick them up without a --path flag.
        //
        // Ordering is safe: they are all dated later than anything upstream
        // ships, so they still run last. deploy-cc.sh keeps its explicit
        // --path call, which becomes a harmless second pass.
        $this->loadMigrationsFrom(database_path('migrations-vatssa'));

        $router->aliasMiddleware('vatssa-bridge', VatssaBridgeToken::class);

        Route::middleware('api')
            ->prefix('api/vatssa')
            ->group(base_path('routes/vatssa.php'));

        Route::group([], base_path('routes/vatssa-web.php'));

        // The seven-day roster warning, scheduled from HERE rather than from
        // app/Console/Kernel.php -- registering it upstream would make the
        // kernel a modified file, and a conflict on every release, for one
        // line. Daily is right: the window is seven days wide, so a missed run
        // still catches everybody.
        //
        // callAfterResolving, NOT app(Schedule::class). Resolving the schedule
        // eagerly runs upstream's Kernel::schedule(), which calls
        // Setting::get('telemetryEnabled') -- a database query. During
        // `package:discover` in the Docker build there is no database, so that
        // took the whole image build down with "could not find driver". This
        // form registers a callback that only fires if something else actually
        // resolves the schedule, which is `schedule:run` and nothing else.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('vatssa:roster-expiry-warning')
                ->dailyAt('06:00')
                ->withoutOverlapping();

            // A mentor can be detached by at least three upstream paths, and
            // not one of them touches the training's status, tells the student
            // or tells the mentor. So a student sat in 'active training' with
            // nobody teaching them, and a mentor kept a slot reserved for
            // somebody who was not coming back. This is what notices.
            //
            // Diffs against vatssa_training_mentors, so it also catches a SWAP,
            // where the training is never mentorless and the old mentor would
            // otherwise never be told.
            $schedule->command('vatssa:mentor-watch')
                ->dailyAt('06:15')
                ->withoutOverlapping();
        });

        // VATSSA: training types come from the table, not from upstream's
        // static array.
        //
        // Assigning the property here rather than editing every reader is the
        // whole trick: `TrainingController::$types` is an UPSTREAM file read by
        // a dozen controllers and blades, and changing all of them would be a
        // dozen merge conflicts on every release. One assignment in an added
        // file gives every one of those readers the database's answer.
        //
        // The FULL map, active and retired. A retired type must still render on
        // the trainings that used it -- a closed training whose kind went blank
        // is a history that lies. The choosers filter to active themselves.
        //
        // Wrapped, because this runs on every boot INCLUDING `package:discover`
        // during the Docker build, where there is no database at all. The same
        // trap the schedule registration above documents.
        try {
            TrainingController::$types = TrainingType::map();
        } catch (\Throwable) {
            // Leave upstream's compiled-in array in place. Yesterday's list is
            // a better failure than an empty dropdown.
        }

        // Routes tasks to the right desk as they are created. An observer,
        // because TaskController::store() calls Task::create() -- so the
        // controller needs no change at all.
        Task::observe(VatssaTaskObserver::class);

        // Every email this application sends, logged. An event listener rather
        // than a line at each of the nineteen notification classes: those are
        // upstream files, and -- the part that matters -- the twentieth one
        // somebody adds would not be logged and nobody would find out until a
        // member asked what they were told.
        Event::listen(MessageSent::class, LogSentMail::class);
    }
}
