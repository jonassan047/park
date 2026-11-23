<?php

namespace App\Services;

use App\Services\Notifier as N;
use App\Support\CaseExp;
use App\Support\SkillCatalog;
use Illuminate\Support\Facades\DB;

// エンジニア検索（v0.7.0）：案件のメールから抜き出した必須・尚可で、合うエンジニアを探し、一覧から案内メールを送る。
// 流れ：検索画面（条件を足す・外す）→ 一覧（チェック・全員へのメッセージ）→ 詳細（○×・個別メッセージ）→ 送信
// 送る所は CaseMail::send を通す（記録・返事のリンク・他社の SES への知らせは今の案内と同じ）。
// 条件の変更はこの検索にだけ保存し、案件の必須・尚可・技術マスタは変えない。
class CaseSearch
{
    const MAX_ITEM_LEN = 60;   // 手で足す項目の長さ
    const MAX_CONFIRM_LEN = 200; // 「エンジニアに確認」に足す文の長さ
    const MAX_ADD = 30;        // 1回に足せる数
    const MAX_EXTRA_ADD = 10;  // 追加の宛先：1回に足せる数
    const MAX_EXTRAS = 30;     // 追加の宛先：1つの検索に置ける数
    const EXTRA_KIND = ReplyTokens::KIND_EXTRA; // reply_tokens.kind
    const FIND_LIMIT = 30;     // 「一覧に足す」で探して出す人数
    const MODE = 'search';     // case_mails.mode
    const MODE_PARTNER = 'partner'; // case_mails.mode：パートナーの担当者が、自社のエンジニアに送った案内
    const MAX_PRICE_LEN = 100; // 単価の文の長さ（case_mails.price_text など）
    const MAX_SUBJECT_LEN = 100; // 件名の長さ
    const MAX_DECLINE_LEN = 500; // 辞退のひとことの長さ
    const NO_STAFF = '担当者がいません';

    // ---------- 権限 ----------

    // 検索を使えるのは、受注 SES の SES・担当者と、「紹介の依頼」を受けた会社（辞退していない）の SES・担当者（v0.16.0）
    public static function canUse($u, object $c): bool
    {
        if (CaseMail::canSend($u, $c)) return true;
        return $u->role === 'ses' && !empty($u->ses_id)
            && DB::table('case_search_staff')->where('case_id', $c->id)->where('partner_ses_id', (int) $u->ses_id)->whereNotNull('sent_at')->whereNull('declined_at')->exists();
    }

    // パートナー側の検索か：SES・担当者で、受注 SES ではない（紹介の依頼を受けて、自社のエンジニアだけで探す）
    public static function isPartnerSide($u, object $c): bool
    {
        return $u->role === 'ses' && !CaseMail::canSend($u, $c);
    }

    // 検索を開けるのは、作った人と同じ会社の SES・担当者だけ（管理者は開けない。v0.11.0）
    public static function canOpen($u, object $s): bool
    {
        return $u->role === 'ses' && $s->ses_id !== null && (int) $s->ses_id === (int) $u->ses_id;
    }

    // ---------- 条件 ----------

    // 条件の項目の同じ・違いを見る鍵（技術は表記ゆれをそろえる）
    public static function key(array $it): string
    {
        if ($it['kind'] === 'text') return 'text:'.trim((string) $it['name']); // エンジニアに確認の文は、そのままの文で見分ける
        return $it['kind'].':'.($it['kind'] === 'skill' ? norm_skill($it['name']) : $it['name']);
    }

    // 検索画面に出す条件。案件の今の値から作り直し、足した項目と「外す」の印だけを前の検索から引き継ぐ
    public static function conditions(object $c, ?object $s): array
    {
        $prev = $s ? (json_decode((string) $s->conditions, true) ?: []) : [];
        $cols = ['req' => ['required_skills', 'required_exp'], 'pref' => ['preferred_skills', 'preferred_exp']];
        $out = [];
        foreach ($cols as $side => [$skillCol, $expCol]) {
            $items = [];
            foreach (skill_items($c->$skillCol) as $w) $items[] = ['name' => self::skillName($w), 'kind' => 'skill', 'src' => 'case', 'on' => true];
            foreach (CaseExp::split($c->$expCol ?? null) as $n) $items[] = ['name' => $n, 'kind' => 'exp', 'src' => 'case', 'on' => true];
            // 同じ項目は重ねない
            $seen = [];
            $items = array_values(array_filter($items, function ($it) use (&$seen) {
                $k = self::key($it);
                if (isset($seen[$k])) return false;
                return $seen[$k] = true;
            }));
            $off = [];
            foreach ($prev[$side] ?? [] as $p) if (($p['src'] ?? '') === 'case' && empty($p['on'])) $off[self::key($p)] = true;
            foreach ($items as &$it) if (isset($off[self::key($it)])) $it['on'] = false;
            unset($it);
            $have = [];
            foreach ($items as $it) $have[self::key($it)] = true;
            foreach ($prev[$side] ?? [] as $p) {
                if (($p['src'] ?? '') !== 'added' || isset($have[self::key($p)])) continue;
                $items[] = ['name' => (string) $p['name'], 'kind' => $p['kind'] === 'exp' ? 'exp' : 'skill', 'src' => 'added', 'on' => !empty($p['on'])];
                $have[self::key($p)] = true;
            }
            $out[$side] = $items;
        }
        $out['confirm'] = self::confirmConditions($c, $prev['confirm'] ?? []);
        return $out;
    }

    // 「エンジニアに確認」の項目：案件の確認事項の行（空行は捨て、同じ文は1つ）→ 前の検索で足した文。外した印は引き継ぐ
    private static function confirmConditions(object $c, array $prev): array
    {
        $items = [];
        $have = [];
        foreach (preg_split('/\R/u', (string) ($c->confirm_items ?? '')) as $ln) {
            $ln = trim($ln);
            if ($ln === '' || isset($have['text:'.$ln])) continue;
            $items[] = ['name' => $ln, 'kind' => 'text', 'src' => 'case', 'on' => true];
            $have['text:'.$ln] = true;
        }
        $off = [];
        foreach ($prev as $p) if (($p['src'] ?? '') === 'case' && empty($p['on'])) $off['text:'.trim((string) $p['name'])] = true;
        foreach ($items as &$it) if (isset($off[self::key($it)])) $it['on'] = false;
        unset($it);
        foreach ($prev as $p) {
            $name = trim((string) ($p['name'] ?? ''));
            if (($p['src'] ?? '') !== 'added' || $name === '' || isset($have['text:'.$name])) continue;
            $items[] = ['name' => $name, 'kind' => 'text', 'src' => 'added', 'on' => !empty($p['on'])];
            $have['text:'.$name] = true;
        }
        return $items;
    }

    // 技術は、マスタにあれば正式な名前に。無い言葉はそのまま（マスタには足さない）
    private static function skillName(string $w): string
    {
        $t = SkillCatalog::find($w);
        return $t ? (string) $t[1] : $w;
    }

    // 手で足した項目（「、」や改行で区切って複数）を、$side（req・pref）に足す。重なる項目は足さず、外してあれば戻す
    public static function addItems(array $cond, string $side, ?string $text): array
    {
        if ($side === 'confirm') return self::addConfirm($cond, $text);
        $exps = \App\Support\SkillForm::caseItems();
        $n = 0;
        foreach (preg_split('/[、,，\r\n;；]+/u', (string) $text) as $w) {
            $w = trim(mb_substr(trim($w), 0, self::MAX_ITEM_LEN));
            if ($w === '') continue;
            if (++$n > self::MAX_ADD) break;
            $it = isset($exps[$w]) ? ['name' => $w, 'kind' => 'exp', 'src' => 'added', 'on' => true] : ['name' => self::skillName($w), 'kind' => 'skill', 'src' => 'added', 'on' => true];
            $found = false;
            foreach ($cond[$side] as &$cur) {
                if (self::key($cur) === self::key($it)) { $cur['on'] = true; $found = true; break; }
            }
            unset($cur);
            if (!$found) $cond[$side][] = $it;
        }
        return $cond;
    }

