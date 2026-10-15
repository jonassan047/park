<?php

use App\Http\Controllers\AccountController as Acc;
use App\Http\Controllers\AdminController as Adm;
use App\Http\Controllers\CaseController as Cas;
use App\Http\Controllers\EgController as Eg;
use App\Http\Controllers\EngineerController as Eng;
use App\Http\Controllers\PipelineController as Pip;
use App\Http\Controllers\PublicController as Pub;
use App\Http\Controllers\SesController as Ses;
use App\Http\Controllers\TwoFactorController as TF;
use Illuminate\Support\Facades\Route;

Route::pattern('id', '[0-9]+');
Route::pattern('pid', '[0-9]+');

// ---------- ログイン前 ----------
Route::get('/', [Pub::class, 'home']);
Route::get('/login', [Pub::class, 'loginForm'])->name('login');
Route::post('/login', [Pub::class, 'login'])->middleware('throttle:10,1');
Route::post('/logout', [Pub::class, 'logout']);
Route::get('/setup', [Pub::class, 'setupForm']);
Route::post('/setup', [Pub::class, 'setup']);
Route::get('/apply', [Pub::class, 'applyForm']);
Route::post('/apply', [Pub::class, 'apply'])->middleware('throttle:5,1');
Route::get('/invite/{token}', [Pub::class, 'inviteForm']);
Route::post('/invite/{token}', [Pub::class, 'invite']);
Route::get('/forgot', [Pub::class, 'forgotForm']);
Route::post('/forgot', [Pub::class, 'forgot'])->middleware('throttle:5,1');

// ---------- 2要素認証（パスワードは通ったが、まだコードを入れていない人も開ける） ----------
Route::middleware('auth')->group(function () {
    Route::get('/two-factor/setup', [TF::class, 'setupForm']);
    Route::post('/two-factor/setup', [TF::class, 'setup'])->middleware('throttle:10,1');
    Route::get('/two-factor/challenge', [TF::class, 'challengeForm']);
    Route::post('/two-factor/challenge', [TF::class, 'challenge'])->middleware('throttle:10,1');
});

// ---------- ログインした人（役割ごとに見え方が変わる共通の画面） ----------
Route::middleware('role')->group(function () {
    Route::get('/notifications', [Acc::class, 'notifications']);
    Route::get('/notifications/{id}', [Acc::class, 'openNotification']);
    Route::post('/notifications/read-all', [Acc::class, 'readAll']);
    Route::get('/account', [Acc::class, 'show']);
    Route::post('/account', [Acc::class, 'update']);
    Route::post('/account/password', [Acc::class, 'password']);
    Route::get('/two-factor/recovery-codes', [TF::class, 'recoveryCodes']);
    Route::post('/two-factor/recovery-codes', [TF::class, 'regenerate']);

    Route::get('/cases/{id}', [Cas::class, 'show']);
    Route::post('/cases/{id}/apply', [Cas::class, 'apply']);

    Route::get('/engineers/{id}', [Eng::class, 'show']);
    Route::get('/engineers/{id}/edit', [Eng::class, 'edit']);
    Route::post('/engineers/{id}', [Eng::class, 'update']);
    Route::get('/engineers/{id}/skills', [Eng::class, 'skills']);
    Route::post('/engineers/{id}/skills', [Eng::class, 'saveSkills']);
    Route::get('/engineers/{id}/projects/new', [Eng::class, 'projectForm']);
    Route::post('/engineers/{id}/projects', [Eng::class, 'saveProject']);
    Route::get('/engineers/{id}/projects/{pid}/edit', [Eng::class, 'projectForm']);
    Route::post('/engineers/{id}/projects/{pid}', [Eng::class, 'saveProject']);
    Route::post('/engineers/{id}/projects/{pid}/delete', [Eng::class, 'deleteProject']);
    Route::get('/engineers/{id}/sheet', [Eng::class, 'sheet']);

    Route::get('/applications/{id}', [Pip::class, 'show']);
    Route::get('/interviews/{id}', [Pip::class, 'interviewShow']);
    Route::get('/contracts/{id}', [Pip::class, 'contractShow']);
});

Route::middleware('role:admin,ses')->group(function () {
    Route::get('/cases/new', [Cas::class, 'create']);
    Route::post('/cases/import', [Cas::class, 'import']);
    Route::post('/cases', [Cas::class, 'store']);
    Route::get('/cases/{id}/edit', [Cas::class, 'edit']);
    Route::post('/cases/{id}', [Cas::class, 'update']);
    Route::post('/cases/{id}/distribute', [Cas::class, 'distribute']);
    Route::post('/cases/{id}/status', [Cas::class, 'status']);
    Route::get('/engineers/new', [Eng::class, 'create']);
    Route::post('/engineers', [Eng::class, 'store']);
    Route::post('/engineers/{id}/invite', [Eng::class, 'invite']);
    Route::get('/invoices/{id}', [Pip::class, 'invoice']);
});

