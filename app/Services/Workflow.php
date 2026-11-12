<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use App\Services\Notifier as N;
use App\Support\CaseExp;
use App\Support\Requirements;
use App\Support\SkillCatalog;

// 業務の手続き（登録・配信・応募・選考・成約・延長）。画面（コントローラー）からはここを呼ぶ。
class Workflow
{
    // ---------- ユーザーと招待 ----------
    // リンクの発行は Registration に集めている（種類 register＝本登録、reset＝パスワードの再設定）
    public static function issueToken(int $userId, int $days = 7, string $kind = 'register'): string
    {
        return Registration::issueToken($userId, $days, $kind);
    }

    // 会社の SES（または次の SES）か
    public static function isRep(?object $u): bool
    {
        return Requirements::isRep($u);
    }

    // ---------- 担当者の停止・再開・退会（管理者と、自社の SES）と、エンジニアの停止・再開 ----------
    // 戻り値は ['type' => 'ok'|'bad', 'msg' => 文]。対象がその SES の担当者でなければ null（呼ぶ側で 404）
    private static function sesUserOf(int $sesId, int $uid): ?object
    {
        return DB::table('users')->where('id', $uid)->where('role', 'ses')->where('ses_id', $sesId)->first() ?: null;
    }

    private static function sesCompanyName(int $sesId): string
    {
        return (string) DB::table('ses_companies')->where('id', $sesId)->value('name');
    }

    // その人が、会社で使える（active で退会していない）担当者の最後の1人なら、断りの結果を返す（会社ごと止めるなら会社の停止・退会を使う）
    private static function onlyUsableUser(int $sesId, object $u): ?array
    {
        if (!$u->active) return null;
        $usable = DB::table('users')->where('ses_id', $sesId)->where('role', 'ses')->where('active', 1)->whereNull('left_at')->count();
        if ($usable > 1) return null;
        return ['type' => 'bad', 'msg' => 'この会社で使える担当者が、この人だけです。停止・退会にはできません。会社ごと止めるときは、SES の「停止」か「退会」を使ってください。'];
    }

    // SES（または次の SES）は、交代のあとでなければ停止・退会にできない（会社に SES がいない状態を作らない。K21）
    private static function repGuard(object $u): ?array
    {
        if (!Requirements::isRep($u)) return null;
        return ['type' => 'bad', 'msg' => 'SES は、停止・退会にできません。先に、管理者にSES の交代をご依頼ください。会社ごと止めるときは、SES の「停止」か「退会」を使ってください。'];
    }

    public static function suspendSesUser(int $sesId, int $uid, $actor): ?array
    {
        if (!($u = self::sesUserOf($sesId, $uid))) return null;
        if ($u->left_at) return ['type' => 'bad', 'msg' => '退会済の人は操作できません。'];
        if (!$u->active) return ['type' => 'ok', 'msg' => 'すでに停止中です。'];
        if ($rep = self::repGuard($u)) return $rep;
        if ($only = self::onlyUsableUser($sesId, $u)) return $only;
        // 招待リンクも使えなくする
        DB::table('users')->where('id', $uid)->update(['active' => 0, 'token' => null, 'token_expires' => null, 'updated_at' => now_str()]);
        N::audit($actor->id ?? null, 'ses-user-suspend', self::sesCompanyName($sesId).' '.$u->name);
        return ['type' => 'ok', 'msg' => "{$u->name} さんを停止しました。".(self::isDual($u) ? '（エンジニアとしても入れなくなります）' : '')];
    }

    public static function resumeSesUser(int $sesId, int $uid, $actor): ?array
    {
        if (!($u = self::sesUserOf($sesId, $uid))) return null;
        if ($u->left_at) return ['type' => 'bad', 'msg' => '退会済の人は操作できません。'];
        if ($u->active) return ['type' => 'ok', 'msg' => 'すでに使えます。'];
        DB::table('users')->where('id', $uid)->update(['active' => 1, 'updated_at' => now_str()]);
        N::audit($actor->id ?? null, 'ses-user-resume', self::sesCompanyName($sesId).' '.$u->name);
        $msg = "{$u->name} さんを再開しました。";
        // パスワードを決めていなかった人は、招待のリンクが無いので、送り直す方法を添える
        if (!$u->registered_at) $msg .= '仮登録のままなので、『送り直し』でご案内を送ってください。';
        return ['type' => 'ok', 'msg' => $msg];
    }

    public static function leaveSesUser(int $sesId, int $uid, $actor): ?array
    {
        if (!($u = self::sesUserOf($sesId, $uid))) return null;
        if ($u->left_at) return ['type' => 'bad', 'msg' => '退会済の人は操作できません。'];
        if ($rep = self::repGuard($u)) return $rep;
        if ($only = self::onlyUsableUser($sesId, $u)) return $only;
        // エンジニアを兼ねている人は、SES 担当者の役割だけ外す（エンジニアとしては引き続き入れる）
        if (self::isDual($u)) {
            // エンジニアが停止中なら、本人の行も止める（エンジニアの再開で、role='eg' の行として戻る）
            $egStopped = DB::table('engineers')->where('id', $u->engineer_id)->whereNotNull('suspended_at')->exists();
            DB::table('users')->where('id', $uid)->update(['role' => 'eg', 'active' => $egStopped ? 0 : $u->active, 'updated_at' => now_str()]);
            N::audit($actor->id ?? null, 'ses-user-leave', self::sesCompanyName($sesId).' '.$u->name);
            return ['type' => 'ok', 'msg' => "{$u->name} さんを SES 担当者から外しました。エンジニアとしては引き続き入れます。"];
        }
        DB::table('users')->where('id', $uid)->update(['active' => 0, 'left_at' => now_str(), 'token' => null, 'token_expires' => null, 'updated_at' => now_str()]);
        N::audit($actor->id ?? null, 'ses-user-leave', self::sesCompanyName($sesId).' '.$u->name);
        return ['type' => 'ok', 'msg' => "{$u->name} さんを退会済にしました。"];
    }

    // ---------- 管理者の停止・再開・退会（管理者だけが押せる） ----------
    // 戻り値は ['type' => 'ok'|'bad', 'msg' => 文]。対象が管理者でなければ null（呼ぶ側で 404）
    // $stopping は、停止・退会のとき true（使える管理者が足りなくなる操作を断るため）
    private static function adminGuard(int $id, $actor, bool $stopping): array|object
    {
        // 呼ぶ側のトランザクションの中で使う。同時に2人が押しても「最後の1人」を守れるよう、行をロックして数える
        $u = DB::table('users')->where('id', $id)->where('role', 'admin')->lockForUpdate()->first();
        if (!$u) return ['null' => true];
        if ($id === (int) ($actor->id ?? 0)) return ['type' => 'bad', 'msg' => 'ご自身は、停止・再開・退会にできません。ほかの管理者にお願いしてください。'];
        if ($u->left_at) return ['type' => 'bad', 'msg' => '退会済の人は操作できません。'];
        if ($stopping) {
            $others = DB::table('users')->where('role', 'admin')->where('id', '!=', $id)->where('active', 1)->whereNull('left_at')->whereNotNull('password')->lockForUpdate()->get(['id'])->count();
            if ($others === 0) return ['type' => 'bad', 'msg' => '使える管理者がほかにいなくなるため、停止・退会にはできません。'];
        }
        return $u;
    }

    public static function suspendAdmin(int $id, $actor): ?array
    {
        return DB::transaction(function () use ($id, $actor) {
            $u = self::adminGuard($id, $actor, true);
            if (is_array($u)) return isset($u['null']) ? null : $u;
            if (!$u->active) return ['type' => 'ok', 'msg' => 'すでに停止中です。'];
            // 招待リンクも使えなくする
            DB::table('users')->where('id', $id)->update(['active' => 0, 'token' => null, 'token_expires' => null, 'updated_at' => now_str()]);
            N::audit($actor->id ?? null, 'admin-suspend', "#user {$id}");
            return ['type' => 'ok', 'msg' => "{$u->name} さんを停止しました。".(self::isDual($u) ? '（エンジニアとしても入れなくなります）' : '')];
        });
    }

    public static function resumeAdmin(int $id, $actor): ?array
    {
        return DB::transaction(function () use ($id, $actor) {
            $u = self::adminGuard($id, $actor, false);
            if (is_array($u)) return isset($u['null']) ? null : $u;
            if ($u->active) return ['type' => 'ok', 'msg' => 'すでに使えます。'];
            DB::table('users')->where('id', $id)->update(['active' => 1, 'updated_at' => now_str()]);
            N::audit($actor->id ?? null, 'admin-resume', "#user {$id}");
            $msg = "{$u->name} さんを再開しました。";
            // パスワードを決めていなかった人は、招待のリンクが無いので、送り直す方法を添える
            if (!$u->registered_at) $msg .= '仮登録のままなので、『送り直し』でご案内を送ってください。';
            return ['type' => 'ok', 'msg' => $msg];
        });
    }

