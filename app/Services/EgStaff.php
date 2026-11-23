<?php

namespace App\Services;

use App\Services\Notifier as N;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

// EG ごとの担当者（v0.17.0）。1人の EG に担当者は必ず1人（engineers.staff_user_id）。同じ会社の中なら付け替えられる。
// 「使える担当者」＝その会社の SES・担当者（兼任の管理者を含む）で、使えていて、退会しておらず、本登録が済み、会社が参加中の人。
class EgStaff
{
    // 付け替えの理由（engineer_staff_logs.reason）
    const REASONS = ['backfill' => '初めの設定', 'create' => '登録', 'manual' => '付け替え', 'move' => '所属の変更', 'suspend' => '担当者の停止', 'leave' => '担当者の退会'];
    const KIND = 'eg-staff'; // お知らせの種類

    // 使える担当者か
    public static function usable(?object $user, int $sesId): bool
    {
        return $user && in_array($user->role, ['ses', 'admin'], true) && (int) $user->ses_id === $sesId
            && $user->active && !$user->left_at && $user->registered_at
            && DB::table('ses_companies')->where('id', $sesId)->value('status') === 'active';
    }

    // 担当者を読む：入っている人が使えればその人、使えなければ会社の SES、それも使えなければ null。読むだけで、書き換えない
    public static function of(object $e): ?object
    {
        $sesId = (int) $e->ses_id;
        $uid = property_exists($e, 'staff_user_id') ? $e->staff_user_id : DB::table('engineers')->where('id', $e->id)->value('staff_user_id');
        if ($uid && self::usable($x = DB::table('users')->find($uid), $sesId)) return $x;
        return self::rep($sesId);
    }

    // 会社の SES（代表者）。使えなければ null
    public static function rep(int $sesId): ?object
    {
        $rep = DB::table('ses_companies')->where('id', $sesId)->value('rep_user_id');
        $x = $rep ? DB::table('users')->find($rep) : null;
        return self::usable($x, $sesId) ? $x : null;
    }

    // 画面の一覧用：EG の行のまとまりから [engineer id => 担当者（users の行）] を、まとめて読む
    public static function ofMany(iterable $engineers): array
    {
        $list = collect($engineers)->values();
        if ($list->isEmpty()) return [];
        $uids = $list->pluck('staff_user_id')->filter()->unique()->all();
        $sesIds = $list->pluck('ses_id')->unique()->all();
        $users = DB::table('users')->whereIn('id', array_merge($uids, DB::table('ses_companies')->whereIn('id', $sesIds)->pluck('rep_user_id')->filter()->all()))->get()->keyBy('id');
        $reps = DB::table('ses_companies')->whereIn('id', $sesIds)->get()->keyBy('id');
        $active = $reps->filter(fn ($s) => $s->status === 'active')->keys()->flip()->all();
        $ok = fn ($x, $sid) => $x && isset($active[$sid]) && in_array($x->role, ['ses', 'admin'], true) && (int) $x->ses_id === $sid && $x->active && !$x->left_at && $x->registered_at;
        $out = [];
        foreach ($list as $e) {
            $sid = (int) $e->ses_id;
            $x = $e->staff_user_id ? ($users[$e->staff_user_id] ?? null) : null;
            if (!$ok($x, $sid)) {
                $r = $reps[$sid]->rep_user_id ?? null;
                $x = $r ? ($users[$r] ?? null) : null;
                if (!$ok($x, $sid)) $x = null;
            }
            $out[(int) $e->id] = $x;
        }
        return $out;
    }

    // その会社の使える担当者。SES を先頭、あとは氏名の順
    public static function candidates(int $sesId): Collection
    {
        if (DB::table('ses_companies')->where('id', $sesId)->value('status') !== 'active') return collect();
        $rep = (int) DB::table('ses_companies')->where('id', $sesId)->value('rep_user_id');
        return DB::table('users')->whereIn('role', ['ses', 'admin'])->where('ses_id', $sesId)->where('active', 1)->whereNull('left_at')->whereNotNull('registered_at')
            ->orderBy('name')->orderBy('id')->get()->sortBy(fn ($x) => (int) $x->id === $rep ? 0 : 1)->values();
    }

    // 新しい EG の担当者：登録した人が SES・担当者（role=ses）で使えればその人、違えば（管理者など）その会社の SES
    public static function initial(int $sesId, $actor): ?int
    {
        if ($actor && ($actor->role ?? null) === 'ses' && self::usable($actor, $sesId)) return (int) $actor->id;
        $rep = self::rep($sesId);
        return $rep ? (int) $rep->id : null;
    }

