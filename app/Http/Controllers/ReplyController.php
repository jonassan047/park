<?php

namespace App\Http\Controllers;

use App\Services\CaseMail as CM;
use App\Services\Notifier as N;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// 案内メールの返事のリンク（ログインしなくてよい）。
// リンクを開いただけでは記録しない（メールソフトが先に開くことがあるため）。画面のボタンを押して記録する
class ReplyController extends Controller
{
    private function load(string $token)
    {
        $m = DB::table('case_mails as m')->join('cases as c', 'c.id', '=', 'm.case_id')->join('engineers as e', 'e.id', '=', 'm.engineer_id')
            ->where('m.token', $token)->select('m.*', 'c.title', 'c.status as case_status', 'e.name as eg_name', 'e.nickname', 'e.status as eg_status', 'e.suspended_at as eg_suspended_at')->first();
        abort_unless($m, 404);
        return $m;
    }

    // 停止中・退会済のエンジニアの返事は受け付けない（やさしい文面の画面を返す）
    private function refused(object $m)
    {
        if ($m->eg_status !== 'left' && !$m->eg_suspended_at) return null;
        return view('public.message', ['title' => 'ご返事を受け付けられません', 'text' => 'このご案内への返事は、いま受け付けていません。お手数ですが、案内を送った担当者に直接ご連絡ください。']);
    }

    public function form(string $token, string $answer)
    {
        $m = $this->load($token);
        abort_unless(isset(CM::REPLIES[$answer]) && $answer !== 'none', 404);
        if ($v = $this->refused($m)) return $v;
        return view('public.reply', ['m' => $m, 'answer' => $answer, 'token' => $token]);
    }

    public function save(Request $r, string $token)
    {
        $m = $this->load($token);
        $answer = (string) $r->input('answer');
        abort_unless(isset(CM::REPLIES[$answer]) && $answer !== 'none', 404);
        if ($v = $this->refused($m)) return $v;
        $note = str_in($r->input('note'));
        CM::reply($m->case_id, $m->engineer_id, $answer, $note, 'link');
        if ($answer === 'declined') DB::table('case_offers')->where('case_id', $m->case_id)->where('engineer_id', $m->engineer_id)->update(['status' => 'declined', 'decline_reason' => $note ?: '見送り']);
        $label = CM::REPLIES[$answer];
        $who = $m->route === 'own' ? $m->eg_name : 'グループのエンジニア';
        // 案内メールは SES 担当者か管理者の立場で送るもの。兼任の人には、その立場あてのお知らせにする
        $senderRole = $m->sent_by ? DB::table('users')->where('id', $m->sent_by)->value('role') : null;
        if ($m->sent_by) N::notify((int) $m->sent_by, "{$who} さんから返事：{$label}（{$m->title}）", $note ?: '', "/cases/{$m->case_id}#mails", true, 'reply', in_array($senderRole, ['admin', 'ses'], true) ? $senderRole : null);
        N::audit(null, 'case-mail-reply', "#{$m->id} {$answer}（リンク）");
        return view('public.message', ['title' => 'ご返事を受け付けました', 'text' => "「{$m->title}」に「{$label}」とお伝えしました。担当者から追ってご連絡します。"]);
    }
}
