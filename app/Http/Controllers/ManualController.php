<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use League\CommonMark\Extension\Table\TableExtension;

// マニュアル（正本は resources/manuals/*.md）。SES・エンジニア向けはログイン前でも読める。運営向けは運営だけ
class ManualController extends Controller
{
    const KINDS = ['admin' => '運営マニュアル', 'ses' => 'SES 企業さま向けマニュアル', 'engineer' => 'エンジニア向けマニュアル'];

    public function index(Request $r)
    {
        $u = $r->user();
        if ($u && $u->role !== 'admin') return redirect('/manual/'.($u->role === 'ses' ? 'ses' : 'engineer'));
        return view('manual.index', ['kinds' => $u ? self::KINDS : array_slice(self::KINDS, 1, null, true)]);
    }

    public function show(Request $r, string $kind)
    {
        abort_unless(isset(self::KINDS[$kind]), 404);
        // 運営向けは、2要素認証まで済ませた運営だけ
        if ($kind === 'admin') abort_unless($r->user() && $r->user()->role === 'admin' && (int) $r->session()->get(\App\Support\TwoFactor::SESSION_KEY) === (int) $r->user()->id, 403);
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
