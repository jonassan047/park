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
        // 管理者は、SES の画面で SES を選んでから登録する（管理者の直属は SES の一覧の「直属」の行から。?ses=direct）
        $sesFixed = null;
        $sesId = null;
        if ($r->user()->role === 'admin') {
            $ses = (string) $r->query('ses');
            if ($ses === 'direct') {
                $sesFixed = ['id' => 'direct', 'name' => '管理者の直属（SES なし）'];
            } elseif (ctype_digit($ses) && ($row = DB::table('ses_companies')->where('id', (int) $ses)->where('kind', 'ses')->where('status', 'active')->first())) {
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
        elseif ($r->input('ses_id') === 'direct') $sesId = $hasName ? W::directSesId() : 0;
        else {
            // 管理者が選べるのは、参加中の SES だけ
            $sesId = (int) $r->input('ses_id');
            if (!DB::table('ses_companies')->where('id', $sesId)->where('kind', 'ses')->where('status', 'active')->exists()) $sesId = 0;
        }
        if (!$sesId || !$hasName) return back()->withInput()->with('flash', ['msg' => '所属と氏名を入れてください。', 'type' => 'bad']);
        if ($lenErr) return back()->withInput()->with('flash', ['msg' => $lenErr, 'type' => 'bad']);
        // 新しく登録するときは、メールアドレスが必須（ご本人への招待メールが届かないとログインできないため）
        $email = strtolower((string) str_in($r->input('email')));
        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) return back()->withInput()->with('flash', ['msg' => 'メールアドレスを正しく入れてください。', 'type' => 'bad']);
        if (DB::table('users')->where('email', $email)->exists()) return back()->withInput()->with('flash', ['msg' => 'このメールアドレスはすでに使われています。', 'type' => 'bad']);
        $id = W::createEngineer($sesId, $body, $r->user());
        return $this->go("/engineers/$id", '登録し、ご本人に招待メールを送りました。');
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
            'eu' => DB::table('users')->where('engineer_id', $id)->where('role', 'eg')->first(),
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
            $email = strtolower((string) str_in($body['email'] ?? ''));
            if ($email && $email !== ($e->email ?? '') && DB::table('users')->where('email', $email)->exists()) return back()->with('flash', ['msg' => 'このメールアドレスはすでに使われています。', 'type' => 'bad']);
        }
        W::updateEngineer($id, $body, $r->user());
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
        if (!$e->email) return $this->go("/engineers/$id", 'メールアドレスが登録されていません。', 'bad');
        W::invite(['email' => $e->email, 'name' => $e->name, 'role' => 'eg', 'ses_id' => $e->ses_id, 'engineer_id' => $e->id], $r->user());
        return $this->go("/engineers/$id", '招待メールを送りました。');
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
        W::updateEngineer($id, $r->only(W::ENG_PROFILE_FIELDS), $r->user());
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
