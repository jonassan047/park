<?php

namespace App\Http\Controllers;

use App\Services\CaseSearch as CS;
use App\Services\Notifier as N;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// エンジニア検索：検索画面（条件）→ 一覧（チェック・全員へのメッセージ）→ 詳細（○×・個別メッセージ）→ 送信
// 使えるのは、今の案内メールと同じ人（管理者と、案件を持ち込んだ会社の SES・担当者）。EG は入れない（ルートで止める）
class CaseSearchController extends Controller
{
    private function loadCase(Request $r, int $id)
    {
        $c = DB::table('cases')->find($id);
        abort_unless($c, 404);
        abort_unless(CS::canUse($r->user(), $c), 403);
        return $c;
    }

    // 検索と、その案件を読む。作った人と同じ会社の人（と管理者）だけ。ほかの案件・人の検索は開けない
    private function loadSearch(Request $r, int $sid): array
    {
        $s = DB::table('case_searches')->find($sid);
        abort_unless($s, 404);
        $c = $this->loadCase($r, (int) $s->case_id);
        abort_unless(CS::canOpen($r->user(), $s), 403);
        return [$s, $c];
    }

    // 募集中でなければ案件の画面へ戻す
    private function closed(object $c)
    {
        return $c->status === 'open' ? null : $this->go("/cases/{$c->id}", '募集中の案件だけ案内できます。', 'bad');
    }

    // 検索画面：左に元のメール、右に必須・尚可
    public function form(Request $r, int $id)
    {
        $c = $this->loadCase($r, $id);
        if ($back = $this->closed($c)) return $back;
        $s = DB::table('case_searches')->where('case_id', $id)->where('created_by', $r->user()->id)->first();
        return view('cases.search', ['c' => $c, 'cond' => CS::conditions($c, $s)]);
    }

    // 条件を保存して検索 → 一覧へ。案件の必須・尚可・技術マスタは変えない
    public function run(Request $r, int $id)
    {
        $c = $this->loadCase($r, $id);
        if ($back = $this->closed($c)) return $back;
        $u = $r->user();
        $s = DB::table('case_searches')->where('case_id', $id)->where('created_by', $u->id)->first();
        $cond = CS::conditions($c, $s);
        $offAll = (array) $r->input('off', []);
        foreach (['req', 'pref'] as $side) {
            $off = array_map('strval', (array) ($offAll[$side] ?? []));
            foreach ($cond[$side] as &$it) $it['on'] = !in_array(CS::key($it), $off, true);
            unset($it);
            $cond = CS::addItems($cond, $side, (string) $r->input('add_'.$side));
        }
        return $this->go('/searches/'.CS::run($u, $c, $cond));
    }

    // 一覧
    public function index(Request $r, int $sid)
    {
        [$s, $c] = $this->loadSearch($r, $sid);
        if ($back = $this->closed($c)) return $back;
        $u = $r->user();
        $data = CS::rows($u, $s);
        $q = trim((string) $r->query('q'));
        $found = $q === '' ? [] : CS::findEngineers($u, $c, $q, array_map(fn ($row) => $row['e']->id, $data['rows']));
        return view('cases.search-list', ['c' => $c, 's' => $s, 'rows' => $data['rows'], 'failed' => $data['failed'], 'q' => $q, 'found' => $found, 'canSend' => N::canSend()]);
    }

    // 一覧のチェック・全員へのメッセージを保存する（詳細へ行く・探す・足す・送るの前にも呼ぶ。戻ってもチェックが残る）
    private function persist(Request $r, object $s): void
    {
        $ids = array_values(array_filter(array_map('intval', (array) $r->input('pick', []))));
        DB::table('case_search_members')->where('search_id', $s->id)->update(['checked' => false]);
        if ($ids) DB::table('case_search_members')->where('search_id', $s->id)->whereIn('engineer_id', $ids)->update(['checked' => true]);
        DB::table('case_searches')->where('id', $s->id)->update(['message_all' => str_in($r->input('message_all')), 'updated_at' => now_str()]);
    }

    public function save(Request $r, int $sid)
    {
        [$s, $c] = $this->loadSearch($r, $sid);
        if ($back = $this->closed($c)) return $back;
        $this->persist($r, $s);
        if ($eid = (int) $r->input('go')) {
            $ok = DB::table('case_search_members')->where('search_id', $s->id)->where('engineer_id', $eid)->exists();
            return $this->go($ok ? "/searches/{$s->id}/members/$eid" : "/searches/{$s->id}");
        }
        if ($r->filled('find')) return redirect("/searches/{$s->id}?q=".rawurlencode(trim((string) $r->input('q'))));
        return $this->go("/searches/{$s->id}", '保存しました。');
    }

    // 一覧に足す（探した範囲の人だけ）
    public function add(Request $r, int $sid)
    {
        [$s, $c] = $this->loadSearch($r, $sid);
        if ($back = $this->closed($c)) return $back;
        $this->persist($r, $s);
        $ok = CS::addMember($r->user(), $s, $c, (int) $r->input('engineer_id'));
        return $ok ? $this->go("/searches/{$s->id}", '一覧に足しました。') : $this->go("/searches/{$s->id}", '足せないエンジニアです。', 'bad');
    }

    // 詳細：条件ごとの ○×・個別メッセージ・メールの見本
    public function member(Request $r, int $sid, int $eid)
    {
        [$s, $c] = $this->loadSearch($r, $sid);
        if ($back = $this->closed($c)) return $back;
        $u = $r->user();
        $row = CS::rowOf($u, $s, $eid);
        abort_unless($row, 404);
        return view('cases.search-member', ['c' => $c, 's' => $s, 'row' => $row, 'note' => CS::noteFor($u, $row), 'mail' => CS::body($u, $c, $s, $row)]);
    }

    public function saveMember(Request $r, int $sid, int $eid)
    {
        [$s, $c] = $this->loadSearch($r, $sid);
        if ($back = $this->closed($c)) return $back;
        $mem = DB::table('case_search_members')->where('search_id', $s->id)->where('engineer_id', $eid)->first();
        abort_unless($mem, 404);
        DB::table('case_search_members')->where('id', $mem->id)->update(['message' => str_in($r->input('message')), 'omit_note' => $r->boolean('omit_note'), 'updated_at' => now_str()]);
        return $this->go("/searches/{$s->id}", '保存しました。');
    }

    // チェックした人に送る
    public function send(Request $r, int $sid)
    {
        [$s, $c] = $this->loadSearch($r, $sid);
        if ($back = $this->closed($c)) return $back;
        $u = $r->user();
        $this->persist($r, $s);
        $s = DB::table('case_searches')->find($s->id); // 保存した全員へのメッセージで読み直す
        $ids = DB::table('case_search_members')->where('search_id', $s->id)->where('checked', true)->pluck('engineer_id')->all();
        if (!$ids) return $this->go("/searches/{$s->id}", '送る相手を選んでください。', 'bad');
        $n = CS::send($u, $c, $s, $ids);
        if ($n === 0) return $this->go("/searches/{$s->id}", '送る相手がいません。', 'bad');
        return $this->go("/cases/{$c->id}#mails", "{$n}名に案内メールを送りました。返事が来たら、下の「案内メール」に記録してください。");
    }
}