    public static function leaveAdmin(int $id, $actor): ?array
    {
        return DB::transaction(function () use ($id, $actor) {
            $u = self::adminGuard($id, $actor, true);
            if (is_array($u)) return isset($u['null']) ? null : $u;
            // エンジニアを兼ねている人は、管理者の役割だけ外す（エンジニアとしては引き続き入れる）
            if (self::isDual($u)) {
                $e = DB::table('engineers')->where('id', $u->engineer_id)->first();
                if (!$e) return ['type' => 'bad', 'msg' => 'エンジニアの記録が見つからないため、退会にできません。管理者にご確認ください。'];
                // エンジニアが停止中なら止めたまま、そうでなければ使える状態にする
                DB::table('users')->where('id', $id)->update(['role' => 'eg', 'ses_id' => $e->ses_id, 'active' => $e->suspended_at ? 0 : 1, 'updated_at' => now_str()]);
                DB::table('engineers')->where('id', $e->id)->update(['is_operator' => 0]);
                N::audit($actor->id ?? null, 'admin-leave', "#user {$id}");
                return ['type' => 'ok', 'msg' => "{$u->name} さんを管理者から外しました。エンジニアとしては引き続き入れます。"];
            }
            DB::table('users')->where('id', $id)->update(['active' => 0, 'left_at' => now_str(), 'token' => null, 'token_expires' => null, 'updated_at' => now_str()]);
            N::audit($actor->id ?? null, 'admin-leave', "#user {$id}");
            return ['type' => 'ok', 'msg' => "{$u->name} さんを退会済にしました。"];
        });
    }

    // エンジニアの停止：ログインできなくし、配信の候補から外す（status は変えない）。相手側にアプリ内のお知らせだけ出す
    public static function suspendEngineer(int $id, $actor): array
    {
        $e = DB::table('engineers')->find($id);
        if ($e->status === 'left') return ['type' => 'bad', 'msg' => '退会済のエンジニアは操作できません。'];
        if ($e->suspended_at) return ['type' => 'ok', 'msg' => 'すでに停止中です。'];
        DB::transaction(function () use ($id) {
            DB::table('engineers')->where('id', $id)->update(['suspended_at' => now_str(), 'updated_at' => now_str()]);
            DB::table('users')->where('engineer_id', $id)->where('role', 'eg')->update(['active' => 0, 'token' => null, 'token_expires' => null]);
        });
        N::audit($actor->id ?? null, 'engineer-suspend', $e->name);
        self::tellEngineerChange($e, $actor, '停止');
        return ['type' => 'ok', 'msg' => 'エンジニア「'.$e->name.'」を停止しました。'];
    }

    public static function resumeEngineer(int $id, $actor): array
    {
        $e = DB::table('engineers')->find($id);
        if ($e->status === 'left') return ['type' => 'bad', 'msg' => '退会済のエンジニアは操作できません。'];
        if (!$e->suspended_at) return ['type' => 'ok', 'msg' => '停止していません。'];
        // 停止のとき active=0 にした本人のアカウントを戻す。退会の記録（left_at）がある人は戻さない
        DB::transaction(function () use ($id) {
            DB::table('engineers')->where('id', $id)->update(['suspended_at' => null, 'updated_at' => now_str()]);
            DB::table('users')->where('engineer_id', $id)->where('role', 'eg')->whereNull('left_at')->update(['active' => 1]);
        });
        N::audit($actor->id ?? null, 'engineer-resume', $e->name);
        self::tellEngineerChange($e, $actor, '再開');
        return ['type' => 'ok', 'msg' => 'エンジニア「'.$e->name.'」を再開しました。'];
    }

    // 管理者が押したら所属の SES へ、SES が押したら管理者へ（メールは送らない。本人には何も送らない）
    private static function tellEngineerChange(object $e, $actor, string $what): void
    {
        $title = "エンジニアを{$what}しました";
        $body = "{$e->name} さんを{$what}しました。";
        if (($actor->role ?? '') === 'admin') {
            if (!self::isDirect((int) $e->ses_id)) N::toSes($e->ses_id, $title, $body, "/engineers/{$e->id}", false, 'engineer-'.($what === '停止' ? 'suspend' : 'resume'));
        } else {
            N::toAdmins($title, $body, "/engineers/{$e->id}", false, 'engineer-'.($what === '停止' ? 'suspend' : 'resume'));
        }
    }

    // 1つの欄の氏名を、name と姓・名の列にして返す（1欄の入口：初めの設定・招待・担当者の追加など）
    public static function nameCols(string $full, ?string $sei = null, ?string $mei = null): array
    {
        if ($sei === null && $mei === null) [$sei, $mei] = split_name($full);
        return ['name' => $full, 'name_sei' => self::cut50($sei), 'name_mei' => self::cut50($mei)];
    }

    // ふりがなの列（姓・名の2欄。つないだ kana も残す）
    public static function kanaCols(array $d): array
    {
        $ks = clean_name($d['kana_sei'] ?? null); $km = clean_name($d['kana_mei'] ?? null);
        return ['kana_sei' => self::cut50($ks), 'kana_mei' => self::cut50($km), 'kana' => join_name($ks, $km)];
    }

    // SES・担当者の氏名の列（姓・名の2欄、または古い1欄から）
    public static function sesPeopleCols(array $d): array
    {
        [$rep, $rs, $rm] = name_parts($d, 'rep', 'rep_name');
        [$con, $cs, $cm] = name_parts($d, 'contact', 'contact_name');
        $cols = ['rep_name' => $rep, 'rep_sei' => self::cut50($rs), 'rep_mei' => self::cut50($rm), 'contact_name' => $con, 'contact_sei' => self::cut50($cs), 'contact_mei' => self::cut50($cm)];
        // 担当者のふりがな。この欄を送らない画面（申し込み・SES の会社情報）では、今の値を消さない
        if (array_key_exists('contact_kana_sei', $d) || array_key_exists('contact_kana_mei', $d)) {
            $cols += ['contact_kana_sei' => self::cut50(clean_name($d['contact_kana_sei'] ?? null)), 'contact_kana_mei' => self::cut50(clean_name($d['contact_kana_mei'] ?? null))];
        }
        return $cols;
    }

    // 郵便番号・住所の検査とそろえ。$d に postal_code・address のキーがあるものだけ返す（無ければ今の値のまま）。
    // 戻り値は ['ok' => [列 => 値]] か ['bad' => '文']。$label は住所の画面の名前（住所・所在地・現住所）
    public static function addressCols(array $d, string $label = '住所'): array
    {
        $ok = [];
        if (array_key_exists('postal_code', $d)) {
            $p = postal_norm(is_string($d['postal_code']) ? $d['postal_code'] : null);
            if ($p === false) return ['bad' => '郵便番号は7桁の数字で入れてください（例：123-4567）。'];
            $ok['postal_code'] = $p;
        }
        if (array_key_exists('address', $d)) {
            $a = clean_name($d['address']);
            if ($a !== null && mb_strlen($a) > 200) return ['bad' => $label.'は200文字までにしてください。'];
            $ok['address'] = $a;
        }
        return ['ok' => $ok];
    }

    private static function cut50(?string $s): ?string
    {
        return $s === null ? null : mb_substr($s, 0, 50);
    }

    // 登録済みの全員の name・kana を姓・名に分けて入れる（マイグレーション用。name・kana は書き換えない）
    public static function splitExistingNames(): void
    {
        foreach (['users', 'engineers'] as $table) {
            DB::table($table)->orderBy('id')->chunkById(200, function ($rows) use ($table) {
                foreach ($rows as $row) {
                    [$ns, $nm] = split_name($row->name);
                    [$ks, $km] = split_name($row->kana);
                    DB::table($table)->where('id', $row->id)->update(['name_sei' => self::cut50($ns), 'name_mei' => self::cut50($nm), 'kana_sei' => self::cut50($ks), 'kana_mei' => self::cut50($km)]);
                }
            });
        }
    }

    // ---------- 兼任（1つのログインで、エンジニアと SES 担当者・管理者を兼ねる。2026-10-20） ----------
    // 下の関数は、DB の行（DB::table('users') の結果）で判定する。画面の $u->role は「いまの立場」に差し替わっているため

    // エンジニアと、SES 担当者（会社つき）か管理者を兼ねているか
    public static function isDual(object $u): bool
    {
        if (empty($u->engineer_id)) return false;
        $role = $u->role ?? '';
        return $role === 'admin' || ($role === 'ses' && !empty($u->ses_id));
    }

    // その人が持つ立場（兼任なら2つ）
    public static function rolesOf(object $u): array
    {
        if (!self::isDual($u)) return [$u->role];
        return [$u->role, 'eg'];
    }

