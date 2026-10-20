<?php

use App\Http\Controllers\AccountController as Acc;
use App\Http\Controllers\AdminController as Adm;
use App\Http\Controllers\AiController as Ai;
use App\Http\Controllers\CaseController as Cas;
use App\Http\Controllers\CaseMailController as CMail;
use App\Http\Controllers\EgController as Eg;
use App\Http\Controllers\EngineerController as Eng;
use App\Http\Controllers\ManualController as Man;
use App\Http\Controllers\PipelineController as Pip;
use App\Http\Controllers\PublicController as Pub;
use App\Http\Controllers\ReplyController as Rep;
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
// 手元の PC の AI とのやり取り（合言葉で確かめる）
Route::post('/ai/worker/next', [Ai::class, 'next'])->middleware('throttle:120,1');
Route::post('/ai/worker/{id}/result', [Ai::class, 'result'])->middleware('throttle:120,1');

// 案内メールの返事のリンク（ログインしなくてよい）
Route::get('/r/{token}/{answer}', [Rep::class, 'form'])->where('token', '[0-9a-f]{32}');
Route::post('/r/{token}', [Rep::class, 'save'])->where('token', '[0-9a-f]{32}')->middleware('throttle:20,1');
Route::get('/manual', [Man::class, 'index']);
Route::get('/manual/{kind}', [Man::class, 'show']);

// ---------- 2要素認証（パスワードは通ったが、まだコードを入れていない人も開ける） ----------
Route::middleware('auth')->group(function () {
    Route::redirect('/two-factor/setup', '/two-factor/challenge'); // 認証アプリのころの道（古いリンク用）
    Route::get('/two-factor/challenge', [TF::class, 'challengeForm']);
    Route::post('/two-factor/challenge', [TF::class, 'challenge'])->middleware('throttle:10,1');
    Route::post('/two-factor/resend', [TF::class, 'resend'])->middleware('throttle:5,10');
});

// ---------- ログインした人（役割ごとに見え方が変わる共通の画面） ----------
Route::middleware('role')->group(function () {
    // 兼任の人が、ログインのときに立場を選ぶ（入ったあとは変えられない。切り替えの道は作らない）
    Route::get('/login/role', [Acc::class, 'roleForm']);
    Route::post('/login/role', [Acc::class, 'chooseRole'])->middleware('throttle:10,1');
    Route::get('/notifications', [Acc::class, 'notifications']);
    Route::get('/notifications/{id}', [Acc::class, 'openNotification']);
    Route::post('/notifications/read-all', [Acc::class, 'readAll']);
    Route::get('/welcome', [Acc::class, 'welcomeForm']);
    Route::post('/welcome', [Acc::class, 'welcome']);
    Route::get('/account', [Acc::class, 'show']);
    Route::post('/account', [Acc::class, 'update']);
    Route::get('/account/profile', [Acc::class, 'profile']);
    Route::post('/account/password', [Acc::class, 'password']);

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
    Route::get('/engineers/{id}/profile', [Eng::class, 'profileForm']);
    Route::post('/engineers/{id}/profile', [Eng::class, 'saveProfile']);
    Route::post('/engineers/{id}/docs/generate', [Eng::class, 'generateDoc']);
    Route::post('/engineers/{id}/docs/upload', [Eng::class, 'uploadDoc']);
    Route::get('/engineers/{id}/docs/{fid}', [Eng::class, 'downloadDoc'])->whereNumber('fid');
    Route::post('/engineers/{id}/docs/{fid}/delete', [Eng::class, 'deleteDoc'])->whereNumber('fid');

    Route::post('/engineers/{id}/docs/{fid}/ai', [Ai::class, 'queueResume'])->whereNumber('fid');
    Route::post('/ai-jobs/{id}/project/{n}', [Ai::class, 'addProject'])->whereNumber('n');
    Route::post('/ai-jobs/{id}/drop', [Ai::class, 'drop']);
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
    Route::post('/cases/{id}/recommend', [Cas::class, 'recommend']);
    Route::get('/cases/{id}/mail', [CMail::class, 'form']);
    Route::post('/cases/{id}/mail/preview', [CMail::class, 'preview']);
    Route::post('/cases/{id}/mail/send', [CMail::class, 'send']);
    Route::post('/case-mails/{id}/reply', [CMail::class, 'reply']);
    Route::get('/case-mails/{id}/doc/{kind}', [CMail::class, 'doc']);
    Route::post('/cases/{id}/status', [Cas::class, 'status']);
    Route::get('/engineers/new', [Eng::class, 'create']);
    Route::post('/engineers', [Eng::class, 'store']);
    Route::post('/engineers/{id}/invite', [Eng::class, 'invite']);
    Route::post('/engineers/{id}/delete', [Eng::class, 'destroy']);
    Route::post('/engineers/{id}/suspend', [Eng::class, 'suspend']);
    Route::post('/engineers/{id}/resume', [Eng::class, 'resume']);
    // 担当者の停止・再開・退会（管理者はどの会社でも、SES は自社の担当者だけ。中で確かめる）
    Route::post('/admin/ses/{id}/users/{uid}/suspend', [Adm::class, 'sesUserSuspend'])->where('uid', '[0-9]+');
    Route::post('/admin/ses/{id}/users/{uid}/resume', [Adm::class, 'sesUserResume'])->where('uid', '[0-9]+');
    Route::post('/admin/ses/{id}/users/{uid}/delete', [Adm::class, 'sesUserDelete'])->where('uid', '[0-9]+');
    Route::get('/invoices/{id}', [Pip::class, 'invoice']);
    // 選考（面談依頼の報告・面談スクリプト・結果・成約）。SES は自社の応募だけ
    Route::post('/applications/{id}/status', [Pip::class, 'status']);
    Route::post('/applications/{id}/note', [Pip::class, 'note']);
    Route::post('/applications/{id}/interview', [Pip::class, 'interview']);
    Route::post('/applications/{id}/contract', [Pip::class, 'contract']);
    Route::post('/interviews/{id}/qa', [Pip::class, 'qa']);
    Route::post('/interviews/{id}/regen', [Pip::class, 'regen']);
    Route::post('/interviews/{id}/result', [Pip::class, 'result']);
    Route::post('/case-mails/{id}/submitted', [CMail::class, 'submitted']);
    Route::post('/interviews/{id}/ai', [Ai::class, 'queueScript']);
    Route::post('/ai-jobs/{id}/apply', [Ai::class, 'apply']);
});