    // 画面のフォームの値を条件に反映する。$input は Request の入力（配列）
    // kw=1（新しいフォーム）：on[side][] にある鍵だけオン、無い鍵はオフ（外れたチェックはブラウザが送らないため、目印の kw で区別する）
    // kw が無い（古い形・空の POST）：off[side][] にある鍵だけオフ。off が無ければ前回の状態のまま
    public static function applyForm(array $cond, array $input): array
    {
        $sides = ['req', 'pref', 'confirm'];
        if (!empty($input['kw'])) {
            $on = (array) ($input['on'] ?? []);
            foreach ($sides as $side) {
                $keys = array_map('strval', (array) ($on[$side] ?? []));
                foreach ($cond[$side] as &$it) $it['on'] = in_array(self::key($it), $keys, true);
                unset($it);
            }
        } elseif (isset($input['off']) && is_array($input['off'])) {
            foreach (['req', 'pref'] as $side) {
                $off = array_map('strval', (array) ($input['off'][$side] ?? []));
                foreach ($cond[$side] as &$it) $it['on'] = !in_array(self::key($it), $off, true);
                unset($it);
            }
        }
        foreach ($sides as $side) $cond = self::addItems($cond, $side, (string) ($input['add_'.$side] ?? ''));
        return $cond;
    }

    // 「追加」ボタン用：条件だけを保存する（検索はせず、一覧のメンバーは作り直さない）
    public static function saveConditions($u, object $c, array $cond): int
    {
        return DB::transaction(function () use ($u, $c, $cond) {
            $json = json_encode($cond, JSON_UNESCAPED_UNICODE);
            $s = DB::table('case_searches')->where('case_id', $c->id)->where('created_by', $u->id)->lockForUpdate()->first();
            if ($s) {
                DB::table('case_searches')->where('id', $s->id)->update(['conditions' => $json, 'updated_at' => now_str()]);
                return (int) $s->id;
            }
            return (int) DB::table('case_searches')->insertGetId(['case_id' => $c->id, 'created_by' => $u->id, 'ses_id' => $u->role === 'ses' ? $u->ses_id : null,
                'conditions' => $json, 'created_at' => now_str(), 'updated_at' => now_str()]);
        });
    }

    // 「エンジニアに確認」に足す。改行だけで区切る（文の中の「、」では割らない）。技術マスタには通さない
    private static function addConfirm(array $cond, ?string $text): array
    {
        $n = 0;
        foreach (preg_split('/\R/u', (string) $text) as $w) {
            $w = trim(mb_substr(trim($w), 0, self::MAX_CONFIRM_LEN));
            if ($w === '') continue;
            if (++$n > self::MAX_ADD) break;
            $found = false;
            foreach ($cond['confirm'] as &$cur) {
                if (trim((string) $cur['name']) === $w) { $cur['on'] = true; $found = true; break; }
            }
            unset($cur);
            if (!$found) $cond['confirm'][] = ['name' => $w, 'kind' => 'text', 'src' => 'added', 'on' => true];
        }
        return $cond;
    }

    // 判定に使う（外していない）項目。confirm（エンジニアに確認）はメールに入れるだけで、点数・合否には使わない
    public static function activeItems(array $cond): array
    {
        $f = fn ($list) => array_values(array_filter($list, fn ($it) => !empty($it['on'])));
        return ['req' => $f($cond['req'] ?? []), 'pref' => $f($cond['pref'] ?? []), 'confirm' => $f($cond['confirm'] ?? [])];
    }

    // ---------- 対象の人と判定 ----------

    // 対象：今の案内と同じ人（使える人・有効な SES・応募済みを除く・稼働中も入る・unavailable は除く）。
    // own＝自社の人（SES・担当者は自分の会社）。sent＝この案件で案内を送った人の返事（無ければ null）
    // kind：proper（自社のエンジニア）／partner（他社のエンジニア）。パートナー側の検索は自社のエンジニアだけ（v0.16.0）
    public static function pool($u, object $c): array
    {
        $sent = DB::table('case_mails')->where('case_id', $c->id)->pluck('reply', 'engineer_id')->all();
        $mine = $u->role === 'ses' ? (int) $u->ses_id : null;
        $ownOnly = self::isPartnerSide($u, $c);
        $out = [];
        foreach (Matcher::forCase($c->id, true) as $m) {
            $e = $m['engineer'];
            if ($e->status === 'unavailable') continue;
            $sid = (int) $e->ses_id;
            $own = $mine !== null && $sid === $mine;
            if ($ownOnly && !$own) continue;
            $out[] = $m + ['own' => (bool) $own, 'kind' => $own ? 'proper' : 'partner', 'sent' => $sent[$e->id] ?? null];
        }
        return $out;
    }

    // パートナーの EG の担当者（v0.17.0）：その EG の担当者（engineers.staff_user_id）。使えない人が入っていれば会社の SES。いなければ null
    public static function staffFor(object $e): ?object
    {
        return EgStaff::of($e);
    }

    // 担当者の表示：企業名[担当者名]
    public static function staffLabel(object $staff, ?string $sesName = null): string
    {
        $company = $sesName ?? (string) DB::table('ses_companies')->where('id', $staff->ses_id)->value('name');
        return $company.'['.$staff->name.']';
    }

    // 担当者として選べる人か（その会社の SES・担当者で、使える人）
    public static function usableStaff(?object $x, int $sesId): bool
    {
        return EgStaff::usable($x, $sesId);
    }

    // この案件で、紹介の依頼を送った（送信済みの）エンジニアの id と、担当者の id
    private static function requested(int $caseId): array
    {
        $rows = DB::table('case_search_staff')->where('case_id', $caseId)->whereNotNull('sent_at')->get();
        $eids = [];
        foreach ($rows as $r) foreach ((array) json_decode((string) $r->engineer_ids, true) as $id) $eids[(int) $id] = true;
        return ['engineers' => $eids, 'staff' => $rows->pluck('staff_user_id')->map(fn ($x) => (int) $x)->flip()->all()];
    }

    // 必須のうち当たった数が 3/4 以上なら合格（必須が0項目なら全員合格）。尚可は足切りしない
    public static function passes(int $h, int $n): bool
    {
        return $n === 0 || $h * 4 >= $n * 3;
    }

    // 1人分の判定。$items は activeItems の形。
    // 項目ごとに hit（当たったか）・months（経験の月数）・label（画面に出す年数の文）。h・n＝必須、k・m＝尚可、monthsSum＝当たった必須の技術の月数の合計
    public static function judge(array $items, array $idx, array $exp): array
    {
        $out = ['req' => [], 'pref' => [], 'h' => 0, 'n' => 0, 'k' => 0, 'm' => 0, 'monthsSum' => 0];
        foreach (['req' => ['h', 'n'], 'pref' => ['k', 'm']] as $side => [$hitKey, $allKey]) {
            foreach ($items[$side] ?? [] as $it) {
                $row = ['name' => $it['name'], 'kind' => $it['kind'], 'hit' => false, 'months' => 0, 'label' => ''];
                if ($it['kind'] === 'exp') {
                    $row['hit'] = isset($exp[$it['name']]);
                } elseif ($s = Matcher::hasSkill($idx, $it['name'])) {
                    $row['hit'] = true;
                    $rank = (int) ($s['rank'] ?? -1);
                    $row['months'] = max((int) ($s['months'] ?? 0), $rank >= 0 ? $rank * 12 : 0);
                    $row['label'] = self::yearsLabel($row['months'], !empty($s['fromProject']), $s['years'] ?? null);
                }
                $out[$side][] = $row;
                $out[$allKey]++;
                if ($row['hit']) {
                    $out[$hitKey]++;
                    if ($side === 'req') $out['monthsSum'] += $row['months'];
                }
            }
        }
        return $out;
    }