    // いま使える立場。eg＝エンジニアが退会済でも停止中でもない／ses＝会社が参加中／admin＝いつも使える
    public static function usableRoles(object $u): array
    {
        $out = [];
        foreach (self::rolesOf($u) as $role) {
            if ($role === 'eg') {
                $e = $u->engineer_id ? DB::table('engineers')->find($u->engineer_id) : null;
                if ($e && $e->status !== 'left' && !$e->suspended_at) $out[] = 'eg';
            } elseif ($role === 'ses') {
                $s = $u->ses_id ? DB::table('ses_companies')->find($u->ses_id) : null;
                if ($s && $s->status === 'active' && $s->kind === 'ses') $out[] = 'ses';
            } else {
                $out[] = $role;
            }
        }
        return $out;
    }

    private static function usableUserRow(string $email)
    {
        // 役割を足せるのは、本登録が済んだ人だけ（仮登録中は断る。立場が変わってしまうため）
        return DB::table('users')->where('email', strtolower($email))->where('active', 1)->whereNull('left_at')->whereNotNull('registered_at');
    }

    // エンジニアの役割を足せる人：同じ SES の担当者、または（実行者が管理者のとき）管理者。すでに別のエンジニアと兼任している人は除く
    public static function egShareTarget(string $email, int $engineerSesId, $actor): ?object
    {
        $u = self::usableUserRow($email)->whereNull('engineer_id')->first();
        if (!$u) return null;
        if ($u->role === 'ses' && (int) $u->ses_id === $engineerSesId) return $u;
        if ($u->role === 'admin' && ($actor->role ?? '') === 'admin') return $u;
        return null;
    }

    // SES 担当者の役割を足せる人：同じ SES のエンジニアのアカウント
    public static function sesShareTarget(string $email, int $sesId): ?object
    {
        $u = self::usableUserRow($email)->where('role', 'eg')->where('ses_id', $sesId)->whereNotNull('engineer_id')->first();
        if (!$u) return null;
        $e = DB::table('engineers')->find($u->engineer_id);
        if (!$e || (int) $e->ses_id !== $sesId || $e->status === 'left') return null;
        // 会社が守秘義務に同意済みでなければ、兼任にしない（SES の画面に入る前に同意が要るため）
        return DB::table('ses_companies')->where('id', $sesId)->whereNotNull('agreed_at')->exists() ? $u : null;
    }

    // 管理者の役割を足せる人：エンジニアのアカウント
    public static function adminShareTarget(string $email): ?object
    {
        $u = self::usableUserRow($email)->where('role', 'eg')->whereNotNull('engineer_id')->first();
        if (!$u) return null;
        $e = DB::table('engineers')->find($u->engineer_id);
        return $e && $e->status !== 'left' ? $u : null;
    }

    // 担当者（または管理者）のアカウントに、エンジニアの役割を足す。管理者には「運営者の印」を付ける
    public static function linkEngineer(int $userId, int $engineerId, $actor): void
    {
        $u = DB::table('users')->find($userId);
        DB::table('users')->where('id', $userId)->update(['engineer_id' => $engineerId, 'updated_at' => now_str()]);
        if ($u->role === 'admin') DB::table('engineers')->where('id', $engineerId)->update(['is_operator' => 1]);
        N::audit($actor->id ?? null, 'dual-link', "#user {$userId} ← エンジニア #{$engineerId}");
        if ((int) ($actor->id ?? 0) !== $userId) {
            N::notify($userId, 'エンジニアとしても登録されました', 'ログインのとき「エンジニア」を選ぶと、エンジニアの画面に入れます。', '/eg', true, 'dual', 'eg');
        }
    }

    // エンジニアのアカウントに、SES 担当者の役割を足す
    public static function addSesRole(int $userId, $actor): void
    {
        DB::table('users')->where('id', $userId)->update(['role' => 'ses', 'updated_at' => now_str()]);
        N::audit($actor->id ?? null, 'dual-add-ses', "#user {$userId}");
        N::notify($userId, 'SES のご担当者としても登録されました', 'ログインのとき「SES 担当者」を選ぶと、SES の画面に入れます。', '/ses', true, 'dual', 'ses');
    }

    // エンジニアのアカウントに、管理者の役割を足す。$d は管理者の登録の画面の値（企業名・所属・電話・郵便番号・住所）
    public static function addAdminRole(int $userId, array $d, $actor): void
    {
        $u = DB::table('users')->find($userId);
        // 空の値で、既にある値を消さない
        $upd = ['role' => 'admin', 'ses_id' => null, 'updated_at' => now_str()];
        if (($c = clean_name($d['company_name'] ?? null)) !== null) $upd['company_name'] = $c;
        foreach (['position', 'phone', 'postal_code', 'address'] as $f) if (($v = str_in($d[$f] ?? '')) !== null) $upd[$f] = $v;
        DB::table('users')->where('id', $userId)->update($upd);
        if ($u->engineer_id) DB::table('engineers')->where('id', $u->engineer_id)->update(['is_operator' => 1]);
        N::audit($actor->id ?? null, 'dual-add-admin', "#user {$userId}");
        N::notify($userId, '管理者としても登録されました', 'ログインのとき「管理者」を選ぶと、管理者の画面に入れます。', '/admin', true, 'dual', 'admin');
    }

    // 管理者が、自分のエンジニアに関わる操作をするとき：記録の頭に付ける印（公平性のため）。そうでなければ空
    public static function selfMark($actor, ?int $engineerId): string
    {
        if (!$engineerId || base_role() !== 'admin' || ($actor->role ?? '') !== 'admin') return '';
        $own = DB::table('users')->where('id', $actor->id ?? 0)->value('engineer_id');
        return $own && (int) $own === (int) $engineerId ? '［本人］' : '';
    }

    // パスワードを持つ行を作る。使うのは「はじめの設定」・見本・テストだけ（人を登録するときは仮登録を使う）なので、本登録済みの日時を入れる
    public static function createUser(string $email, string $name, string $role, string $password, array $extra = []): int
    {
        return DB::table('users')->insertGetId(['email' => strtolower($email)] + self::nameCols($name, $extra['sei'] ?? null, $extra['mei'] ?? null) + ['role' => $role, 'password' => Hash::make($password),
            'ses_id' => $extra['ses_id'] ?? null, 'engineer_id' => $extra['engineer_id'] ?? null, 'registered_at' => now_str(), 'created_at' => now_str(), 'updated_at' => now_str()]);
    }