Route::middleware('role:admin,eg')->group(function () {
    Route::get('/engineers/{id}/messages', [Eng::class, 'messages']);
    Route::post('/engineers/{id}/messages', [Eng::class, 'postMessage']);
    Route::post('/contracts/{id}/followup', [Pip::class, 'followup']);
});

Route::middleware('role:ses,eg')->post('/applications/{id}/withdraw', [Pip::class, 'withdraw']);

// ---------- 運営 ----------
Route::middleware('role:admin')->group(function () {
    Route::post('/applications/{id}/status', [Pip::class, 'status']);
    Route::post('/applications/{id}/note', [Pip::class, 'note']);
    Route::post('/applications/{id}/interview', [Pip::class, 'interview']);
    Route::post('/applications/{id}/contract', [Pip::class, 'contract']);
    Route::post('/interviews/{id}/qa', [Pip::class, 'qa']);
    Route::post('/interviews/{id}/regen', [Pip::class, 'regen']);
    Route::post('/interviews/{id}/result', [Pip::class, 'result']);
    Route::post('/contracts/{id}/extend', [Pip::class, 'extend']);
    Route::post('/contracts/{id}/end', [Pip::class, 'end']);

    Route::prefix('admin')->group(function () {
        Route::get('/', [Adm::class, 'dashboard']);
        Route::get('/ses', [Adm::class, 'sesIndex']);
        Route::get('/ses/new', [Adm::class, 'sesNew']);
        Route::post('/ses', [Adm::class, 'sesStore']);
        Route::get('/ses/{id}', [Adm::class, 'sesShow']);
        Route::post('/ses/{id}', [Adm::class, 'sesUpdate']);
        Route::post('/ses/{id}/approve', [Adm::class, 'sesApprove']);
        Route::post('/ses/{id}/suspend', [Adm::class, 'sesSuspend']);
        Route::post('/ses/{id}/users', [Adm::class, 'sesUsers']);
        Route::get('/engineers', [Adm::class, 'engineers']);
        Route::post('/engineers/operator', [Adm::class, 'operator']);
        Route::get('/cases', [Adm::class, 'cases']);
        Route::get('/applications', [Adm::class, 'applications']);
        Route::get('/contracts', [Adm::class, 'contracts']);
        Route::get('/invoices', [Adm::class, 'invoices']);
        Route::post('/invoices/build', [Adm::class, 'buildInvoices']);
        Route::post('/invoices/{id}/issue', [Adm::class, 'issueInvoice']);
        Route::post('/invoices/{id}/paid', [Adm::class, 'paidInvoice']);
        Route::get('/messages', [Adm::class, 'messages']);
        Route::get('/mail', [Adm::class, 'mail']);
        Route::get('/mail/{id}', [Adm::class, 'mailShow']);
        Route::post('/mail/{id}/resend', [Adm::class, 'resend']);
        Route::post('/mail/flush', [Adm::class, 'flush']);
        Route::post('/mail/broadcast', [Adm::class, 'broadcast']);
        Route::get('/reports', [Adm::class, 'reports']);
        Route::get('/settings', [Adm::class, 'settings']);
        Route::post('/settings', [Adm::class, 'saveSettings']);
        Route::post('/settings/testmail', [Adm::class, 'testMail']);
        Route::post('/settings/admins', [Adm::class, 'admins']);
        Route::get('/audit', [Adm::class, 'audit']);
        Route::post('/users/{id}/reset-2fa', [TF::class, 'adminReset']);
    });
});

// ---------- SES ----------
Route::middleware('role:ses')->prefix('ses')->group(function () {
    Route::get('/', [Ses::class, 'dashboard']);
    Route::get('/engineers', [Ses::class, 'engineers']);
    Route::get('/cases', [Ses::class, 'cases']);
    Route::get('/applications', [Ses::class, 'applications']);
    Route::get('/contracts', [Ses::class, 'contracts']);
    Route::get('/invoices', [Ses::class, 'invoices']);
    Route::get('/company', [Ses::class, 'company']);
    Route::post('/company', [Ses::class, 'saveCompany']);
});

// ---------- エンジニア ----------
Route::middleware('role:eg')->group(function () {
    Route::get('/eg', [Eg::class, 'home']);
    Route::get('/eg/offers', [Eg::class, 'offers']);
    Route::get('/eg/applications', [Eg::class, 'applications']);
    Route::post('/cases/{id}/decline', [Cas::class, 'decline']);
    Route::post('/interviews/{id}/memo', [Pip::class, 'memo']);
    Route::get('/eg/profile', fn () => redirect('/engineers/'.auth()->user()->engineer_id));
    Route::get('/eg/sheet', fn () => redirect('/engineers/'.auth()->user()->engineer_id.'/sheet'));
    Route::get('/eg/messages', fn () => redirect('/engineers/'.auth()->user()->engineer_id.'/messages'));
});
