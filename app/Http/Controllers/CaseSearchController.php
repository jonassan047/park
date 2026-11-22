<?php

namespace App\Http\Controllers;

use App\Services\CaseSearch as CS;
use App\Services\Notifier as N;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// エンジニア検索：検索画面（条件）→ 一覧（チェック・全員へのメッセージ）→ 詳細（○×・個別メッセージ）→ 送信
// 使えるのは、今の案内メールと同じ人（管理者と、提案元 SES の SES・担当者）。EG は入れない（ルートで止める）
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

    // 古いリンク（検索画面）：条件は案件の画面の「エンジニアを検索」に移した
    public function form(Request $r, int $id)
    {
        $c = $this->loadCase($r, $id);
        if ($back = $this->closed($c)) return $back;
        return redirect("/cases/{$id}#keywords");
    }

    // 画面の入力から、この人の検索の条件を作る（チェックの状態と、各節の追加の欄）。保存はしない
    private function formCond(Request $r, object $c): array
    {
        $s = DB::table('case_searches')->where('case_id', $c->id)->where('created_by', $r->user()->id)->first();
        return CS::applyForm(CS::conditions($c, $s), $r->all());
    }

    // 条件を保存して検索 →「エンジニアを選択」へ。案件の必須・尚可・技術マスタは変えない
    public function run(Request $r, int $id)
    {
        $c = $this->loadCase($r, $id);
        if ($back = $this->closed($c)) return $back;
        return $this->go('/searches/'.CS::run($r->user(), $c, $this->formCond($r, $c)));
    }

    // 各「追加」ボタン：条件だけ保存して、案件の画面のキーワード欄へ戻る（検索はしない）
    public function keywords(Request $r, int $id)
    {
        $c = $this->loadCase($r, $id);
        if ($back = $this->closed($c)) return $back;
        CS::saveConditions($r->user(), $c, $this->formCond($r, $c));
        return $this->go("/cases/{$id}#keywords", 'キーワードを保存しました。');
    }

    // エンジニアを選択
    public function index(Request $r, int $sid)
    {
        [$s, $c] = $this->loadSearch($r, $sid);
        if ($back = $this->closed($c)) return $back;
        $u = $r->user();
        $data = CS::rows($u, $s);
        $q = trim((string) $r->query('q'));
        $found = $q === '' ? [] : CS::findEngineers($u, $c, $q, array_map(fn ($row) => $row['e']->id, $data['rows']));
        $extras = DB::table('case_search_extras')->where('search_id', $s->id)->orderBy('id')->get();
        return view('cases.search-list', ['c' => $c, 's' => $s, 'rows' => $data['rows'], 'failed' => $data['failed'], 'q' => $q, 'found' => $found, 'extras' => $extras, 'canSend' => N::canSend()]);
    }

    // 一覧のチェック・全員へのメッセージを保存する（詳細へ行く・探す・足す・送るの前にも呼ぶ。戻ってもチェックが残る）
    // 追加の宛先のチェック（xpick[]）も入れ直す。送信済みの宛先は触らない
    private function persist(Request $r, object $s): void
    {
        $ids = array_values(array_filter(array_map('intval', (array) $r->input('pick', []))));
        DB::table('case_search_members')->where('search_id', $s->id)->update(['checked' => false]);
        if ($ids) DB::table('case_search_members')->where('search_id', $s->id)->whereIn('engineer_id', $ids)->update(['checked' => true]);
        $xids = array_values(array_filter(array_map('intval', (array) $r->input('xpick', []))));
        DB::table('case_search_extras')->where('search_id', $s->id)->whereNull('sent_at')->update(['checked' => false]);
        if ($xids) DB::table('case_search_extras')->where('search_id', $s->id)->whereNull('sent_at')->whereIn('id', $xids)->update(['checked' => true]);
        DB::table('case_searches')->where('id', $s->id)->update(['message_all' => str_in($r->input('message_all')), 'updated_at' => now_str()]);
    }

    // メールアドレスを追加（PARK に登録していない人）
    public function addExtras(Request $r, int $sid)
    {
        [$s, $c] = $this->loadSearch($r, $sid);
        if ($back = $this->closed($c)) return $back;
        $this->persist($r, $s);
        $res = CS::addExtras($r->user(), $s, $c, (string) $r->input('extra_text'));
        $msg = $res['added'] ? "{$res['added']}件を追加の宛先に足しました。" : '足した宛先はありません。';
        if ($res['skipped']) $msg .= ' '.implode(' ', $res['skipped']);
        $back = $this->go("/searches/{$s->id}#extras", $msg, $res['added'] ? 'ok' : 'bad');
        return $res['skipped'] ? $back->withInput(['extra_text' => (string) $r->input('extra_text')]) : $back; // 断られた入力は欄に残す
    }

    // 追加の宛先を外す（送る前の入力の取り消し。送った行は記録なので消さない）
    public function removeExtra(Request $r, int $sid, int $xid)
    {
        [$s, $c] = $this->loadSearch($r, $sid);
        if ($back = $this->closed($c)) return $back;
        $x = DB::table('case_search_extras')->where('id', $xid)->where('search_id', $s->id)->first();
        abort_unless($x, 404);
        $this->persist($r, $s);
        if ($x->sent_at) return $this->go("/searches/{$s->id}#extras", '送った宛先は外せません。', 'bad');
        DB::table('case_search_extras')->where('id', $x->id)->whereNull('sent_at')->delete();
        return $this->go("/searches/{$s->id}#extras", '宛先を外しました。');
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
        if ($warn = $this->extraTextWarning($r, $s)) return $warn;
        return $this->go("/searches/{$s->id}", '保存しました。');
    }

    // 「メールアドレスを追加」の欄に書いたまま［宛先に足す］を押さずに保存・送信したとき：入力を残して戻し、先に足すよう知らせる
    private function extraTextWarning(Request $r, object $s)
    {
        if (trim((string) $r->input('extra_text')) === '') return null;
        return $this->go("/searches/{$s->id}#extras", '「メールアドレスを追加」の欄に、足していない宛先があります。先に［宛先に足す］を押してください。', 'bad')
            ->withInput(['extra_text' => (string) $r->input('extra_text')]);
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
        DB::table('case_search_members')->where('id', $mem->id)->update(['message' => str_in($r->input('message')), 'updated_at' => now_str()]);
        return $this->go("/searches/{$s->id}", '保存しました。');
    }

    // チェックした人に送る
    public function send(Request $r, int $sid)
    {
        [$s, $c] = $this->loadSearch($r, $sid);
        if ($back = $this->closed($c)) return $back;
        $u = $r->user();
        $this->persist($r, $s);
        if ($warn = $this->extraTextWarning($r, $s)) return $warn;
        $s = DB::table('case_searches')->find($s->id); // 保存した全員へのメッセージで読み直す
        $ids = DB::table('case_search_members')->where('search_id', $s->id)->where('checked', true)->pluck('engineer_id')->all();
        $hasExtra = DB::table('case_search_extras')->where('search_id', $s->id)->where('checked', true)->whereNull('sent_at')->exists();
        if (!$ids && !$hasExtra) return $this->go("/searches/{$s->id}", '送る相手を選んでください。', 'bad');
        $n = $ids ? CS::send($u, $c, $s, $ids) : 0;
        $ex = $hasExtra ? CS::sendExtras($u, $c, $s) : ['sent' => 0, 'dup' => 0, 'noReply' => false];
        $x = $ex['sent'];
        $note = '';
        if ($ex['dup'] > 0) $note .= "この案件でもう送っている宛先 {$ex['dup']}件は、送りませんでした。";
        if ($ex['noReply']) $note .= '返信が届く先（受信の設定か、担当者のメールアドレス）が無いので、追加の宛先には送りませんでした。';
        if ($n === 0 && $x === 0) return $this->go("/searches/{$s->id}", '送る相手がいません。'.$note, 'bad');
        if ($n > 0) {
            $msg = "{$n}名に案内メールを送りました。返事が来たら、下の「案内メール」に記録してください。";
            if ($x > 0) $msg .= "追加の宛先 {$x}件にも送りました。";
        } else {
            $msg = "追加の宛先 {$x}件に案内メールを送りました。";
        }
        return $this->go("/cases/{$c->id}#mails", $msg.$note);
    }
}