    // 管理者の情報（氏名・企業名・メール）を直す。$d は name_sei・name_mei・company_name・email（メールは無ければ変えない）。
    // 仮登録中（本登録が済む前）は、企業名・姓・名・メールの4つだけ直せる（ほかは本人が本登録で入れる）。同じ行のまま。
    // メールを変えられるのは、仮登録中の他人だけ。変えたら新しいアドレスに仮登録のご案内を送り直す（前のリンクは使えなくなる）。自分・停止中・本登録が済んだ人は変えられない。
    // 必須の欄（企業名・姓・名・電話）は、本人も上位も空にできない（K20）
    public static function updateAdmin(int $id, array $d, $actor): array
    {
        $bad = fn (string $msg) => ['type' => 'bad', 'msg' => $msg];
        $u = DB::table('users')->where('id', $id)->where('role', 'admin')->first();
        if (!$u) return $bad('この管理者は見つかりません。');

        // 仮登録中は、4つの項目だけ受け取る（電話などを混ぜても入らない）
        if (!$u->registered_at) $d = array_intersect_key($d, array_flip(['name_sei', 'name_mei', 'company_name', 'email']));

        // 姓・名の列が空の古い行は、つないだ name を分けたものを基準にする（何も変えずに保存したら「変化なし」）
        [$curSei, $curMei] = ($u->name_sei || $u->name_mei) ? [$u->name_sei, $u->name_mei] : split_name($u->name);
        $sei = clean_name($d['name_sei'] ?? null); $company = clean_name($d['company_name'] ?? null);
        $mei = array_key_exists('name_mei', $d) ? clean_name($d['name_mei']) : $curMei; // 名の欄が来なければ今のまま
        if ($sei === null) return $bad('氏名（姓）を入れてください。');
        if ($company === null) return $bad('企業名を入れてください。');
        // 必須の欄（名・電話）は、欄が来て空なら断る
        $given = [];
        foreach (['name_mei', 'phone'] as $k) if (array_key_exists($k, $d)) $given[$k] = is_string($d[$k]) ? clean_name($d[$k]) : null;
        if ($why = Requirements::checkNotEmpty('admin', false, $given)) return $bad($why);
        if (mb_strlen($sei) > 50 || mb_strlen((string) $mei) > 50) return $bad('氏名は姓・名とも50文字までです。');
        if (mb_strlen($company) > 100) return $bad('企業名は100文字までです。');

        // メール：小文字にそろえて比べる。大文字小文字だけの違いは「変えていない」
        $old = strtolower((string) $u->email);
        $new = array_key_exists('email', $d) ? strtolower(trim((string) $d['email'])) : $old;
        $emailChanged = $new !== $old;
        if ($emailChanged) {
            if ((int) $u->id === (int) ($actor->id ?? 0)) return $bad('ご自分のメールアドレスは変えられません。');
            if (!$u->active) return $bad('停止中の人のメールアドレスは変えられません。');
            if ($u->password || $u->registered_at) return $bad('使用中の方のメールアドレスは変えられません。');
            if ($new === '' || !filter_var($new, FILTER_VALIDATE_EMAIL)) return $bad('メールアドレスを正しく入れてください。');
            if (DB::table('users')->where('email', $new)->where('id', '!=', $id)->exists()) return $bad('このメールアドレスはすでに使われています。');
        }

        // 所属・役職・電話・郵便番号・住所（キーが来たものだけ。検査は保存の前に全部すませる）
        $extra = [];
        if (array_key_exists('position', $d)) {
            $pos = str_in($d['position']);
            if ($pos !== null && mb_strlen($pos) > 100) return $bad('所属・役職は100文字までにしてください。');
            $extra['position'] = $pos;
        }
        if (array_key_exists('phone', $d)) {
            $tel = str_in($d['phone']);
            if ($tel !== null && (mb_strlen($tel) > 30 || !preg_match('/^[0-9+\-() ]*$/', $tel))) return $bad('電話番号は30文字までの、数字・+・-・()・空白で入れてください。');
            $extra['phone'] = $tel;
        }
        $ad = self::addressCols($d);
        if (isset($ad['bad'])) return $bad($ad['bad']);
        $extra += $ad['ok'];

        $ks = clean_name($d['kana_sei'] ?? null); $km = clean_name($d['kana_mei'] ?? null);
        if (mb_strlen((string) $ks) > 50 || mb_strlen((string) $km) > 50) return $bad('ふりがなは姓・名とも50文字までです。');
        $name = join_name($sei, $mei);
        $upd = [];
        if ($sei !== $curSei || $mei !== $curMei) $upd += ['name_sei' => $sei, 'name_mei' => $mei, 'name' => $name];
        if (array_key_exists('kana_sei', $d) || array_key_exists('kana_mei', $d)) {
            [$curKs, $curKm] = ($u->kana_sei || $u->kana_mei) ? [$u->kana_sei, $u->kana_mei] : split_name($u->kana);
            if ($ks !== $curKs || $km !== $curKm) $upd += ['kana_sei' => $ks, 'kana_mei' => $km, 'kana' => join_name($ks, $km)];
        }
        if ($company !== $u->company_name) $upd['company_name'] = $company;
        if ($emailChanged) $upd['email'] = $new;
        $changedExtra = [];
        foreach ($extra as $f => $val) {
            if ($val !== ($u->$f ?? null)) { $upd[$f] = $val; $changedExtra[] = $f; }
        }
        if (!$upd) return ['type' => 'ok', 'msg' => '保存しました。'];

        DB::transaction(fn () => DB::table('users')->where('id', $id)->update($upd + ['updated_at' => now_str()]));

        $msg = '保存しました。';
        if ($emailChanged) { // ここに来るのは仮登録中の人だけ。新しいリンクで、前のリンクは使えなくなる
            Registration::sendLink(DB::table('users')->find($id), $actor);
            $msg = '保存し、新しいメールアドレスに仮登録のご案内を送り直しました（前のリンクは使えなくなりました）。';
        }

        $log = [];
        if (isset($upd['name'])) $log[] = '氏名：'.$u->name.'→'.$name;
        if (isset($upd['company_name'])) $log[] = '企業名：'.$u->company_name.'→'.$company;
        if ($emailChanged) $log[] = "メール：{$old}→{$new}";
        // 電話・住所は個人情報なので、変えたという項目の名前だけを記録する（中身は書かない）
        $labels = ['position' => '所属・役職', 'phone' => '電話番号', 'postal_code' => '住所', 'address' => '住所'];
        $names = array_unique(array_map(fn ($f) => $labels[$f], $changedExtra));
        if ($names) $log[] = implode('・', $names).'を変更';
        N::audit($actor->id ?? null, 'admin-edit', mb_substr("#$id ".implode('／', $log), 0, 300));
        return ['type' => 'ok', 'msg' => $msg];
    }

    // ---------- 最初の情報登録 ----------
    // 氏名・ふりがな・電話・所属／役職を保存する。登録を済ませた日時は、最初の1回だけ入れる
    // 管理者は企業名、SES は SES名（ses_companies.name）も保存する。戻り値は、画面に出す注意（なければ null）
    public static function saveProfile($user, array $d): ?string
    {
        $upd = ['updated_at' => now_str()];
        // ふりがな・所属／役職も、送られたときだけ更新する（送られなかった欄を空で上書きしない）
        if (array_key_exists('kana_sei', $d) || array_key_exists('kana_mei', $d)) {
            $ks = clean_name($d['kana_sei'] ?? null); $km = clean_name($d['kana_mei'] ?? null);
            $upd += ['kana_sei' => $ks, 'kana_mei' => $km, 'kana' => join_name($ks, $km)];
        }
        // 電話は必須なので、送られなかったときは今の値のまま（送られて空なら、呼ぶ側が断っている）
        if (array_key_exists('phone', $d)) $upd['phone'] = str_in($d['phone'] ?? '');
        // 姓・名がどちらも空なら、今の氏名のまま
        $ns = clean_name($d['name_sei'] ?? null); $nm = clean_name($d['name_mei'] ?? null);
        if ($ns !== null || $nm !== null) $upd += ['name_sei' => $ns, 'name_mei' => $nm, 'name' => join_name($ns, $nm)];
        if ($user->role !== 'eg' && array_key_exists('position', $d)) $upd['position'] = str_in($d['position'] ?? '');
        if ($user->role === 'admin' && ($c = str_in($d['company_name'] ?? '')) !== null) $upd['company_name'] = $c;
        // 管理者の郵便番号・住所（呼ぶ側で検査済み。キーが来たものだけ保存）
        if ($user->role === 'admin') {
            foreach (['postal_code', 'address'] as $f) if (array_key_exists($f, $d)) $upd[$f] = str_in($d[$f] ?? '');
        }
        if (!DB::table('users')->where('id', $user->id)->value('profile_completed_at')) $upd['profile_completed_at'] = now_str();
        $warn = null;
        DB::transaction(function () use ($user, $upd, $ns, $nm) {
            DB::table('users')->where('id', $user->id)->update($upd);
            // SES が姓名を直したら、会社の SES の欄も同じにする（企業名はここでは変えられない）
            if ($user->role === 'ses' && ($ns !== null || $nm !== null) && Requirements::isRep($user)) Registration::syncRepName((int) $user->ses_id, $ns, $nm);
        });
        self::syncEngineerFromUser($user->id); // エンジニア本人なら、技術登録にもそろえる（兼任の人は対象外）
        N::audit($user->id, 'profile', '');
        return $warn;
    }

    // SES名を変える（最初の情報登録・アカウント・会社の画面の三つの入口で同じ動き）。変えたときだけ記録して、管理者にベルで知らせる。
    // 同じ名前の SES がほかにあれば、その注意の文を返す
    public static function renameSes(int $sesId, string $name, $actor): ?string
    {
        $name = trim($name);
        $s = DB::table('ses_companies')->find($sesId);
        if (!$s || $s->kind !== 'ses' || $name === '' || $s->name === $name) return null;
        DB::table('ses_companies')->where('id', $sesId)->update(['name' => $name, 'updated_at' => now_str()]);
        N::audit($actor->id ?? null, 'ses-rename', "{$s->name} → $name");
        $dup = DB::table('ses_companies')->where('id', '!=', $sesId)->where('name', $name)->exists();
        N::toAdmins('SES の名前が変わりました', "{$s->name} が名前を $name に変えました。".($dup ? '（同じ名前の SES がほかにもあります）' : ''), "/admin/ses/$sesId", false, 'ses-rename');
        return $dup ? '同じ名前の SES がほかにもあります。見分けられるよう、正式名称で入れてください。' : null;
    }

    // 情報の登録のあとに進む先と、そこに出すお知らせ（なければ null）
    public static function profileHome($user): array
    {
        if ($user->role === 'ses') {
            return ['/ses', null];
        }
        if ($user->role === 'eg') {
            if (!DB::table('engineers')->where('id', $user->engineer_id)->value('self_confirmed_at')) return ['/engineers/'.$user->engineer_id, '登録の内容を確かめて「確認しました」を押してください。'];
            return ['/eg', null];
        }
        return ['/admin', null];
    }

    // ---------- 管理者の直属 ----------
    // 管理者に直接つくエンジニアの入れ物（SES ではない）。必要になったときに1つだけ作る
    public static function directSesId(): int
    {
        $id = DB::table('ses_companies')->where('kind', 'direct')->orderBy('id')->value('id');
        if ($id) return (int) $id;
        return (int) DB::table('ses_companies')->insertGetId(['name' => '管理者の直属', 'kind' => 'direct', 'status' => 'active',
            'self_confirmed_at' => now_str(), 'joined_at' => today_str(), 'created_at' => now_str(), 'updated_at' => now_str()]);
    }

