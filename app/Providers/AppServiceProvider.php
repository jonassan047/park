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
        // 受信箱を読む元（v0.12.0）。本物は IMAP。テストでは偽物に差し替える
        $this->app->bind(\App\Contracts\MailSource::class, fn () => \App\Support\ImapClient::fromConfig());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // 公開サーバー（APP_URL が https）では、リンク・フォームの送り先を必ず https で作る
        if (str_starts_with((string) config('app.url'), 'https://')) {
            \Illuminate\Support\Facades\URL::forceScheme('https');
            \Illuminate\Support\Facades\URL::forceRootUrl(config('app.url'));
        }
    }
}
