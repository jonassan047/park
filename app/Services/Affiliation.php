<?php

namespace App\Services;

use App\Services\Notifier as N;
use App\Services\Workflow as W;
use App\Support\Perms;
use Illuminate\Support\Facades\DB;

// エンジニアの所属 SES を変える（v0.8.1）。
//   管理者        → どの会社のエンジニアも、参加中の SES か直属へ
//   SES・担当者   → 自社のエンジニアだけを、ほかの参加中の SES へ（直属へは移せない）
//   エンジニア本人 → 変えられない
// 付け替えるのは、engineers.ses_id と本人のアカウント（role=eg）の users.ses_id だけ。
// 応募・契約・請求・案内メールなどの記録は、そのときの会社の事実なので書き換えない。
// 変えられない条件（blocker）は1か所。画面の出し分けも、送信も、ここを使う。
class Affiliation
{
    // 選考中の応募の状態（エンジニアの詳細の「選考中」の数え方と同じ）
    const IN_SELECTION = ['applied', 'submitted', 'doc_passed', 'interview', 'interviewed'];
    const KIND = 'engineer-move'; // お知らせ・記録の種類

    // 変更先の候補 [会社の id => 名前]。管理者は直属を先頭に、参加中の SES を名前順。SES・担当者は自社を除く参加中の SES を名前順
    public static function targets($u, object $e): array
    {
        if (!$u || !in_array($u->role, ['admin', 'ses'], true)) return [];
        $out = [];
        if ($u->role === 'admin') {
            \App\Services\Workflow::directSesId(); // 直属の会社の行がまだ無い DB でも、選べるように作っておく
            foreach (DB::table('ses_companies')->where('kind', 'direct')->orderBy('id')->get(['id', 'name']) as $s) $out[(int) $s->id] = (string) $s->name;
        }
        foreach (DB::table('ses_companies')->where('kind', 'ses')->where('status', 'active')->orderBy('name')->get(['id', 'name']) as $s) $out[(int) $s->id] = (string) $s->name;
        unset($out[(int) $e->ses_id]);
        return $out;
    }

    // 変えられない理由の文（変えられるなら null）。仮登録中の文だけは、立場で変わる
    public static function blocker($u, object $e): ?string
    {
        $isAdmin = $u && $u->role === 'admin';
        if ($e->status === 'left') return '退会済のエンジニアは、所属を変えられません。';
        // 仮登録中＝本登録が済んでいないアカウント（role=eg）がある人。ログインの無い古い行は対象にしない
        if (DB::table('users')->where('engineer_id', $e->id)->where('role', 'eg')->whereNull('registered_at')->exists()) {
            return $isAdmin
                ? '仮登録中のため、ここでは変えられません。「仮登録の内容を直す」（氏名・メール・所属）から変えてください。'
                : '仮登録中のため変えられません。所属を変えるときは、管理者にご依頼ください。';
        }
        if (!empty($e->suspended_at) && !$isAdmin) return '停止中のエンジニアの所属は、管理者だけが変えられます。';
        if (DB::table('users')->where('engineer_id', $e->id)->whereIn('role', ['ses', 'admin'])->exists()) {
            return 'この方は SES 担当者（または管理者）を兼ねているため、所属を変えられません。管理者にご相談ください。';
        }
        if (DB::table('contracts')->where('engineer_id', $e->id)->where('status', 'active')->exists()) {
            return '稼働中の契約があるため、所属を変えられません。契約が終わってから変えてください。';
        }
        $n = DB::table('applications')->where('engineer_id', $e->id)->whereIn('status', self::IN_SELECTION)->count();
        if ($n > 0) return "選考中の応募が {$n} 件あるため、所属を変えられません。選考が終わってから変えてください。";
        return null;
    }

    // 所属を変える。戻り値：['ok' => 文, 'to' => 戻り先]／['bad' => 文]／['forbidden' => true]
    public static function move($u, int $id, $to): array
    {
        if (!$u || !in_array($u->role, ['admin', 'ses'], true)) return ['forbidden' => true];
        if (!is_string($to) && !is_int($to)) return ['bad' => '変更先を選んでください。'];
        $to = trim((string) $to);
        if ($to === '') return ['bad' => '変更先を選んでください。'];
        if (!ctype_digit($to)) return ['bad' => '選べない会社です。'];
        $toId = (int) $to;

        $res = DB::transaction(function () use ($u, $id, $toId) {
            // 開いている間に状態が変わっても安全なように、ここで読み直して、検査をやり直す
            $e = DB::table('engineers')->where('id', $id)->lockForUpdate()->first();
            if (!$e) return ['forbidden' => true];
            if (!Perms::engineer($u, $e)) return ['forbidden' => true];
            $names = DB::table('ses_companies')->whereIn('id', [(int) $e->ses_id, $toId])->pluck('name', 'id');
            $from = (int) $e->ses_id;
            if ($toId === $from) return ['bad' => 'すでに「'.($names[$from] ?? '').'」の所属です。'];
            if ($msg = self::blocker($u, $e)) return ['bad' => $msg];
            if (!isset(self::targets($u, $e)[$toId])) return ['bad' => '選べない会社です。'];

            $now = now_str();
            DB::table('engineers')->where('id', $id)->update(['ses_id' => $toId, 'updated_at' => $now]);
            DB::table('users')->where('engineer_id', $id)->where('role', 'eg')->update(['ses_id' => $toId, 'updated_at' => $now]);
            $fromName = (string) ($names[$from] ?? '');
            $toName = (string) ($names[$toId] ?? '');
            N::audit($u->id, self::KIND, W::selfMark($u, $id)."エンジニア #{$id}：{$fromName} → {$toName}");
            return ['done' => true, 'from' => $from, 'toId' => $toId, 'fromName' => $fromName, 'toName' => $toName, 'name' => (string) $e->name];
        });
        if (!isset($res['done'])) return $res;

        // お知らせ（トランザクションの後）
        [$from, $toId, $fromName, $toName, $name] = [$res['from'], $res['toId'], $res['fromName'], $res['toName'], $res['name']];
        $title = "{$name} さんの所属が変わりました";
        $body = "{$name} さんの所属が「{$fromName}」から「{$toName}」に変わりました。";
        N::toSes($from, $title, $body, W::isDirect($from) ? "/engineers/{$id}" : '/ses/engineers', false, self::KIND);
        N::toSes($toId, $title, $body, "/engineers/{$id}", false, self::KIND);
        if ($u->role === 'ses') N::toAdmins($title, $body, "/engineers/{$id}", false, self::KIND);
        N::toEg($id, '所属の会社が変わりました', "あなたの所属の会社が「{$fromName}」から「{$toName}」に変わりました。分からないことは、{$toName} のご担当者にお問い合わせください。", '/eg', true, self::KIND);

        return $u->role === 'admin'
            ? ['ok' => "所属を「{$toName}」に変えました。", 'to' => "/engineers/{$id}"]
            : ['ok' => "{$name} さんの所属を「{$toName}」に変えました。これからは {$toName} が扱います。", 'to' => '/ses/engineers'];
    }
}
