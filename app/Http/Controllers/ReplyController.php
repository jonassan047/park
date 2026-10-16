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
            ->where('m.token', $token)->select('m.*', 'c.title', 'c.status as case_status', 'e.name as eg_name', 'e.nickname')->first();
        abort_unless($m, 404);
        return $m;
    }

    public function form(string $token, string $answer)
    {
        $m = $this->load($token);
        abort_unless(isset(CM::REPLIES[$answer]) && $answer !== 'none', 404);
        return view('public.reply', ['m' => $m, 'answer' => $answer, 'token' => $token]);
    }

    public function save(Request $r, string $token)
    {
        $m = $this->load($token);
        $answer = (string) $r->input('answer');
        abort_unless(isset(CM::REPLIES[$answer]) && $answer !== 'none', 404);
        $note = str_in($r->input('note'));
        CM::reply($m->case_id, $m->engineer_id, $answer, $note, 'link');
        if ($answer === 'declined') DB::table('case_offers')->where('case_id', $m->case_id)->where('engineer_id', $m->engineer_id)->update(['status' => 'declined', 'decline_reason' => $note ?: '見送り']);
        $label = CM::REPLIES[$answer];
        $who = $m->route === 'own' ? $m->eg_name : 'グループのエンジニア';
        if ($m->sent_by) N::notify((int) $m->sent_by, "{$who} さんから返事：{$label}（{$m->title}）", $note ?: '', "/cases/{$m->case_id}#mails", true, 'reply');
        N::audit(null, 'case-mail-reply', "#{$m->id} {$answer}（リンク）");
        return view('public.message', ['title' => 'ご返事を受け付けました', 'text' => "「{$m->title}」に「{$label}」とお伝えしました。担当者から追ってご連絡します。"]);
    }
}
