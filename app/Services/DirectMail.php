<?php

namespace App\Services;

use App\Services\Notifier as N;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

// 連絡メール（v0.8.0）：案件に結び付かない、人から人への自由な件名・本文のメール。
// 送れる範囲（1か所で決める。画面の出し分けも、送るときも、ここを使う）
//   管理者   → 全社のエンジニア
//   SES・担当者 → 自社のエンジニアだけ
//   エンジニア → 所属の会社の SES・担当者（直属なら管理者）だけ
// 宛先は、使える人だけ（エンジニアは本登録済み・停止中でない・退会でない、SES・担当者・管理者は使えている人）。自分自身は出ない。
// 案件の案内メール（CaseMail）・管理者とのやり取り（messages）とは別のもの。
class DirectMail
{
    const MAX_TO = 50;        // 1回に送れる人数
    const MAX_SUBJECT = 100;  // 件名の長さ
    const MAX_BODY = 5000;    // 本文の長さ
    const PER_PAGE = 50;      // 一覧の1ページの件数
    const KIND = 'direct-mail'; // mail_outbox・お知らせの種類

    // ---------- 権限 ----------

    public static function canCompose($u): bool
    {
        return $u && in_array($u->role, ['admin', 'ses', 'eg'], true);
    }

    // 連絡メールを開けるのは、送った人・受け取った人・管理者だけ
    public static function canView($u, object $m): bool
    {
        if (!$u) return false;
        if ($u->role === 'admin') return true;
        if ($m->from_user_id !== null && (int) $m->from_user_id === (int) $u->id) return true;
        return DB::table('direct_mail_recipients')->where('direct_mail_id', $m->id)->where('to_user_id', $u->id)->exists();
    }

    // ---------- 送れる相手 ----------