    // 年数の見せ方：「約 3 年」。経歴だけで当たったときは「経歴から」
    private static function yearsLabel(int $months, bool $fromProject, ?string $years): string
    {
        $about = $months > 0 ? '約 '.num_disp(round($months / 12, 1)).' 年' : '';
        if ($fromProject) return $about ? "経歴から（$about）" : '経歴から';
        return $about ?: ($years ?: '');
    }

    // 希望単価が案件の単価＋10万円以上なら、検索の結果から外す（2026-11-23 TEZZO）。どちらかの単価が無ければ外さない
    const PRICE_OVER = 10;
    public static function priceOver(object $c, object $e): bool
    {
        if (!$c->price || !$e->desired_price) return false;
        return (float) $e->desired_price >= (float) $c->price + self::PRICE_OVER;
    }

    // 並び：自社 → 他社。それぞれ、当たった必須の技術の月数の合計 → h → 点数 → id の順
    public static function sortRows(array $rows): array
    {
        usort($rows, fn ($a, $b) => ($b['own'] <=> $a['own'])
            ?: ($b['j']['monthsSum'] <=> $a['j']['monthsSum'])
            ?: ($b['j']['h'] <=> $a['j']['h'])
            ?: ($b['score'] <=> $a['score'])
            ?: ($a['e']->id <=> $b['e']->id));
        return $rows;
    }

    // 対象の全員を判定して並べる。1人分は ['e', 'own', 'sent', 'score', 'j', 'pass']
    public static function evaluate($u, object $c, array $cond): array
    {
        $pool = self::pool($u, $c);
        $ids = array_map(fn ($m) => $m['engineer']->id, $pool);
        $idxs = Matcher::skillIndexMany($ids);
        $exps = SkillChecks::expMany($ids);
        $items = self::activeItems($cond);
        $rows = [];
        foreach ($pool as $m) {
            $e = $m['engineer'];
            $j = self::judge($items, $idxs[$e->id] ?? [], $exps[$e->id] ?? []);
            $over = self::priceOver($c, $e);
            $rows[] = ['e' => $e, 'own' => $m['own'], 'sent' => $m['sent'], 'score' => $m['score'], 'j' => $j, 'over' => $over, 'pass' => !$over && self::passes($j['h'], $j['n'])];
        }
        return self::sortRows($rows);
    }

    // 画面に出す名前：管理者と自社の人は氏名（所属の会社）。他社の人（パートナー）は「イニシャル（企業名[担当者名]）」。担当者がいなければ「イニシャル（企業名）」
    public static function displayName($u, array $m): string
    {
        $e = $m['e'];
        if ($u->role === 'admin' || $m['own']) return $e->name.'（'.$e->ses_name.'）';
        $who = !empty($m['staff']) ? self::staffLabel($m['staff'], $e->ses_name) : $e->ses_name;
        return ($e->initials ?: '—').'（'.$who.'）';
    }

    // ---------- 検索の保存と一覧 ----------

    // 検索を保存し（案件×作った人で1つ。もう一度なら書き直す）、一覧のメンバーを作り直す。検索の id を返す。
    // 残すのは、手で足した人と、個別メッセージを書いた人。チェックは合格者に入れ直す（送信済みは外す）
    public static function run($u, object $c, array $cond): int
    {
        $ev = self::evaluate($u, $c, $cond);
        return DB::transaction(function () use ($u, $c, $cond, $ev) {
            $json = json_encode($cond, JSON_UNESCAPED_UNICODE);
            $s = DB::table('case_searches')->where('case_id', $c->id)->where('created_by', $u->id)->first();
            if ($s) {
                $sid = (int) $s->id;
                DB::table('case_searches')->where('id', $sid)->update(['conditions' => $json, 'updated_at' => now_str()]);
            } elseif ($dup = DB::table('case_searches')->where('case_id', $c->id)->where('created_by', $u->id)->lockForUpdate()->first()) {
                $sid = (int) $dup->id; // ほぼ同時の2回目：先にできた検索を使う
                DB::table('case_searches')->where('id', $sid)->update(['conditions' => $json, 'updated_at' => now_str()]);
            } else {
                $sid = (int) DB::table('case_searches')->insertGetId(['case_id' => $c->id, 'created_by' => $u->id, 'ses_id' => $u->role === 'ses' ? $u->ses_id : null,
                    'conditions' => $json, 'created_at' => now_str(), 'updated_at' => now_str()]);
            }
            $old = DB::table('case_search_members')->where('search_id', $sid)->get()->keyBy('engineer_id');
            $keep = [];
            foreach ($ev as $row) {
                $eid = (int) $row['e']->id;
                $prev = $old[$eid] ?? null;
                $keepRow = $prev && ($prev->by_hand || trim((string) $prev->message) !== '');
                if (!$row['pass'] && !$keepRow) continue;
                $checked = $row['sent'] === null && ($row['pass'] || ($prev && $prev->checked));
                // 個別メッセージを書いた人は、条件に届かなくなっても一覧に残す（手で足した人と同じ扱い）
                if ($prev) DB::table('case_search_members')->where('id', $prev->id)->update(['checked' => $checked, 'by_hand' => $prev->by_hand || !$row['pass'], 'updated_at' => now_str()]);
                else DB::table('case_search_members')->insert(['search_id' => $sid, 'engineer_id' => $eid, 'checked' => $checked, 'by_hand' => false, 'created_at' => now_str(), 'updated_at' => now_str()]);
                $keep[] = $eid;
            }
            DB::table('case_search_members')->where('search_id', $sid)->whereNotIn('engineer_id', $keep)->delete();
            return $sid;
        });
    }

    // 一覧に出す行：メンバーのうち、合格した人と手で足した人（今の対象の中にいる人だけ）。
    // 戻り値：proper＝プロパー（自社のエンジニア）の行、partner＝パートナー（他社のエンジニア）の行、
    //   staff＝「エンジニア・担当者を追加」で足した担当者の行、failed＝条件に届かなかった人の数（一覧には出さない）、
    //   rows＝proper と partner をつなげたもの。行は evaluate の1人分＋ member・name（パートナーは staff・requested も）
    public static function rows($u, object $s): array
    {
        $c = DB::table('cases')->find($s->case_id);
        $ev = self::evaluate($u, $c, json_decode((string) $s->conditions, true) ?: []);
        $members = DB::table('case_search_members')->where('search_id', $s->id)->get()->keyBy('engineer_id');
        $req = self::requested((int) $c->id);
        $proper = [];
        $partner = [];
        $failed = 0;
        // パートナーの EG の担当者は、人数によらず決まった回数でまとめて読む
        $staffMap = EgStaff::ofMany(collect($ev)->filter(fn ($r) => !$r['own'] && isset($members[$r['e']->id]))->pluck('e'));
        foreach ($ev as $row) {
            $mem = $members[$row['e']->id] ?? null;
            if (!$row['pass'] && !($mem && $mem->by_hand)) { $failed++; continue; }
            if (!$mem) continue;
            $row['member'] = $mem;
            if ($row['own']) {
                $row['name'] = self::displayName($u, $row);
                $proper[] = $row;
                continue;
            }
            $row['staff'] = $staffMap[(int) $row['e']->id] ?? null;
            // 依頼済み：この案件で、その人を含む依頼を送った／その担当者に送った
            $row['requested'] = isset($req['engineers'][(int) $row['e']->id]) || ($row['staff'] && isset($req['staff'][(int) $row['staff']->id]));
            $row['name'] = self::displayName($u, $row);
            $partner[] = $row;
        }
        return ['proper' => $proper, 'partner' => $partner, 'rows' => array_merge($proper, $partner), 'staff' => self::staffRows($s), 'failed' => $failed];
    }

