<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

// ホームのいちばん上の「今やること」。上から片付ければ、仕事が終わるように並べる。
// 1件は ['text' => 何のことか, 'do' => 何をするか, 'url' => 押すと開く画面]
class Todo
{
    public static function for($u): array
    {
        return match ($u->role) {
            'admin' => self::admin(),
            'ses' => self::ses((int) $u->ses_id),
            'eg' => self::eg((int) $u->engineer_id),
            default => [],
        };
    }

    private static function item(string $text, string $do, string $url): array
    {
        return compact('text', 'do', 'url');
    }

    // 応募のうち、次の手を打つ必要があるもの（SES・運営で共通）
    private static function pipeline($q): array
    {
        $out = [];
        $weekAgo = date('Y-m-d H:i:s', strtotime('-3 days'));
        foreach ((clone $q)->where('a.status', 'submitted')->where('a.updated_at', '<', $weekAgo)->get() as $a) {
            $out[] = self::item("{$a->eg_name} さん「{$a->title}」：書類を返して3日たちました", '先方の返事を確かめる（面談依頼が来たら記録）', "/applications/{$a->id}");
        }
        $now = now_str();
        $ivs = DB::table('interviews as iv')->joinSub((clone $q)->select('a.id', 'c.title', 'e.name as eg_name'), 'x', 'x.id', '=', 'iv.application_id')
            ->where('iv.result', 'pending')->whereNotNull('iv.scheduled_at')->where('iv.scheduled_at', '<', $now)->select('iv.id', 'x.title', 'x.eg_name')->get();
        foreach ($ivs as $iv) $out[] = self::item("{$iv->eg_name} さん「{$iv->title}」：面談が終わりました", '結果を記録する', "/interviews/{$iv->id}");
        return $out;
    }

    private static function apps()
    {
        return DB::table('applications as a')->join('cases as c', 'c.id', '=', 'a.case_id')->join('engineers as e', 'e.id', '=', 'a.engineer_id')
            ->select('a.*', 'c.title', 'e.name as eg_name');
    }

