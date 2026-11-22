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

    // ---------- 権限 ----------

    public static function canUse($u, object $c): bool
    {
        return CaseMail::canSend($u, $c);
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
    public static function pool($u, object $c): array
    {
        $sent = DB::table('case_mails')->where('case_id', $c->id)->pluck('reply', 'engineer_id')->all();
        $mine = $u->role === 'ses' ? (int) $u->ses_id : null;
        $out = [];
        foreach (Matcher::forCase($c->id, true) as $m) {
            $e = $m['engineer'];
            if ($e->status === 'unavailable') continue;
            $sid = (int) $e->ses_id;
            $own = $mine !== null && $sid === $mine;
            $out[] = $m + ['own' => (bool) $own, 'sent' => $sent[$e->id] ?? null];
        }
        return $out;
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
            $rows[] = ['e' => $e, 'own' => $m['own'], 'sent' => $m['sent'], 'score' => $m['score'], 'j' => $j, 'pass' => self::passes($j['h'], $j['n'])];
        }
        return self::sortRows($rows);
    }

    // 画面に出す名前：管理者と自社の人は氏名、他社の人（SES・担当者から見たとき）はイニシャル。後ろに所属の会社
    public static function displayName($u, array $m): string
    {
        $e = $m['e'];
        $name = ($u->role === 'admin' || $m['own']) ? $e->name : ($e->initials ?: '—');
        return $name.'（'.$e->ses_name.'）';
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
    // 戻り値：rows＝行（evaluate の1人分＋ member・name）、failed＝条件に届かなかった人の数（一覧には出さない）
    public static function rows($u, object $s): array
    {
        $c = DB::table('cases')->find($s->case_id);
        $ev = self::evaluate($u, $c, json_decode((string) $s->conditions, true) ?: []);
        $members = DB::table('case_search_members')->where('search_id', $s->id)->get()->keyBy('engineer_id');
        $rows = [];
        $failed = 0;
        foreach ($ev as $row) {
            $mem = $members[$row['e']->id] ?? null;
            if (!$row['pass'] && !($mem && $mem->by_hand)) { $failed++; continue; }
            if (!$mem) continue;
            $rows[] = $row + ['member' => $mem, 'name' => self::displayName($u, $row)];
        }
        return ['rows' => $rows, 'failed' => $failed];
    }

    // 1人分の行（詳細画面・送信用）。メンバーでない人・今の対象に入っていない人は null
    public static function rowOf($u, object $s, int $engineerId): ?array
    {
        $mem = DB::table('case_search_members')->where('search_id', $s->id)->where('engineer_id', $engineerId)->first();
        if (!$mem) return null;
        $c = DB::table('cases')->find($s->case_id);
        foreach (self::evaluate($u, $c, json_decode((string) $s->conditions, true) ?: []) as $row) {
            if ((int) $row['e']->id === $engineerId) return $row + ['member' => $mem, 'name' => self::displayName($u, $row)];
        }
        return null;
    }

    // 「一覧に足す」で探す。見えてよい名前だけで探す（管理者と自社の人は氏名・愛称・イニシャル、他社の人はイニシャル）。すでに一覧にいる人・案内済みの人は出さない
    public static function findEngineers($u, object $c, string $q, array $exclude = []): array
    {
        $q = trim($q);
        if ($q === '') return [];
        $out = [];
        foreach (self::pool($u, $c) as $m) {
            $e = $m['engineer'];
            if ($m['sent'] !== null || in_array((int) $e->id, array_map('intval', $exclude), true)) continue;
            $hay = ($u->role === 'admin' || $m['own']) ? [$e->name, $e->nickname, $e->initials] : [$e->initials];
            $hit = false;
            foreach ($hay as $h) if ($h !== null && $h !== '' && mb_stripos((string) $h, $q) !== false) { $hit = true; break; }
            if (!$hit) continue;
            $out[] = ['e' => $e, 'own' => $m['own'], 'name' => self::displayName($u, ['e' => $e, 'own' => $m['own']])];
            if (count($out) >= self::FIND_LIMIT) break;
        }
        return $out;
    }

    // 一覧に足す（探した範囲＝対象の中の人だけ）。足したら true
    public static function addMember($u, object $s, object $c, int $engineerId): bool
    {
        $ok = false;
        foreach (self::pool($u, $c) as $m) if ((int) $m['engineer']->id === $engineerId) { $ok = true; break; }
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

    // メールの雛形（今の案内の本文に、全員へのメッセージ・尚可・個別メッセージの差し込みを足したもの）
    public static function defaultBody(): string
    {
        return "{呼び名} さん\n\nお疲れさまです。{送り主}です。\nあなたに合いそうな案件が届いたので、ご案内します。\n\n{全員へのメッセージ}\n\n"
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
            '{全員へのメッセージ}' => self::clean($s->message_all), '{個別メッセージ}' => self::clean($row['member']->message ?? null),
            '{必須}' => $names($items['req']) ?: '—', '{尚可}' => $names($items['pref']), '{合う点}' => implode('、', $fit) ?: '—',
            '{確認事項の節}' => self::confirmBlock($confirm, !$row['own'] && !Inbound::enabled()), // 受信が使えない他社のエンジニアは、返信が届かない
        ];
        $tpl = self::defaultBody();
        if ($vals['{尚可}'] === '') $tpl = str_replace("■尚可：{尚可}\n", '', $tpl); // 尚可が無ければ、その行ごと無くす
        $callee = $row['own'] ? $row['e'] : (object) array_merge((array) $row['e'], ['nickname' => null]); // 他社の人は愛称を出さず、イニシャルで呼ぶ
        $plain = (object) array_merge((array) $c, ['confirm_items' => '']); // 確認事項は上の節で出すので、render の一行の確認事項は使わない
        $text = self::fill($tpl, $vals, fn ($t) => CaseMail::render($t, $plain, $callee, ['reasons' => []], (bool) $row['own'], self::fromName($u)));
        return ['subject' => CaseMail::defaultSubject($c), 'body' => $text, 'confirm' => $confirm];
    }

    private static function clean(?string $v): string
    {
        return trim(str_replace("\r\n", "\n", (string) $v));
    }

    // $ids に送る。今この人が送ってよい人か（対象の中・案内済みでない・この検索のメンバー）でもう一度しぼる。送った人数を返す
    public static function send($u, object $c, object $s, array $ids): int
    {
        $ids = array_map('intval', $ids);
        $members = DB::table('case_search_members')->where('search_id', $s->id)->whereIn('engineer_id', $ids)->get()->keyBy('engineer_id');
        $items = [];
        foreach (self::evaluate($u, $c, json_decode((string) $s->conditions, true) ?: []) as $row) {
            $eid = (int) $row['e']->id;
            if (!isset($members[$eid]) || $row['sent'] !== null) continue;
            $items[$eid] = self::body($u, $c, $s, $row + ['member' => $members[$eid]]);
        }
        if (!$items) return 0;
        // 同時に2回押されても、同じ人に2通出さない：案件の行を押さえてから、送信済みを見直して送る
        $n = DB::transaction(function () use ($u, $c, $items) {
            DB::table('cases')->where('id', $c->id)->lockForUpdate()->first();
            return CaseMail::send($u, $c, $items, self::MODE);
        });
        DB::table('case_searches')->where('id', $s->id)->update(['last_sent_at' => now_str(), 'updated_at' => now_str()]);
        N::audit($u->id, 'case-search-send', "{$c->title} → {$n}名");
        return $n;
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
            if (self::isEngineerEmail($addr)) { $skipped[] = "{$addr} は、登録しているエンジニアです。「エンジニアを追加」から足してください。"; continue; }
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

    // 追加の宛先に送る本文の型（合う点・個別メッセージ・返事のリンク・PARK の画面のリンクは入れない）
    public static function extraTemplate(): string
    {
        return "{呼び名} 様\n\nお疲れさまです。{送り主}です。\n案件のご案内です。\n\n{全員へのメッセージ}\n\n"
            ."■案件：{案件名}\n■案件 ID：{案件ID}\n■単価：{単価}\n■開始：{開始}\n■場所・働き方：{場所}／{働き方}\n■必須：{必須}\n■尚可：{尚可}\n\n{確認事項の節}\n\n"
            ."ご興味があれば、このメールに返信してください（条件で気になる点があれば、それも一緒に）。";
    }

    // 追加の宛先1件分の件名・本文
    public static function extraBody($u, object $c, object $s, object $x): array
    {
        $items = self::activeItems(json_decode((string) $s->conditions, true) ?: []);
        $names = fn (array $list) => implode('、', array_column($list, 'name'));
        $confirm = array_column($items['confirm'], 'name');
        $vals = ['{全員へのメッセージ}' => self::clean($s->message_all), '{必須}' => $names($items['req']) ?: '—', '{尚可}' => $names($items['pref']),
            '{確認事項の節}' => self::confirmBlock($confirm)];
        $tpl = self::extraTemplate();
        if ($vals['{尚可}'] === '') $tpl = str_replace("■尚可：{尚可}\n", '', $tpl);
        $callee = (object) ['name' => $x->name ?: 'ご担当者', 'nickname' => null];
        $plain = (object) array_merge((array) $c, ['confirm_items' => '']);
        $text = self::fill($tpl, $vals, fn ($t) => CaseMail::render($t, $plain, $callee, ['reasons' => []], true, self::fromName($u)));
        return ['subject' => CaseMail::defaultSubject($c), 'body' => $text, 'confirm' => $confirm];
    }

    // チェックした未送信の追加の宛先に送る。戻り：['sent' => 送った件数, 'dup' => この案件で送っていたので飛ばした件数, 'noReply' => 返信先が無く送らなかったか]
    public static function sendExtras($u, object $c, object $s): array
    {
        $sesId = $u->role === 'ses' ? (int) $u->ses_id : null;
        $dup = 0;
        $noReply = false;
        $n = DB::transaction(function () use ($u, $c, $s, $sesId, &$dup, &$noReply) {
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
                $it = self::extraBody($u, $c, $s, $x);
                $inbound = Inbound::enabled();
                [$tokenId, $token] = ReplyTokens::issue(self::EXTRA_KIND, (int) $c->id, $sesId, null, $inbound);
                $body = $it['body']."\n\n※このメールに返信すると、".($inbound ? '担当者と PARK に届きます。' : '担当者に届きます。')
                    ."\n\n――――――――――\n［システム伝達項目・消さないでください］\n案内番号：PARK-".ReplyTokens::tagOf($token)."\n案件 ID：".(($c->code ?? null) ?: '—');
                $replyTo = $inbound ? Inbound::replyAddress($token).($u->email ? ', '.$u->email : '') : ($u->email ?: null);
                $mark = ReplyTokens::subjectMark($token);
                $subject = (string) $it['subject'];
                $sendSubject = str_contains($subject, $mark) ? $subject : rtrim($subject).' '.$mark;
                $outbox = N::queueMail($x->email, $x->name ?: '', $sendSubject, $body, 'case-search-extra', $replyTo, null);
                DB::table('case_search_extras')->where('id', $x->id)->update(['reply_token_id' => $tokenId, 'outbox_id' => $outbox, 'subject' => $subject, 'body' => $body,
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