    // 「エンジニア・担当者を追加」で足した担当者の行（エンジニアの指定なし）。表示：（エンジニアの指定なし）企業名[担当者名]
    public static function staffRows(object $s): array
    {
        $out = [];
        $req = self::requested((int) $s->case_id);
        foreach (DB::table('case_search_staff')->where('search_id', $s->id)->where('by_hand', true)->orderBy('id')->get() as $st) {
            $user = DB::table('users')->find($st->staff_user_id);
            // エンジニアの指定があれば（パートナーの人をまとめた行と同じ担当者）、「指定なし」とは書かない
            $hasEngineers = !empty(json_decode((string) $st->engineer_ids, true));
            $out[] = ['st' => $st, 'user' => $user, 'label' => ($hasEngineers ? '（エンジニアの指定あり）' : '（エンジニアの指定なし）').($user ? self::staffLabel($user) : '—'),
                'requested' => !$st->sent_at && isset($req['staff'][(int) $st->staff_user_id])];
        }
        return $out;
    }

    // 1人分の行（詳細画面・送信用）。メンバーでない人・今の対象に入っていない人は null
    public static function rowOf($u, object $s, int $engineerId): ?array
    {
        $mem = DB::table('case_search_members')->where('search_id', $s->id)->where('engineer_id', $engineerId)->first();
        if (!$mem) return null;
        $c = DB::table('cases')->find($s->case_id);
        foreach (self::evaluate($u, $c, json_decode((string) $s->conditions, true) ?: []) as $row) {
            if ((int) $row['e']->id !== $engineerId) continue;
            $row['member'] = $mem;
            if (!$row['own']) $row['staff'] = EgStaff::ofMany([$row['e']])[(int) $row['e']->id] ?? null;
            $row['name'] = self::displayName($u, $row);
            return $row;
        }
        return null;
    }

    // 「一覧に足す」で探す。自社のエンジニア（氏名・愛称・イニシャル）と、他社の担当者（企業名・担当者の氏名）。
    // すでに一覧にいる人・案内済みの人は出さない。他社のエンジニアはここでは探せない。パートナー側の検索では自社のエンジニアだけ
    // 戻り：['type' => 'engineer'|'staff', 'name' => 表示名, 'e' => エンジニア | 'user' => 担当者]
    public static function findTargets($u, object $c, string $q, array $excludeEngineers = [], array $excludeStaff = []): array
    {
        $q = trim($q);
        if ($q === '') return [];
        $out = [];
        foreach (self::pool($u, $c) as $m) {
            $e = $m['engineer'];
            if (!$m['own'] || $m['sent'] !== null || in_array((int) $e->id, array_map('intval', $excludeEngineers), true)) continue;
            $hit = false;
            foreach ([$e->name, $e->nickname, $e->initials] as $h) if ($h !== null && $h !== '' && mb_stripos((string) $h, $q) !== false) { $hit = true; break; }
            if (!$hit) continue;
            $out[] = ['type' => 'engineer', 'e' => $e, 'name' => self::displayName($u, ['e' => $e, 'own' => true])];
            if (count($out) >= self::FIND_LIMIT) return $out;
        }
        if (self::isPartnerSide($u, $c)) return $out;
        $req = self::requested((int) $c->id);
        $users = DB::table('users as x')->join('ses_companies as s', 's.id', '=', 'x.ses_id')->whereIn('x.role', ['ses', 'admin'])->where('s.status', 'active')
            ->where('x.ses_id', '!=', (int) $u->ses_id)->orderBy('x.id')->select('x.*', 's.name as ses_name')->get();
        foreach ($users as $x) {
            if (!self::usableStaff($x, (int) $x->ses_id) || in_array((int) $x->id, array_map('intval', $excludeStaff), true) || isset($req['staff'][(int) $x->id])) continue;
            $hit = false;
            foreach ([$x->ses_name, $x->name] as $h) if ($h !== null && $h !== '' && mb_stripos((string) $h, $q) !== false) { $hit = true; break; }
            if (!$hit) continue;
            $out[] = ['type' => 'staff', 'user' => $x, 'name' => self::staffLabel($x, $x->ses_name)];
            if (count($out) >= self::FIND_LIMIT) break;
        }
        return $out;
    }

    // 他社の担当者を、一覧に足す（エンジニアの指定なし）。足したら true
    public static function addStaff($u, object $s, object $c, int $userId): bool
    {
        if (self::isPartnerSide($u, $c)) return false;
        $x = DB::table('users')->find($userId);
        if (!$x || !$x->ses_id || (int) $x->ses_id === (int) $u->ses_id || !self::usableStaff($x, (int) $x->ses_id)) return false;
        if (isset(self::requested((int) $c->id)['staff'][(int) $x->id])) return false;
        $row = DB::table('case_search_staff')->where('search_id', $s->id)->where('staff_user_id', $x->id)->first();
        if ($row) {
            if ($row->sent_at) return false;
            DB::table('case_search_staff')->where('id', $row->id)->update(['by_hand' => true, 'checked' => true, 'updated_at' => now_str()]);
            return true;
        }
        DB::table('case_search_staff')->insert(['search_id' => $s->id, 'case_id' => $c->id, 'ses_id' => $u->ses_id, 'created_by' => $u->id, 'partner_ses_id' => (int) $x->ses_id,
            'staff_user_id' => $x->id, 'by_hand' => true, 'checked' => true, 'created_at' => now_str(), 'updated_at' => now_str()]);
        return true;
    }

    // 手で足した担当者を外す（送る前のものだけ）
    public static function removeStaff(object $s, int $stid): bool
    {
        return DB::table('case_search_staff')->where('id', $stid)->where('search_id', $s->id)->where('by_hand', true)->whereNull('sent_at')->delete() > 0;
    }

    // 一覧に足す（探した範囲＝自社の対象の中の人だけ）。足したら true
    public static function addMember($u, object $s, object $c, int $engineerId): bool
    {
        $ok = false;
        foreach (self::pool($u, $c) as $m) if ($m['own'] && (int) $m['engineer']->id === $engineerId) { $ok = true; break; }
        if (!$ok) return false;
        $mem = DB::table('case_search_members')->where('search_id', $s->id)->where('engineer_id', $engineerId)->first();
        $sent = DB::table('case_mails')->where('case_id', $c->id)->where('engineer_id', $engineerId)->exists();
        if ($mem) DB::table('case_search_members')->where('id', $mem->id)->update(['by_hand' => true, 'checked' => !$sent, 'updated_at' => now_str()]);
        else DB::table('case_search_members')->insert(['search_id' => $s->id, 'engineer_id' => $engineerId, 'checked' => !$sent, 'by_hand' => true, 'created_at' => now_str(), 'updated_at' => now_str()]);
        return true;
    }

    // ---------- メール ----------

    // 必須に×がある人への確認の一文：「…に関しては○○様とご検討ください」。詳細画面で SES が見る注意書きで、メールには入れない。○○様＝そのエンジニア（他社の人はイニシャル）。×が無ければ null
    public static function noteFor($u, array $row): ?string
    {
        $bad = [];
        foreach ($row['j']['req'] as $it) if (!$it['hit']) $bad[] = $it['name'];
        if (!$bad) return null;
        $who = ($row['own'] ? $row['e']->name : ($row['e']->initials ?: 'エンジニア')).'様';
        return implode('、', $bad).'に関しては'.$who.'とご検討ください。';
    }

    // 詳細画面の見本に使うメールの雛形（今の案内の本文に、尚可・個別メッセージの差し込みを足したもの。「全員へのメッセージ」は v0.16.0 でやめた）
    public static function defaultBody(): string
    {
        return "{呼び名} さん\n\nお疲れさまです。{送り主}です。\nあなたに合いそうな案件が届いたので、ご案内します。\n\n"
            ."■案件：{案件名}\n■案件 ID：{案件ID}\n■単価：{単価}\n■開始：{開始}\n■場所・働き方：{場所}／{働き方}\n■必須：{必須}\n■尚可：{尚可}\n\n{確認事項の節}\n\n■あなたに合う点：{合う点}\n\n"
            ."{個別メッセージ}\n\n"
            ."ご興味があれば、このメールに返信してください（条件で気になる点があれば、それも一緒に）。\n見送る場合も、理由をひとこと返信いただけると、次のご案内の参考にします。";
    }