    public static function ses(int $sesId): array
    {
        $out = [];
        $s = DB::table('ses_companies')->find($sesId);
        if ($s && !$s->self_confirmed_at) $out[] = self::item('会社の情報', '中身を確かめて「確認しました」を押す', '/ses/company');
        // 案内メールの返事
        $mails = DB::table('case_mails as m')->join('cases as c', 'c.id', '=', 'm.case_id')->join('engineers as e', 'e.id', '=', 'm.engineer_id')
            ->where('c.source_ses_id', $sesId)->where('c.status', 'open')->select('m.*', 'c.title', 'e.name', 'e.initials')->get();
        $applied = DB::table('applications')->whereIn('case_id', $mails->pluck('case_id')->unique()->all())->get(['case_id', 'engineer_id'])
            ->map(fn ($a) => $a->case_id.'-'.$a->engineer_id)->all();
        foreach ($mails as $m) {
            if (in_array($m->case_id.'-'.$m->engineer_id, $applied)) continue;
            $who = $m->route === 'own' ? $m->name : ($m->initials ?: 'グループの人');
            if ($m->reply === 'interested') $out[] = self::item("{$who} さんが「応募したい」：{$m->title}", '書類を添付して先方に返し、「書類を返した」を押す', "/cases/{$m->case_id}#mails");
            if ($m->reply === 'question') $out[] = self::item("{$who} さんから質問：{$m->title}", ($m->reply_note ?: '内容を確かめて').'。答えて、返事を記録する', "/cases/{$m->case_id}#mails");
        }
        // 自社で持ち込んで、まだ誰にも案内していない案件
        $cases = DB::table('cases as c')->where('c.source_ses_id', $sesId)->where('c.status', 'open')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('case_mails')->whereColumn('case_mails.case_id', 'c.id'))->get();
        foreach ($cases as $c) $out[] = self::item("新しい案件「{$c->title}」", 'エンジニアに案内する', "/cases/{$c->id}/mail");
        $out = array_merge($out, self::pipeline(self::apps()->where('a.ses_id', $sesId)));
        foreach (DB::table('engineers')->where('ses_id', $sesId)->whereNull('self_confirmed_at')->get() as $e) {
            $out[] = self::item("{$e->name} さんの本人の確認がまだ", 'ご本人に声をかける（確認するまで応募できません）', "/engineers/{$e->id}");
        }
        return $out;
    }

    public static function eg(int $engineerId): array
    {
        $out = [];
        $e = DB::table('engineers')->find($engineerId);
        if (!$e) return [];
        if (!$e->self_confirmed_at) $out[] = self::item('登録の内容', '中身を確かめて「確認しました」を押す（済むまで応募できません）', '/eg#confirm');
        $offers = DB::table('case_offers as o')->join('cases as c', 'c.id', '=', 'o.case_id')->where('o.engineer_id', $engineerId)
            ->whereIn('o.status', ['sent', 'viewed'])->where('c.status', 'open')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('applications as a')->whereColumn('a.case_id', 'o.case_id')->where('a.engineer_id', $engineerId))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('case_mails as m')->whereColumn('m.case_id', 'o.case_id')->where('m.engineer_id', $engineerId)->where('m.reply', '!=', 'none'))
            ->select('o.case_id', 'c.title')->get();
        foreach ($offers as $o) $out[] = self::item("案件のご案内「{$o->title}」", '中身を見て、応募するか見送るか返事をする', "/cases/{$o->case_id}");
        $ivs = DB::table('interviews as iv')->join('applications as a', 'a.id', '=', 'iv.application_id')->join('cases as c', 'c.id', '=', 'a.case_id')
            ->where('a.engineer_id', $engineerId)->where('iv.result', 'pending')->where(fn ($q) => $q->whereNull('iv.scheduled_at')->orWhere('iv.scheduled_at', '>=', now_str()))
            ->select('iv.id', 'iv.scheduled_at', 'c.title')->get();
        foreach ($ivs as $iv) $out[] = self::item("面談「{$iv->title}」".($iv->scheduled_at ? '（'.fmt_dt($iv->scheduled_at).'）' : ''), '面談スクリプトを読んで、答えを練習する', "/interviews/{$iv->id}");
        foreach (Workflow::historyTodo($engineerId) as $k) $out[] = self::item("経歴の更新「{$k->title}」", $k->status === 'ended' ? '終わった案件の経歴を書く' : '経験票に追加する', "/engineers/{$engineerId}/projects/new?contract={$k->id}");
        return $out;
    }

    public static function admin(): array
    {
        $out = [];
        foreach (DB::table('ses_companies')->where('status', 'pending')->get() as $s) $out[] = self::item("参加の申し込み：{$s->name}", '内容を確かめて承認する', "/admin/ses/{$s->id}");
        $out = array_merge($out, self::pipeline(self::apps()));
        $passed = self::apps()->where('a.status', 'interviewed')->where('a.updated_at', '<', date('Y-m-d H:i:s', strtotime('-14 days')))->get();
        foreach ($passed as $a) $out[] = self::item("{$a->eg_name} さん「{$a->title}」：面談を通って2週間", '成約か、次の面談か、見送りかを SES に確かめる', "/applications/{$a->id}");
        $n = DB::table('messages')->where('read_by_admin', false)->distinct()->count('engineer_id');
        if ($n) $out[] = self::item("エンジニアからのメッセージ（{$n}人）", '読んで返事をする', '/admin/messages');
        $n = DB::table('skill_terms')->where('reviewed', false)->count();
        if ($n) $out[] = self::item("技術マスタ：自動で足された技術（{$n}件）", '分類を確かめる', '/admin/skills');
        $n = DB::table('ai_jobs')->where('status', 'done')->count();
        if ($n) $out[] = self::item("AI の下書きができています（{$n}件）", '中身を確かめて、採用するか捨てる', '/admin/ai');
        return $out;
    }
}
