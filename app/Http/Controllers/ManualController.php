<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use League\CommonMark\Extension\Table\TableExtension;

// マニュアル（正本は resources/manuals/*.md）。SES・エンジニア向けはログイン前でも読める。管理者向けは管理者だけ
// 操作手順書（*-steps）：ログイン・設定・メールの処理だけを、立場ごとに順に書いたもの
class ManualController extends Controller
{
    const KINDS = [
        'admin-steps' => '管理者 操作手順書', 'admin' => '管理者マニュアル',
        'ses-steps' => 'SES 企業さま 操作手順書', 'ses' => 'SES 企業さま向けマニュアル',
        'engineer-steps' => 'エンジニア 操作手順書', 'engineer' => 'エンジニア向けマニュアル',
    ];

    public function index(Request $r)
    {
        $u = $r->user();
        $role = $u ? ['admin' => 'admin', 'ses' => 'ses', 'eg' => 'engineer'][$u->role] ?? 'engineer' : null;
        // 管理者はすべて。SES・エンジニアは自分の立場の2つ。ログイン前は管理者向け以外
        $kinds = array_filter(self::KINDS, fn ($k) => $role === 'admin' || ($role ? str_starts_with($k, $role) : !str_starts_with($k, 'admin')), ARRAY_FILTER_USE_KEY);
        return view('manual.index', ['kinds' => $kinds]);
    }

    public function show(Request $r, string $kind)
    {
        abort_unless(isset(self::KINDS[$kind]), 404);
        // 管理者向けは、2要素認証まで済ませた管理者だけ
        if (str_starts_with($kind, 'admin')) abort_unless($r->user() && $r->user()->role === 'admin' && (int) $r->session()->get(\App\Support\TwoFactor::SESSION_KEY) === (int) $r->user()->id, 403);
        $md = file_get_contents(resource_path("manuals/$kind.md"));
        // 見出しに飛べるよう id を付け、目次を作る
        $html = Str::markdown($md, ['html_input' => 'escape', 'allow_unsafe_links' => false], [new TableExtension()]);
        $toc = [];
        $html = preg_replace_callback('/<h2>(.*?)<\/h2>/u', function ($m) use (&$toc) {
            $id = 's'.(count($toc) + 1);
            $toc[] = [$id, strip_tags($m[1])];
            return "<h2 id=\"$id\">{$m[1]}</h2>";
        }, $html);
        $html = preg_replace('/^<h1>.*?<\/h1>/u', '', $html);
        return view('manual.show', ['title' => self::KINDS[$kind], 'html' => $html, 'toc' => $toc, 'kind' => $kind]);
    }
}