    // 「エンジニアに確認」の節（チェックした項目だけ。0件なら空）。返事が必須であることを書く
    // $byLink：返信が届かない（受信が使えない他社のエンジニアあて）ので、返事のリンクの「ひとこと」で答えてもらうとき
    public static function confirmBlock(array $names, bool $byLink = false): string
    {
        $names = array_values(array_filter(array_map('trim', $names), fn ($n) => $n !== ''));
        if (!$names) return '';
        $how = $byLink ? '下の返事のリンクの「ひとこと」で' : 'このメールへの返信で';
        return "■ご確認ください（必ずご返信ください）\n"
            .implode("\n", array_map(fn ($n) => '・'.$n, $names))
            ."\n上の項目について、{$how}必ずお答えください（お答えがないと、ご提案を進められません）。";
    }

    // 雛形の差し込みを済ませる。$vals（目印 => 中身）は目印にしておき、$render（今の案内の差し込み：呼び名・案件名など）を先に通す。
    // メッセージの中の {…} は差し込みにしない。中身が空の目印だけの行は消す
    private static function fill(string $tpl, array $vals, callable $render): string
    {
        $marks = [];
        $i = 0;
        foreach ($vals as $k => $v) {
            $m = "\u{E000}".$i++."\u{E001}";
            $tpl = str_replace($k, $m, $tpl);
            $marks[$m] = $v;
        }
        $text = $render($tpl);
        $lines = [];
        foreach (explode("\n", $text) as $ln) {
            if (isset($marks[trim($ln)]) && $marks[trim($ln)] === '') continue; // 空の差し込みだけの行は消す
            $lines[] = $ln;
        }
        $text = strtr(implode("\n", $lines), $marks);
        return preg_replace("/\n{3,}/", "\n\n", $text);
    }

    // 送り主の名前（今の案内と同じ）
    private static function fromName($u): string
    {
        if ($u->role === 'ses') return DB::table('ses_companies')->where('id', $u->ses_id)->value('name').' '.$u->name;
        return admin_sender($u) ? admin_sender($u).' '.$u->name : (string) setting('operator_name');
    }

    // 1人分の件名・本文。空の差し込みは、その行ごと詰める
    public static function body($u, object $c, object $s, array $row): array
    {
        $cond = json_decode((string) $s->conditions, true) ?: [];
        $items = self::activeItems($cond);
        $names = fn (array $list) => implode('、', array_column($list, 'name'));
        $fit = [];
        foreach (['req', 'pref'] as $side) foreach ($row['j'][$side] as $it) if ($it['hit']) $fit[] = $it['name'].($it['label'] ? '（'.$it['label'].'）' : '');
        $confirm = array_column($items['confirm'], 'name');
        $vals = [
            '{個別メッセージ}' => self::clean($row['member']->message ?? null),
            '{必須}' => $names($items['req']) ?: '—', '{尚可}' => $names($items['pref']), '{合う点}' => implode('、', $fit) ?: '—',
            '{確認事項の節}' => self::confirmBlock($confirm, !$row['own'] && !Inbound::enabled()), // 受信が使えない他社のエンジニアは、返信が届かない
        ];
        $tpl = self::defaultBody();
        if ($vals['{尚可}'] === '') $tpl = str_replace("■尚可：{尚可}\n", '', $tpl); // 尚可が無ければ、その行ごと無くす
        $callee = $row['own'] ? $row['e'] : (object) array_merge((array) $row['e'], ['nickname' => null]); // 他社の人は愛称を出さず、イニシャルで呼ぶ
        $plain = (object) array_merge((array) $c, ['confirm_items' => '']); // 確認事項は上の節で出すので、render の一行の確認事項は使わない
        $text = self::fill($tpl, $vals, fn ($t) => CaseMail::render($t, $plain, $callee, ['reasons' => []], (bool) $row['own'], self::fromName($u)));
        // パートナー側の検索は、プロバイダーからの金額ではなく、作成画面と同じ「伝えられた単価」にそろえる
        if (self::isPartnerSide($u, $c)) {
            $price = self::properPrice($u, $c, $s);
            $text = preg_replace_callback('/^■単価：.*$/mu', fn () => '■単価：'.$price, $text, 1) ?? $text;
        }
        return ['subject' => CaseMail::defaultSubject($c), 'body' => $text, 'confirm' => $confirm];
    }

    private static function clean(?string $v): string
    {
        return trim(str_replace("\r\n", "\n", (string) $v));
    }

    // ---------- 作成画面（v0.16.0）：プロパー → 担当者の順に、本文を直して送る ----------

    // 本文の最初の「■単価：」の行の中身（前後の空白を取る。100字まで）。無ければ null
    public static function priceTextOf(string $body): ?string
    {
        if (!preg_match('/^■単価：(.*)$/mu', str_replace("\r\n", "\n", $body), $m)) return null;
        $v = trim(mb_substr(trim($m[1]), 0, self::MAX_PRICE_LEN));
        return $v === '' ? null : $v;
    }

    // 案件の項目（案件名・ID・開始・場所・必須・尚可）を、はじめから文字にして本文に入れるための材料
    private static function caseLines($u, object $c, object $s, bool $byLink = false): array
    {
        $items = self::activeItems(json_decode((string) $s->conditions, true) ?: []);
        $names = fn (array $list) => implode('、', array_column($list, 'name'));
        return ['title' => $c->title, 'code' => ($c->code ?? null) ?: '—', 'start' => $c->start_date ? fmt_date($c->start_date) : '相談',
            'place' => ($c->location ?: '—').'／'.lbl('workStyle', $c->work_style), 'req' => $names($items['req']) ?: '—', 'pref' => $names($items['pref']),
            'confirm' => self::confirmBlock(array_column($items['confirm'], 'name'), $byLink), 'confirmNames' => array_column($items['confirm'], 'name')];
    }

    // プロパーの本文の単価：受注 SES なら案件の金額（無ければ「相談」）。パートナー側の検索なら、紹介の依頼で伝えられた単価（無ければ「相談」）
    public static function properPrice($u, object $c, object $s): string
    {
        if (self::isPartnerSide($u, $c)) {
            $t = $s->request_id ? DB::table('case_search_staff')->where('id', $s->request_id)->value('price_text') : null;
            return $t ?: '相談';
        }
        return $c->price ? man($c->price) : '相談';
    }

    // プロパーへのメールの雛形。案件の項目は文字にして入れ（直せる）、1人ずつ変わる {呼び名}・{合う点}・{個別メッセージ} だけ差し込みのまま
    public static function properTemplate($u, object $c, object $s): string
    {
        $l = self::caseLines($u, $c, $s);
        $text = "{呼び名} さん\n\nお疲れさまです。".self::fromName($u)."です。\nあなたに合いそうな案件が届いたので、ご案内します。\n\n"
            ."■案件：{$l['title']}\n■案件 ID：{$l['code']}\n■単価：".self::properPrice($u, $c, $s)."\n■開始：{$l['start']}\n■場所・働き方：{$l['place']}\n■必須：{$l['req']}\n"
            .($l['pref'] !== '' ? "■尚可：{$l['pref']}\n" : '')
            ."\n".($l['confirm'] !== '' ? $l['confirm']."\n\n" : '')
            ."■あなたに合う点：{合う点}\n\n{個別メッセージ}\n\n"
            ."ご興味があれば、このメールに返信してください（条件で気になる点があれば、それも一緒に）。\n見送る場合も、理由をひとこと返信いただけると、次のご案内の参考にします。";
        return $text;
    }

