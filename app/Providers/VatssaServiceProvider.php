<?php

namespace App\Providers;

use App\Http\Middleware\VatssaBridgeToken;
use App\Models\Task;
use App\Observers\VatssaTaskObserver;
use Illuminate\Routing\Router;
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

        // Routes tasks to the right desk as they are created. An observer,
        // because TaskController::store() calls Task::create() -- so the
        // controller needs no change at all.
        Task::observe(VatssaTaskObserver::class);
    }
}
