<?php

namespace App\Services;

use App\Services\Notifier as N;
use Illuminate\Support\Facades\DB;

// 案件のやり取り（v0.10.0）：案件×エンジニア×会社ごとの1本（case_threads）と、その中の発言（case_thread_posts）。
// v0.10.1：書ける相手（partner_ses_id）は「案件を持ち込んだ会社」（持ち込み元が無い・所属と同じ・直属のエンジニアなら、所属の会社。直属なら管理者）。
// エンジニアの所属の会社（ses_id）は、その1本を全部読めるが、相手が別の会社のときは読むだけ。
// 管理者は、すべてを読める。書けるのは、直属のエンジニアとのやり取りだけ。画面（コントローラー・ビュー）は、ここを呼ぶ。
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

    // 相手（書ける会社）を決める（R1）：案件を持ち込んだ会社。ただし、持ち込み元が無い・所属と同じ・SES ではない・エンジニアが直属のときは、所属の会社
    public static function partnerFor(object $c, object $e): int
    {
        $own = (int) $e->ses_id;
        $s = (int) ($c->source_ses_id ?? 0);
        if ($s && $s !== $own && !Workflow::isDirect($own) && DB::table('ses_companies')->where('id', $s)->value('kind') === 'ses') return $s;
        return $own;
    }

    // この1本の相手の会社（古い1本は partner_ses_id が空なので、所属の会社）
    public static function partnerOf(object $t): int
    {
        return (int) ($t->partner_ses_id ?: $t->ses_id);
    }

    // 今のエンジニアの所属の1本が、もうあればその相手。まだ無ければ、決め方（partnerFor）で決まる相手
    public static function currentPartner(object $c, object $e): int
    {
        $t = DB::table('case_threads')->where('case_id', $c->id)->where('engineer_id', $e->id)->where('ses_id', $e->ses_id)->first();
        return $t ? self::partnerOf($t) : self::partnerFor($c, $e);
    }

    // 相手が管理者（直属）か
    public static function isDirectThread(object $t): bool
    {
        return Workflow::isDirect(self::partnerOf($t));
    }

    // 案件を持ち込んだ会社の担当者が、まだ1本が無い相手に書き始められるか（R9）：相手が自社で、案内メールの記録がある人だけ
    // $hasMail：案内メールの記録があると分かっているとき（案内メールの一覧の行など）は true を渡すと、読み直さない
    public static function canStart($u, object $c, object $e, ?bool $hasMail = null): bool
    {
        if (!$u || $u->role !== 'ses' || !$u->ses_id) return false;
        if (self::currentPartner($c, $e) !== (int) $u->ses_id) return false;
        return $hasMail ?? DB::table('case_mails')->where('case_id', $c->id)->where('engineer_id', $e->id)->exists();
    }

    // エンジニアの名前の出し方（R5）。ほかの会社の担当者には「イニシャル（所属の会社名）」、管理者・本人・所属の会社には氏名。
    // $t があれば、その1本を作ったときの所属で見る
    public static function egName($u, object $e, ?object $t = null): string
    {
        $sesId = $t ? (int) $t->ses_id : (int) $e->ses_id;
        $viewer = ($u && $u->role === 'ses') ? (int) $u->ses_id : null;
        $sesName = ($viewer !== null && $viewer !== $sesId) ? (string) DB::table('ses_companies')->where('id', $sesId)->value('name') : '';
        return self::maskName($viewer, (string) $e->name, $e->initials ?? null, $sesId, $sesName);
    }

    // 一覧・今やること用：listQuery・unreadRows の行（eg_name・eg_initials・ses_id・ses_name つき）から。$viewerSesId が null なら氏名
    public static function egNameRow(?int $viewerSesId, object $row): string
    {
        return self::maskName($viewerSesId, (string) $row->eg_name, $row->eg_initials ?? null, (int) $row->ses_id, (string) ($row->ses_name ?? ''));
    }

    private static function maskName(?int $viewerSesId, string $name, ?string $initials, int $egSesId, string $sesName): string
    {
        if ($viewerSesId === null || $viewerSesId === $egSesId) return $name;
        return ($initials ?: '—').'（'.$sesName.'）';
    }

    // その人から見て、エンジニアが自分の会社の人か（出してよいのは EG- とエンジニアの詳細へのリンク）。行は ses_id を持つこと
    public static function isOwnEg($u, object $t): bool
    {
        return !$u || $u->role !== 'ses' || (int) $t->ses_id === (int) $u->ses_id;
    }

    // その人が、この1本の相手の側か（未読を数え、書ける側）。行は partner_id（listQuery）を持つこと
    public static function isMine($u, object $row): bool
    {
        if ($u->role === 'ses') return (int) $row->partner_id === (int) $u->ses_id;
        return $u->role === 'admin' && $row->partner_kind === 'direct';
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
            'ses' => (int) $t->ses_id === (int) $u->ses_id || self::partnerOf($t) === (int) $u->ses_id,
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
        if ($u->role === 'ses') $q->where(fn ($w) => $w->where('ses_id', $u->ses_id)->orWhere('partner_ses_id', $u->ses_id));
        elseif ($u->role === 'eg') $q->where('engineer_id', $u->engineer_id);
        elseif ($u->role !== 'admin') return collect();
        return $q->orderByRaw('ses_id = ? desc', [(int) $e->ses_id])->orderByDesc('id')->get();
    }

    // 一覧用の元の問い合わせ：1本ごとに、案件・エンジニア・会社の名前と、未読（EG の発言で、相手側がまだ読んでいないもの）の数を付ける。見える範囲の絞りは呼ぶ側で
    public static function listQuery()
    {
        $unread = DB::table('case_thread_posts as p')->whereColumn('p.thread_id', 't.id')->where('p.from_side', 'eg')->where('p.read_by_side', false)->selectRaw('count(*)');
        return DB::table('case_threads as t')->join('cases as c', 'c.id', '=', 't.case_id')->join('engineers as e', 'e.id', '=', 't.engineer_id')->join('ses_companies as s', 's.id', '=', 't.ses_id')
            ->join('ses_companies as ps', 'ps.id', '=', DB::raw('coalesce(t.partner_ses_id, t.ses_id)'))
            ->select('t.*', 'c.title', 'c.code as case_code', 'e.name as eg_name', 'e.initials as eg_initials', 'e.code as eg_code', 's.name as ses_name', 's.kind as ses_kind',
                'ps.id as partner_id', 'ps.name as partner_name', 'ps.kind as partner_kind')
            ->selectSub($unread, 'unread');
    }

    // 1本ずつに、発言（posts）と相手の呼び名（partner）と、今の所属か（current）を付ける
    public static function withPosts($threads, object $e)
    {
        foreach ($threads as $t) {
            $t->posts = DB::table('case_thread_posts')->where('thread_id', $t->id)->orderBy('id')->get();
            $t->partner = self::partnerName(self::partnerOf($t));
            $t->ses_then = (string) DB::table('ses_companies')->where('id', $t->ses_id)->value('name'); // その1本を作ったときの所属の会社
            $t->current = (int) $t->ses_id === (int) $e->ses_id;
        }
        return $threads;
    }

    // 書けないときの理由の文（書けるなら null）。読めるかどうかは、ここでは見ない
    public static function canPost($u, object $c, object $e): ?string
    {
        if (!$u) return '書けません。';
        $partner = self::currentPartner($c, $e); // 書ける会社（案件を持ち込んだ会社。R1）
        if ($u->role === 'eg') {
            if ((int) $u->engineer_id !== (int) $e->id) return 'このやり取りには書けません。';
        } elseif ($u->role === 'ses') {
            if ((int) $u->ses_id !== $partner) {
                // 所属の会社は読むだけ（R3）
                if ((int) $e->ses_id === (int) $u->ses_id) return 'このやり取りの相手は、案件を持ち込んだ '.self::partnerName($partner).' の担当者です。貴社は読むだけです。';
                // 前の所属の会社（そのエンジニアの前の1本がある）と、今は相手ではなくなった会社を分ける
                if (DB::table('case_threads')->where('case_id', $c->id)->where('engineer_id', $e->id)->where('ses_id', $u->ses_id)->exists()) return 'このエンジニアは、いま貴社の所属ではないので、読むだけです。';
                return 'このやり取りの相手は、今は貴社ではないので、読むだけです。';
            }
        } elseif ($u->role !== 'admin') {
            return '書けません。';
        }
        // 兼任の人が、担当者・管理者の立場で、自分自身のやり取りに書くことはできない
        if ($u->role !== 'eg' && $u->engineer_id && (int) $u->engineer_id === (int) $e->id) return 'ご自身のやり取りには、エンジニアとして入って書いてください。';
        if ($e->status === 'left' || $e->suspended_at || !$e->registered_at) return 'このエンジニアは、停止中・退会済・仮登録のため、書けません（過去のやり取りは読めます）。';
        if (DB::table('ses_companies')->where('id', $e->ses_id)->value('status') !== 'active') return '所属の会社が参加中ではないため、書けません。';
        // 相手が別の会社（案件を持ち込んだ会社）のとき、その会社も参加中であること（R10）
        if ($partner !== (int) $e->ses_id && DB::table('ses_companies')->where('id', $partner)->value('status') !== 'active') return '案件を持ち込んだ会社が参加中ではないため、書けません。';
        $reached = DB::table('case_offers')->where('case_id', $c->id)->where('engineer_id', $e->id)->exists()
            || DB::table('applications')->where('case_id', $c->id)->where('engineer_id', $e->id)->exists();
        if (!$reached) return 'この案件は、このエンジニアに届いていないので、書けません。';
        if ($c->status !== 'open'
            && !DB::table('applications')->where('case_id', $c->id)->where('engineer_id', $e->id)->whereIn('status', Affiliation::IN_SELECTION)->exists()) {
            return '募集を終えた案件なので、読むだけです。';
        }
        // 管理者は、各社と EG のやり取りは読むだけ（TEZZO 2026-11-14）。直属の EG とのやり取りだけ、管理者が相手なので書ける
        if ($u->role === 'admin' && !Workflow::isDirect($partner)) return '会社と EG のやり取りは、管理者は読むだけです。';
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
        $partner = self::currentPartner($c, $e);
        $side = $u->role;
        $dup = false;
        $created = false;
        $threadId = 0;
        try {
        DB::transaction(function () use ($u, $c, $e, $body, $sesId, $partner, $side, &$dup, &$created, &$threadId) {
            // 1本目なら作る（同時に作られても、unique に当たったら読み直す）。相手は、作るときに決めて残す
            $created = DB::table('case_threads')->insertOrIgnore(['case_id' => $c->id, 'engineer_id' => $e->id, 'ses_id' => $sesId, 'partner_ses_id' => $partner, 'created_at' => now_str()]) > 0;
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

        $t = DB::table('case_threads')->find($threadId);
        self::notify($u, $c, $e, self::partnerOf($t), $body);
        // 相手が別の会社で、1本が初めてできたときだけ、所属の会社に知らせる（読むだけ。メールなし。R8）
        if ($created && self::partnerOf($t) !== $sesId) {
            $tag = Ids::tag($c->code ?? null);
            N::toSes($sesId, "自社のエンジニアが案件のやり取りを始めました：{$c->title}".$tag,
                "{$e->name} さんと ".self::partnerName(self::partnerOf($t))." の担当者が、案件「{$c->title}」のやり取りを始めました（貴社は読むだけです）。",
                "/cases/{$c->id}/threads/{$e->id}", false, self::KIND, (int) $u->id);
        }
        // 記録には、本文も名前も書かない
        N::audit($u->id, self::KIND, "#thread {$threadId} 案件 ".($c->code ?? $c->id));
        return ['type' => 'ok', 'msg' => '送りました。'];
    }

    // お知らせとメール（R8）。EG → 相手の担当者（直属は管理者）／担当者・管理者 → EG。$partnerId は相手の会社。所属の会社には、発言ごとの知らせは出さない
    private static function notify($u, object $c, object $e, int $partnerId, string $body): void
    {
        $sesId = $partnerId;
        $tag = Ids::tag($c->code ?? null);
        $preview = mb_substr($body, 0, self::PREVIEW_LEN);
        $threadLink = "/cases/{$c->id}/threads/{$e->id}";
        $egLink = "/cases/{$c->id}#thread";
        if ($u->role === 'eg') {
            // 相手が所属と違う会社なら、氏名ではなく「イニシャル（所属の会社名）」で知らせる
            $who = $partnerId === (int) $e->ses_id ? $e->name : self::egName((object) ['role' => 'ses', 'ses_id' => $partnerId], $e);
            $text = "{$who} さんが、案件「{$c->title}」について書きました。\n\n{$preview}";
            // 兼任の人が自分の発言で自分に知らせないよう、書いた人（$u->id）は除く
            N::toSes($sesId, "エンジニアから案件の質問が届きました：{$c->title}".$tag, $text, $threadLink, true, self::KIND, (int) $u->id);
            // 会社に使える担当者が1人もいないときは、管理者にも知らせる（直属は toSes が管理者に届けている）
            if (!Workflow::isDirect($sesId) && N::sesUsers($sesId)->isEmpty()) N::toAdmins("エンジニアから案件の質問が届きました：{$c->title}".$tag, $text, $threadLink, true, self::KIND, (int) $u->id);
        } elseif ($u->role === 'ses') {
            N::toEg($e->id, "担当者から案件の返事が届きました：{$c->title}".$tag, "案件「{$c->title}」について、担当者から書き込みがありました。\n\n{$preview}", $egLink, true, self::KIND, (int) $u->id);
        } else {
            // 管理者が書けるのは直属のエンジニアとのやり取りだけなので、会社への知らせは要らない
            N::toEg($e->id, "管理者から案件の返事が届きました：{$c->title}".$tag, "案件「{$c->title}」について、管理者から書き込みがありました。\n\n{$preview}", $egLink, true, self::KIND, (int) $u->id);
        }
    }

    // 開いた人の分の既読をつける（D7）。EG は相手側の発言を、担当者は EG の発言を既読にする。管理者は、直属の1本だけ（会社の1本は既読にしない）
    public static function markRead($u, object $t): void
    {
        if (!self::visible($u, $t)) return;
        if ($u->role === 'eg') {
            DB::table('case_thread_posts')->where('thread_id', $t->id)->where('from_side', '!=', 'eg')->where('read_by_eg', false)->update(['read_by_eg' => true]);
        } elseif (($u->role === 'ses' && self::partnerOf($t) === (int) $u->ses_id) || ($u->role === 'admin' && self::isDirectThread($t))) {
            DB::table('case_thread_posts')->where('thread_id', $t->id)->where('from_side', 'eg')->where('read_by_side', false)->update(['read_by_side' => true]);
        }
    }

    // 未読の1本ごとの数（案件・エンジニアの名前つき）。$fromEg が true なら EG の発言、false なら相手側の発言を数える
    private static function unreadRows(bool $fromEg)
    {
        $q = DB::table('case_thread_posts as p')->join('case_threads as t', 't.id', '=', 'p.thread_id')->join('cases as c', 'c.id', '=', 't.case_id')->join('engineers as e', 'e.id', '=', 't.engineer_id')
            ->join('ses_companies as s', 's.id', '=', 't.ses_id')
            ->groupBy('t.id', 't.case_id', 't.engineer_id', 't.ses_id', 'c.title', 'e.name', 'e.initials', 's.name')
            ->select('t.id as thread_id', 't.case_id', 't.engineer_id', 't.ses_id', 'c.title', 'e.name as eg_name', 'e.initials as eg_initials', 's.name as ses_name', DB::raw('count(*) as n'));
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
        $q = self::unreadRows(true)->whereRaw('coalesce(t.partner_ses_id, t.ses_id) = ?', [$sesId]); // 相手が自社の1本だけ（読むだけの1本は数えない）
        if ($exceptEngineerId) $q->where('t.engineer_id', '!=', $exceptEngineerId);
        return $q->get();
    }

    // 管理者あて：直属のエンジニアの未読の発言がある1本（会社の1本は出さない）。$exceptEngineerId は、本人（兼任）の分を除く
    public static function unreadDirect(?int $exceptEngineerId = null)
    {
        $q = self::unreadRows(true)->whereRaw("coalesce(t.partner_ses_id, t.ses_id) in (select id from ses_companies where kind = 'direct')");
        if ($exceptEngineerId) $q->where('t.engineer_id', '!=', $exceptEngineerId);
        return $q->get();
    }

    // park:status 用：v0.10.1 より前の形で続く1本の数（相手が所属の会社のままで、今の決め方なら案件を持ち込んだ別の会社になる、発言のある1本）
    public static function legacyCount(): int
    {
        return DB::table('case_threads as t')->join('cases as c', 'c.id', '=', 't.case_id')
            ->whereRaw('coalesce(t.partner_ses_id, t.ses_id) = t.ses_id')
            ->whereNotNull('c.source_ses_id')->whereColumn('c.source_ses_id', '!=', 't.ses_id')
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('ses_companies as sc')->whereColumn('sc.id', 'c.source_ses_id')->where('sc.kind', 'ses'))
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('ses_companies as sb')->whereColumn('sb.id', 't.ses_id')->where('sb.kind', '!=', 'direct'))
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('case_thread_posts as p')->whereColumn('p.thread_id', 't.id'))
            ->count();
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