    // 担当者へのメールの雛形（4.4）。{宛名}・{依頼するエンジニア} だけ差し込みのまま
    public static function staffTemplate($u, object $c, object $s): string
    {
        $l = self::caseLines($u, $c, $s);
        return "{宛名} 様\n\nお疲れさまです。".self::fromName($u)."です。\nPARK のグループの案件について、貴社のエンジニアのご紹介をお願いします。\n\n"
            ."■案件：{$l['title']}\n■案件 ID：{$l['code']}\n■単価：".($c->price ? man($c->price) : '相談')."\n■開始：{$l['start']}\n■場所・働き方：{$l['place']}\n■必須：{$l['req']}\n"
            .($l['pref'] !== '' ? "■尚可：{$l['pref']}\n" : '')
            ."\n■ご紹介をお願いしたい方（貴社のエンジニア）\n{依頼するエンジニア}\n\n"
            ."上の方への案内や、ほかの方の検索は、PARK の案件の画面からできます。\nここに書いた単価が、貴社から案内するときの単価になります。";
    }

    // プロパー1人分の本文：{呼び名}・{合う点}・{個別メッセージ} を差し替える（個別メッセージが空なら、その行ごと消す）
    public static function renderProper(string $body, array $row): string
    {
        $fit = [];
        foreach (['req', 'pref'] as $side) foreach ($row['j'][$side] as $it) if ($it['hit']) $fit[] = $it['name'].($it['label'] ? '（'.$it['label'].'）' : '');
        $vals = ['{合う点}' => implode('、', $fit) ?: '—', '{個別メッセージ}' => self::clean($row['member']->message ?? null)];
        $call = CaseMail::callName($row['e'], true);
        return self::fill(str_replace("\r\n", "\n", $body), $vals, fn ($t) => str_replace('{呼び名}', $call, $t));
    }

    // 担当者1人分の本文：{宛名}（企業名 氏名）・{依頼するエンジニア}（イニシャルを「・K.A」の形で1行ずつ）を差し替える
    public static function renderStaff(string $body, object $user, array $engineerIds): string
    {
        $lines = [];
        foreach ($engineerIds as $id) {
            $ini = DB::table('engineers')->where('id', (int) $id)->value('initials');
            $lines[] = '・'.($ini ?: '（イニシャル未登録）');
        }
        $list = $lines ? implode("\n", $lines) : '（指定なし。貴社のエンジニアからお探しください）';
        return self::fill(str_replace("\r\n", "\n", $body), ['{依頼するエンジニア}' => $list, '{宛名}' => staff_label($user)], fn ($t) => $t);
    }

    // 作成画面に出す、送るプロパー（チェック済み・未送信の自社のエンジニア）と追加の宛先（チェック済み・未送信）
    public static function properTargets($u, object $s): array
    {
        $rows = array_values(array_filter(self::rows($u, $s)['proper'], fn ($r) => $r['member']->checked && $r['sent'] === null));
        $extras = DB::table('case_search_extras')->where('search_id', $s->id)->where('checked', true)->whereNull('sent_at')->orderBy('id')->get();
        return ['rows' => $rows, 'extras' => $extras];
    }

    // 送る順番に入っている、未送信の担当者（辞退していない）。id の順
    public static function batch(object $s): \Illuminate\Support\Collection
    {
        return DB::table('case_search_staff')->where('search_id', $s->id)->where('in_batch', true)->whereNull('sent_at')->whereNull('declined_at')->orderBy('id')->get();
    }

    // 「エンジニア・担当者へメールを作成」を押したとき：チェックした担当者の束を作り直す。
    // パートナーのエンジニアは担当者ごとにまとめ、手で足した担当者（spick）も入れる。依頼済み・担当者なしの行は入れない
    public static function buildBatch($u, object $c, object $s): void
    {
        if (self::isPartnerSide($u, $c)) return;
        DB::transaction(function () use ($u, $c, $s) {
            DB::table('case_search_staff')->where('search_id', $s->id)->whereNull('sent_at')->update(['in_batch' => false, 'updated_at' => now_str()]);
            $req = self::requested((int) $c->id);
            $groups = [];
            foreach (self::rows($u, $s)['partner'] as $row) {
                if (!$row['member']->checked || $row['sent'] !== null || $row['requested'] || !$row['staff']) continue;
                $groups[(int) $row['staff']->id][] = (int) $row['e']->id;
            }
            foreach ($groups as $userId => $eids) {
                $user = DB::table('users')->find($userId);
                $json = json_encode(array_values($eids));
                $cur = DB::table('case_search_staff')->where('search_id', $s->id)->where('staff_user_id', $userId)->first();
                if ($cur) {
                    if (!$cur->sent_at) DB::table('case_search_staff')->where('id', $cur->id)->update(['engineer_ids' => $json, 'checked' => true, 'in_batch' => true, 'updated_at' => now_str()]);
                } else {
                    // 同時に押されても、同じ担当者の行が重なって落ちないようにする
                    DB::table('case_search_staff')->insertOrIgnore(['search_id' => $s->id, 'case_id' => $c->id, 'ses_id' => $u->ses_id, 'created_by' => $u->id, 'partner_ses_id' => (int) $user->ses_id,
                        'staff_user_id' => $userId, 'engineer_ids' => $json, 'by_hand' => false, 'checked' => true, 'in_batch' => true, 'created_at' => now_str(), 'updated_at' => now_str()]);
                }
            }
            // 手で足した担当者（チェック済み・未送信・依頼済みでない）。EG をまとめた行と同じ担当者なら、その行（上で in_batch 済み）のまま
            foreach (DB::table('case_search_staff')->where('search_id', $s->id)->where('by_hand', true)->where('checked', true)->whereNull('sent_at')->get() as $st) {
                if (isset($req['staff'][(int) $st->staff_user_id])) continue;
                DB::table('case_search_staff')->where('id', $st->id)->update(['in_batch' => true, 'updated_at' => now_str()]);
            }
        });
    }

    // プロパーのチェック済み・未送信の人に送る。$subject・$body は作成画面で直した共通の本文。
    // 今この人が送ってよい人か（対象の中・自社・案内済みでない・この検索のメンバー）でもう一度しぼる。戻り：送った人数
    public static function sendProper($u, object $c, object $s, string $subject, string $body): int
    {
        $members = DB::table('case_search_members')->where('search_id', $s->id)->where('checked', true)->get()->keyBy('engineer_id');
        $price = self::priceTextOf($body);
        $confirm = array_column(self::activeItems(json_decode((string) $s->conditions, true) ?: [])['confirm'], 'name');
        $items = [];
        foreach (self::evaluate($u, $c, json_decode((string) $s->conditions, true) ?: []) as $row) {
            $eid = (int) $row['e']->id;
            if (!isset($members[$eid]) || !$row['own'] || $row['sent'] !== null) continue;
            $items[$eid] = ['subject' => $subject, 'body' => self::renderProper($body, $row + ['member' => $members[$eid]]), 'confirm' => $confirm, 'price_text' => $price];
        }
        if (!$items) return 0;
        // 同時に2回押されても、同じ人に2通出さない：案件の行を押さえてから、送信済みを見直して送る
        $mode = self::isPartnerSide($u, $c) ? self::MODE_PARTNER : self::MODE;
        $n = DB::transaction(function () use ($u, $c, $items, $mode) {
            DB::table('cases')->where('id', $c->id)->lockForUpdate()->first();
            return CaseMail::send($u, $c, $items, $mode);
        });
        DB::table('case_searches')->where('id', $s->id)->update(['last_sent_at' => now_str(), 'proper_subject' => null, 'proper_body' => null, 'updated_at' => now_str()]);
        N::audit($u->id, 'case-search-send', "{$c->title} → {$n}名");
        return $n;
    }

