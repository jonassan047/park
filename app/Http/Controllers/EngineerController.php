<?php

namespace App\Http\Controllers;

use App\Services\EngineerDocs;
use App\Services\Matcher;
use App\Services\Notifier as N;
use App\Services\Qa;
use App\Services\Workflow as W;
use App\Support\Perms;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

// エンジニア：登録・プロフィール・スキル・経験票・スキルシート・管理者とのやり取り
class EngineerController extends Controller
{
    private function load(Request $r, int $id)
    {
        $e = DB::table('engineers as e')->join('ses_companies as s', 's.id', '=', 'e.ses_id')->where('e.id', $id)->select('e.*', 's.name as ses_name')->first();
        abort_unless($e, 404);
        abort_unless(Perms::engineer($r->user(), $e), 403);
        return $e;
    }

    public function create(Request $r)
    {
        // 管理者は、SES の画面で SES を選んでから登録する（管理者の直属は、新しくは登録できない）
        $sesFixed = null;
        $sesId = null;
        if ($r->user()->role === 'admin') {
            $ses = (string) $r->query('ses');
            if (ctype_digit($ses) && ($row = DB::table('ses_companies')->where('id', (int) $ses)->where('kind', 'ses')->where('status', 'active')->first())) {
                $sesFixed = ['id' => (string) $row->id, 'name' => $row->name];
            } else {
                return redirect('/admin/ses')->with('flash', ['msg' => 'エンジニアは、SES の画面で SES を選んでから登録します。', 'type' => 'warn']);
            }
            $sesId = $sesFixed['id'];
        }
        return view('engineers.form', ['e' => (object) ['ses_id' => $sesId], 'action' => '/engineers', 'isNew' => true, 'sesFixed' => $sesFixed]);
    }

    // 氏名・ふりがなの姓・名を整える（前後の空白は全角も取る）。姓・名の欄が無く name だけ来たら、空白で分ける。
    // 戻り値は [整えた入力, 姓・名の欄で送られたか, 長さのエラー（なければ null）]
    private function cleanNames(array $body): array
    {
        $split = !array_key_exists('name_sei', $body) && !array_key_exists('name_mei', $body);
        foreach (['name' => 'name', 'kana' => 'kana'] as $full => $base) {
            if (!array_key_exists($base.'_sei', $body) && !array_key_exists($base.'_mei', $body)) {
                if (!array_key_exists($full, $body)) continue;
                [$body[$base.'_sei'], $body[$base.'_mei']] = split_name(is_string($body[$full]) ? $body[$full] : null);
            }
            $body[$base.'_sei'] = clean_name($body[$base.'_sei'] ?? null);
            $body[$base.'_mei'] = clean_name($body[$base.'_mei'] ?? null);
            $body[$full] = join_name($body[$base.'_sei'], $body[$base.'_mei']);
        }
        $label = ['name_sei' => '氏名（姓）', 'name_mei' => '氏名（名）', 'kana_sei' => 'ふりがな（姓）', 'kana_mei' => 'ふりがな（名）'];
        $err = null;
        foreach ($label as $k => $t) if (mb_strlen((string) ($body[$k] ?? '')) > 50) { $err = "{$t}は50文字までにしてください。"; break; }
        return [$body, !$split, $err];
    }

