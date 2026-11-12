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
    const MAX_ADD = 30;        // 1回に足せる数
    const FIND_LIMIT = 30;     // 「一覧に足す」で探して出す人数
    const MODE = 'search';     // case_mails.mode

    // ---------- 権限 ----------

    public static function canUse($u, object $c): bool
    {
        return CaseMail::canSend($u, $c);
    }

    // 検索を開けるのは、管理者と、作った人と同じ会社の SES・担当者
    public static function canOpen($u, object $s): bool
    {
        if ($u->role === 'admin') return true;
        return $u->role === 'ses' && $s->ses_id !== null && (int) $s->ses_id === (int) $u->ses_id;
    }

    // ---------- 条件 ----------

    // 条件の項目の同じ・違いを見る鍵（技術は表記ゆれをそろえる）
    public static function key(array $it): string
    {
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
            foreach (parse_list($c->$skillCol) as $w) $items[] = ['name' => self::skillName($w), 'kind' => 'skill', 'src' => 'case', 'on' => true];
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
        return $out;
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

    // 判定に使う（外していない）項目
    public static function activeItems(array $cond): array
    {
        $f = fn ($list) => array_values(array_filter($list, fn ($it) => !empty($it['on'])));
        return ['req' => $f($cond['req'] ?? []), 'pref' => $f($cond['pref'] ?? [])];
    }

    // ---------- 対象の人と判定 ----------

    // 対象：今の案内と同じ人（使える人・有効な SES・応募済みを除く・稼働中も入る・unavailable は除く）。
    // own＝自社の人（SES・担当者は自分の会社。管理者は直属の会社）。sent＝この案件で案内を送った人の返事（無ければ null）
    public static function pool($u, object $c): array
    {
        $sent = DB::table('case_mails')->where('case_id', $c->id)->pluck('reply', 'engineer_id')->all();
        $mine = $u->role === 'ses' ? (int) $u->ses_id : null;
        $direct = [];
        $out = [];
        foreach (Matcher::forCase($c->id, true) as $m) {
            $e = $m['engineer'];
            if ($e->status === 'unavailable') continue;
            $sid = (int) $e->ses_id;
            $own = $mine === null ? ($direct[$sid] ??= Workflow::isDirect($sid)) : $sid === $mine;
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
            ."■案件：{案件名}\n■単価：{単価}\n■開始：{開始}\n■場所・働き方：{場所}／{働き方}\n■必須：{必須}\n■尚可：{尚可}\n■あなたに合う点：{合う点}\n\n"
            ."{個別メッセージ}\n\n"
            ."ご興味があれば、このメールに返信してください（条件で気になる点があれば、それも一緒に）。\n見送る場合も、理由をひとこと返信いただけると、次のご案内の参考にします。";
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
        $vals = [
            '{全員へのメッセージ}' => self::clean($s->message_all), '{個別メッセージ}' => self::clean($row['member']->message ?? null),
            '{必須}' => $names($items['req']) ?: '—', '{尚可}' => $names($items['pref']), '{合う点}' => implode('、', $fit) ?: '—',
        ];
        $tpl = self::defaultBody();
        if ($vals['{尚可}'] === '') $tpl = str_replace("■尚可：{尚可}\n", '', $tpl); // 尚可が無ければ、その行ごと無くす
        // 差し込みを目印にしておき、今の案内の差し込み（呼び名・案件名など）を先に済ませる。メッセージの中の {…} は差し込みにしない
        $marks = [];
        $i = 0;
        foreach ($vals as $k => $v) {
            $m = "\u{E000}".$i++."\u{E001}";
            $tpl = str_replace($k, $m, $tpl);
            $marks[$m] = $v;
        }
        $callee = $row['own'] ? $row['e'] : (object) array_merge((array) $row['e'], ['nickname' => null]); // 他社の人は愛称を出さず、イニシャルで呼ぶ
        $text = CaseMail::render($tpl, $c, $callee, ['reasons' => []], (bool) $row['own'], self::fromName($u));
        $lines = [];
        foreach (explode("\n", $text) as $ln) {
            if (isset($marks[trim($ln)]) && $marks[trim($ln)] === '') continue; // 空の差し込みだけの行は消す
            $lines[] = $ln;
        }
        $text = strtr(implode("\n", $lines), $marks);
        $text = preg_replace("/\n{3,}/", "\n\n", $text);
        return ['subject' => CaseMail::defaultSubject($c), 'body' => $text];
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
}