    // 担当者に送る（3.7）。$staffIds は case_search_staff の id。戻り：['sent' => 送った数, 'skipped' => 飛ばした数]
    public static function sendStaff($u, object $c, object $s, array $staffIds, string $subject, string $body): array
    {
        $price = self::priceTextOf($body);
        $sent = 0;
        $skipped = 0;
        DB::transaction(function () use ($u, $c, $s, $staffIds, $subject, $body, $price, &$sent, &$skipped) {
            DB::table('cases')->where('id', $c->id)->lockForUpdate()->first(); // 同時に2回押されても、同じ担当者に2通出さない
            foreach (array_values(array_unique(array_map('intval', $staffIds))) as $id) {
                $st = DB::table('case_search_staff')->where('id', $id)->where('search_id', $s->id)->first();
                $user = $st ? DB::table('users')->find($st->staff_user_id) : null;
                $dup = $st && DB::table('case_search_staff')->where('case_id', $c->id)->where('staff_user_id', $st->staff_user_id)->whereNotNull('sent_at')->exists(); // ほかの検索で送った
                if (!$st || $st->sent_at || $st->declined_at || $dup || !self::usableStaff($user, (int) $st->partner_ses_id)) { $skipped++; continue; }
                // 先に「送った」印を付ける。0行なら、ほかで送っているので送らない
                $claimed = DB::table('case_search_staff')->where('id', $st->id)->whereNull('sent_at')->update(['sent_at' => now_str(), 'sent_by' => $u->id, 'in_batch' => false, 'checked' => false, 'updated_at' => now_str()]);
                if (!$claimed) { $skipped++; continue; }
                $text = self::renderStaff($body, $user, (array) json_decode((string) $st->engineer_ids, true));
                $text .= "\n\n▼案件の画面（紹介の依頼）\n".url("/cases/{$c->id}#referral")."\n※このメールに返信しても届きません。ご返事（案内・推薦・辞退）は上の画面からお願いします。";
                // Reply-To は付けない：他社の人に個人のアドレスを出さない。返事は PARK の画面で受ける
                $outbox = N::queueMail($user->email, $user->name, $subject, $text, 'case-referral', null, null);
                N::notify((int) $user->id, 'エンジニアの紹介の依頼：'.$c->title.Ids::tag($c->code ?? null), '案件「'.$c->title.'」で、貴社のエンジニアのご紹介をお願いしています。案件の画面の「紹介の依頼」から、ご確認ください。',
                    "/cases/{$c->id}#referral", false, 'case-referral', 'ses');
                DB::table('case_search_staff')->where('id', $st->id)->update(['subject' => $subject, 'body' => $text, 'price_text' => $price, 'outbox_id' => $outbox]);
                $sent++;
            }
        });
        if ($sent) {
            DB::table('case_searches')->where('id', $s->id)->update(['last_sent_at' => now_str(), 'updated_at' => now_str()]);
            N::audit($u->id, 'case-referral-send', ($c->code ?? $c->id)." → 担当者 {$sent}名"); // 名前・アドレスは書かない
        }
        return ['sent' => $sent, 'skipped' => $skipped];
    }

    // 担当者の紹介の依頼を辞退する（依頼を受けた会社の SES・担当者）。依頼した人にお知らせ。戻り：辞退を記録したら true
    public static function decline($u, object $c, object $st, ?string $note): bool
    {
        $claimed = DB::table('case_search_staff')->where('id', $st->id)->whereNotNull('sent_at')->whereNull('declined_at')
            ->update(['declined_at' => now_str(), 'declined_by' => $u->id, 'declined_note' => $note === null ? null : mb_substr($note, 0, self::MAX_DECLINE_LEN), 'in_batch' => false, 'updated_at' => now_str()]);
        if (!$claimed) return false;
        N::audit($u->id, 'case-referral-decline', ($c->code ?? $c->id).' 辞退');
        $company = (string) DB::table('ses_companies')->where('id', $u->ses_id)->value('name');
        N::notify((int) $st->sent_by, "紹介の依頼の辞退：{$c->title}".Ids::tag($c->code ?? null), "{$company} の担当者が、今回は紹介できる人がいないと答えました。".($note ? "\nひとこと：".mb_substr($note, 0, self::MAX_DECLINE_LEN) : ''),
            "/cases/{$c->id}#mails", true, 'case-referral-decline', 'ses');
        return true;
    }

    // 紹介の依頼で、自分の検索を始める（パートナーの担当者）：依頼元の条件を写して、自分の検索を作る／書き直す。依頼されたエンジニア（自社で今も対象の人）にチェックを入れる。検索の id を返す
    public static function startFromRequest($u, object $c, object $st): int
    {
        $from = DB::table('case_searches')->find($st->search_id);
        $cond = $from ? (string) $from->conditions : json_encode(self::conditions($c, null), JSON_UNESCAPED_UNICODE);
        $sid = DB::transaction(function () use ($u, $c, $st, $cond) {
            $s = DB::table('case_searches')->where('case_id', $c->id)->where('created_by', $u->id)->lockForUpdate()->first();
            if ($s) {
                DB::table('case_searches')->where('id', $s->id)->update(['conditions' => $cond, 'request_id' => $st->id, 'updated_at' => now_str()]);
                return (int) $s->id;
            }
            return (int) DB::table('case_searches')->insertGetId(['case_id' => $c->id, 'created_by' => $u->id, 'ses_id' => $u->ses_id, 'conditions' => $cond, 'request_id' => $st->id,
                'created_at' => now_str(), 'updated_at' => now_str()]);
        });
        $s = DB::table('case_searches')->find($sid);
        $cond = json_decode((string) $s->conditions, true) ?: [];
        $sid = self::run($u, $c, $cond); // 自社のエンジニアだけで評価して、メンバーを作り直す
        foreach ((array) json_decode((string) $st->engineer_ids, true) as $eid) self::addMember($u, DB::table('case_searches')->find($sid), $c, (int) $eid);
        return $sid;
    }

    // ---------- 追加の宛先（PARK に登録していない人。v0.15.0） ----------

    // 1行に1件：「アドレス」か「名前 <アドレス>」。足した数と、足さなかった理由を返す
    public static function addExtras($u, object $s, object $c, ?string $text): array
    {
        $added = 0;
        $skipped = [];
        $over = 0;      // 1回の上限・1検索の上限を超えた件数
        $have = DB::table('case_search_extras')->where('search_id', $s->id)->count();
        $seen = DB::table('case_search_extras')->where('search_id', $s->id)->pluck('email')->map(fn ($x) => strtolower($x))->all();
        $sentBefore = DB::table('case_search_extras')->where('case_id', $c->id)->whereNotNull('sent_at')->pluck('email')->map(fn ($x) => strtolower($x))->all();
        foreach (preg_split('/\R/u', (string) $text) as $line) {
            $line = trim($line);
            if ($line === '') continue;
            $name = '';
            $addr = $line;
            if (preg_match('/^(.*?)<([^<>]+)>\s*$/u', $line, $m)) { $name = (string) preg_replace('/^[\s"\'“”「」]+|[\s"\'“”「」]+$/u', '', $m[1]); $addr = trim($m[2]); } // 名前の前後の引用符は取る
            $addr = strtolower($addr);
            if (mb_strlen($addr) > 254 || !filter_var($addr, FILTER_VALIDATE_EMAIL)) { $skipped[] = "「{$line}」はメールアドレスの形ではありません。"; continue; }
            if (in_array($addr, $seen, true)) { $skipped[] = "{$addr} は、この検索にもうあります。"; continue; }
            if (in_array($addr, $sentBefore, true)) { $skipped[] = "{$addr} には、この案件でもう送っています。"; continue; }
            if (self::isEngineerEmail($addr)) { $skipped[] = "{$addr} は、登録しているエンジニアです。「エンジニア・担当者を追加」から足してください。"; continue; }
            if (self::isParkAddress($addr)) { $skipped[] = "{$addr} は、PARK のアドレスなので足せません。"; continue; }
            if ($added >= self::MAX_EXTRA_ADD || $have + $added >= self::MAX_EXTRAS) { $over++; continue; }
            try {
                DB::table('case_search_extras')->insert(['search_id' => $s->id, 'case_id' => $c->id, 'ses_id' => $u->role === 'ses' ? $u->ses_id : null, 'created_by' => $u->id,
                    'email' => $addr, 'name' => $name === '' ? null : mb_substr($name, 0, 100), 'checked' => true, 'created_at' => now_str(), 'updated_at' => now_str()]);
            } catch (\Illuminate\Database\QueryException $e) {
                $skipped[] = "{$addr} は、この検索にもうあります。"; // 同時に同じ宛先が入った（unique に当たった）
                continue;
            }
            $seen[] = $addr;
            $added++;
        }
        if ($over) $skipped[] = "1回に足せるのは".self::MAX_EXTRA_ADD."件まで、1つの検索には".self::MAX_EXTRAS."件までです（{$over}件は足していません）。";
        if ($added) N::audit($u->id, 'case-search-extra-add', ($c->code ?? $c->id)." 検索#{$s->id} {$added}件"); // アドレスは記録の文に書かない
        return ['added' => $added, 'skipped' => $skipped];
    }

