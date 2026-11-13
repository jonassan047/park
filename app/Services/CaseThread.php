<?php

namespace App\Services;

use App\Services\Notifier as N;
use Illuminate\Support\Facades\DB;

// 案件のやり取り（v0.10.0）：案件×エンジニア×会社ごとの1本（case_threads）と、その中の発言（case_thread_posts）。
// 相手は「エンジニアの今の所属の会社の担当者」（直属のエンジニアなら管理者）。案件を持ち込んだほかの会社には見せない・届けない。
// 管理者は、すべてを読めて、書ける。画面（コントローラー・ビュー）は、ここを呼ぶ。
class CaseThread
{
    const KIND = 'case-thread';     // お知らせ・メール・記録の種類
    const MAX_LEN = 2000;           // 1回の発言の長さ（字）
    const DUP_SECONDS = 60;         // 同じ人が同じ文を送り直したと見なす秒数（二重押しの守り）
    const PREVIEW_LEN = 200;        // お知らせに載せる、発言の頭の字数
    const STALE_DAYS = 3;           // park:status：EG の発言に返事がないまま過ぎた日数

    // エンジニアの今の所属の会社
    public static function partnerSesId(object $e): int
    {
        return (int) $e->ses_id;
    }

    // 相手が管理者（直属）か
    public static function isDirectThread(object $t): bool
    {
        return Workflow::isDirect((int) $t->ses_id);
    }

    // 相手の呼び名（エンジニアの画面に出す）
    public static function partnerName(int $sesId): string
    {
        if (Workflow::isDirect($sesId)) return (string) setting('operator_name');
        return (string) DB::table('ses_companies')->where('id', $sesId)->value('name');
    }

    // その人に、この1本が見えるか
    public static function visible($u, object $t): bool
    {
        if (!$u) return false;
        return match ($u->role) {
            'admin' => true,
            'ses' => (int) $t->ses_id === (int) $u->ses_id,
            'eg' => (int) $t->engineer_id === (int) $u->engineer_id,
            default => false,
        };
    }

    // その人が見られる、案件×エンジニアの1本たち（今の所属の1本を先に、あとは新しい順）
    public static function threadsFor($u, int $caseId, object|int $engineer)
    {
        $e = is_object($engineer) ? $engineer : DB::table('engineers')->find((int) $engineer);
        if (!$e || !$u) return collect();
        $q = DB::table('case_threads')->where('case_id', $caseId)->where('engineer_id', $e->id);
        if ($u->role === 'ses') $q->where('ses_id', $u->ses_id);
        elseif ($u->role === 'eg') $q->where('engineer_id', $u->engineer_id);
        elseif ($u->role !== 'admin') return collect();
        return $q->orderByRaw('ses_id = ? desc', [(int) $e->ses_id])->orderByDesc('id')->get();
    }

    // 一覧用の元の問い合わせ：1本ごとに、案件・エンジニア・会社の名前と、未読（EG の発言で、相手側がまだ読んでいないもの）の数を付ける。見える範囲の絞りは呼ぶ側で
    public static function listQuery()
    {
        $unread = DB::table('case_thread_posts as p')->whereColumn('p.thread_id', 't.id')->where('p.from_side', 'eg')->where('p.read_by_side', false)->selectRaw('count(*)');
        return DB::table('case_threads as t')->join('cases as c', 'c.id', '=', 't.case_id')->join('engineers as e', 'e.id', '=', 't.engineer_id')->join('ses_companies as s', 's.id', '=', 't.ses_id')
            ->select('t.*', 'c.title', 'c.code as case_code', 'e.name as eg_name', 'e.code as eg_code', 's.name as ses_name', 's.kind as ses_kind')
            ->selectSub($unread, 'unread');
    }

    // 1本ずつに、発言（posts）と相手の呼び名（partner）と、今の所属か（current）を付ける
    public static function withPosts($threads, object $e)
    {
        foreach ($threads as $t) {
            $t->posts = DB::table('case_thread_posts')->where('thread_id', $t->id)->orderBy('id')->get();
            $t->partner = self::partnerName((int) $t->ses_id);
            $t->current = (int) $t->ses_id === (int) $e->ses_id;
        }
        return $threads;
    }