Route::middleware('role:admin,eg')->group(function () {
    Route::get('/engineers/{id}/messages', [Eng::class, 'messages']);
    Route::post('/engineers/{id}/messages', [Eng::class, 'postMessage']);
    Route::post('/contracts/{id}/followup', [Pip::class, 'followup']);
});

Route::middleware('role:ses,eg')->post('/applications/{id}/withdraw', [Pip::class, 'withdraw']);

// ---------- 管理者 ----------
Route::middleware('role:admin')->group(function () {
    Route::post('/contracts/{id}/extend', [Pip::class, 'extend']);
    Route::post('/contracts/{id}/end', [Pip::class, 'end']);

    Route::prefix('admin')->group(function () {
        Route::get('/', [Adm::class, 'dashboard']);
        Route::get('/ses', [Adm::class, 'sesIndex']);
        Route::get('/ses/new', [Adm::class, 'sesNew']);
        Route::post('/ses', [Adm::class, 'sesStore']);
        Route::get('/ses/{id}', [Adm::class, 'sesShow']);
        Route::get('/ses/{id}/edit', [Adm::class, 'sesEdit']);
        Route::get('/ses/{id}/users/new', [Adm::class, 'sesUserNew']);
        Route::get('/ses/{id}/users/{uid}', [Adm::class, 'sesUserShow'])->whereNumber('uid');
        Route::post('/ses/{id}/delete', [Adm::class, 'sesDelete']);
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
        Route::get('/admins', [Adm::class, 'adminsIndex']);
        Route::get('/admins/new', [Adm::class, 'adminNew']);
        Route::post('/admins', [Adm::class, 'admins']);
        Route::post('/admins/{id}/reinvite', [Adm::class, 'reinviteAdmin']);
        Route::get('/admins/{id}', [Adm::class, 'adminShow']);
        Route::get('/admins/{id}/edit', [Adm::class, 'adminEdit']);
        Route::post('/admins/{id}', [Adm::class, 'adminUpdate']);
        Route::get('/audit', [Adm::class, 'audit']);
        Route::get('/ai', [Ai::class, 'index']);
        Route::get('/skills', [Adm::class, 'skills']);
        Route::post('/skills', [Adm::class, 'addSkill']);
        Route::post('/skills/{id}', [Adm::class, 'updateSkill']);
        Route::post('/skills/{id}/merge', [Adm::class, 'mergeSkill']);
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
    Route::get('/company/edit', [Ses::class, 'companyEdit']);
    Route::post('/company', [Ses::class, 'saveCompany']);
});

// ---------- エンジニア ----------
Route::middleware('role:eg')->group(function () {
    Route::get('/eg/consent', [Eg::class, 'consentForm']);
    Route::post('/eg/consent', [Eg::class, 'consent']);
    Route::get('/eg', [Eg::class, 'home']);
    Route::get('/eg/offers', [Eg::class, 'offers']);
    Route::get('/eg/applications', [Eg::class, 'applications']);
    Route::post('/cases/{id}/decline', [Cas::class, 'decline']);
    Route::post('/interviews/{id}/memo', [Pip::class, 'memo']);
    Route::get('/eg/profile', fn () => redirect('/engineers/'.auth()->user()->engineer_id));
    Route::post('/engineers/{id}/confirm', [Eng::class, 'confirm']);
    Route::get('/eg/sheet', fn () => redirect('/engineers/'.auth()->user()->engineer_id.'/sheet'));
    Route::get('/eg/messages', fn () => redirect('/engineers/'.auth()->user()->engineer_id.'/messages'));
});