    // 登録しているエンジニアのアドレスか（エンジニア本人の登録と、ログインのアドレス）
    private static function isEngineerEmail(string $addr): bool
    {
        return DB::table('engineers')->whereRaw('lower(email) = ?', [$addr])->exists()
            || DB::table('users')->whereRaw('lower(email) = ?', [$addr])->whereNotNull('engineer_id')->exists();
    }

    // PARK 自身の送信元・返信先か（同じドメインの park+… の形も）
    private static function isParkAddress(string $addr): bool
    {
        $own = array_filter([strtolower((string) config('mail.from.address')), strtolower((string) config('park.inbound.reply_base'))]);
        if (in_array($addr, $own, true)) return true;
        $at = strrpos($addr, '@');
        foreach ($own as $o) {
            $p = strrpos($o, '@');
            if ($p === false || $at === false) continue;
            if (substr($addr, $at + 1) === substr($o, $p + 1) && str_starts_with(substr($addr, 0, $at), substr($o, 0, $p).'+')) return true;
        }
        return false;
    }

    // 追加の宛先1件分の件名・本文。プロパーと同じ本文を使う：{呼び名} は名前（無ければ「ご担当者」）、{合う点}・{個別メッセージ} の行は消す
    // $subject・$body が無ければ、プロパーの雛形（作成画面で直す前の形）を使う
    public static function extraBody($u, object $c, object $s, object $x, ?string $subject = null, ?string $body = null): array
    {
        $body = str_replace("\r\n", "\n", $body ?? self::properTemplate($u, $c, $s));
        $body = preg_replace('/^.*\{合う点\}.*\n?/mu', '', $body) ?? $body;
        $body = preg_replace('/^[ \x{3000}]*\{個別メッセージ\}[ \x{3000}]*\n?/mu', '', $body) ?? $body;
        $body = $x->name ? str_replace('{呼び名} さん', $x->name.' 様', $body) : str_replace('{呼び名} さん', 'ご担当者 様', $body);
        $body = str_replace('{呼び名}', $x->name ?: 'ご担当者', $body);
        return ['subject' => $subject ?? CaseMail::defaultSubject($c), 'body' => preg_replace("/\n{3,}/", "\n\n", $body), 'confirm' => array_column(self::activeItems(json_decode((string) $s->conditions, true) ?: [])['confirm'], 'name')];
    }

    // チェックした未送信の追加の宛先に送る。$subject・$body は作成画面で直した共通の本文（無ければ雛形）。
    // 戻り：['sent' => 送った件数, 'dup' => この案件で送っていたので飛ばした件数, 'noReply' => 返信先が無く送らなかったか]
    public static function sendExtras($u, object $c, object $s, ?string $subject = null, ?string $body = null): array
    {
        $sesId = $u->role === 'ses' ? (int) $u->ses_id : null;
        $dup = 0;
        $noReply = false;
        $n = DB::transaction(function () use ($u, $c, $s, $sesId, $subject, $body, &$dup, &$noReply) {
            DB::table('cases')->where('id', $c->id)->lockForUpdate()->first(); // 同時に2回押されても、同じ宛先に2通出さない
            // 受信が使えず、送った担当者のアドレスも無いと、返信がどこにも届かない。送らない
            if (!Inbound::enabled() && !$u->email) {
                $noReply = DB::table('case_search_extras')->where('search_id', $s->id)->where('checked', true)->whereNull('sent_at')->exists();
                return 0;
            }
            $rows = DB::table('case_search_extras')->where('search_id', $s->id)->where('checked', true)->whereNull('sent_at')->orderBy('id')->lockForUpdate()->get();
            $n = 0;
            foreach ($rows as $x) {
                // ほかの検索で、この案件にもう送ったアドレスには送らない（チェックは外す）
                $sentSame = DB::table('case_search_extras')->where('case_id', $c->id)->whereRaw('lower(email) = ?', [strtolower($x->email)])->whereNotNull('sent_at')->exists();
                if ($sentSame) {
                    DB::table('case_search_extras')->where('id', $x->id)->update(['checked' => false, 'updated_at' => now_str()]);
                    $dup++;
                    continue;
                }
                // 先に「送った」印を付ける。0行なら、ほかで送っているので送らない
                $claimed = DB::table('case_search_extras')->where('id', $x->id)->whereNull('sent_at')->update(['sent_at' => now_str(), 'sent_by' => $u->id, 'checked' => false, 'updated_at' => now_str()]);
                if (!$claimed) continue;
                $it = self::extraBody($u, $c, $s, $x, $subject, $body);
                $inbound = Inbound::enabled();
                [$tokenId, $token] = ReplyTokens::issue(self::EXTRA_KIND, (int) $c->id, $sesId, null, $inbound);
                // 共通の $body・$subject は書き換えない（次の人の本文に前の人の名前が入るため）
                $text = $it['body']."\n\n※このメールに返信すると、".($inbound ? '担当者と PARK に届きます。' : '担当者に届きます。')
                    ."\n\n――――――――――\n［システム伝達項目・消さないでください］\n案内番号：PARK-".ReplyTokens::tagOf($token)."\n案件 ID：".(($c->code ?? null) ?: '—');
                $replyTo = $inbound ? Inbound::replyAddress($token).($u->email ? ', '.$u->email : '') : ($u->email ?: null);
                $mark = ReplyTokens::subjectMark($token);
                $subj = (string) $it['subject'];
                $sendSubject = str_contains($subj, $mark) ? $subj : rtrim($subj).' '.$mark;
                $outbox = N::queueMail($x->email, $x->name ?: '', $sendSubject, $text, 'case-search-extra', $replyTo, null);
                DB::table('case_search_extras')->where('id', $x->id)->update(['reply_token_id' => $tokenId, 'outbox_id' => $outbox, 'subject' => $subj, 'body' => $text,
                    'confirm_items' => $it['confirm'] ? implode("\n", $it['confirm']) : null]);
                ReplyTokens::attach($tokenId, ['search_extra_id' => $x->id, 'outbox_id' => $outbox, 'message_id' => $outbox ? DB::table('mail_outbox')->where('id', $outbox)->value('message_id') : null]);
                $n++;
            }
            return $n;
        });
        if ($n) {
            DB::table('case_searches')->where('id', $s->id)->update(['last_sent_at' => now_str(), 'updated_at' => now_str()]);
            N::audit($u->id, 'case-search-extra-send', "{$c->title} → 追加の宛先 {$n}件");
        }
        return ['sent' => $n, 'dup' => $dup, 'noReply' => $noReply];
    }
}
