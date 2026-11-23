<?php

namespace App\Http\Controllers;

use App\Services\CaseMail;
use App\Services\CaseSearch as CS;
use App\Services\Notifier as N;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// エンジニア検索：検索画面（条件）→ 一覧（チェック・全員へのメッセージ）→ 詳細（○×・個別メッセージ）→ 送信
// 使えるのは、今の案内メールと同じ人（管理者と、受注 SES の SES・担当者）。エンジニアは入れない（ルートで止める）
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
        $found = $q === '' ? [] : CS::findTargets($u, $c, $q, array_map(fn ($row) => $row['e']->id, $data['rows']), array_map(fn ($sr) => $sr['st']->staff_user_id, $data['staff']));
        $extras = DB::table('case_search_extras')->where('search_id', $s->id)->orderBy('id')->get();
        return view('cases.search-list', ['c' => $c, 's' => $s, 'proper' => $data['proper'], 'partner' => $data['partner'], 'staffRows' => $data['staff'], 'failed' => $data['failed'],
            'q' => $q, 'found' => $found, 'extras' => $extras, 'canSend' => N::canSend(), 'partnerSide' => CS::isPartnerSide($u, $c),
            'batchN' => CS::batch($s)->count(), 'price' => CS::isPartnerSide($u, $c) ? CS::properPrice($u, $c, $s) : ($c->price ? man($c->price) : ($c->price_note ?: '—'))]);
    }

    // 一覧のチェックを保存する（詳細へ行く・探す・足す・作成画面へ進むの前にも呼ぶ。戻ってもチェックが残る）
    // 追加の宛先のチェック（xpick[]）・手で足した担当者のチェック（spick[]）も入れ直す。送信済みのものは触らない
    private function persist(Request $r, object $s): void
    {
        $ids = array_values(array_filter(array_map('intval', (array) $r->input('pick', []))));
        DB::table('case_search_members')->where('search_id', $s->id)->update(['checked' => false]);
        if ($ids) DB::table('case_search_members')->where('search_id', $s->id)->whereIn('engineer_id', $ids)->update(['checked' => true]);
        $xids = array_values(array_filter(array_map('intval', (array) $r->input('xpick', []))));
        DB::table('case_search_extras')->where('search_id', $s->id)->whereNull('sent_at')->update(['checked' => false]);
        if ($xids) DB::table('case_search_extras')->where('search_id', $s->id)->whereNull('sent_at')->whereIn('id', $xids)->update(['checked' => true]);
        $stids = array_values(array_filter(array_map('intval', (array) $r->input('spick', []))));
        DB::table('case_search_staff')->where('search_id', $s->id)->where('by_hand', true)->whereNull('sent_at')->update(['checked' => false]);
        if ($stids) DB::table('case_search_staff')->where('search_id', $s->id)->where('by_hand', true)->whereNull('sent_at')->whereIn('id', $stids)->update(['checked' => true]);
        DB::table('case_searches')->where('id', $s->id)->update(['updated_at' => now_str()]);
    }

    // メールアドレスを追加（PARK に登録していない人。パートナー側の検索では使えない）
    public function addExtras(Request $r, int $sid)
    {
        [$s, $c] = $this->loadSearch($r, $sid);
        if ($back = $this->closed($c)) return $back;
        if (CS::isPartnerSide($r->user(), $c)) return $this->go("/searches/{$s->id}", '紹介の依頼からの検索では、メールアドレスは足せません。', 'bad');
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
        // パートナーの人には直接メールを送らない（担当者に紹介を依頼する）ので、見本は出さない
        return view('cases.search-member', ['c' => $c, 's' => $s, 'row' => $row, 'note' => CS::noteFor($u, $row), 'mail' => $row['own'] ? CS::body($u, $c, $s, $row) : null]);
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

    // 手で足した担当者（他社）を一覧に足す
    public function addStaff(Request $r, int $sid)
    {
        [$s, $c] = $this->loadSearch($r, $sid);
        if ($back = $this->closed($c)) return $back;
        $this->persist($r, $s);
        $ok = CS::addStaff($r->user(), $s, $c, (int) $r->input('user_id'));
        return $ok ? $this->go("/searches/{$s->id}", '担当者を一覧に足しました。') : $this->go("/searches/{$s->id}", '足せない担当者です。', 'bad');
    }

    // 手で足した担当者を外す（送る前のものだけ）
    public function removeStaff(Request $r, int $sid, int $stid)
    {
        [$s, $c] = $this->loadSearch($r, $sid);
        if ($back = $this->closed($c)) return $back;
        $this->persist($r, $s);
        abort_unless(CS::removeStaff($s, $stid), 404);
        return $this->go("/searches/{$s->id}", '担当者を外しました。');
    }

    // ---------- 作成画面（v0.16.0）：プロパー → 担当者の順に、本文を直して送る ----------

    // 「エンジニア・担当者へメールを作成」：チェックを保存し、担当者の束を作って、作成画面へ。何も選んでいなければ一覧へ戻す
    // 古い POST /searches/{sid}/send も、ここへ移す（直接は送らない）
    public function startCompose(Request $r, int $sid)
    {
        [$s, $c] = $this->loadSearch($r, $sid);
        if ($back = $this->closed($c)) return $back;
        $u = $r->user();
        $this->persist($r, $s);
        if ($warn = $this->extraTextWarning($r, $s)) return $warn;
        CS::buildBatch($u, $c, $s);
        $t = CS::properTargets($u, $s);
        if (!count($t['rows']) && !count($t['extras']) && CS::batch($s)->isEmpty()) return $this->go("/searches/{$s->id}", '送る相手を選んでください。', 'bad');
        return redirect("/searches/{$s->id}/compose");
    }

    // 作成画面：チェックしたプロパーか追加の宛先がいれば、先にプロパーの画面。いなければ、束の一番上の担当者の画面。どちらも無ければ案件の画面へ
    public function compose(Request $r, int $sid)
    {
        [$s, $c] = $this->loadSearch($r, $sid);
        if ($back = $this->closed($c)) return $back;
        $u = $r->user();
        $t = CS::properTargets($u, $s);
        $batch = CS::batch($s);
        $base = ['c' => $c, 's' => $s, 'canSend' => N::canSend(), 'batchN' => $batch->count(), 'hasProper' => count($t['rows']) || count($t['extras'])];
        if ($base['hasProper']) {
            $tpl = CS::properTemplate($u, $c, $s);
            $partnerSide = CS::isPartnerSide($u, $c);
            return view('cases.search-compose', $base + ['mode' => 'proper', 'rows' => $t['rows'], 'extras' => $t['extras'], 'subject' => $s->proper_subject ?: CaseMail::defaultSubject($c), 'body' => $s->proper_body ?: $tpl,
                'price' => CS::properPrice($u, $c, $s), 'priceLabel' => $partnerSide ? '伝えられた単価' : 'プロバイダーからの金額', 'who' => 'エンジニア',
                'help' => '{呼び名}（その人の呼び名）・{合う点}（その人に合う点）・{個別メッセージ}（その人あての一言。無ければ行ごと消えます）は、1人ずつ入れ替わります。']);
        }
        if ($batch->isNotEmpty()) {
            $top = $batch->first();
            $user = DB::table('users')->find($top->staff_user_id);
            $rest = $batch->slice(1)->values()->map(function ($b) {
                $x = DB::table('users')->find($b->staff_user_id);
                $b->label = $x ? CS::staffLabel($x) : '—';
                $b->initials = $this->initialsOf($b);
                return $b;
            });
            $top->label = $user ? CS::staffLabel($user) : '—';
            $top->initials = $this->initialsOf($top);
            return view('cases.search-compose', $base + ['mode' => 'staff', 'top' => $top, 'rest' => $rest, 'subject' => $top->subject ?: '【紹介のお願い】'.$c->title, 'body' => $top->body ?: CS::staffTemplate($u, $c, $s),
                'price' => $c->price ? man($c->price) : '相談', 'priceLabel' => 'プロバイダーからの金額', 'who' => '担当者',
                'help' => '{宛名}（企業名 氏名）と {依頼するエンジニア}（依頼するエンジニアのイニシャル）は、担当者ごとに入れ替わります。']);
        }
        return $this->go("/cases/{$c->id}#mails", '送る相手は残っていません。');
    }

    // 依頼するエンジニアのイニシャル（画面の一覧に出す）
    private function initialsOf(object $b): array
    {
        $ids = (array) json_decode((string) $b->engineer_ids, true);
        return $ids ? DB::table('engineers')->whereIn('id', array_map('intval', $ids))->orderBy('id')->pluck('initials')->map(fn ($i) => $i ?: '—')->all() : [];
    }

    // 件名・本文を受ける（空の本文は断る。件名は空なら既定）
    private function subjectBody(Request $r, string $defaultSubject): array
    {
        $subject = mb_substr(trim((string) $r->input('subject')), 0, CS::MAX_SUBJECT_LEN) ?: $defaultSubject;
        return [$subject, trim(str_replace("\r\n", "\n", (string) $r->input('body')))];
    }

    // 次の行き先：未送信の担当者がいれば作成画面、いなければ案件の画面
    private function nextPath(object $s, object $c): string
    {
        return CS::batch($s)->isNotEmpty() ? "/searches/{$s->id}/compose" : "/cases/{$c->id}#mails";
    }

    // プロパー（と追加の宛先）に、直した本文で送る
    public function sendProper(Request $r, int $sid)
    {
        [$s, $c] = $this->loadSearch($r, $sid);
        if ($back = $this->closed($c)) return $back;
        $u = $r->user();
        [$subject, $body] = $this->subjectBody($r, CaseMail::defaultSubject($c));
        if ($body === '') return $this->go("/searches/{$s->id}/compose", '本文を入れてください。', 'bad');
        $s = DB::table('case_searches')->find($s->id);
        $hasExtra = !CS::isPartnerSide($u, $c) && DB::table('case_search_extras')->where('search_id', $s->id)->where('checked', true)->whereNull('sent_at')->exists();
        $n = CS::sendProper($u, $c, $s, $subject, $body);
        $ex = $hasExtra ? CS::sendExtras($u, $c, $s, $subject, $body) : ['sent' => 0, 'dup' => 0, 'noReply' => false];
        $x = $ex['sent'];
        $note = '';
        if ($ex['dup'] > 0) $note .= "この案件でもう送っている宛先 {$ex['dup']}件は、送りませんでした。";
        if ($ex['noReply']) $note .= '返信が届く先（受信の設定か、担当者のメールアドレス）が無いので、追加の宛先には送りませんでした。';
        if ($n === 0 && $x === 0) return $this->go($this->nextPath($s, $c), '送る相手がいません。'.$note, 'bad');
        $msg = $n > 0 ? "{$n}名のエンジニアに案内メールを送りました。返事が来たら、案件の画面の「案内済メール」に記録してください。" : '';
        if ($x > 0) $msg .= $n > 0 ? "追加の宛先 {$x}件にも送りました。" : "追加の宛先 {$x}件に案内メールを送りました。";
        return $this->go($this->nextPath($s, $c), $msg.$note);
    }

    // 担当者の画面：一番上の担当者と、チェックした同じ条件の担当者に、同じ本文（宛名と依頼するエンジニアだけ差し替え）で送る
    public function sendStaff(Request $r, int $sid, int $stid)
    {
        [$s, $c] = $this->loadSearch($r, $sid);
        if ($back = $this->closed($c)) return $back;
        $batch = CS::batch($s);
        abort_unless($batch->contains('id', $stid), 404);
        [$subject, $body] = $this->subjectBody($r, '【紹介のお願い】'.$c->title);
        if ($body === '') return $this->go("/searches/{$s->id}/compose", '本文を入れてください。', 'bad');
        $others = array_map('intval', (array) $r->input('others', []));
        $ids = array_merge([$stid], array_values(array_intersect($others, $batch->pluck('id')->map(fn ($x) => (int) $x)->all())));
        $res = CS::sendStaff($r->user(), $c, $s, $ids, $subject, $body);
        $note = $res['skipped'] ? "{$res['skipped']}名は、送れなかったので飛ばしました（もう送っている・使えない担当者です）。" : '';
        if ($res['sent'] === 0) return $this->go($this->nextPath($s, $c), '送れた担当者はいません。'.$note, 'bad');
        return $this->go($this->nextPath($s, $c), "{$res['sent']}名の担当者に、紹介のお願いを送りました。".$note);
    }

    // この担当者には今回は送らない：束から外して、次へ
    public function skipStaff(Request $r, int $sid, int $stid)
    {
        [$s, $c] = $this->loadSearch($r, $sid);
        if ($back = $this->closed($c)) return $back;
        abort_unless(CS::batch($s)->contains('id', $stid), 404);
        DB::table('case_search_staff')->where('id', $stid)->where('search_id', $s->id)->whereNull('sent_at')->update(['in_batch' => false, 'updated_at' => now_str()]);
        return $this->go($this->nextPath($s, $c), 'この担当者には、今回は送りませんでした。');
    }

    // 下書きを保存（送らずに、同じ画面へ）。kind＝proper（検索の下書き）／staff（その担当者の行の下書き）
    public function saveDraft(Request $r, int $sid)
    {
        [$s, $c] = $this->loadSearch($r, $sid);
        if ($back = $this->closed($c)) return $back;
        $subject = mb_substr(trim((string) $r->input('subject')), 0, CS::MAX_SUBJECT_LEN) ?: null;
        $body = trim(str_replace("\r\n", "\n", (string) $r->input('body'))) ?: null;
        if ($r->input('kind') === 'staff') {
            $stid = (int) $r->input('stid');
            abort_unless(CS::batch($s)->contains('id', $stid), 404);
            DB::table('case_search_staff')->where('id', $stid)->where('search_id', $s->id)->whereNull('sent_at')->update(['subject' => $subject, 'body' => $body, 'updated_at' => now_str()]);
        } else {
            DB::table('case_searches')->where('id', $s->id)->update(['proper_subject' => $subject, 'proper_body' => $body, 'updated_at' => now_str()]);
        }
        return $this->go("/searches/{$s->id}/compose", '下書きを保存しました。');
    }

    // ---------- 紹介の依頼を受けた担当者（パートナー）の側 ----------

    // 自分の会社あての、送信済みの依頼（辞退していないもの）
    private function myRequest(Request $r, object $c, int $stid): object
    {
        $st = DB::table('case_search_staff')->where('id', $stid)->where('case_id', $c->id)->where('partner_ses_id', (int) $r->user()->ses_id)->whereNotNull('sent_at')->first();
        abort_unless($st, 404);
        return $st;
    }

    // 自社のエンジニアを検索する：依頼元の条件をもとに、自分の検索を作って「エンジニアを選択」へ
    public function referralSearch(Request $r, int $id, int $stid)
    {
        $c = $this->loadCase($r, $id);
        if ($back = $this->closed($c)) return $back;
        $st = $this->myRequest($r, $c, $stid);
        if ($st->declined_at) return $this->go("/cases/{$c->id}#referral", 'この依頼は、辞退しています。', 'bad');
        return $this->go('/searches/'.CS::startFromRequest($r->user(), $c, $st));
    }

    // 今回は紹介できる人がいません（辞退）。ひとこと任意
    public function referralDecline(Request $r, int $id, int $stid)
    {
        $c = $this->loadCase($r, $id);
        $st = $this->myRequest($r, $c, $stid);
        $ok = CS::decline($r->user(), $c, $st, str_in($r->input('note')));
        return $this->go("/cases/{$c->id}#referral", $ok ? '辞退しました。依頼した担当者に知らせました。' : 'すでに辞退しています。', $ok ? 'ok' : 'bad');
    }
}
