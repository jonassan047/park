<?php

namespace App\Console\Commands;

use App\Services\Registration;
use App\Services\Workflow;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

// 公開サーバーで、最初の管理者を仮登録して、本登録のリンクを出す。
// 画面の「はじめの設定」は、公開中だとほかの人に先に使われるおそれがあるので、サーバーの中からこちらを使う。
// 作った人は、ほかの管理者が仮登録した人と同じ本登録の画面（企業名・お名前・電話・パスワード）を通る。メールは送らない（リンクを出すだけ）。
// 使い方：php artisan park:invite-admin メールアドレス 姓 名 [--company=企業名]
class InviteAdmin extends Command
{
    protected $signature = 'park:invite-admin {email} {sei} {mei} {--company= : 企業名（本登録の画面に入れておく。ご本人が確かめて直せる）}';
    protected $description = '管理者を仮登録し、本登録用のリンク（7日間有効）を表示する';

    public function handle(): int
    {
        $email = strtolower(trim((string) $this->argument('email')));
        $sei = clean_name((string) $this->argument('sei'));
        $mei = clean_name((string) $this->argument('mei'));
        $company = clean_name((string) $this->option('company'));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return $this->refuse('メールアドレスを正しく入れてください。');
        if ($sei === null || $mei === null) return $this->refuse('お名前は、姓と名の両方を入れてください。');
        if (mb_strlen($sei) > 50 || mb_strlen($mei) > 50) return $this->refuse('お名前は、姓・名とも50文字までです。');
        if ($company !== null && mb_strlen($company) > 100) return $this->refuse('企業名は100文字までです。');

        $u = DB::table('users')->where('email', $email)->first();
        if ($u) {
            if ($u->left_at) return $this->refuse('このメールアドレスは、退会済の方が使っていたため登録できません。');
            if ($u->registered_at || $u->password) return $this->refuse('このメールアドレスは、すでに使われています。');
            if ($u->role !== 'admin') return $this->refuse('このメールアドレスは、管理者ではない立場の仮登録に使われています。');
            if (!$u->active) return $this->refuse('このメールアドレスは、停止中の方が使っています。');
            // 仮登録中の管理者：お名前（と、あれば企業名）を入れ直して、リンクを新しくする
            $id = (int) $u->id;
            DB::table('users')->where('id', $id)->update(Workflow::nameCols(join_name($sei, $mei), $sei, $mei) + ($company !== null ? ['company_name' => $company] : []) + ['updated_at' => now_str()]);
        } else {
            $id = (int) DB::table('users')->insertGetId(['email' => $email] + Workflow::nameCols(join_name($sei, $mei), $sei, $mei)
                + ['role' => 'admin', 'company_name' => $company, 'created_at' => now_str(), 'updated_at' => now_str()] + Workflow::roleCodes('admin'));
        }
        $token = Registration::issueToken($id);
        DB::table('users')->where('id', $id)->update(['reg_declined_at' => null]);
        $this->info('次のリンクを開いて、本登録をしてください（7日間有効）：');
        $this->line(url("/invite/$token"));
        return 0;
    }

    private function refuse(string $message): int
    {
        $this->error($message);
        return 1;
    }
}
