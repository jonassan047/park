<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// PARK の業務テーブル（SES・エンジニア・案件・配信・応募・面談・契約・請求・メール・記録）
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $t) {
            $t->string('key')->primary();
            $t->text('value')->nullable();
        });

        Schema::create('ses_companies', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('rep_name')->nullable();
            $t->string('contact_name')->nullable();
            $t->string('email')->nullable();
            $t->string('phone')->nullable();
            $t->string('address')->nullable();
            $t->integer('employees')->nullable();
            $t->string('strengths')->nullable();
            $t->text('message')->nullable();
            $t->string('status', 20)->default('pending'); // pending 申込中 / active 参加中 / suspended 停止
            $t->dateTime('agreed_at')->nullable();
            $t->date('joined_at')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
        });

        Schema::create('engineers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('ses_id')->constrained('ses_companies');
            $t->string('name');
            $t->string('kana')->nullable();
            $t->string('initials', 20)->nullable();
            $t->string('email')->nullable();
            $t->integer('birth_year')->nullable();
            $t->string('nearest_station')->nullable();
            $t->decimal('years_exp', 4, 1)->nullable();
            $t->text('summary')->nullable();
            $t->string('strengths')->nullable();
            $t->decimal('desired_price', 6, 1)->nullable(); // 万円／月
            $t->decimal('min_price', 6, 1)->nullable();
            $t->date('available_from')->nullable();
            $t->string('work_style', 10)->default('any');
            $t->integer('days_per_week')->default(5);
            $t->string('status', 20)->default('available'); // available 待機 / proposing 提案中 / working 稼働中 / unavailable 休止
            $t->dateTime('consent_at')->nullable();
            $t->boolean('is_operator')->default(false);
            $t->timestamps();
        });

        Schema::create('engineer_skills', function (Blueprint $t) {
            $t->id();
            $t->foreignId('engineer_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->string('category', 10)->default('other');
            $t->decimal('years', 4, 1)->nullable();
            $t->integer('level')->default(3);
        });

        Schema::create('engineer_projects', function (Blueprint $t) {
            $t->id();
            $t->foreignId('engineer_id')->constrained()->cascadeOnDelete();
            $t->string('title');
            $t->string('industry')->nullable();
            $t->string('period_from', 7)->nullable(); // YYYY-MM
            $t->string('period_to', 7)->nullable();
            $t->string('role')->nullable();
            $t->integer('team_size')->nullable();
            $t->string('tech', 500)->nullable();
            $t->string('phases')->nullable();
            $t->text('description')->nullable();
            $t->text('achievements')->nullable();
        });

        Schema::create('cases', function (Blueprint $t) {
            $t->id();
            $t->string('title');
            $t->string('client_label')->nullable();
            $t->foreignId('source_ses_id')->nullable()->constrained('ses_companies');
            $t->unsignedBigInteger('registered_by')->nullable();
            $t->decimal('price', 6, 1)->nullable();
            $t->string('price_note')->nullable();
            $t->text('required_skills')->nullable();
            $t->text('preferred_skills')->nullable();
            $t->text('description')->nullable();
            $t->string('location')->nullable();
            $t->string('work_style', 10)->default('any');
            $t->date('start_date')->nullable();
            $t->string('period')->nullable();
            $t->integer('days_per_week')->nullable();
            $t->integer('interviews_count')->nullable();
            $t->integer('headcount')->nullable();
            $t->decimal('min_years', 4, 1)->nullable();
            $t->text('conditions')->nullable();
            $t->date('deadline')->nullable();
            $t->string('status', 20)->default('open'); // draft 下書き / open 募集中 / filled 充足 / closed 終了
            $t->text('raw_text')->nullable();
            $t->timestamps();
        });

        Schema::create('case_offers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('case_id')->constrained('cases')->cascadeOnDelete();
            $t->foreignId('engineer_id')->constrained()->cascadeOnDelete();
            $t->integer('score')->nullable();
            $t->text('reasons')->nullable();
            $t->text('missing')->nullable();
            $t->unsignedBigInteger('sent_by')->nullable();
            $t->dateTime('sent_at');
            $t->dateTime('viewed_at')->nullable();
            $t->string('status', 20)->default('sent'); // sent 配信 / viewed 既読 / declined 見送り / applied 応募
            $t->string('decline_reason')->nullable();
            $t->unique(['case_id', 'engineer_id']);
        });

        Schema::create('applications', function (Blueprint $t) {
            $t->id();
            $t->foreignId('case_id')->constrained('cases')->cascadeOnDelete();
            $t->foreignId('engineer_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('ses_id');
            $t->string('status', 20)->default('applied');
            $t->decimal('proposed_price', 6, 1)->nullable();
            $t->boolean('start_ok')->default(false);
            $t->boolean('price_ok')->default(false);
            $t->boolean('style_ok')->default(false);
            $t->text('condition_note')->nullable();
            $t->string('reject_reason')->nullable();
            $t->text('reject_note')->nullable();
            $t->unsignedBigInteger('applied_by')->nullable();
            $t->dateTime('applied_at');
            $t->dateTime('updated_at')->nullable();
            $t->unique(['case_id', 'engineer_id']); // GP の中で同じ案件に二重に応募しない
        });

        Schema::create('application_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('application_id')->constrained()->cascadeOnDelete();
            $t->string('status', 20);
            $t->text('note')->nullable();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->dateTime('at');
        });

        Schema::create('interviews', function (Blueprint $t) {
            $t->id();
            $t->foreignId('application_id')->constrained()->cascadeOnDelete();
            $t->integer('round')->default(1);
            $t->dateTime('scheduled_at')->nullable();
            $t->string('place')->nullable();
            $t->string('result', 10)->default('pending');
            $t->text('feedback')->nullable();
            $t->longText('qa_json')->nullable();
            $t->text('eg_memo')->nullable();
            $t->dateTime('created_at');
        });

        Schema::create('contracts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('application_id')->nullable()->constrained();
            $t->foreignId('case_id')->constrained('cases');
            $t->foreignId('engineer_id')->constrained();
            $t->foreignId('ses_id')->constrained('ses_companies');
            $t->decimal('price', 6, 1);
            $t->date('start_date');
            $t->date('end_date')->nullable();
            $t->integer('extensions')->default(0);
            $t->string('status', 10)->default('active');
            $t->string('end_reason')->nullable();
            $t->dateTime('created_at');
        });

        Schema::create('follow_ups', function (Blueprint $t) {
            $t->id();
            $t->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $t->dateTime('at');
            $t->integer('mood')->nullable();
            $t->text('troubles')->nullable();
            $t->text('note')->nullable();
            $t->unsignedBigInteger('by_user')->nullable();
        });

        Schema::create('invoices', function (Blueprint $t) {
            $t->id();
            $t->foreignId('ses_id')->constrained('ses_companies');
            $t->string('period', 7);
            $t->longText('items_json');
            $t->integer('subtotal');
            $t->integer('tax');
            $t->integer('total');
            $t->string('status', 10)->default('draft'); // draft 下書き / issued 発行 / paid 入金済み
            $t->dateTime('issued_at')->nullable();
            $t->dateTime('paid_at')->nullable();
            $t->dateTime('created_at');
            $t->unique(['ses_id', 'period']);
        });

        Schema::create('messages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('engineer_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('from_user')->nullable();
            $t->text('body');
            $t->dateTime('created_at');
            $t->boolean('read_by_eg')->default(false);
            $t->boolean('read_by_admin')->default(false);
        });

        Schema::create('app_notifications', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('title');
            $t->text('body')->nullable();
            $t->string('link')->nullable();
            $t->dateTime('read_at')->nullable();
            $t->dateTime('created_at');
            $t->index(['user_id', 'read_at']);
        });

        Schema::create('mail_outbox', function (Blueprint $t) {
            $t->id();
            $t->string('to_email');
            $t->string('to_name')->nullable();
            $t->string('subject');
            $t->text('body');
            $t->string('kind', 30)->nullable();
            $t->string('status', 10)->default('queued'); // queued 送信待ち / sent 送信済み / failed 失敗 / saved 保存のみ
            $t->text('error')->nullable();
            $t->dateTime('created_at');
            $t->dateTime('sent_at')->nullable();
        });

        Schema::create('audit_log', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('action', 50);
            $t->text('detail')->nullable();
            $t->dateTime('at');
        });
    }

    public function down(): void
    {
        foreach (['audit_log', 'mail_outbox', 'app_notifications', 'messages', 'invoices', 'follow_ups', 'contracts', 'interviews',
            'application_events', 'applications', 'case_offers', 'cases', 'engineer_projects', 'engineer_skills', 'engineers', 'ses_companies', 'settings'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
