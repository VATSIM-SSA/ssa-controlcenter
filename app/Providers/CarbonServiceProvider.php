<?php

namespace App\Providers;

use App\Support\DisplayTime;
use Carbon\Carbon;
use Illuminate\Support\ServiceProvider;

class CarbonServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
        Carbon::macro('toEuropeanDate', function ($includeDayName = null) {
            if ($includeDayName) {
                return $this->format('D. d/m/Y');
            }

            return $this->format('d/m/Y');
        });

        Carbon::macro('toEuropeanDateTime', function () {
            return $this->format('d/m/Y H:i\z');
        });

        Carbon::macro('toEuropeanTime', function () {
            return $this->format('H:i\z');
        });

        // The toUser* twins format in the signed-in user's chosen time zone (Zulu by default).
        // Use them on pages only. E-mails and log messages keep the Zulu macros above, as
        // their reader isn't the user who triggered them.
        Carbon::macro('toUserDate', function ($includeDayName = null) {
            return DisplayTime::convert($this)->format($includeDayName ? 'D. d/m/Y' : 'd/m/Y');
        });

        Carbon::macro('toUserDateTime', function () {
            return DisplayTime::convert($this)->format('d/m/Y H:i') . DisplayTime::suffix($this);
        });

        Carbon::macro('toUserTime', function () {
            return DisplayTime::convert($this)->format('H:i') . DisplayTime::suffix($this);
        });
    }
}