    // 書けないときの理由の文（書けるなら null）。読めるかどうかは、ここでは見ない
    public static function canPost($u, object $c, object $e): ?string
    {
        if (!$u) return '書けません。';
        if ($u->role === 'eg') {
            if ((int) $u->engineer_id !== (int) $e->id) return 'このやり取りには書けません。';
        } elseif ($u->role === 'ses') {
            if ((int) $e->ses_id !== (int) $u->ses_id) return 'このエンジニアは、いま貴社の所属ではないので、読むだけです。';
        } elseif ($u->role !== 'admin') {
            return '書けません。';
        }
        // 兼任の人が、担当者・管理者の立場で、自分自身のやり取りに書くことはできない
        if ($u->role !== 'eg' && $u->engineer_id && (int) $u->engineer_id === (int) $e->id) return 'ご自身のやり取りには、エンジニアとして入って書いてください。';
        if ($e->status === 'left' || $e->suspended_at || !$e->registered_at) return 'このエンジニアは、停止中・退会済・仮登録のため、書けません（過去のやり取りは読めます）。';
        if (DB::table('ses_companies')->where('id', $e->ses_id)->value('status') !== 'active') return '所属の会社が参加中ではないため、書けません。';
        $reached = DB::table('case_offers')->where('case_id', $c->id)->where('engineer_id', $e->id)->exists()
            || DB::table('applications')->where('case_id', $c->id)->where('engineer_id', $e->id)->exists();
        if (!$reached) return 'この案件は、このエンジニアに届いていないので、書けません。';
        if ($c->status !== 'open'
            && !DB::table('applications')->where('case_id', $c->id)->where('engineer_id', $e->id)->whereIn('status', Affiliation::IN_SELECTION)->exists()) {
            return '募集を終えた案件なので、読むだけです。';
        }
        return null;
    }

    // 書いたときの表示名。管理者の個人名は出さない
    private static function label($u, object $e): string
    {
        if ($u->role === 'eg') return (string) $e->name;
        if ($u->role === 'ses') return $u->name.'（'.DB::table('ses_companies')->where('id', $u->ses_id)->value('name').'）';
        return (string) setting('operator_name');
    }

    // 発言する。戻り値は ['type' => 'ok'|'bad', 'msg' => 文]。権限（案件・エンジニアが見られるか）は呼ぶ側で確かめておく
    public static function post($u, object $c, object $e, string $body): array
    {
        $body = trim(str_replace("\r\n", "\n", $body));
        $len = mb_strlen($body);
        if ($len < 1 || $len > self::MAX_LEN) return ['type' => 'bad', 'msg' => '1〜'.self::MAX_LEN.' 字で書いてください。'];
        if ($why = self::canPost($u, $c, $e)) return ['type' => 'bad', 'msg' => $why];

        $sesId = self::partnerSesId($e);
        $side = $u->role;
        $dup = false;
        $threadId = 0;
        try {
        DB::transaction(function () use ($u, $c, $e, $body, $sesId, $side, &$dup, &$threadId) {
            // 1本目なら作る（同時に作られても、unique に当たったら読み直す）
            DB::table('case_threads')->insertOrIgnore(['case_id' => $c->id, 'engineer_id' => $e->id, 'ses_id' => $sesId, 'created_at' => now_str()]);
            $t = DB::table('case_threads')->where('case_id', $c->id)->where('engineer_id', $e->id)->where('ses_id', $sesId)->first();
            if (!$t) throw new \RuntimeException('thread not found'); // 作れていない（読み直しても無い）
            $threadId = (int) $t->id;
            // 二重押し：同じ人が同じ文を 60 秒以内に送っていたら、保存しない
            $dup = DB::table('case_thread_posts')->where('thread_id', $t->id)->where('from_user_id', $u->id)->where('from_side', $side)->where('body', $body)
                ->where('created_at', '>=', date('Y-m-d H:i:s', time() - self::DUP_SECONDS))->exists();
            if ($dup) return;
            DB::table('case_thread_posts')->insert(['thread_id' => $t->id, 'from_user_id' => $u->id, 'from_side' => $side, 'from_label' => self::label($u, $e), 'body' => $body,
                'read_by_eg' => $side === 'eg', 'read_by_side' => $side !== 'eg', 'created_at' => now_str()]);
            DB::table('case_threads')->where('id', $t->id)->update(['last_post_at' => now_str()]);
        });
        } catch (\RuntimeException $ex) {
            return ['type' => 'bad', 'msg' => '送れませんでした。もう一度お試しください。'];
        }
        if ($dup) return ['type' => 'ok', 'msg' => '送りました。'];

        self::notify($u, $c, $e, $sesId, $body);
        // 記録には、本文も名前も書かない
        N::audit($u->id, self::KIND, "#thread {$threadId} 案件 ".($c->code ?? $c->id));
        return ['type' => 'ok', 'msg' => '送りました。'];
    }