    private static function cleanIds(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map(fn ($i) => is_numeric($i) ? (int) $i : 0, $ids), fn ($i) => $i > 0)));
    }

    // 管理者・SES・担当者が選んだエンジニア（engineers.id）を仕分ける。
    // ok＝送れる人（[engineers.id => 届け先のアカウント（users の行。eg_name に氏名）]）、unusable＝範囲の中だが使えない人、outside＝範囲の外（他社・いない・自分）
    public static function engineerTargets($u, array $ids): array
    {
        $ids = self::cleanIds($ids);
        $out = ['ok' => [], 'unusable' => [], 'outside' => []];
        if (!$ids) return $out;
        $mySes = (int) ($u->ses_id ?? 0);
        if (!$u || !in_array($u->role, ['admin', 'ses'], true) || ($u->role === 'ses' && $mySes === 0)) {
            $out['outside'] = $ids;
            return $out;
        }
        // 人数が増えても、問い合わせは3回だけ
        $engs = DB::table('engineers')->whereIn('id', $ids)->get(['id', 'ses_id', 'name'])->keyBy('id');
        $usable = Registration::usableEngineers(DB::table('engineers')->whereIn('id', $ids))->pluck('id')->flip()->all();
        // アカウント：本人の行（エンジニア）を先に。兼任の SES・管理者の行も、同じ人なら使う
        $accounts = DB::table('users')->whereIn('engineer_id', $ids)->whereIn('role', ['eg', 'ses', 'admin'])->where('active', 1)->whereNull('left_at')->whereNotNull('registered_at')
            ->orderByRaw("case when role = 'eg' then 0 else 1 end")->orderBy('id')->get()->unique('engineer_id')->keyBy('engineer_id');
        foreach ($ids as $id) {
            $e = $engs[$id] ?? null;
            if (!$e || (int) $id === (int) ($u->engineer_id ?? 0) || ($u->role === 'ses' && (int) $e->ses_id !== $mySes)) { $out['outside'][] = $id; continue; }
            if (isset($usable[$id]) && isset($accounts[$id])) {
                $row = $accounts[$id];
                $row->eg_name = (string) $e->name;
                $out['ok'][$id] = $row;
            } else {
                $out['unusable'][] = $id;
            }
        }
        return $out;
    }

    // エンジニアが送れる、所属の会社の SES・担当者（直属なら管理者）の全体。
    // ok＝使える人（[users.id => 行]。代表を先に、ほかは名前順）、unusable＝範囲の中だが今は使えない人の users.id
    private static function staffRange($u): array
    {
        $out = ['ok' => [], 'unusable' => []];
        if (!$u || $u->role !== 'eg' || empty($u->engineer_id)) return $out;
        $e = DB::table('engineers')->find($u->engineer_id);
        $s = $e ? DB::table('ses_companies')->find($e->ses_id) : null;
        if (!$s) return $out;
        $direct = $s->kind === 'direct';
        $rows = $direct
            ? DB::table('users')->where('role', 'admin')->where('id', '!=', $u->id)->orderBy('name')->get()
            : DB::table('users')->where('role', 'ses')->where('ses_id', $s->id)->where('id', '!=', $u->id)->orderBy('name')->get();
        $rep = $direct ? 0 : (int) $s->rep_user_id;
        $rows = $rows->sortBy(fn ($x) => (int) $x->id === $rep ? 0 : 1)->values(); // 代表を先に（同じ並びの中では名前順のまま）
        foreach ($rows as $x) {
            if (Registration::usable($x)) $out['ok'][(int) $x->id] = $x;
            else $out['unusable'][] = (int) $x->id;
        }
        return $out;
    }

    // エンジニアが送れる相手（使える人だけ）
    public static function staffTargets($u): array
    {
        return self::staffRange($u)['ok'];
    }

    // 送る相手を仕分ける（管理者・SES はエンジニアの id、エンジニアは相手の users.id）。形は engineerTargets と同じ
    public static function targets($u, array $ids): array
    {
        if ($u && $u->role === 'eg') {
            $range = self::staffRange($u);
            $out = ['ok' => [], 'unusable' => [], 'outside' => []];
            foreach (self::cleanIds($ids) as $id) {
                if (isset($range['ok'][$id])) $out['ok'][$id] = $range['ok'][$id];
                elseif (in_array($id, $range['unusable'], true)) $out['unusable'][] = $id;
                else $out['outside'][] = $id;
            }
            return $out;
        }
        return self::engineerTargets($u, $ids);
    }

    // 作成画面に並べる名前（管理者・SES）：エンジニアの氏名。管理者には会社名も添える
    public static function engineerLabels($u, array $ok): array
    {
        $ses = $u->role === 'admin' && $ok
            ? DB::table('engineers as e')->join('ses_companies as s', 's.id', '=', 'e.ses_id')->whereIn('e.id', array_keys($ok))->get(['e.id', 's.name', 's.kind'])->keyBy('id')
            : collect();
        $out = [];
        foreach ($ok as $eid => $row) {
            $s = $ses[$eid] ?? null;
            $out[$eid] = $row->eg_name.($s ? '（'.($s->kind === 'direct' ? '直属' : $s->name).'）' : '');
        }
        return $out;
    }

    // 作成画面に並べる名前（エンジニア）：相手の氏名。代表・管理者の印を添える
    public static function staffLabels($u, array $ok): array
    {
        $repId = 0;
        if ($ok && $u->role === 'eg') {
            $e = DB::table('engineers')->find($u->engineer_id);
            $repId = (int) ($e ? DB::table('ses_companies')->where('id', $e->ses_id)->value('rep_user_id') : 0);
        }
        $out = [];
        foreach ($ok as $id => $row) {
            $out[$id] = user_label($row).($row->role === 'admin' ? '（管理者）' : ((int) $id === $repId ? '（代表）' : ''));
        }
        return $out;
    }

    // ---------- 返信先 ----------

    private static function sesOf(object $x): int
    {
        if (!empty($x->ses_id)) return (int) $x->ses_id;
        return empty($x->engineer_id) ? 0 : (int) DB::table('engineers')->where('id', $x->engineer_id)->value('ses_id');
    }

    // 返信先を付けてよいか：送った人が管理者のときは付けない（個人のアドレスを見せない。今の案内メールと同じ）。
    // ほかは、受け取る人が管理者か、同じ会社のときだけ（他社にアドレスを出さない）
    public static function replyToAllowed(object $from, object $to): bool
    {
        if ($from->role === 'admin') return false;
        if ($to->role === 'admin') return true;
        $a = self::sesOf($from);
        return $a !== 0 && $a === self::sesOf($to);
    }

    // ---------- メールの本文 ----------

    // 宛名・送った人の本文・誰から・返信先・PARK の画面の URL。あとから Notifier::queueMail が署名の足を付ける
    public static function compose(string $toName, string $body, string $fromLabel, bool $hasReplyTo, int $mailId): string
    {
        return "{$toName} 様\n\n".$body."\n\n――――――――――\n{$fromLabel} から、PARK の連絡メールでお送りしています。\n"
            .($hasReplyTo ? "このメールに返信すると、{$fromLabel} に届きます。\n" : '')
            .'PARK の画面でも読めます：'.url("/mails/{$mailId}");
    }

    private static function trimAll(?string $s): string
    {
        return preg_replace('/^[\s　]+|[\s　]+$/u', '', (string) $s) ?? '';
    }

    // ---------- 送る ----------

    // 送る。$ids は管理者・SES ならエンジニアの id、エンジニアなら相手の users.id。$key は作成画面の使い捨ての鍵。
    // 戻り値：['ok' => id, 'sent' => n, 'skipped' => 外した人数] ／ ['bad' => 文] ／ ['forbidden' => true]（範囲の外が混ざった） ／ ['dup' => 前の id]（同じ鍵で送り済み）
    public static function send($u, array $ids, string $subject, string $body, string $key): array
    {
        if (!self::canCompose($u)) return ['forbidden' => true];
        $subject = self::trimAll(preg_replace('/\p{Cc}+/u', ' ', $subject) ?? '');
        $body = self::trimAll(str_replace(["\r\n", "\r"], "\n", $body));
        if ($subject === '') return ['bad' => '件名を入れてください。'];
        if (mb_strlen($subject) > self::MAX_SUBJECT) return ['bad' => '件名は '.self::MAX_SUBJECT.' 文字までにしてください。'];
        if ($body === '') return ['bad' => '本文を入れてください。'];
        @set_time_limit(180); // 最大 50 通を順に送るので、実行時間の上限で途中で止まらないようにする
        if (mb_strlen($body) > self::MAX_BODY) return ['bad' => '本文は '.self::MAX_BODY.' 文字までにしてください。'];
        if (!preg_match('/^[A-Za-z0-9]{8,40}$/', $key)) return ['bad' => '画面をもう一度開いてから、送り直してください。'];
        $ids = self::cleanIds($ids);
        if (count($ids) > self::MAX_TO) return ['bad' => '一度に送れるのは '.self::MAX_TO.' 名までです。一覧で減らしてください。'];
        if (!$ids) return ['bad' => '送る相手を選んでください。'];

        $t = self::targets($u, $ids);
        if ($t['outside']) return ['forbidden' => true];
        if (!$t['ok']) return ['bad' => '送れる相手がいません。'];

        // 二重送信の守り：同じ鍵は1回だけ（先に行を入れる。同時の2回でも DB の unique が止める）
        $dup = fn () => DB::table('direct_mails')->where('send_key', $key)->first();
        if ($old = $dup()) return (int) $old->from_user_id === (int) $u->id ? ['dup' => (int) $old->id] : ['bad' => '画面をもう一度開いてから、送り直してください。'];
        $fromLabel = $u->role === 'admin' ? (admin_sender($u) ?? setting('operator_name')) : user_label($u);
        try {
            $mailId = (int) DB::table('direct_mails')->insertGetId([
                'send_key' => $key, 'from_user_id' => $u->id, 'from_role' => $u->role, 'from_ses_id' => self::fromSesId($u), 'from_label' => mb_substr($fromLabel, 0, 200),
                'subject' => $subject, 'body' => $body, 'to_count' => count($t['ok']), 'created_at' => now_str(),
            ]);
        } catch (QueryException $e) {
            if ($old = $dup()) return (int) $old->from_user_id === (int) $u->id ? ['dup' => (int) $old->id] : ['bad' => '画面をもう一度開いてから、送り直してください。'];
            throw $e;
        }

        $viaEg = $u->role !== 'eg'; // 管理者・SES が送る相手はエンジニア。エンジニアが送る相手は SES・担当者・管理者
        $sent = 0;
        foreach ($t['ok'] as $id => $to) {
            if ($viaEg) { $toRole = 'eg'; $toName = (string) $to->eg_name; $toLabel = $toName; $eid = (int) $id; }
            else { $toRole = $to->role; $toLabel = user_label($to); $toName = $toLabel; $eid = null; }
            $replyTo = self::replyToAllowed($u, $to) ? ($u->email ?: null) : null;
            $outbox = N::queueMail($to->email, $toName, $subject, self::compose($toName, $body, $fromLabel, (bool) $replyTo, $mailId), self::KIND, $replyTo, $u->role === 'admin' ? admin_sender($u) : $fromLabel);
            DB::table('direct_mail_recipients')->insert(['direct_mail_id' => $mailId, 'to_user_id' => $to->id, 'to_engineer_id' => $eid, 'to_role' => $toRole,
                'to_label' => mb_substr($toLabel, 0, 200), 'outbox_id' => $outbox, 'created_at' => now_str()]);
            // 画面のお知らせだけ（メールは本体が届くので重ねない）
            N::notify((int) $to->id, "{$fromLabel}から連絡メールが届きました", $subject, "/mails/{$mailId}", false, self::KIND, $toRole);
            $sent++;
        }
        N::audit($u->id, 'direct-mail', "#{$mailId} ".lbl('role', $u->role)." → {$sent}名"); // 件名・本文・名前は書かない
        return ['ok' => $mailId, 'sent' => $sent, 'skipped' => count($t['unusable'])];
    }

    // 送った人の会社（管理者は空。エンジニアは所属の会社）
    private static function fromSesId($u): ?int
    {
        if ($u->role === 'admin') return null;
        $id = $u->role === 'eg' ? (int) DB::table('engineers')->where('id', $u->engineer_id)->value('ses_id') : (int) $u->ses_id;
        return $id ?: null;
    }

    // ---------- 一覧・画面 ----------

    // 見られる箱：in（受け取った）・out（送った）・all（管理者だけ）。それ以外は in
    public static function box($u, ?string $box): string
    {
        if ($box === 'out') return 'out';
        if ($box === 'all' && $u->role === 'admin') return 'all';
        return 'in';
    }

    // 一覧（新しい順）。['rows' => [...], 'more' => 次のページがあるか]。各行に first（相手の先頭の名前。受け取った箱は送った人）と others（ほか n 名）を付ける
    public static function listFor($u, string $box, int $page = 1): array
    {
        $page = max(1, $page);
        $q = DB::table('direct_mails as m')->select('m.*');
        if ($box === 'in') $q->join('direct_mail_recipients as r', 'r.direct_mail_id', '=', 'm.id')->where('r.to_user_id', $u->id);
        elseif ($box === 'out') $q->where('m.from_user_id', $u->id);
        $rows = $q->orderByDesc('m.id')->offset(($page - 1) * self::PER_PAGE)->limit(self::PER_PAGE + 1)->get();
        $more = $rows->count() > self::PER_PAGE;
        $rows = $rows->take(self::PER_PAGE)->values();
        $first = [];
        if ($box !== 'in' && $rows->count()) {
            foreach (DB::table('direct_mail_recipients')->whereIn('direct_mail_id', $rows->pluck('id'))->orderBy('id')->get(['direct_mail_id', 'to_label']) as $r) {
                $first[$r->direct_mail_id] ??= $r->to_label;
            }
        }
        foreach ($rows as $m) {
            $m->first = $box === 'in' ? $m->from_label : ($first[$m->id] ?? '—');
            $m->others = $box === 'in' ? 0 : max(0, (int) $m->to_count - 1);
        }
        return ['rows' => $rows, 'more' => $more];
    }

    // 一覧に出す宛先の文：先頭の名前と「ほか n 名」
    public static function recipientsText(object $m): string
    {
        return $m->first.($m->others ? '　ほか '.$m->others.' 名' : '');
    }

    // 宛先の表（送れたかの状態つき）。送った人と管理者の画面に出す
    public static function recipients(int $mailId)
    {
        return DB::table('direct_mail_recipients as r')->leftJoin('mail_outbox as o', 'o.id', '=', 'r.outbox_id')->where('r.direct_mail_id', $mailId)
            ->orderBy('r.id')->get(['r.*', 'o.status as mail_status']);
    }
}