    // 付け替える。同じ人なら何もしない。使える担当者でなければ false
    public static function assign(object $e, ?int $toUserId, $actor, string $reason): bool
    {
        $fresh = DB::table('engineers')->find($e->id);
        if (!$fresh || !$toUserId || !self::usable($to = DB::table('users')->find($toUserId), (int) $fresh->ses_id)) return false;
        $from = $fresh->staff_user_id ? (int) $fresh->staff_user_id : null;
        if ($from === (int) $toUserId) return true;
        DB::table('engineers')->where('id', $fresh->id)->update(['staff_user_id' => $toUserId, 'updated_at' => now_str()]);
        self::log((int) $fresh->id, $from, (int) $toUserId, $actor?->id, $reason);
        N::audit($actor->id ?? null, 'eg-staff-change', "エンジニア #{$fresh->id}：#".($from ?? '—')." → #{$toUserId}（".(self::REASONS[$reason] ?? $reason).'）'); // 名前は書かない
        if ($reason === 'backfill') return true;
        N::notify((int) $toUserId, "{$fresh->name} さんの担当になりました", "{$fresh->name} さんの担当になりました。エンジニアの画面から、内容を確かめてください。", "/engineers/{$fresh->id}", false, self::KIND, 'ses');
        if ($from && $from !== (int) $toUserId && self::usable($old = DB::table('users')->find($from), (int) $fresh->ses_id)) {
            N::notify($from, "{$fresh->name} さんの担当が替わりました", "{$fresh->name} さんの担当が {$to->name} さんに替わりました。", "/engineers/{$fresh->id}", false, self::KIND, 'ses');
        }
        N::toEg((int) $fresh->id, '担当者が替わりました', "担当者が {$to->name} さんに替わりました。", '/eg', false, self::KIND);
        return true;
    }

    // 所属の会社が変わった EG の担当者を、新しい会社の人にする（SES、いなければ使える担当者の先頭、それもいなければ空にして記録する）。
    // 前の会社の人が担当者のまま残らないようにする。戻りは、新しい担当者の id（空にしたときは null）
    public static function moveTo(int $engineerId, int $newSesId, $actor): ?int
    {
        $to = self::rep($newSesId) ?? self::candidates($newSesId)->first();
        if ($to) {
            self::assign((object) ['id' => $engineerId], (int) $to->id, $actor, 'move');
            return (int) $to->id;
        }
        DB::table('engineers')->where('id', $engineerId)->update(['staff_user_id' => null, 'updated_at' => now_str()]);
        N::audit($actor->id ?? null, 'eg-staff-change', "エンジニア #{$engineerId}：担当者を空にしました（新しい会社に使える担当者がいません）");
        return null;
    }

    // その人が担当の EG を、全部、会社の SES に移す。戻りは件数
    public static function release(int $userId, $actor, string $reason): int
    {
        $n = 0;
        foreach (DB::table('engineers')->where('staff_user_id', $userId)->orderBy('id')->get() as $e) {
            $rep = self::rep((int) $e->ses_id);
            if (!$rep || (int) $rep->id === $userId) continue;
            if (self::assign($e, (int) $rep->id, $actor, $reason)) $n++;
        }
        return $n;
    }

    // 担当者がまだ空の EG に、はじめの担当者を入れる（見本のデータ・マイグレーションと同じ順。お知らせは出さない）。戻りは入れた数
    public static function fillMissing(): int
    {
        $n = 0;
        foreach (DB::table('engineers')->whereNull('staff_user_id')->orderBy('id')->get() as $e) {
            $sesId = (int) $e->ses_id;
            $pick = null;
            $reg = DB::table('users')->where('engineer_id', $e->id)->orderByRaw("role = 'eg' desc")->orderBy('id')->value('registered_by');
            if ($reg && self::usable(DB::table('users')->find($reg), $sesId)) $pick = (int) $reg;
            if (!$pick && ($rep = self::rep($sesId))) $pick = (int) $rep->id;
            if (!$pick) $pick = (int) (self::candidates($sesId)->sortBy('id')->first()->id ?? 0) ?: null;
            if (!$pick) continue;
            DB::table('engineers')->where('id', $e->id)->update(['staff_user_id' => $pick]);
            self::log((int) $e->id, null, $pick, null, 'backfill');
            $n++;
        }
        return $n;
    }

    // 担当者が空の EG の数（park:status 用）
    public static function emptyCount(): int
    {
        return DB::table('engineers')->whereNull('staff_user_id')->count();
    }

    // 付け替えの記録（新しい順）
    public static function logs(int $engineerId, int $limit = 20): Collection
    {
        return DB::table('engineer_staff_logs')->where('engineer_id', $engineerId)->orderByDesc('id')->limit($limit)->get();
    }

    public static function log(int $engineerId, ?int $from, int $to, ?int $by, string $reason): void
    {
        DB::table('engineer_staff_logs')->insert(['engineer_id' => $engineerId, 'from_user_id' => $from, 'to_user_id' => $to, 'changed_by' => $by, 'reason' => $reason, 'created_at' => now_str()]);
    }
}
