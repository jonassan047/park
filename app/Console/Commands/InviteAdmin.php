<?php

namespace App\Console\Commands;

use App\Services\Workflow;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

// 公開サーバーで、最初の運営アカウントを作る（招待のリンクを出す）。
// 画面の「はじめの設定」は、公開中だとほかの人に先に使われるおそれがあるので、サーバーの中からこちらを使う。
// 使い方：php artisan park:invite-admin メールアドレス 名前
class InviteAdmin extends Command
{
    protected $signature = 'park:invite-admin {email} {name}';
    protected $description = '運営のアカウントを作り、パスワード設定用のリンク（7日間有効）を表示する';

    public function handle(): int
    {
        $email = strtolower($this->argument('email'));
        if (DB::table('users')->where('email', $email)->whereNotNull('password')->exists()) {
            $this->error('このメールアドレスは、すでに使われています。');
            return 1;
        }
        if (!DB::table('users')->where('email', $email)->exists()) {
            DB::table('users')->insert(['email' => $email, 'name' => $this->argument('name'), 'role' => 'admin', 'created_at' => now_str(), 'updated_at' => now_str()]);
        }
        $id = DB::table('users')->where('email', $email)->value('id');
        $token = Workflow::issueToken($id);
        $this->info('次のリンクを開いて、パスワードと2要素認証を設定してください（7日間有効）：');
        $this->line(url("/invite/$token"));
        return 0;
    }
}