    public static function isDirect(?int $sesId): bool
    {
        return $sesId && DB::table('ses_companies')->where('id', $sesId)->value('kind') === 'direct';
    }

    // ---------- SES ----------
    // 管理者（$actor が admin）が登録するときは、申し込みの通知と受付メールを出さない
    public static function applySes(array $d, $actor = null): int
    {
        $id = DB::table('ses_companies')->insertGetId([
            'name' => str_in($d['name'] ?? ''), ...self::sesPeopleCols($d),
            'email' => strtolower((string) str_in($d['email'] ?? '')), 'phone' => str_in($d['phone'] ?? ''),
            'postal_code' => postal_norm(is_string($d['postal_code'] ?? null) ? $d['postal_code'] : null) ?: null, 'address' => str_in($d['address'] ?? ''),
            'employees' => int_or_null($d['employees'] ?? null), 'strengths' => str_in($d['strengths'] ?? ''), 'message' => str_in($d['message'] ?? ''),
            'notes' => str_in($d['notes'] ?? ''), 'status' => 'pending', 'agreed_at' => !empty($d['agree']) ? now_str() : null, 'created_at' => now_str(), 'updated_at' => now_str(),
        ]);
        if (($actor->role ?? '') === 'admin') return $id;
        $name = str_in($d['name'] ?? ''); $contact = self::sesPeopleCols($d)['contact_name'];
        N::toAdmins('SES から参加の申し込みがありました', "{$name}（{$contact} 様）から参加の申し込みがありました。内容を確認し、承認してください。", "/admin/ses/$id", true, 'ses-apply');
        N::queueMail(str_in($d['email'] ?? ''), $contact, '参加のお申し込みを受け付けました', "{$contact} 様\n\nPARK への参加のお申し込みを受け付けました。管理者が内容を確認のうえ、ご連絡します。\n\n会社名：{$name}", 'ses-apply');
        return $id;
    }

    // ---------- エンジニア ----------
    const ENG_FIELDS = ['name', 'kana', 'initials', 'email', 'birth_year', 'nearest_station', 'years_exp', 'summary', 'strengths', 'desired_price', 'min_price', 'available_from', 'work_style', 'days_per_week', 'status',
        // 履歴書・業務経歴書の項目（2026-10-16）
        'gender', 'phone', 'postal_code', 'address', 'address_kana', 'nearest_line', 'walk_minutes', 'education', 'graduated_ym', 'career_summary', 'desired_roles', 'english_level', 'appeal', 'wishes', 'birth_date', 'nickname',
        // 氏名・ふりがなの姓・名（2026-10-19。末尾に足した）
        'name_sei', 'name_mei', 'kana_sei', 'kana_mei'];

    // 詳しいプロフィールの画面で保存する項目（gender から nickname まで。姓・名は含めない）
    const ENG_PROFILE_FIELDS = ['gender', 'phone', 'postal_code', 'address', 'address_kana', 'nearest_line', 'walk_minutes', 'education', 'graduated_ym', 'career_summary',
        'desired_roles', 'english_level', 'appeal', 'wishes', 'birth_date', 'nickname'];

    private static function engValues(array $d, ?object $old = null): array
    {
        // 姓・名の欄があればそれをつなぐ。無くて name・kana だけなら分ける（見本・AI・古い画面のため）
        foreach (['name' => 'name', 'kana' => 'kana'] as $full => $base) {
            if (array_key_exists($base.'_sei', $d) || array_key_exists($base.'_mei', $d)) {
                $d[$base.'_sei'] = clean_name($d[$base.'_sei'] ?? null);
                $d[$base.'_mei'] = clean_name($d[$base.'_mei'] ?? null);
                $d[$full] = join_name($d[$base.'_sei'], $d[$base.'_mei']);
            } elseif (array_key_exists($full, $d)) {
                [$d[$base.'_sei'], $d[$base.'_mei']] = array_map([self::class, 'cut50'], split_name(is_string($d[$full]) ? $d[$full] : null));
            }
        }
        $v = [];
        foreach (self::ENG_FIELDS as $f) {
            if (!array_key_exists($f, $d)) { $v[$f] = $old->$f ?? null; continue; }
            if (in_array($f, ['birth_year', 'days_per_week', 'walk_minutes'])) $v[$f] = int_or_null($d[$f]);
            elseif (in_array($f, ['years_exp', 'desired_price', 'min_price'])) $v[$f] = num_or_null($d[$f]);
            else $v[$f] = str_in($d[$f]);
        }
        if (!$v['initials'] && $v['kana']) $v['initials'] = self::initialsFromKana($v['kana']);
        if ($v['birth_date']) $v['birth_year'] = (int) substr($v['birth_date'], 0, 4); // 生年月日があれば、年もそろえる
        $v['email'] = $v['email'] ? strtolower($v['email']) : null;
        $v['work_style'] = $v['work_style'] ?: 'any';
        $v['status'] = $v['status'] ?: 'available';
        $v['days_per_week'] = $v['days_per_week'] ?: 5;
        return $v;
    }

    // 「やまだ たろう」→「T.Y」（スキルシートで名前を伏せるとき用）
    public static function initialsFromKana(string $kana): ?string
    {
        $rows = ['A' => 'あ', 'I' => 'い', 'U' => 'う', 'E' => 'え', 'O' => 'お', 'K' => 'かきくけこ', 'G' => 'がぎぐげご', 'S' => 'さしすせそ', 'J' => 'じ', 'Z' => 'ざずぜぞ',
            'T' => 'たつてと', 'C' => 'ち', 'D' => 'だぢづでど', 'N' => 'なにぬねの', 'H' => 'はひへほ', 'F' => 'ふ', 'B' => 'ばびぶべぼ', 'P' => 'ぱぴぷぺぽ',
            'M' => 'まみむめも', 'Y' => 'やゆよ', 'R' => 'らりるれろ', 'W' => 'わ'];
        $hira = mb_convert_kana($kana, 'cs');
        $parts = preg_split('/\s+/u', trim($hira));
        if (count($parts) < 2) return null;
        $ini = function ($ch) use ($rows) { foreach ($rows as $r => $chars) if (mb_strpos($chars, $ch) !== false) return $r; return '?'; };
        return $ini(mb_substr($parts[1], 0, 1)).'.'.$ini(mb_substr($parts[0], 0, 1));
    }

    // $linked：同じメールアドレスの担当者・管理者のアカウントに、エンジニアの役割を足したとき、その人の行が入る（招待は送らない）
    // $tellAdmins：管理者に「登録されました」のお知らせを出すか（仮登録のときは出さない。本登録のとき Registration が知らせる）
    public static function createEngineer(int $sesId, array $d, $actor, bool $invite = true, ?object &$linked = null, bool $tellAdmins = true): int
    {
        $v = self::engValues($d);
        $id = DB::table('engineers')->insertGetId($v + ['ses_id' => $sesId, 'created_at' => now_str(), 'updated_at' => now_str()]);
        N::audit($actor->id ?? null, 'engineer-create', "{$v['name']}（SES {$sesId}）");
        $linked = null;
        if ($v['email'] && ($t = self::egShareTarget($v['email'], $sesId, $actor))) {
            self::linkEngineer($t->id, $id, $actor);
            $linked = $t;
        } elseif ($invite && $v['email'] && !DB::table('users')->where('email', $v['email'])->exists()) {
            // ログインの無いエンジニアに、仮登録のアカウントを作ってご案内を送る（技術登録の氏名・ふりがな・電話を写す。B1・AC7）
            Registration::resendEngineer(DB::table('engineers')->find($id), $actor);
        }
        if ($tellAdmins) N::toAdmins('エンジニアが登録されました', "{$v['name']} さんが登録されました。経験票・スキルシートの作成を一緒に進めてください。", "/engineers/$id", false);
        return $id;
    }

    public static function updateEngineer(int $id, array $d, $actor): void
    {
        $old = DB::table('engineers')->find($id);
        $v = self::engValues($d, $old);
        DB::table('engineers')->where('id', $id)->update($v + ['updated_at' => now_str()]);
        N::audit($actor->id, 'engineer-update', self::selfMark($actor, $id).$v['name']);
        self::syncUsersFromEngineer($id); // 氏名・ふりがな・電話を、本人のアカウントにもそろえる
        self::editedBy($id, $actor);
    }

    // ---------- エンジニアの氏名・ふりがな・電話を、アカウント（users）と技術登録（engineers）でそろえる（B1） ----------
    // 兼任の人（users.role が ses・admin）は対象にしない。電話は会社のものかもしれないため。self_confirmed_at には触らない