    // お知らせとメール（D8）。EG → 所属の担当者（直属は管理者）／担当者 → EG／管理者 → EG と、直属でなければ会社（お知らせだけ）
    private static function notify($u, object $c, object $e, int $sesId, string $body): void
    {
        $tag = Ids::tag($c->code ?? null);
        $preview = mb_substr($body, 0, self::PREVIEW_LEN);
        $threadLink = "/cases/{$c->id}/threads/{$e->id}";
        $egLink = "/cases/{$c->id}#thread";
        if ($u->role === 'eg') {
            $text = "{$e->name} さんが、案件「{$c->title}」について書きました。\n\n{$preview}";
            // 兼任の人が自分の発言で自分に知らせないよう、書いた人（$u->id）は除く
            N::toSes($sesId, "エンジニアから案件の質問が届きました：{$c->title}".$tag, $text, $threadLink, true, self::KIND, (int) $u->id);
            // 会社に使える担当者が1人もいないときは、管理者にも知らせる（直属は toSes が管理者に届けている）
            if (!Workflow::isDirect($sesId) && N::sesUsers($sesId)->isEmpty()) N::toAdmins("エンジニアから案件の質問が届きました：{$c->title}".$tag, $text, $threadLink, true, self::KIND, (int) $u->id);
        } elseif ($u->role === 'ses') {
            N::toEg($e->id, "担当者から案件の返事が届きました：{$c->title}".$tag, "案件「{$c->title}」について、担当者から書き込みがありました。\n\n{$preview}", $egLink, true, self::KIND, (int) $u->id);
        } else {
            N::toEg($e->id, "管理者から案件の返事が届きました：{$c->title}".$tag, "案件「{$c->title}」について、管理者から書き込みがありました。\n\n{$preview}", $egLink, true, self::KIND, (int) $u->id);
            if (!Workflow::isDirect($sesId)) {
                N::toSes($sesId, "管理者が案件のやり取りに書き込みました：{$c->title}".$tag, "{$e->name} さんとの案件「{$c->title}」のやり取りに、管理者が書き込みました。\n\n{$preview}", $threadLink, false, self::KIND, (int) $u->id);
            }
        }
    }

    // 開いた人の分の既読をつける（D7）。EG は相手側の発言を、担当者は EG の発言を既読にする。管理者は、直属の1本だけ（会社の1本は既読にしない）
    public static function markRead($u, object $t): void
    {
        if (!self::visible($u, $t)) return;
        if ($u->role === 'eg') {
            DB::table('case_thread_posts')->where('thread_id', $t->id)->where('from_side', '!=', 'eg')->where('read_by_eg', false)->update(['read_by_eg' => true]);
        } elseif ($u->role === 'ses' || ($u->role === 'admin' && self::isDirectThread($t))) {
            DB::table('case_thread_posts')->where('thread_id', $t->id)->where('from_side', 'eg')->where('read_by_side', false)->update(['read_by_side' => true]);
        }
    }

    // 未読の1本ごとの数（案件・エンジニアの名前つき）。$fromEg が true なら EG の発言、false なら相手側の発言を数える
    private static function unreadRows(bool $fromEg)
    {
        $q = DB::table('case_thread_posts as p')->join('case_threads as t', 't.id', '=', 'p.thread_id')->join('cases as c', 'c.id', '=', 't.case_id')->join('engineers as e', 'e.id', '=', 't.engineer_id')
            ->groupBy('t.id', 't.case_id', 't.engineer_id', 'c.title', 'e.name')
            ->select('t.id as thread_id', 't.case_id', 't.engineer_id', 'c.title', 'e.name as eg_name', DB::raw('count(*) as n'));
        return $fromEg ? $q->where('p.from_side', 'eg')->where('p.read_by_side', false) : $q->where('p.from_side', '!=', 'eg')->where('p.read_by_eg', false);
    }

    // エンジニアあて：未読の相手側の発言がある1本
    public static function unreadForEg(int $engineerId)
    {
        return self::unreadRows(false)->where('t.engineer_id', $engineerId)->get();
    }

    // 担当者あて：未読の EG の発言がある1本（自社のものだけ）。$exceptEngineerId は、本人（兼任）のエンジニアの分を除く
    public static function unreadForSes(int $sesId, ?int $exceptEngineerId = null)
    {
        $q = self::unreadRows(true)->where('t.ses_id', $sesId);
        if ($exceptEngineerId) $q->where('t.engineer_id', '!=', $exceptEngineerId);
        return $q->get();
    }

    // 管理者あて：直属のエンジニアの未読の発言がある1本（会社の1本は出さない）。$exceptEngineerId は、本人（兼任）の分を除く
    public static function unreadDirect(?int $exceptEngineerId = null)
    {
        $q = self::unreadRows(true)->whereIn('t.ses_id', DB::table('ses_companies')->where('kind', 'direct')->select('id'));
        if ($exceptEngineerId) $q->where('t.engineer_id', '!=', $exceptEngineerId);
        return $q->get();
    }

    // park:status 用：最後の発言が EG で、N 日以上たっている1本の数（返事がないまま）
    public static function staleCount(): int
    {
        $limit = date('Y-m-d H:i:s', time() - self::STALE_DAYS * 86400);
        return DB::table('case_threads as t')
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('case_thread_posts as p')->whereColumn('p.thread_id', 't.id')
                ->where('p.from_side', 'eg')->where('p.created_at', '<', $limit)
                ->whereRaw('p.id = (select max(l.id) from case_thread_posts as l where l.thread_id = t.id)'))
            ->count();
    }
}
