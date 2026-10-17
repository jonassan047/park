<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(\App\Http\Middleware\CanonicalHost::class);
        $middleware->alias(['role' => \App\Http\Middleware\Role::class]);
        $middleware->redirectGuestsTo(fn () => url('/login'));
        // 手元の PC の AI とのやり取りは、合言葉で確かめる（画面のフォームではないので CSRF は使わない）
        $middleware->validateCsrfTokens(except: ['ai/worker/*']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