    // アカウントの値を、技術登録に写す。DB の行を読み直し、role='eg' で engineer_id があるときだけ
    public static function syncEngineerFromUser(int $userId): void
    {
        $u = DB::table('users')->find($userId);
        if (!$u || $u->role !== 'eg' || !$u->engineer_id) return;
        $e = DB::table('engineers')->find($u->engineer_id);
        if (!$e) return;
        $upd = ['kana' => $u->kana, 'kana_sei' => $u->kana_sei, 'kana_mei' => $u->kana_mei, 'phone' => $u->phone, 'updated_at' => now_str()];
        // 姓・名がどちらも空なら、氏名は今のまま
        if ($u->name_sei !== null || $u->name_mei !== null) $upd += ['name' => $u->name, 'name_sei' => $u->name_sei, 'name_mei' => $u->name_mei];
        if (empty($e->initials) && $u->kana) $upd['initials'] = self::initialsFromKana($u->kana);
        DB::table('engineers')->where('id', $e->id)->update($upd);
    }

    // 技術登録の値を、そのエンジニア本人のアカウント（role='eg' の行だけ）に写す
    public static function syncUsersFromEngineer(int $engineerId): void
    {
        $e = DB::table('engineers')->find($engineerId);
        if (!$e) return;
        DB::table('users')->where('engineer_id', $engineerId)->where('role', 'eg')->update([
            'name' => $e->name, 'name_sei' => $e->name_sei, 'name_mei' => $e->name_mei,
            'kana' => $e->kana, 'kana_sei' => $e->kana_sei, 'kana_mei' => $e->kana_mei, 'phone' => $e->phone, 'updated_at' => now_str(),
        ]);
    }

    // 登録の内容は、本人が責任を持つ。管理者・SES が書き換えたら、本人にもう一度確かめてもらう
    public static function editedBy(int $engineerId, $actor): void
    {
        if ($actor && $actor->role !== 'eg') DB::table('engineers')->where('id', $engineerId)->update(['self_confirmed_at' => null]);
    }

    public static function confirmEngineer(int $engineerId, $actor): void
    {
        DB::table('engineers')->where('id', $engineerId)->update(['self_confirmed_at' => now_str()]);
        N::audit($actor->id, 'engineer-confirm', (string) $engineerId);
    }

    // 成約した案件のうち、経験票に書いてもらう必要があるもの（始まったのにまだ、終わったのに終わってから書いていない）
    public static function historyTodo(int $engineerId)
    {
        return DB::table('contracts as k')->join('cases as c', 'c.id', '=', 'k.case_id')->where('k.engineer_id', $engineerId)
            ->where(fn ($q) => $q->whereNull('k.history_updated_at')->orWhere(fn ($q) => $q->where('k.status', 'ended')->whereColumn('k.history_updated_at', '<', 'k.end_date')))
            ->select('k.*', 'c.title')->orderByDesc('k.start_date')->get();
    }

    // スキルのチェックの保存の入口（中身は SkillChecks::save）。戻り値は ['ok' => true, 'count' => n, 'names' => [...], 'skipped' => n] か ['bad' => '理由']
    public static function saveSkills(int $engineerId, array $d): array
    {
        return SkillChecks::save($engineerId, $d);
    }

    const PROJ_FIELDS = ['title', 'industry', 'period_from', 'period_to', 'role', 'team_size', 'tech', 'phases', 'description', 'achievements', 'os_tools', 'langs', 'dbs', 'sort'];

    // 経歴を保存して、その経歴の id を返す（技術の索引も作り直す）。別のエンジニアの経歴 id を渡されたら何もしない（0 を返す）
    public static function saveProject(int $engineerId, array $d, ?int $projectId = null): int
    {
        $v = [];
        foreach (self::PROJ_FIELDS as $f) {
            if (in_array($f, ['team_size', 'sort'])) $v[$f] = int_or_null($d[$f] ?? null);
            elseif ($f === 'phases') $v[$f] = implode('、', parse_list(implode('、', (array) ($d[$f] ?? [])))) ?: null;
            else $v[$f] = str_in($d[$f] ?? '');
        }
        if ($v['sort'] === null) unset($v['sort']);
        return DB::transaction(function () use ($engineerId, $v, $projectId) {
            if ($projectId) {
                if (!DB::table('engineer_projects')->where('id', $projectId)->where('engineer_id', $engineerId)->exists()) return 0;
                DB::table('engineer_projects')->where('id', $projectId)->update($v);
                $pid = $projectId;
            } else {
                $pid = (int) DB::table('engineer_projects')->insertGetId($v + ['engineer_id' => $engineerId]);
            }
            ProjectSkills::rebuild($pid);
            DB::table('engineers')->where('id', $engineerId)->update(['updated_at' => now_str()]);
            return $pid;
        });
    }

    // 学歴・職歴・資格（行ごとに送られてくる。空の行は捨てる）
    public static function saveHistories(int $engineerId, array $d): void
    {
        DB::transaction(function () use ($engineerId, $d) {
            DB::table('engineer_histories')->where('engineer_id', $engineerId)->delete();
            $kinds = (array) ($d['h_kind'] ?? []); $yms = (array) ($d['h_ym'] ?? []); $texts = (array) ($d['h_text'] ?? []);
            foreach ($texts as $i => $text) {
                if (trim((string) $text) === '') continue;
                DB::table('engineer_histories')->insert(['engineer_id' => $engineerId, 'kind' => ($kinds[$i] ?? 'work') === 'edu' ? 'edu' : 'work', 'ym' => str_in($yms[$i] ?? ''), 'text' => trim($text), 'sort' => $i]);
            }
            DB::table('engineer_certs')->where('engineer_id', $engineerId)->delete();
            $names = (array) ($d['c_name'] ?? []); $cyms = (array) ($d['c_ym'] ?? []);
            foreach ($names as $i => $name) {
                if (trim((string) $name) === '') continue;
                DB::table('engineer_certs')->insert(['engineer_id' => $engineerId, 'name' => trim($name), 'ym' => str_in($cyms[$i] ?? ''), 'sort' => $i]);
            }
        });
        DB::table('engineers')->where('id', $engineerId)->update(['updated_at' => now_str()]);
    }

    // ---------- 案件 ----------
    const CASE_FIELDS = ['title', 'client_label', 'price', 'price_note', 'required_skills', 'preferred_skills', 'description', 'location', 'work_style', 'start_date', 'period',
        'days_per_week', 'interviews_count', 'headcount', 'min_years', 'conditions', 'deadline', 'status', 'raw_text'];

    // 必須・尚可の技術を正式な名前にそろえ、マスタに無いものは足して管理者に知らせる（案件の保存と、AI の結果の差し替えで使う）
    public static function normalizeCaseSkills(array $v, string $title): array
    {
        $added = [];
        foreach (['required_skills', 'preferred_skills'] as $f) {
            $list = [];
            foreach (parse_list($v[$f] ?? '') as $w) {
                if ($name = SkillCatalog::ensure($w)) $added[] = $name;
                $t = SkillCatalog::find($w);
                $list[] = $t[1] ?? $w;
            }
            $v[$f] = $list ? implode('、', array_unique($list)) : null;
        }
        if ($added) N::toAdmins('案件から新しい技術を技術マスタに追加しました', '「'.$title.'」に、マスタに無い技術がありました：'.implode('、', $added)."\n分類（言語・フレームワーク・DB など）と別名を確かめてください。", '/admin/skills', false);
        return $v;
    }

    public static function saveCase(array $d, $user, ?int $id = null): int
    {
        $v = [];
        foreach (self::CASE_FIELDS as $f) {
            if (in_array($f, ['days_per_week', 'interviews_count', 'headcount'])) $v[$f] = int_or_null($d[$f] ?? null);
            elseif (in_array($f, ['price', 'min_years'])) $v[$f] = num_or_null($d[$f] ?? null);
            else $v[$f] = str_in($d[$f] ?? '');
        }
        $v['work_style'] = $v['work_style'] ?: 'any';
        $v['status'] = $v['status'] ?: 'open';
        $v = self::normalizeCaseSkills($v, $v['title']);
        // 必須・尚可の業務（業務経験・業務領域）。技術マスタには通さない。画面（_exp）の保存のときだけ更新する。
        // 画面を通らない保存（見本など）では、新しい案件に限って、送られた文から入れる。更新では触らない
        if (array_key_exists('_exp', $d)) {
            $exp = is_array($d['exp'] ?? null) ? $d['exp'] : [];
            $req = []; $pref = [];
            foreach (CaseExp::itemsById() as $itemId => $name) {
                $pick = $exp[$itemId] ?? null;
                if ($pick === 'required') $req[] = $name;
                elseif ($pick === 'preferred') $pref[] = $name;
            }
            $v['required_exp'] = CaseExp::join($req);
            $v['preferred_exp'] = CaseExp::join($pref);
        } elseif (!$id) {
            $v['required_exp'] = CaseExp::join(CaseExp::split(is_string($d['required_exp'] ?? null) ? $d['required_exp'] : null));
            $v['preferred_exp'] = CaseExp::join(CaseExp::split(is_string($d['preferred_exp'] ?? null) ? $d['preferred_exp'] : null));
        }
        if ($id) {
            DB::table('cases')->where('id', $id)->update($v + ['updated_at' => now_str()]);
            N::audit($user->id, 'case-update', $v['title']);
            return $id;
        }
        $v['source_ses_id'] = $user->role === 'ses' ? $user->ses_id : int_or_null($d['source_ses_id'] ?? null);
        $newId = DB::table('cases')->insertGetId($v + ['registered_by' => $user->id, 'created_at' => now_str(), 'updated_at' => now_str()]);
        N::audit($user->id, 'case-create', $v['title']);
        if ($user->role === 'ses') N::toAdmins('SES から案件が登録されました', "「{$v['title']}」が登録されました。マッチングして配信してください。", "/cases/$newId", true, 'case');
        return $newId;
    }

