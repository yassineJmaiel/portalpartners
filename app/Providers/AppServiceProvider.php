<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        /* MAIL_MAILER=graph: every e-mail of the send seam (App\Portal\Deliver) leaves through Microsoft 365 */
        \Illuminate\Support\Facades\Mail::extend('graph', fn () => new \App\Mail\GraphTransport);
    }
}