    public function store(Request $r)
    {
        [$body, $byParts, $lenErr] = $this->cleanNames($r->except('_token', 'ses_id'));
        $hasName = $byParts ? ($body['name_sei'] ?? null) && ($body['name_mei'] ?? null) : ($body['name'] ?? null);
        if ($r->user()->role === 'ses') $sesId = $r->user()->ses_id;
        else {
            // 管理者が選べるのは、参加中の SES だけ（'direct' など数字でない値は 0 になり、はじかれる）
            $sesId = (int) $r->input('ses_id');
            if (!DB::table('ses_companies')->where('id', $sesId)->where('kind', 'ses')->where('status', 'active')->exists()) $sesId = 0;
        }
        if (!$sesId || !$hasName) return back()->withInput()->with('flash', ['msg' => '所属と氏名を入れてください。', 'type' => 'bad']);
        if ($lenErr) return back()->withInput()->with('flash', ['msg' => $lenErr, 'type' => 'bad']);
        // 新しく登録するときは、メールアドレスが必須（ご本人への招待メールが届かないとログインできないため）
        $email = strtolower((string) str_in($r->input('email')));
        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) return back()->withInput()->with('flash', ['msg' => 'メールアドレスを正しく入れてください。', 'type' => 'bad']);
        // すでに使われているアドレスでも、同じ SES の担当者（管理者なら管理者）のアカウントなら、エンジニアの役割を足せる（兼任）
        if (DB::table('users')->where('email', $email)->exists() && !W::egShareTarget($email, $sesId, $r->user())) return back()->withInput()->with('flash', ['msg' => 'このメールアドレスはすでに使われています。', 'type' => 'bad']);
        $ad = W::addressCols($body, '現住所');
        if (isset($ad['bad'])) return back()->withInput()->with('flash', ['msg' => $ad['bad'], 'type' => 'bad']);
        $id = W::createEngineer($sesId, $ad['ok'] + $body, $r->user(), $r->user()->role === 'ses', $linked);
        if ($linked) {
            return $this->go("/engineers/$id", (int) $linked->id === (int) $r->user()->id
                ? 'ご自身をエンジニアとして登録しました。エンジニアの画面は、ログアウトして入り直し、「エンジニア」を選ぶと開けます。'
                : '登録しました。このメールアドレスの方のアカウントに、エンジニアの役割を足しました（招待メールは送っていません）。');
        }
        return $this->go("/engineers/$id", $r->user()->role === 'ses' ? '登録し、ご本人に招待メールを送りました。' : '登録しました。招待メールは、所属の SES から送ってもらいます。');
    }

    public function show(Request $r, int $id)
    {
        $e = $this->load($r, $id);
        return view('engineers.show', [
            'e' => $e,
            'skills' => DB::table('engineer_skills')->where('engineer_id', $id)->orderBy('category')->orderByDesc('years')->get(),
            'projects' => DB::table('engineer_projects')->where('engineer_id', $id)->orderByDesc('period_from')->get(),
            'check' => Qa::sheetCheck($id),
            'apps' => DB::table('applications as a')->join('cases as c', 'c.id', '=', 'a.case_id')->where('a.engineer_id', $id)->select('a.*', 'c.title')->orderByDesc('a.id')->get(),
            // ログインの欄：兼任の人も engineer_id で探す（role は問わない）。本人の行（role=eg）があればそれを優先
            'eu' => DB::table('users')->where('engineer_id', $id)->orderByRaw("case when role = 'eg' then 0 else 1 end")->first(),
            // 停止の確認の文の材料：稼働中の契約の有無と、選考中の応募の数
            'working' => DB::table('contracts')->where('engineer_id', $id)->where('status', 'active')->exists(),
            'inSelection' => DB::table('applications')->where('engineer_id', $id)->whereIn('status', ['applied', 'submitted', 'doc_passed', 'interview', 'interviewed'])->count(),
            'hist' => DB::table('engineer_histories')->where('engineer_id', $id)->orderBy('sort')->get(),
            'certs' => DB::table('engineer_certs')->where('engineer_id', $id)->orderBy('sort')->get(),
            'files' => DB::table('engineer_files')->where('engineer_id', $id)->orderByDesc('id')->get(),
        ]);
    }

    public function edit(Request $r, int $id)
    {
        return view('engineers.form', ['e' => $this->load($r, $id), 'action' => "/engineers/$id", 'isNew' => false, 'sesFixed' => null]);
    }

    public function update(Request $r, int $id)
    {
        $e = $this->load($r, $id);
        $in = $r->except('_token', 'ses_id');
        // 姓・名の片方だけ送られたときは、もう片方は今の値のまま
        foreach (['name', 'kana'] as $base) {
            foreach (['sei' => 'mei', 'mei' => 'sei'] as $a => $b) {
                if (array_key_exists("{$base}_$a", $in) && !array_key_exists("{$base}_$b", $in)) $in["{$base}_$b"] = $e->{"{$base}_$b"};
            }
        }
        [$body, , $lenErr] = $this->cleanNames($in);
        // 姓は必須、名は空でもよい（名のない人もいるため）
        if (array_key_exists('name_sei', $body) && empty($body['name_sei'])) return back()->withInput()->with('flash', ['msg' => '氏名（姓）を入れてください。', 'type' => 'bad']);
        if ($lenErr) return back()->withInput()->with('flash', ['msg' => $lenErr, 'type' => 'bad']);
        if ($r->user()->role === 'eg') {
            unset($body['email']);
            if (!in_array($body['status'] ?? '', ['available', 'unavailable'])) unset($body['status']);
        } else {
            // 退会は詳細の「退会」でだけ行う。退会済の人の状態は、ここでは変えられない
            if ($e->status === 'left') unset($body['status']);
            elseif (($body['status'] ?? '') === 'left') return back()->withInput()->with('flash', ['msg' => '状態を「退会済」にはできません。退会は、詳細の「退会」を使ってください。', 'type' => 'bad']);
            $email = strtolower((string) str_in($body['email'] ?? ''));
            // すでに使われているアドレスでも、同じ SES の担当者（管理者なら管理者）のアカウントで、このエンジニアにまだログインが無ければ通す（兼任。ここでは紐づけない）
            // 通すのは SES 担当者のアドレスだけ（管理者のアドレスは、実行者が誰でも、ここでは紐づけない）
            $share = W::egShareTarget($email, (int) $e->ses_id, $r->user());
            if ($email && $email !== ($e->email ?? '') && DB::table('users')->where('email', $email)->exists()
                && !($share && $share->role === 'ses' && !DB::table('users')->where('engineer_id', $id)->exists())) {
                return back()->with('flash', ['msg' => 'このメールアドレスはすでに使われています。', 'type' => 'bad']);
            }
        }
        // 郵便番号・住所は、この画面からは送られないが、手で送られても検査してそろえる
        $ad = W::addressCols($body, '現住所');
        if (isset($ad['bad'])) return back()->withInput()->with('flash', ['msg' => $ad['bad'], 'type' => 'bad']);
        W::updateEngineer($id, $ad['ok'] + $body, $r->user());
        return $this->go("/engineers/$id", '保存しました。');
    }

    // 本人が、登録の内容（管理者・SES が入れたものも含む）を確かめ、責任を持つ
    public function confirm(Request $r, int $id)
    {
        abort_unless($r->user()->role === 'eg' && (int) $r->user()->engineer_id === $id, 403);
        W::confirmEngineer($id, $r->user());
        return $this->go('/eg', '登録の内容を確認しました。内容が変わったら、ご自身で直してください。');
    }

    public function invite(Request $r, int $id)
    {
        $e = $this->load($r, $id);
        if ($r->user()->role !== 'ses') return $this->go("/engineers/$id", '招待メールは、所属の SES だけが送れます。', 'bad');
        if ($e->status === 'left') return $this->go("/engineers/$id", '退会済のため、招待を送れません。', 'bad');
        if ($e->suspended_at) return $this->go("/engineers/$id", '停止中のため、招待を送れません。', 'bad');
        if (!$e->email) return $this->go("/engineers/$id", 'メールアドレスが登録されていません。', 'bad');
        // 同じ SES の担当者のアカウントなら、招待ではなくエンジニアの役割を足す（兼任）
        $share = W::egShareTarget($e->email, (int) $e->ses_id, $r->user())
            || DB::table('users')->where('email', strtolower($e->email))->where('engineer_id', $id)->whereIn('role', ['ses', 'admin'])->exists();
        $res = W::invite(['email' => $e->email, 'name' => $e->name, 'role' => 'eg', 'ses_id' => $e->ses_id, 'engineer_id' => $e->id], $r->user());
        // 返ってきた人がこのエンジニアのアカウントでなければ（ほかの立場の人が使っているアドレス）、送ったことにしない
        if (!$res || (int) $res->engineer_id !== (int) $e->id) {
            return $this->go("/engineers/$id", '招待を送れませんでした（このメールアドレスは、ほかの立場の人が使っています）。', 'bad');
        }
        return $this->go("/engineers/$id", $share ? 'この方のアカウントに、エンジニアの役割を足しました。' : '招待メールを送りました。');
    }

    // エンジニアを退会済にする（記録は残す）。ログインできなくなる。稼働中の契約があるときはできない
    public function destroy(Request $r, int $id)
    {
        $e = $this->load($r, $id);
        if ($e->status === 'left') return $this->go("/engineers/$id", 'すでに退会済です。');
        if (DB::table('contracts')->where('engineer_id', $id)->where('status', 'active')->exists()) return $this->go("/engineers/$id", '退会にできません。稼働中の契約があります。先に契約を終了してください。', 'bad');
        DB::table('engineers')->where('id', $id)->update(['status' => 'left']);
        DB::table('users')->where('engineer_id', $id)->where('role', 'eg')->update(['active' => 0, 'left_at' => now_str()]);
        // 兼任の人は、エンジニアの紐づけだけ外す（SES 担当者・管理者としては引き続き入れる）
        $mark = W::selfMark($r->user(), $id); // 外す前に決める（外すと自分のエンジニアではなくなるため）
        $dualIds = DB::table('users')->where('engineer_id', $id)->whereIn('role', ['ses', 'admin'])->pluck('id');
        if ($dualIds->isNotEmpty()) {
            DB::table('users')->whereIn('id', $dualIds)->update(['engineer_id' => null, 'updated_at' => now_str()]);
            foreach ($dualIds as $uid) N::audit($r->user()->id, 'dual-unlink', "#user {$uid} ← エンジニア #{$id}");
        }
        N::audit($r->user()->id, 'engineer-leave', $mark.$e->name);
        return $this->go("/engineers/$id", 'エンジニア「'.$e->name.'」を退会済にしました。');
    }

    // エンジニアの停止・再開（管理者と所属の SES。権限は load() で確かめる。本人は role ミドルウェアで入れない）
    public function suspend(Request $r, int $id)
    {
        $this->load($r, $id);
        $res = W::suspendEngineer($id, $r->user());
        return $this->go("/engineers/$id", $res['msg'], $res['type']);
    }

    public function resume(Request $r, int $id)
    {
        $this->load($r, $id);
        $res = W::resumeEngineer($id, $r->user());
        return $this->go("/engineers/$id", $res['msg'], $res['type']);
    }

    public function skills(Request $r, int $id)
    {
        $e = $this->load($r, $id);
        return view('engineers.skills', ['e' => $e, 'skills' => DB::table('engineer_skills')->where('engineer_id', $id)->orderBy('category')->orderByDesc('years')->get(),
            'terms' => DB::table('skill_terms')->orderBy('category')->orderBy('name')->get(['name', 'category'])]);
    }

    public function saveSkills(Request $r, int $id)
    {
        $this->load($r, $id);
        W::saveSkills($id, $r->all());
        W::editedBy($id, $r->user());
        return $this->go("/engineers/$id", 'スキルを保存しました。');
    }

    public function projectForm(Request $r, int $id, ?int $pid = null)
    {
        $e = $this->load($r, $id);
        $p = $pid ? DB::table('engineer_projects')->where('id', $pid)->where('engineer_id', $id)->first() : (object) [];
        abort_unless($p, 404);
        // ホームの「経歴の更新」から：成約した案件の名前と期間を入れておく
        $k = $this->contractOf($r, $id);
        if ($k && !$pid) $p = (object) ['title' => $k->title, 'period_from' => substr($k->start_date, 0, 7), 'period_to' => $k->status === 'ended' && $k->end_date ? substr($k->end_date, 0, 7) : null];
        return view('engineers.project', ['e' => $e, 'p' => $p, 'contract' => $k, 'action' => $pid ? "/engineers/$id/projects/$pid" : "/engineers/$id/projects"]);
    }

    private function contractOf(Request $r, int $id)
    {
        $kid = int_or_null($r->input('contract'));
        return $kid ? DB::table('contracts as k')->join('cases as c', 'c.id', '=', 'k.case_id')->where('k.id', $kid)->where('k.engineer_id', $id)->select('k.*', 'c.title')->first() : null;
    }

    public function saveProject(Request $r, int $id, ?int $pid = null)
    {
        $this->load($r, $id);
        if (!str_in($r->input('title'))) return back()->withInput()->with('flash', ['msg' => '案件名を入れてください。', 'type' => 'bad']);
        W::saveProject($id, $r->all(), $pid);
        W::editedBy($id, $r->user());
        // 本人が成約した案件を書いたら、経歴の更新は済み
        if (($k = $this->contractOf($r, $id)) && $r->user()->role === 'eg') DB::table('contracts')->where('id', $k->id)->update(['history_updated_at' => now_str()]);
        return $this->go("/engineers/$id", $pid ? '保存しました。' : '経験票に追加しました。');
    }

    public function deleteProject(Request $r, int $id, int $pid)
    {
        $this->load($r, $id);
        DB::table('engineer_projects')->where('id', $pid)->where('engineer_id', $id)->delete();
        W::editedBy($id, $r->user());
        return $this->go("/engineers/$id", '削除しました。');
    }

    // スキルシート（印刷・PDF 用。?case= で案件に合わせて並べ替え、?anon=1 で名前を伏せる）
    public function sheet(Request $r, int $id)
    {
        $e = $this->load($r, $id);
        $c = $r->query('case') ? DB::table('cases')->find((int) $r->query('case')) : null;
        $skills = DB::table('engineer_skills')->where('engineer_id', $id)->orderBy('category')->orderByDesc('years')->get();
        $projects = DB::table('engineer_projects')->where('engineer_id', $id)->orderByDesc('period_from')->get()->all();
        $want = $c ? array_map('norm_skill', array_merge(parse_list($c->required_skills), parse_list($c->preferred_skills))) : [];
        $isHit = function ($t) use ($want) {
            $n = norm_skill($t);
            foreach ($want as $w) if ($w === $n || (mb_strlen($w) >= 3 && mb_strlen($n) >= 3 && loose_match($n, $w))) return true;
            return false;
        };
        if ($c) {
            // 案件に関係する技術を多く使った案件を上に（同じなら新しい順）
            usort($projects, function ($a, $b) use ($isHit) {
                $ha = count(array_filter(project_techs($a), $isHit)); $hb = count(array_filter(project_techs($b), $isHit));
                return $hb <=> $ha ?: strcmp((string) $b->period_from, (string) $a->period_from);
            });
        }
        $idx = Matcher::skillIndex($id);
        $reqRows = $c ? array_map(fn ($r) => [$r, ($s = Matcher::hasSkill($idx, $r)) ? '○ '.($s['years'] ? num_disp($s['years']).'年' : '経験あり') : '—'], parse_list($c->required_skills)) : [];
        return view('engineers.sheet', ['e' => $e, 'c' => $c, 'anon' => $r->query('anon') === '1', 'byCat' => $skills->groupBy('category'),
            'projects' => $projects, 'isHit' => $isHit, 'reqRows' => $reqRows]);
    }

    public function messages(Request $r, int $id)
    {
        $e = $this->load($r, $id);
        $msgs = DB::table('messages as m')->leftJoin('users as u', 'u.id', '=', 'm.from_user')->where('m.engineer_id', $id)->select('m.*', 'u.name', 'u.role')->orderBy('m.id')->get();
        DB::table('messages')->where('engineer_id', $id)->update([$r->user()->role === 'eg' ? 'read_by_eg' : 'read_by_admin' => true]);
        return view('engineers.messages', compact('e', 'msgs'));
    }

    public function postMessage(Request $r, int $id)
    {
        $e = $this->load($r, $id);
        $body = ($r->boolean('review') ? "【スキルシートのレビュー依頼】\n" : '').trim((string) $r->input('body'));
        if (trim($body) === '') return redirect("/engineers/$id/messages");
        $isEg = $r->user()->role === 'eg';
        DB::table('messages')->insert(['engineer_id' => $id, 'from_user' => $r->user()->id, 'body' => $body, 'created_at' => now_str(), 'read_by_eg' => $isEg, 'read_by_admin' => !$isEg]);
        if ($isEg) N::toAdmins("{$e->name} さんからメッセージ", mb_substr($body, 0, 200), "/engineers/$id/messages", true, 'message');
        else N::toEg($id, '管理者からメッセージが届きました', mb_substr($body, 0, 400), "/engineers/$id/messages", true, 'message');
        return redirect("/engineers/$id/messages");
    }

    // ---------- 詳しいプロフィール（履歴書・業務経歴書の項目、学歴・職歴、資格） ----------
    public function profileForm(Request $r, int $id)
    {
        $e = $this->load($r, $id);
        return view('engineers.profile', ['e' => $e,
            'hist' => DB::table('engineer_histories')->where('engineer_id', $id)->orderBy('sort')->get(),
            'certs' => DB::table('engineer_certs')->where('engineer_id', $id)->orderBy('sort')->get()]);
    }

    public function saveProfile(Request $r, int $id)
    {
        $this->load($r, $id);
        $in = $r->only(W::ENG_PROFILE_FIELDS);
        // 郵便番号・住所は検査してそろえる（だめなら何も保存しない）
        $ad = W::addressCols($in, '現住所');
        if (isset($ad['bad'])) return back()->withInput()->with('flash', ['msg' => $ad['bad'], 'type' => 'bad']);
        W::updateEngineer($id, $ad['ok'] + $in, $r->user());
        W::saveHistories($id, $r->all());
        W::editedBy($id, $r->user());
        return $this->go("/engineers/$id", '詳しいプロフィールを保存しました。');
    }

    // ---------- データ領域（書類） ----------
    public function generateDoc(Request $r, int $id)
    {
        $this->load($r, $id);
        $kind = $r->input('kind') === 'resume' ? 'resume' : 'careersheet';
        $fid = EngineerDocs::generate($id, $kind, $r->user(), $kind === 'careersheet' && $r->boolean('anon'));
        return $this->go("/engineers/$id#docs", EngineerDocs::KINDS[$kind].'を書き出して保存しました。下の一覧からダウンロードできます。');
    }

    public function uploadDoc(Request $r, int $id)
    {
        $this->load($r, $id);
        $r->validate(['file' => 'required|file|max:10240|mimes:pdf,xlsx,xls,docx,doc,jpg,jpeg,png,txt,csv'], [], ['file' => 'ファイル']);
        EngineerDocs::store($id, $r->file('file'), (string) $r->input('kind'), (string) str_in($r->input('title')), $r->user());
        return $this->go("/engineers/$id#docs", 'アップロードしました。');
    }

    private function doc(Request $r, int $id, int $fid)
    {
        $this->load($r, $id);
        $f = DB::table('engineer_files')->where('id', $fid)->where('engineer_id', $id)->first();
        abort_unless($f, 404);
        return $f;
    }

    public function downloadDoc(Request $r, int $id, int $fid)
    {
        $f = $this->doc($r, $id, $fid);
        abort_unless(Storage::disk(EngineerDocs::DISK)->exists($f->path), 404);
        N::audit($r->user()->id, 'doc-download', $f->filename);
        return Storage::disk(EngineerDocs::DISK)->download($f->path, $f->filename);
    }

    public function deleteDoc(Request $r, int $id, int $fid)
    {
        $f = $this->doc($r, $id, $fid);
        Storage::disk(EngineerDocs::DISK)->delete($f->path);
        DB::table('engineer_files')->where('id', $fid)->delete();
        N::audit($r->user()->id, 'doc-delete', $f->filename);
        return $this->go("/engineers/$id#docs", '削除しました。');
    }
}