    // 選んだエンジニアに案件を配信し、所属の SES にも知らせる
    public static function distribute(int $caseId, array $engineerIds, $actor, string $message = ''): int
    {
        $c = DB::table('cases')->find($caseId);
        $bySes = []; $count = 0;
        $idxs = Matcher::skillIndexMany($engineerIds); // 人数によらず、まとめて読む
        $exps = SkillChecks::expMany($engineerIds);
        foreach (array_map('intval', $engineerIds) as $eid) {
            // 仮登録・停止中・退会済は飛ばす。所属の会社が参加中（active。直属の入れ物も active）でなければ飛ばす
            $e = Registration::usableEngineers(DB::table('engineers')->where('id', $eid)
                ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('ses_companies')->whereColumn('ses_companies.id', 'engineers.ses_id')->where('ses_companies.status', 'active')))->first();
            if (!$e || DB::table('case_offers')->where('case_id', $caseId)->where('engineer_id', $eid)->exists()) continue;
            $s = Matcher::score($c, $e, $idxs[$eid] ?? [], $exps[$eid] ?? []);
            DB::table('case_offers')->insert(['case_id' => $caseId, 'engineer_id' => $eid, 'score' => $s['score'], 'reasons' => implode(' / ', $s['reasons']),
                'missing' => implode(' / ', $s['missing']), 'sent_by' => $actor->id, 'sent_at' => now_str()]);
            N::toEg($eid, "あなたに合う案件が届きました：{$c->title}",
                "{$c->title}\n単価：".($c->price ? man($c->price) : '相談').'／開始：'.($c->start_date ? fmt_date($c->start_date) : '相談').'／'.lbl('workStyle', $c->work_style)
                ."\n合う点：".(implode('、', array_slice($s['reasons'], 0, 4)) ?: '—').($message ? "\n\n管理者より：$message" : '')."\n\n条件を確認のうえ、応募するか見送るかを選んでください。",
                "/cases/$caseId", true, 'offer');
            $bySes[$e->ses_id][] = "{$e->name}（{$s['score']}点）";
            $count++;
        }
        foreach ($bySes as $sesId => $names) {
            if (self::isDirect($sesId)) continue; // 直属は、操作した管理者への知らせが要らない
            N::toSes($sesId, "貴社のエンジニアに案件を配信しました：{$c->title}", "次の方に「{$c->title}」を配信しました。\n".implode("\n", $names)."\n\nご本人が応募を決めるほか、貴社から個別にご案内いただくこともできます。", "/cases/$caseId", true, 'offer-ses');
        }
        $mark = '';
        foreach ($engineerIds as $eid) if ($mark === '') $mark = self::selfMark($actor, (int) $eid);
        N::audit($actor->id, 'distribute', "{$mark}{$c->title} → {$count}名");
        return $count;
    }

    // ---------- 応募と選考 ----------
    public static function addEvent(int $appId, string $status, ?string $note, $actor): void
    {
        DB::table('application_events')->insert(['application_id' => $appId, 'status' => $status, 'note' => $note ?? '', 'user_id' => $actor->id ?? null, 'at' => now_str()]);
    }

    public static function apply(int $caseId, int $engineerId, array $d, $actor): int
    {
        $c = DB::table('cases')->find($caseId);
        $e = DB::table('engineers')->find($engineerId);
        if (!$c || !$e) throw new \RuntimeException('案件またはエンジニアが見つかりません');
        if ($c->status !== 'open') throw new \RuntimeException('この案件は募集を終えています');
        if ($e->status === 'left') throw new \RuntimeException('このエンジニアは退会済のため、応募できません。');
        if ($e->suspended_at) throw new \RuntimeException('このエンジニアは停止中のため、応募できません。');
        if (!$e->registered_at) throw new \RuntimeException('このエンジニアは、本登録がまだ済んでいないため、応募できません。');
        if (!$e->self_confirmed_at) throw new \RuntimeException('エンジニアご本人が、まだ登録の内容を確認していません。ご本人がホームの「登録の内容の確認」を済ませてから応募してください');
        // GP の中での二重応募は受け付けない（同じ人が別の SES 経由で出るのを防ぐ）
        if (DB::table('applications')->where('case_id', $caseId)->where('engineer_id', $engineerId)->exists()) throw new \RuntimeException('この案件には応募済みです');
        $appId = DB::table('applications')->insertGetId([
            'case_id' => $caseId, 'engineer_id' => $engineerId, 'ses_id' => $e->ses_id, 'status' => 'applied',
            'proposed_price' => num_or_null($d['proposed_price'] ?? null) ?? $c->price,
            'start_ok' => !empty($d['start_ok']), 'price_ok' => !empty($d['price_ok']), 'style_ok' => !empty($d['style_ok']),
            'condition_note' => str_in($d['condition_note'] ?? ''), 'applied_by' => $actor->id, 'applied_at' => now_str(), 'updated_at' => now_str(),
        ]);
        self::addEvent($appId, 'applied', str_in($d['condition_note'] ?? ''), $actor);
        DB::table('case_offers')->where('case_id', $caseId)->where('engineer_id', $engineerId)->update(['status' => 'applied']);
        if ($e->status === 'available') DB::table('engineers')->where('id', $engineerId)->update(['status' => 'proposing']);
        $who = ['eg' => 'ご本人', 'ses' => '所属の SES', 'admin' => '管理者'][$actor->role];
        N::toAdmins("応募がありました：{$c->title}", "{$e->name} さんが「{$c->title}」に応募しました（{$who}から）。スキルシートを案件に合わせて整え、提出してください。", "/applications/$appId", true, 'apply');
        if (!self::isDirect($e->ses_id)) N::toSes($e->ses_id, "貴社のエンジニアが応募しました：{$c->title}", "{$e->name} さんが「{$c->title}」に応募しました。選考の進み具合は PARK でご確認いただけます。", "/applications/$appId", true, 'apply');
        if ($actor->role !== 'eg') N::toEg($engineerId, "案件に応募しました：{$c->title}", "「{$c->title}」に、{$who}からあなたの応募を出しました。条件に問題があれば、すぐにご連絡ください。", "/applications/$appId", true, 'apply');
        N::audit($actor->id, 'apply', self::selfMark($actor, $engineerId)."{$e->name} → {$c->title}");
        return $appId;
    }

    public static function refreshEngineerStatus(int $engineerId): void
    {
        $e = DB::table('engineers')->find($engineerId);
        if (!$e || in_array($e->status, ['unavailable', 'left'], true)) return;
        if (DB::table('contracts')->where('engineer_id', $engineerId)->where('status', 'active')->exists()) { DB::table('engineers')->where('id', $engineerId)->update(['status' => 'working']); return; }
        $active = DB::table('applications')->where('engineer_id', $engineerId)->whereNotIn('status', ['won', 'rejected', 'withdrawn'])->exists();
        DB::table('engineers')->where('id', $engineerId)->update(['status' => $active ? 'proposing' : 'available']);
    }

    public static function loadApp(int $appId): ?object
    {
        return DB::table('applications as a')->join('cases as c', 'c.id', '=', 'a.case_id')->join('engineers as e', 'e.id', '=', 'a.engineer_id')
            ->join('ses_companies as s', 's.id', '=', 'a.ses_id')->where('a.id', $appId)
            ->select('a.*', 'c.title', 'c.price as case_price', 'c.start_date as case_start', 'c.headcount', 'e.name as eg_name', 's.name as ses_name')->first();
    }

    public static function setStatus(int $appId, string $status, $actor, string $reason = '', string $note = ''): void
    {
        $a = self::loadApp($appId);
        if (!$a) throw new \RuntimeException('応募が見つかりません');
        $upd = ['status' => $status, 'updated_at' => now_str()];
        if ($status === 'rejected') { $upd['reject_reason'] = $reason ?: 'その他'; $upd['reject_note'] = $note; }
        DB::table('applications')->where('id', $appId)->update($upd);
        self::addEvent($appId, $status, implode('：', array_filter([$reason, $note])), $actor);
        $label = lbl('appStatus', $status);
        $msgs = [
            'submitted' => 'スキルシートを提出しました。書類の結果をお待ちください。',
            'doc_passed' => '書類選考を通過しました。面談の日程を調整します。',
            'rejected' => '今回は見送りとなりました。'.($reason ? "理由：{$reason}。" : '').($note ? "{$note}。" : '').'次の案件に活かします。',
            'withdrawn' => '応募を取り下げました。',
        ];
        if (isset($msgs[$status])) {
            N::toEg($a->engineer_id, "{$label}：{$a->title}", $msgs[$status], "/applications/$appId", true, 'status');
            N::toSes($a->ses_id, "{$a->eg_name} さん {$label}：{$a->title}", $msgs[$status], "/applications/$appId", true, 'status');
        }
        // 直属の応募は、上の toSes が管理者に届くので、ここでは重ねて出さない
        if ($status === 'withdrawn' && !(isset($msgs[$status]) && self::isDirect($a->ses_id))) N::toAdmins("応募の取り下げ：{$a->title}", "{$a->eg_name} さんが応募を取り下げました。$note", "/applications/$appId", false);
        N::audit($actor->id, 'app-status', self::selfMark($actor, $a->engineer_id)."{$a->eg_name} / {$a->title} → $label");
        self::refreshEngineerStatus($a->engineer_id);
    }

    public static function scheduleInterview(int $appId, array $d, $actor): int
    {
        $a = self::loadApp($appId);
        $round = (int) DB::table('interviews')->where('application_id', $appId)->max('round') + 1;
        $when = str_replace('T', ' ', (string) ($d['scheduled_at'] ?? ''));
        $id = DB::table('interviews')->insertGetId(['application_id' => $appId, 'round' => $round, 'scheduled_at' => $when ?: null, 'place' => str_in($d['place'] ?? ''),
            'request_note' => str_in($d['request_note'] ?? ''),
            'qa_json' => json_encode(Qa::generate($a->case_id, $a->engineer_id, str_in($d['request_note'] ?? '')), JSON_UNESCAPED_UNICODE), 'created_at' => now_str()]);
        DB::table('applications')->where('id', $appId)->update(['status' => 'interview', 'updated_at' => now_str()]);
        self::addEvent($appId, 'interview', "{$round}回目 $when ".($d['place'] ?? ''), $actor);
        $whenText = ($when ?: '日時調整中').'／'.(str_in($d['place'] ?? '') ?? '場所・URL は追ってご連絡');
        N::toEg($a->engineer_id, "面談が決まりました：{$a->title}（{$round}回目）", "面談：{$whenText}\n\nこの案件に合わせた「面談対策の Q&A」を用意しました。面談の前に目を通し、自分の言葉で答えられるようにしておいてください。", "/interviews/$id", true, 'interview');
        N::toSes($a->ses_id, "面談が決まりました：{$a->title}", "面談：{$whenText}\n面談スクリプト（想定質問と答え方）を作りました。", "/interviews/$id", true, 'interview');
        if (($actor->role ?? '') === 'ses') N::toAdmins("SES から面談依頼の報告：{$a->title}", "{$a->eg_name} さん／面談：{$whenText}", "/interviews/$id", false, 'interview');
        N::audit($actor->id, 'interview', self::selfMark($actor, $a->engineer_id)."{$a->title} {$round}回目");
        return $id;
    }

    public static function interviewResult(int $ivId, string $result, ?string $feedback, $actor): void
    {
        $iv = DB::table('interviews')->find($ivId);
        DB::table('interviews')->where('id', $ivId)->update(['result' => $result, 'feedback' => str_in($feedback)]);
        if ($result === 'pass') {
            DB::table('applications')->where('id', $iv->application_id)->update(['status' => 'interviewed', 'updated_at' => now_str()]);
            self::addEvent($iv->application_id, 'interviewed', "{$iv->round}回目 通過。".$feedback, $actor);
            $a = self::loadApp($iv->application_id);
            N::toEg($a->engineer_id, "面談を通過しました：{$a->title}", "{$iv->round}回目の面談を通過しました。".($feedback ? "\n感想：$feedback" : '')."\n次のご連絡をお待ちください。", "/applications/{$a->id}", true, 'status');
            N::toSes($a->ses_id, "面談通過：{$a->title}", "{$iv->round}回目の面談を通過しました。", "/applications/{$a->id}", true, 'status');
            N::audit($actor->id ?? null, 'interview-result', self::selfMark($actor, $a->engineer_id)."{$a->eg_name} / {$a->title} {$iv->round}回目 通過");
        } else {
            self::setStatus($iv->application_id, 'rejected', $actor, '面談の受け答え', (string) $feedback);
        }
    }

    public static function makeContract(int $appId, array $d, $actor): int
    {
        $a = self::loadApp($appId);
        if (DB::table('contracts')->where('application_id', $appId)->exists()) throw new \RuntimeException('この応募はすでに契約になっています');
        $start = str_in($d['start_date'] ?? '') ?? today_str();
        // 契約は3か月ごと（初めの契約の終わりは、開始から3か月目の月末）
        $end = str_in($d['end_date'] ?? '') ?? month_end($start, 2);
        $price = num_or_null($d['price'] ?? null) ?? $a->proposed_price ?? $a->case_price;
        if (!$price) throw new \RuntimeException('契約の金額を入れてください');
        $kid = DB::table('contracts')->insertGetId(['application_id' => $appId, 'case_id' => $a->case_id, 'engineer_id' => $a->engineer_id, 'ses_id' => $a->ses_id,
            'price' => $price, 'start_date' => $start, 'end_date' => $end, 'status' => 'active', 'created_at' => now_str()]);
        DB::table('applications')->where('id', $appId)->update(['status' => 'won', 'updated_at' => now_str()]);
        self::addEvent($appId, 'won', "契約 {$start}〜{$end}／".man($price), $actor);
        DB::table('engineers')->where('id', $a->engineer_id)->update(['status' => 'working']);
        $won = DB::table('applications')->where('case_id', $a->case_id)->where('status', 'won')->count();
        if ($won >= ($a->headcount ?: 1)) DB::table('cases')->where('id', $a->case_id)->update(['status' => 'filled', 'updated_at' => now_str()]);
        N::toEg($a->engineer_id, "成約おめでとうございます：{$a->title}", "「{$a->title}」に決まりました。開始は {$start} です。\n最初の1か月に、困りごとがないか短くお話を聞かせてください（PARK のホームから記入できます）。\nこの案件を、経験票にも追加してください（ホームの「経歴の更新」から）。", "/eg", true, 'won');
        N::toSes($a->ses_id, "成約しました：{$a->title}", "{$a->eg_name} さんが「{$a->title}」に決まりました。\n契約：{$start}〜{$end}／".man($price).(self::isDirect($a->ses_id) ? '' : "\n成功報酬（初期費用）は、稼働開始の月の請求に含まれます。"), "/contracts/$kid", true, 'won');
        N::audit($actor->id, 'contract', self::selfMark($actor, $a->engineer_id)."{$a->eg_name} / {$a->title} ".man($price));
        return $kid;
    }

    public static function extendContract(int $id, int $months, $actor): void
    {
        $k = DB::table('contracts as k')->join('cases as c', 'c.id', '=', 'k.case_id')->where('k.id', $id)->select('k.*', 'c.title')->first();
        $newEnd = month_end($k->end_date ?: today_str(), $months ?: 3);
        DB::table('contracts')->where('id', $id)->update(['end_date' => $newEnd, 'extensions' => $k->extensions + 1, 'status' => 'active']);
        N::toSes($k->ses_id, "契約が延長されました：{$k->title}", "契約の終わりが {$newEnd} になりました。", "/contracts/$id", true, 'contract');
        N::toEg($k->engineer_id, "契約が延長されました：{$k->title}", "契約の終わりが {$newEnd} になりました。引き続きよろしくお願いします。", "/contracts/$id", true, 'contract');
        N::audit($actor->id, 'contract-extend', self::selfMark($actor, $k->engineer_id)."{$k->title} → $newEnd");
    }

    public static function endContract(int $id, array $d, $actor): void
    {
        $k = DB::table('contracts')->find($id);
        DB::table('contracts')->where('id', $id)->update(['status' => 'ended', 'end_date' => str_in($d['end_date'] ?? '') ?? ($k->end_date ?: today_str()), 'end_reason' => str_in($d['end_reason'] ?? '')]);
        N::audit($actor->id, 'contract-end', self::selfMark($actor, $k->engineer_id)."#$id ".($d['end_reason'] ?? ''));
        self::refreshEngineerStatus($k->engineer_id);
        $title = DB::table('cases')->where('id', $k->case_id)->value('title');
        N::toEg($k->engineer_id, "経歴を更新してください：{$title}", "「{$title}」が終わりました。おつかれさまでした。\n終わった月、担当したこと、成果を経験票に書いてください（ホームの「経歴の更新」から）。次の案件のスキルシートに使います。", '/eg', true, 'history');
    }
}
