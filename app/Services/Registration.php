<?php

namespace App\Services;

use App\Services\Notifier as N;
use App\Services\Workflow as W;
use App\Support\Requirements;
use App\Support\RegistrationText as T;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// 仮登録 → 本登録の手続き。リンクの発行・本登録の完了・送り直し・取り消し・権限・候補から外す条件を、ここに集める。
// 画面（コントローラー）と Workflow は、ここを呼ぶ。
class Registration
{
    const TTL_DAYS = 7;          // 仮登録リンクの有効日数
    const PENDING_KEY = 'reg_pending';

    // 最後の finishPending の結果（none／done／renewed／unusable）と、本登録で出す注意（画面用）
    public static string $lastResult = 'none';
    public static ?string $lastWarn = null;

    // 本登録が完了しなかったとき（リンクが新しくなった／使えなくなった）に画面へ出す文。使えなくなった理由は出さず、共通の文にする
    public static function notDoneMessage(): string
    {
        return self::$lastResult === 'renewed' ? T::LINK_RENEWED : T::LINK_UNUSABLE;
    }

    // 文字列だけを通す（配列などを送られても落ちないように。文字でなければ null）
    public static function s($v): ?string
    {
        return is_string($v) ? $v : null;
    }

    // 会社情報の編集（POST /ses/company・POST /admin/ses/{id}）の入力を、Requirements の項目名にそろえる
    public static function companyInput(array $all): array
    {
        return ['ses_name' => clean_name(self::s($all['name'] ?? null)), 'name_sei' => clean_name(self::s($all['rep_sei'] ?? null)), 'name_mei' => clean_name(self::s($all['rep_mei'] ?? null)),
            'phone' => str_in(self::s($all['phone'] ?? null)), 'postal_code' => self::s($all['postal_code'] ?? null), 'address' => clean_name(self::s($all['address'] ?? null))];
    }

    // 会社情報の長さと形の検査（本登録の画面と同じ決まり。Registration::rules を使う）。断る文を返す（通せば null）
    public static function checkCompanyInput(array $in): ?string
    {
        [$rules, $msg, $attr] = self::rules('ses', true, ['ses_name', 'name_sei', 'name_mei', 'phone']);
        $v = \Illuminate\Support\Facades\Validator::make($in, $rules, $msg, $attr);
        return $v->fails() ? $v->errors()->first() : null;
    }

    // ---------- リンク ----------
    public static function issueToken(int $userId, int $days = self::TTL_DAYS, string $kind = 'register'): string
    {
        $t = Str::random(40);
        DB::table('users')->where('id', $userId)->update(['token' => $t, 'token_expires' => date('Y-m-d H:i:s', time() + $days * 86400), 'token_kind' => $kind]);
        return $t;
    }

    // リンクから人を探す（種類を問わない）。期限内・使える人（停止・退会でない）だけ
    public static function tokenRow(?string $t): ?object
    {
        if (!$t) return null;
        return DB::table('users')->where('token', $t)->where('token_expires', '>', now_str())->where('active', 1)->whereNull('left_at')->first() ?: null;
    }

    public static function userByRegisterToken(?string $t): ?object
    {
        $u = self::tokenRow($t);
        if (!$u || $u->token_kind !== 'register' || $u->registered_at || $u->reg_declined_at || !self::companyAllows($u)) return null;
        return $u;
    }

    public static function userByResetToken(?string $t): ?object
    {
        $u = self::tokenRow($t);
        if (!$u || $u->token_kind !== 'reset' || !$u->registered_at || !self::companyAllows($u)) return null;
        return $u;
    }

    // 会社の状態がリンクを通してよいか（K31）。SES は仮登録でも参加中でも、担当者・エンジニアは参加中だけ。管理者は常に
    public static function companyAllows(object $u): bool
    {
        if ($u->role === 'admin' || empty($u->ses_id)) return true;
        $s = DB::table('ses_companies')->find($u->ses_id);
        if (!$s) return false;
        if ($s->kind === 'direct') return true;
        $isRep = $u->role === 'ses' && ((int) $s->rep_user_id === (int) $u->id || (int) $s->next_rep_user_id === (int) $u->id);
        return $isRep ? in_array($s->status, ['provisional', 'active'], true) : $s->status === 'active';
    }

    // ---------- 仮登録メール ----------
    // 宛先の企業名（管理者は企業名、SES の人は SES 名。直属は空）
    public static function companyOf(object $u): string
    {
        if ($u->role === 'admin') return (string) $u->company_name;
        $s = $u->ses_id ? DB::table('ses_companies')->find($u->ses_id) : null;
        return $s && $s->kind !== 'direct' ? (string) $s->name : '';
    }

    // 登録した人の企業名（メールの「登録した人の企業名」）
    public static function actorCompany($actor): string
    {
        if (!$actor) return (string) setting('operator_name');
        if ($actor->role === 'admin') return admin_sender($actor) ?? setting('operator_name');
        return self::companyOf($actor);
    }

    // 新しいリンクを発行して、仮登録メールを送る。$note は本文の頭に足す1文（承認のとき）。戻り値は mail_outbox の id
    public static function sendLink(object $target, $actor, string $note = ''): ?int
    {
        $target = DB::table('users')->find($target->id);
        $token = self::issueToken($target->id);
        DB::table('users')->where('id', $target->id)->update(['reg_declined_at' => null]);
        $isRep = Requirements::isRep($target);
        [$sei, $mei] = ($target->name_sei || $target->name_mei) ? [$target->name_sei, $target->name_mei] : split_name($target->name);
        $body = T::provisionalBody([
            'company' => self::companyOf($target), 'sei' => (string) $sei, 'mei' => (string) $mei, 'note' => $note,
            'by_company' => self::actorCompany($actor), 'by_name' => (string) ($actor->name ?? ''),
            'role_name' => Requirements::roleName($target->role, $isRep), 'link' => url("/invite/$token"), 'required' => T::requiredLines($target->role, $isRep),
        ]);
        $id = N::queueMail($target->email, $target->name, T::PROVISIONAL_SUBJECT, $body, 'invite', null, admin_sender($actor));
        N::audit($actor->id ?? null, 'invite', lbl('role', $target->role)." #{$target->id}"); // 氏名・アドレスは記録に書かない
        return $id;
    }

    // ---------- SES の仮登録（管理者 → SES。公開の申し込みの承認も同じ入口） ----------
    // $d：name（企業正式名称）・sei・mei（SES）・email。$pending が来たら、申込中の会社をこの入力で仮登録に進める。
    // 戻り値：['ok' => true, 'ses_id', 'user_id'] か ['bad' => 文]
    public static function provisionalSes(array $d, $actor, ?object $pending = null): array
    {
        $name = clean_name(self::s($d['name'] ?? null)); $sei = clean_name(self::s($d['sei'] ?? null)); $mei = clean_name(self::s($d['mei'] ?? null));
        $email = strtolower(trim((string) self::s($d['email'] ?? null)));
        if ($name === null) return ['bad' => '企業正式名称を入れてください。'];
        if ($sei === null) return ['bad' => 'SES の姓を入れてください。'];
        if ($mei === null) return ['bad' => 'SES の名を入れてください。'];
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return ['bad' => 'メールアドレスを正しく入れてください。'];
        if (mb_strlen($name) > 100) return ['bad' => '企業正式名称は100文字までにしてください。'];
        if (mb_strlen($sei) > 50 || mb_strlen($mei) > 50) return ['bad' => 'SES の氏名は、姓・名とも50文字までです。'];
        if ($why = self::emailInUse($email)) return ['bad' => $why];

        $origEmail = $pending ? strtolower((string) $pending->email) : null;
        $ids = DB::transaction(function () use ($name, $sei, $mei, $email, $actor, $pending) {
            $company = ['name' => $name, 'rep_name' => join_name($sei, $mei), 'rep_sei' => $sei, 'rep_mei' => $mei, 'email' => $email, 'status' => 'provisional', 'updated_at' => now_str()];
            if ($pending) {
                // 申し込みのときの同意は、別の人のものかもしれないので、SES の本登録で取り直す（前の日時は記録に残す）
                if ($pending->agreed_at) N::audit($actor->id ?? null, 'ses-approve-agreed', "{$pending->name} 前の同意 {$pending->agreed_at}");
                DB::table('ses_companies')->where('id', $pending->id)->update($company + ['agreed_at' => null]);
                $sesId = (int) $pending->id;
            } else {
                $sesId = (int) DB::table('ses_companies')->insertGetId($company + ['kind' => 'ses', 'code' => Ids::next('S'), 'created_at' => now_str()]);
            }
            $uid = (int) DB::table('users')->insertGetId(['email' => $email] + W::nameCols(join_name($sei, $mei), $sei, $mei)
                + ['role' => 'ses', 'ses_id' => $sesId, 'registered_by' => $actor->id ?? null, 'created_at' => now_str(), 'updated_at' => now_str()] + W::roleCodes('ses'));
            DB::table('ses_companies')->where('id', $sesId)->update(['rep_user_id' => $uid]);
            return [$sesId, $uid];
        });
        [$sesId, $uid] = $ids;
        $same = $origEmail !== null && $origEmail === $email;
        self::sendLink(DB::table('users')->find($uid), $actor, $pending && $same ? T::APPROVAL_NOTE : '');
        // 申し込んだアドレスが SES と違うときは、承認の知らせを別に送る
        if ($pending && $origEmail && !$same) {
            N::queueMail($origEmail, (string) ($pending->contact_name ?: $pending->rep_name ?: $pending->name), T::APPROVAL_SUBJECT, T::approvalBody($name), 'ses-approve', null, admin_sender($actor));
        }
        N::audit($actor->id ?? null, $pending ? 'ses-approve' : 'ses-create', $name);
        return ['ok' => true, 'ses_id' => $sesId, 'user_id' => $uid];
    }

    // ---------- 管理者の仮登録（管理者 → 管理者。企業名・姓・名・メールの4つだけ。K02・K05・K15） ----------
    // $d：company（企業名）・sei・mei・email。電話などは、ご本人が本登録で入れる。
    // すでに本登録が済んだエンジニアのアドレスなら、仮登録ではなく管理者の役割を足す（兼任）。
    // 戻り値：['ok' => true, 'user_id' => int, 'linked' => ?object（役割を足した相手）] か ['bad' => 文]
    public static function provisionalAdmin(array $d, $actor): array
    {
        $company = clean_name(self::s($d['company'] ?? null)); $sei = clean_name(self::s($d['sei'] ?? null)); $mei = clean_name(self::s($d['mei'] ?? null));
        $email = strtolower(trim((string) self::s($d['email'] ?? null)));
        if ($sei === null) return ['bad' => '氏名（姓）を入れてください。'];
        if ($mei === null) return ['bad' => '氏名（名）を入れてください。'];
        if ($company === null) return ['bad' => '企業名を入れてください。'];
        if (mb_strlen($sei) > 50 || mb_strlen($mei) > 50) return ['bad' => '氏名は、姓・名とも50文字までです。'];
        if (mb_strlen($company) > 100) return ['bad' => '企業名は100文字までです。'];
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return ['bad' => 'メールアドレスを正しく入れてください。'];

        if (DB::table('users')->where('email', $email)->exists()) {
            $share = W::adminShareTarget($email); // 本登録済みのエンジニアだけ。仮登録中・停止中・退会済は断る
            if (!$share) return ['bad' => self::emailInUse($email) ?? 'このメールアドレスはすでに使われています。'];
            W::addAdminRole((int) $share->id, ['company_name' => $company], $actor); // 空の値で、既にある値は消さない
            return ['ok' => true, 'user_id' => (int) $share->id, 'linked' => $share];
        }
        $uid = (int) DB::table('users')->insertGetId(['email' => $email] + W::nameCols(join_name($sei, $mei), $sei, $mei)
            + ['role' => 'admin', 'company_name' => $company, 'registered_by' => $actor->id ?? null, 'created_at' => now_str(), 'updated_at' => now_str()] + W::roleCodes('admin'));
        self::sendLink(DB::table('users')->find($uid), $actor);
        N::audit($actor->id ?? null, 'reg-person', "管理者 #{$uid}"); // 氏名・アドレスは記録に書かない
        return ['ok' => true, 'user_id' => $uid, 'linked' => null];
    }

    // このアドレスを新しく登録できない理由（無ければ null）。$exceptUserId の人は除く
    public static function emailInUse(string $email, ?int $exceptUserId = null): ?string
    {
        $u = DB::table('users')->where('email', strtolower($email))->when($exceptUserId, fn ($q) => $q->where('id', '!=', $exceptUserId))->first();
        if (!$u) return null;
        if ($u->left_at) return 'このメールアドレスは、退会済の方が使っていたため登録できません。';
        if (!$u->active) return 'このメールアドレスは、停止中の方が使っているため登録できません。';
        if (!$u->registered_at) return 'このメールアドレスは、仮登録中の方が使っています。送り直しや直しは、その方の画面から行ってください。';
        return 'このメールアドレスはすでに使われています。';
    }

    // ---------- 仮登録中の直し（企業名・姓・名・メール。同じ行のまま） ----------
    // SES の仮登録を直す。メールを変えたときだけ新しいリンクを送る。['ok' => 文] か ['bad' => 文]
    public static function editProvisionalSes(object $s, array $d, $actor): array
    {
        $rep = DB::table('users')->find($s->rep_user_id);
        if (!$rep || $rep->registered_at) return ['bad' => '仮登録の内容は、本登録が済む前だけ直せます。'];
        $name = clean_name(self::s($d['name'] ?? null)); $sei = clean_name(self::s($d['sei'] ?? null)); $mei = clean_name(self::s($d['mei'] ?? null));
        $email = strtolower(trim((string) self::s($d['email'] ?? null)));
        if ($name === null || $sei === null || $mei === null) return ['bad' => '企業正式名称とSES の姓・名を入れてください。'];
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return ['bad' => 'メールアドレスを正しく入れてください。'];
        if (mb_strlen($name) > 100 || mb_strlen($sei) > 50 || mb_strlen($mei) > 50) return ['bad' => '企業名は100文字、氏名は姓・名とも50文字までです。'];
        $changed = $email !== strtolower((string) $rep->email);
        if ($changed && ($why = self::emailInUse($email, (int) $rep->id))) return ['bad' => $why];
        DB::transaction(function () use ($s, $rep, $name, $sei, $mei, $email) {
            DB::table('users')->where('id', $rep->id)->update(W::nameCols(join_name($sei, $mei), $sei, $mei) + ['email' => $email, 'updated_at' => now_str()]);
            DB::table('ses_companies')->where('id', $s->id)->update(['name' => $name, 'rep_name' => join_name($sei, $mei), 'rep_sei' => $sei, 'rep_mei' => $mei, 'email' => $email, 'updated_at' => now_str()]);
        });
        N::audit($actor->id ?? null, 'reg-edit', "ses #{$s->id}");
        if ($changed) {
            self::sendLink(DB::table('users')->find($rep->id), $actor); // 新しいリンクで、前のリンクは使えなくなる
            return ['ok' => '保存し、新しいメールアドレスに仮登録のご案内を送りました。'];
        }
        return ['ok' => '保存しました。'];
    }

    // SES の氏名（rep_* が空なら、SES の人の姓名）。[氏名, 姓, 名]
    public static function repName(object $s): array
    {
        if (($s->rep_sei ?? null) || ($s->rep_mei ?? null) || ($s->rep_name ?? null)) {
            [$a, $b] = ($s->rep_sei || $s->rep_mei) ? [$s->rep_sei, $s->rep_mei] : split_name($s->rep_name);
            return [$s->rep_name ?: join_name($a, $b), $a, $b];
        }
        $u = !empty($s->rep_user_id) ? DB::table('users')->find($s->rep_user_id) : null;
        if (!$u) return [null, null, null];
        [$a, $b] = ($u->name_sei || $u->name_mei) ? [$u->name_sei, $u->name_mei] : split_name($u->name);
        return [$u->name, $a, $b];
    }

    // SES の姓名を直したとき、会社の rep_* と SES の人の氏名をそろえる（B4）
    public static function syncRepName(int $sesId, ?string $sei, ?string $mei): void
    {
        $s = DB::table('ses_companies')->find($sesId);
        if (!$s) return;
        $name = join_name($sei, $mei);
        DB::table('ses_companies')->where('id', $sesId)->update(['rep_name' => $name, 'rep_sei' => $sei, 'rep_mei' => $mei, 'updated_at' => now_str()]);
        if ($s->rep_user_id) DB::table('users')->where('id', $s->rep_user_id)->update(['name' => $name, 'name_sei' => $sei, 'name_mei' => $mei, 'updated_at' => now_str()]);
    }

    // ---------- 本登録の保留と完了 ----------
    // 入力を検査したあと、DB には書かず、暗号にしてセッションに置く（途中でやめたら何も残らない）
    public static function stash(Request $r, object $user, string $token, array $data, string $passwordHash): void
    {
        $r->session()->put(self::PENDING_KEY, Crypt::encryptString(json_encode(['user' => $user->id, 'token' => $token, 'data' => $data, 'hash' => $passwordHash])));
    }

    // 確認コードを通ったとき、保留していた入力を保存して本登録を完了する。結果：none（保留なし）／done／renewed（リンクが新しくなっていた）／unusable（行が消えた・止まった・会社が止まった）
    public static function finishPending(Request $r): string
    {
        self::$lastWarn = null;
        $raw = $r->session()->get(self::PENDING_KEY);
        if (!$raw) return self::$lastResult = 'none';
        $r->session()->forget(self::PENDING_KEY);
        try {
            $p = json_decode(Crypt::decryptString($raw), true);
        } catch (\Throwable) {
            return self::$lastResult = 'none';
        }
        $me = $r->user();
        if (!$p || !$me || (int) $p['user'] !== (int) $me->id) return self::$lastResult = 'none';

        $changes = []; $warn = null; $done = null; $result = 'unusable'; $already = false;
        DB::transaction(function () use ($p, &$changes, &$warn, &$done, &$result, &$already) {
            $u = DB::table('users')->where('id', $p['user'])->lockForUpdate()->first();
            // 行が消えた・止まった・退会した・会社が止まった：リンクは使えない
            if (!$u || !$u->active || $u->left_at || !self::companyAllows($u)) { $result = 'unusable'; return; }
            if ($u->registered_at) {
                // 同じ入力をもう一度送った（二重の操作）：すでに同じ内容で完了していれば、完了として扱う（知らせは重ねない）
                $already = !$u->token && $u->password === $p['hash'];
                $result = $already ? 'done' : 'unusable';
                return;
            }
            // 途中で送り直された・「心当たりがない」が押された：リンクが新しくなった
            if ($u->token !== $p['token']) { $result = 'renewed'; return; }
            self::applyRegistration($u, $p['data'], $p['hash'], $changes, $warn);
            $done = DB::table('users')->find($u->id);
        });
        if ($already) return self::$lastResult = 'done';
        if (!$done) return self::$lastResult = $result;
        self::$lastWarn = $warn;
        N::audit($done->id, 'registered', lbl('role', $done->role));
        self::notifyDone($done, $changes, $warn);
        return self::$lastResult = 'done';
    }

    // 保存する中身（立場ごと）。変わった内容は $changes に前後を入れる
    private static function applyRegistration(object $u, array $d, string $hash, array &$changes, ?string &$warn): void
    {
        $now = now_str();
        $isRep = Requirements::isRep($u);
        $kind = Requirements::kind($u->role, $isRep);
        $sei = clean_name($d['name_sei'] ?? null); $mei = clean_name($d['name_mei'] ?? null);
        $name = join_name($sei, $mei);
        if ($name !== $u->name) $changes[] = "氏名：{$u->name} → {$name}";
        $upd = ['password' => $hash, 'name' => $name, 'name_sei' => $sei, 'name_mei' => $mei, 'registered_at' => $now, 'profile_completed_at' => $u->profile_completed_at ?: $now,
            'token' => null, 'token_expires' => null, 'token_kind' => null, 'reg_declined_at' => null, 'updated_at' => $now] + W::kanaCols($d);
        if ($kind !== 'eg') $upd['position'] = str_in($d['position'] ?? '');
        if ($kind === 'admin') {
            $company = clean_name($d['company_name'] ?? null);
            if ($company !== $u->company_name) $changes[] = "企業名：{$u->company_name} → {$company}";
            $upd += ['company_name' => $company, 'phone' => str_in($d['phone'] ?? ''), 'postal_code' => str_in($d['postal_code'] ?? ''), 'address' => str_in($d['address'] ?? '')];
        }
        if ($kind === 'staff') $upd += ['phone' => str_in($d['phone'] ?? ''), 'secrecy_agreed_at' => $now];
        if ($kind === 'rep') $upd += ['secrecy_agreed_at' => $now];

        if ($kind === 'rep') {
            $s = DB::table('ses_companies')->find($u->ses_id);
            $newName = clean_name($d['ses_name'] ?? null);
            if ($newName !== $s->name) {
                $changes[] = "企業名：{$s->name} → {$newName}";
                if (DB::table('ses_companies')->where('id', '!=', $s->id)->where('name', $newName)->exists()) $warn = '同じ名前の SES がほかにもあります。見分けられるよう、正式名称をご確認ください。';
            }
            $co = ['name' => $newName, 'phone' => str_in($d['phone'] ?? ''), 'postal_code' => str_in($d['postal_code'] ?? ''), 'address' => str_in($d['address'] ?? ''),
                'agreed_at' => $now, 'self_confirmed_at' => $now, 'rep_name' => $name, 'rep_sei' => $sei, 'rep_mei' => $mei, 'updated_at' => $now];
            if (in_array($s->status, ['provisional', 'pending'], true)) $co['status'] = 'active';
            if (!$s->joined_at) $co['joined_at'] = today_str();
            // 交代の途中だった次の SESなら、ここで SES が入れ替わる
            if ((int) $s->next_rep_user_id === (int) $u->id) $co += ['rep_user_id' => $u->id, 'next_rep_user_id' => null];
            DB::table('ses_companies')->where('id', $s->id)->update($co);
        }

        if ($kind === 'eg') {
            $e = DB::table('engineers')->find($u->engineer_id);
            $upd += ['phone' => str_in($d['phone'] ?? '')];
            $ev = ['name' => $name, 'name_sei' => $sei, 'name_mei' => $mei, 'phone' => str_in($d['phone'] ?? ''), 'postal_code' => str_in($d['postal_code'] ?? ''), 'address' => str_in($d['address'] ?? ''),
                'consent_at' => $now, 'self_confirmed_at' => $now, 'registered_at' => $now, 'updated_at' => $now] + W::kanaCols($d);
            if (empty($e->initials) && $ev['kana']) $ev['initials'] = W::initialsFromKana($ev['kana']);
            // 氏名の変更は、users の分ですでに書いていれば重ねない（同じ変更が2行出ないように）
            if ($e && $e->name !== $name && !preg_grep('/^氏名：/u', $changes)) $changes[] = "氏名：{$e->name} → {$name}";
            DB::table('engineers')->where('id', $u->engineer_id)->update($ev);
        }
        $changes = array_values(array_unique($changes));
        DB::table('users')->where('id', $u->id)->update($upd);
    }

    // ---------- 知らせ（K10・K25） ----------
    // いま使える人か（使えている・退会していない・本登録済み・SES の人は会社が参加中）
    public static function usable(?object $x): bool
    {
        if (!$x || !$x->active || $x->left_at || !$x->registered_at) return false;
        if ($x->role === 'ses') return DB::table('ses_companies')->where('id', $x->ses_id)->value('status') === 'active';
        return true;
    }

    // 本登録（または「心当たりがない」）を知らせる相手。登録した人が使えればその人、SES の中の人なら SES も、
    // 管理者の本登録と SES の本登録は管理者全員も。誰もいなければ管理者全員。重ねず、本人は除く
    public static function recipientsFor(object $target): array
    {
        $out = [];
        $add = function (?object $x) use (&$out, $target) {
            if ($x && self::usable($x) && (int) $x->id !== (int) $target->id) $out[$x->id] = $x;
        };
        $registrar = $target->registered_by ? DB::table('users')->find($target->registered_by) : null;
        $add($registrar);
        $isRep = Requirements::isRep($target);
        $s = $target->ses_id ? DB::table('ses_companies')->find($target->ses_id) : null;
        if ($s && $s->kind === 'ses' && in_array($target->role, ['ses', 'eg'], true) && !$isRep && $s->rep_user_id) $add(DB::table('users')->find($s->rep_user_id));
        // 管理者の直属のエンジニアは、SES がいないので、管理者全員に知らせる（S2-31）
        if ($target->role === 'admin' || ($target->role === 'ses' && $isRep) || ($s && $s->kind === 'direct')) {
            foreach (DB::table('users')->where('role', 'admin')->get() as $a) $add($a);
        }
        if (!$out) foreach (DB::table('users')->where('role', 'admin')->get() as $a) $add($a);
        return array_values($out);
    }

    private static function whoOf(object $u): array
    {
        $isRep = Requirements::isRep($u);
        $roleName = Requirements::roleName($u->role, $isRep);
        $company = self::companyOf($u);
        return [trim($company.' '.$u->name), $roleName, $isRep];
    }

    // 受け取る人ごとの「開く先」と、どの立場あてか
    private static function linkFor(object $rec, object $target): array
    {
        if ($rec->role === 'admin') {
            $to = $target->role === 'admin' ? "/admin/admins/{$target->id}" : ($target->role === 'ses' ? "/admin/ses/{$target->ses_id}" : "/engineers/{$target->engineer_id}");
            return [$to, 'admin'];
        }
        return [$target->role === 'eg' ? "/engineers/{$target->engineer_id}" : '/ses/company', 'ses'];
    }

    private static function notifyDone(object $u, array $changes, ?string $warn): void
    {
        [$who, $roleName] = self::whoOf($u);
        foreach (self::recipientsFor($u) as $rec) {
            [$link, $forRole] = self::linkFor($rec, $u);
            N::notify($rec->id, T::doneTitle($who), T::doneBody($who, $roleName, $changes, $warn), $link, true, 'registered', $forRole);
        }
    }

    // 「心当たりがない」：リンクを無効にして、上位に知らせる
    public static function decline(object $u): void
    {
        DB::table('users')->where('id', $u->id)->update(['token' => null, 'token_expires' => null, 'token_kind' => null, 'reg_declined_at' => now_str(), 'updated_at' => now_str()]);
        N::audit($u->id, 'reg-declined', lbl('role', $u->role));
        [$who, $roleName] = self::whoOf($u);
        foreach (self::recipientsFor(DB::table('users')->find($u->id)) as $rec) {
            [$link, $forRole] = self::linkFor($rec, $u);
            N::notify($rec->id, T::declinedTitle($who), T::declinedBody($who, $roleName), $link, true, 'registered', $forRole);
        }
    }

    // ---------- 権限（1か所） ----------
    // $actor が $target（users の行）の送り直し・直し・取り消しをしてよいか。管理者は全員、SES は自社の担当者とエンジニア、担当者は自社のエンジニア
    public static function canManage(?object $actor, object $target): bool
    {
        if (!$actor) return false;
        if ($actor->role === 'admin') return true;
        if ($actor->role !== 'ses' || empty($actor->ses_id) || (int) $target->ses_id !== (int) $actor->ses_id) return false;
        if ($target->role === 'ses') return Requirements::isRep($actor) && (int) $target->id !== (int) $actor->id && !Requirements::isRep($target);
        return $target->role === 'eg';
    }

    // ---------- 担当者・エンジニアの仮登録（上位が 姓・名・メール の3つを入れる。K12・K15） ----------
    // $kind：'ses'（担当者）か 'eg'。$sesId：所属する会社（参加中の SES だけ）。$d：sei・mei・email。
    // 戻り値：['ok' => true, 'user_id' => ?int, 'engineer_id' => ?int, 'linked' => ?object（役割を足した相手）] か ['bad' => 文]
    public static function provisionalPerson(string $kind, int $sesId, array $d, $actor): array
    {
        $s = DB::table('ses_companies')->where('id', $sesId)->where('kind', 'ses')->where('status', 'active')->first();
        if (!$s) return ['bad' => '担当者・エンジニアを登録できるのは、参加中の SES だけです。'];
        $sei = clean_name(self::s($d['sei'] ?? null)); $mei = clean_name(self::s($d['mei'] ?? null));
        $email = strtolower(trim((string) self::s($d['email'] ?? null)));
        if ($sei === null || $mei === null) return ['bad' => '氏名（姓・名）を入れてください。'];
        if (mb_strlen($sei) > 50 || mb_strlen($mei) > 50) return ['bad' => '氏名は、姓・名とも50文字までにしてください。'];
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return ['bad' => 'メールアドレスを正しく入れてください。'];

        // すでに使われているアドレス：同じ SES の本登録済みの人（または管理者が登録する管理者）なら、役割を足す（兼任）。それ以外は断る
        $share = null;
        if (DB::table('users')->where('email', $email)->exists()) {
            $share = $kind === 'eg' ? W::egShareTarget($email, $sesId, $actor) : W::sesShareTarget($email, $sesId);
            if (!$share) return self::refuseEmail($email, $kind, $actor);
        }
        if ($kind === 'ses' && $share) {
            W::addSesRole($share->id, $actor);
            N::audit($actor->id ?? null, 'reg-person', '担当者 #'.$share->id.'（役割を追加）');
            return ['ok' => true, 'user_id' => (int) $share->id, 'engineer_id' => null, 'linked' => $share];
        }

        $linked = null; $eid = null; $uid = null;
        DB::transaction(function () use ($kind, $sesId, $sei, $mei, $email, $actor, &$linked, &$eid, &$uid) {
            if ($kind === 'eg') {
                // エンジニアの行は仮登録のまま（engineers.registered_at は本登録で入る）。管理者へのお知らせは、本登録のときに Registration が出す
                $eid = W::createEngineer($sesId, ['name_sei' => $sei, 'name_mei' => $mei, 'email' => $email], $actor, false, $linked, false);
                if ($linked) return;
            }
            $uid = (int) DB::table('users')->insertGetId(['email' => $email] + W::nameCols(join_name($sei, $mei), $sei, $mei)
                + ['role' => $kind, 'ses_id' => $sesId, 'engineer_id' => $eid, 'registered_by' => $actor->id ?? null, 'created_at' => now_str(), 'updated_at' => now_str()] + W::roleCodes((string) $kind));
        });
        if ($linked) return ['ok' => true, 'user_id' => (int) $linked->id, 'engineer_id' => $eid, 'linked' => $linked];

        $target = DB::table('users')->find($uid);
        self::sendLink($target, $actor);
        N::audit($actor->id ?? null, 'reg-person', lbl('role', $kind)." #{$uid}"); // 氏名・アドレスは記録に書かない
        self::tellRep($s, $target, $actor);
        return ['ok' => true, 'user_id' => $uid, 'engineer_id' => $eid, 'linked' => null];
    }

    // 使われているアドレスで登録できないとき。SES の人には理由によらず同じ文（他社の人の存在を漏らさない）と記録、管理者には理由を出す
    private static function refuseEmail(string $email, string $kind, $actor, ?int $exceptUserId = null): array
    {
        if (($actor->role ?? '') === 'ses') {
            N::audit($actor->id ?? null, 'reg-refused', ($kind === 'eg' ? 'エンジニア' : '担当者').'の仮登録'); // アドレスは記録に書かない
            return ['bad' => T::REFUSED_EMAIL, 'refused' => true];
        }
        return ['bad' => self::emailInUse($email, $exceptUserId) ?? 'このメールアドレスはすでに使われています。'];
    }

    // 管理者が担当者・エンジニアを仮登録したら、所属の SES にメールとベルで知らせる
    private static function tellRep(object $s, object $target, $actor): void
    {
        if (($actor->role ?? '') !== 'admin' || !$s->rep_user_id) return;
        [$who, $roleName] = self::whoOf($target);
        N::notify((int) $s->rep_user_id, T::adminRegisteredTitle($who, $roleName), T::adminRegisteredBody($who, $roleName, (string) ($actor->name ?? '')),
            $target->role === 'eg' ? "/engineers/{$target->engineer_id}" : '/ses/company', true, 'registered-by-admin', 'ses');
    }

    // 仮登録中の担当者・エンジニアの直し（姓・名・メール。管理者だけ、所属を参加中の SES に直せる）。同じ行のまま。
    // メールか所属を変えたときだけ、新しいリンクを送る。['ok' => 文] か ['bad' => 文]
    public static function editProvisionalPerson(object $t, array $d, $actor): array
    {
        if ($t->registered_at) return ['bad' => '仮登録の内容は、本登録が済む前だけ直せます。'];
        if (!in_array($t->role, ['ses', 'eg'], true) || Requirements::isRep($t)) return ['bad' => 'この方の仮登録は、ここでは直せません。'];
        $sei = clean_name(self::s($d['sei'] ?? null)); $mei = clean_name(self::s($d['mei'] ?? null));
        $email = strtolower(trim((string) self::s($d['email'] ?? null)));
        if ($sei === null || $mei === null) return ['bad' => '氏名（姓・名）を入れてください。'];
        if (mb_strlen($sei) > 50 || mb_strlen($mei) > 50) return ['bad' => '氏名は、姓・名とも50文字までにしてください。'];
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return ['bad' => 'メールアドレスを正しく入れてください。'];
        $mailChanged = $email !== strtolower((string) $t->email);
        if ($mailChanged && DB::table('users')->where('email', $email)->where('id', '!=', $t->id)->exists()) return self::refuseEmail($email, $t->role, $actor, (int) $t->id);
        // 所属を変えられるのは管理者だけ。選べるのは参加中の SES だけ（SES の人が ses_id を混ぜても無視する）
        $sesId = (int) $t->ses_id;
        if (($actor->role ?? '') === 'admin' && isset($d['ses_id']) && ctype_digit((string) $d['ses_id']) && (int) $d['ses_id'] !== $sesId) {
            if (!DB::table('ses_companies')->where('id', (int) $d['ses_id'])->where('kind', 'ses')->where('status', 'active')->exists()) return ['bad' => '所属に選べるのは、参加中の SES だけです。'];
            $sesId = (int) $d['ses_id'];
        }
        $sesChanged = $sesId !== (int) $t->ses_id;
        DB::transaction(function () use ($t, $sei, $mei, $email, $sesId) {
            DB::table('users')->where('id', $t->id)->update(W::nameCols(join_name($sei, $mei), $sei, $mei) + ['email' => $email, 'ses_id' => $sesId, 'updated_at' => now_str()]);
            if ($t->role === 'eg' && $t->engineer_id) {
                DB::table('engineers')->where('id', $t->engineer_id)->update(W::nameCols(join_name($sei, $mei), $sei, $mei) + ['email' => $email, 'ses_id' => $sesId, 'updated_at' => now_str()]);
            }
        });
        N::audit($actor->id ?? null, 'reg-edit', lbl('role', $t->role)." #{$t->id}");
        if ($mailChanged || $sesChanged) {
            self::sendLink(DB::table('users')->find($t->id), $actor); // 新しいリンクで、前のリンクは使えなくなる
            return ['ok' => '保存し、新しい仮登録のご案内を送りました（前のリンクは使えなくなりました）。'];
        }
        return ['ok' => '保存しました。'];
    }

    // エンジニアの「送り直し」（エンジニアの画面から）。アカウントがあればそのリンクを送り直し、無ければ（ログインの無いエンジニア）ここで作る。
    // 同じ SES の本登録済みの担当者（管理者）のアドレスなら、リンクではなくエンジニアの役割を足す。['type' => 'ok'|'bad', 'msg' => 文]
    public static function resendEngineer(object $e, $actor): array
    {
        $bad = fn (string $m) => ['type' => 'bad', 'msg' => $m];
        if ($e->status === 'left') return $bad('退会済のため、送れません。');
        if ($e->suspended_at) return $bad('停止中のため、送れません。');
        if (!$e->email) return $bad('メールアドレスが登録されていません。');
        $mine = DB::table('users')->where('engineer_id', $e->id)->where('role', 'eg')->first();
        if ($mine) return self::resend($mine, $actor);
        // 同じ SES の担当者（管理者）のアカウントなら、リンクではなくエンジニアの役割を足す（兼任）
        $linkedOther = DB::table('users')->where('email', strtolower($e->email))->where('engineer_id', $e->id)->whereIn('role', ['ses', 'admin'])->exists();
        if ($linkedOther) return ['type' => 'ok', 'msg' => 'この方のアカウントに、エンジニアの役割を足しました。'];
        if ($t = W::egShareTarget($e->email, (int) $e->ses_id, $actor)) {
            W::linkEngineer($t->id, (int) $e->id, $actor);
            return ['type' => 'ok', 'msg' => 'この方のアカウントに、エンジニアの役割を足しました。'];
        }
        if (DB::table('users')->where('email', strtolower($e->email))->exists()) {
            $r = self::refuseEmail(strtolower($e->email), 'eg', $actor);
            return $bad($r['bad']);
        }
        // ログインの無いエンジニア：技術登録の氏名・ふりがな・電話を写して、仮登録のアカウントを作る
        [$sei, $mei] = ($e->name_sei || $e->name_mei) ? [$e->name_sei, $e->name_mei] : split_name($e->name);
        $uid = (int) DB::table('users')->insertGetId(['email' => strtolower($e->email)] + W::nameCols((string) $e->name, $sei, $mei) + W::kanaCols((array) $e)
            + ['phone' => $e->phone, 'role' => 'eg', 'ses_id' => $e->ses_id, 'engineer_id' => $e->id, 'registered_by' => $actor->id ?? null, 'created_at' => now_str(), 'updated_at' => now_str()]);
        self::sendLink(DB::table('users')->find($uid), $actor);
        N::audit($actor->id ?? null, 'reg-resend', 'エンジニア #'.$uid);
        return ['type' => 'ok', 'msg' => '仮登録のご案内を送りました。'];
    }

    // ---------- SES の交代（管理者だけ。K21） ----------
    // 本登録済みの担当者から選ぶ。前の SES は担当者になる。会社の SES の欄（rep_*）は新しい人の姓名にそろえる。['type', 'msg']
    public static function changeRep(int $sesId, int $userId, $actor): array
    {
        $bad = fn (string $m) => ['type' => 'bad', 'msg' => $m];
        $res = null;
        DB::transaction(function () use ($sesId, $userId, $actor, $bad, &$res) {
            $s = DB::table('ses_companies')->where('id', $sesId)->where('kind', 'ses')->lockForUpdate()->first();
            if (!$s || $s->status !== 'active') { $res = $bad('SES を交代できるのは、参加中の SES だけです。'); return; }
            $new = DB::table('users')->where('id', $userId)->where('role', 'ses')->where('ses_id', $sesId)->where('active', 1)->whereNull('left_at')->whereNotNull('registered_at')->first();
            if (!$new) { $res = $bad('SES にできるのは、この会社の、本登録が済んだ担当者だけです。'); return; }
            if ((int) $s->rep_user_id === (int) $new->id) { $res = $bad('すでに SES です。'); return; }
            $old = $s->rep_user_id ? DB::table('users')->find($s->rep_user_id) : null;
            [, $a, $b] = ($new->name_sei || $new->name_mei) ? [null, $new->name_sei, $new->name_mei] : [null, ...split_name($new->name)];
            DB::table('ses_companies')->where('id', $sesId)->update(['rep_user_id' => $new->id, 'next_rep_user_id' => null, 'rep_name' => $new->name, 'rep_sei' => $a, 'rep_mei' => $b, 'updated_at' => now_str()]);
            N::audit($actor->id ?? null, 'rep-change', "ses #{$sesId}");
            $body = T::repChangedBody((string) $s->name, (string) $new->name, (string) ($old->name ?? '（なし）'));
            N::notify((int) $new->id, T::REP_CHANGED_TITLE, $body, '/ses/company', true, 'rep-change', 'ses');
            if ($old && (int) $old->id !== (int) $new->id) N::notify((int) $old->id, T::REP_CHANGED_TITLE, $body, '/ses/company', true, 'rep-change', 'ses');
            $res = ['type' => 'ok', 'msg' => "{$new->name} さんを SES にしました。"];
        });
        return $res;
    }

    // 担当者が（本登録済みで）1人もいない会社の交代：次の SES を仮登録する。本登録が済むと、SES が入れ替わる。それまでは今の SES のまま
    public static function provisionalNextRep(int $sesId, array $d, $actor): array
    {
        $s = DB::table('ses_companies')->where('id', $sesId)->where('kind', 'ses')->first();
        if (!$s || $s->status !== 'active') return ['type' => 'bad', 'msg' => 'SES を交代できるのは、参加中の SES だけです。'];
        $staff = DB::table('users')->where('ses_id', $sesId)->where('role', 'ses')->where('active', 1)->whereNull('left_at')->whereNotNull('registered_at')
            ->when($s->rep_user_id, fn ($q) => $q->where('id', '!=', $s->rep_user_id))->exists();
        if ($staff) return ['type' => 'bad', 'msg' => '本登録が済んだ担当者がいます。その中から SES を選んでください。'];
        if ($s->next_rep_user_id && DB::table('users')->where('id', $s->next_rep_user_id)->whereNull('registered_at')->exists()) {
            return ['type' => 'bad', 'msg' => 'すでに次の SES を仮登録しています。送り直しや取り消しは、その方の行から行ってください。'];
        }
        $sei = clean_name(self::s($d['sei'] ?? null)); $mei = clean_name(self::s($d['mei'] ?? null));
        $email = strtolower(trim((string) self::s($d['email'] ?? null)));
        if ($sei === null || $mei === null) return ['type' => 'bad', 'msg' => '氏名（姓・名）を入れてください。'];
        if (mb_strlen($sei) > 50 || mb_strlen($mei) > 50) return ['type' => 'bad', 'msg' => '氏名は、姓・名とも50文字までにしてください。'];
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return ['type' => 'bad', 'msg' => 'メールアドレスを正しく入れてください。'];
        if ($why = self::emailInUse($email)) return ['type' => 'bad', 'msg' => $why];
        $uid = (int) DB::transaction(function () use ($sesId, $sei, $mei, $email, $actor) {
            $uid = (int) DB::table('users')->insertGetId(['email' => $email] + W::nameCols(join_name($sei, $mei), $sei, $mei)
                + ['role' => 'ses', 'ses_id' => $sesId, 'registered_by' => $actor->id ?? null, 'created_at' => now_str(), 'updated_at' => now_str()] + W::roleCodes('ses'));
            DB::table('ses_companies')->where('id', $sesId)->update(['next_rep_user_id' => $uid,'updated_at' => now_str()]);
            return $uid;
        });
        self::sendLink(DB::table('users')->find($uid), $actor); // 次の SESなので、SES の本登録の画面になる
        N::audit($actor->id ?? null, 'rep-next', "ses #{$sesId}");
        return ['type' => 'ok', 'msg' => '次の SES を仮登録し、仮登録のご案内を送りました。本登録が済むと、SES が入れ替わります。'];
    }

    // ---------- 送り直し・取り消し ----------
    // 戻り値：['type' => 'ok'|'bad', 'msg' => 文]
    public static function resend(object $target, $actor): array
    {
        if ($target->registered_at) return ['type' => 'bad', 'msg' => 'この方は、すでに本登録が済んでいます。'];
        if ($target->left_at || !$target->active) return ['type' => 'bad', 'msg' => '停止中か退会済の方には送り直せません。'];
        if (!self::companyAllows($target)) return ['type' => 'bad', 'msg' => '会社が参加中ではないため、送り直せません。'];
        if (!$target->email) return ['type' => 'bad', 'msg' => 'メールアドレスが登録されていません。'];
        self::sendLink($target, $actor);
        N::audit($actor->id ?? null, 'reg-resend', lbl('role', $target->role)." #{$target->id}");
        return ['type' => 'ok', 'msg' => '仮登録のご案内を送り直しました（前のリンクは使えなくなりました）。'];
    }

    // 仮登録の取り消し：パスワードも本登録の日時も無い行だけ消す（L・2026-10-31 TEZZO 承認）。['type', 'msg', 'sesDeleted' => bool]
    public static function cancel(object $target, $actor): array
    {
        $bad = fn (string $m) => ['type' => 'bad', 'msg' => $m, 'sesDeleted' => false];
        $used = '本登録が済んでいる（使い始めた）方は、取り消せません。';
        if ($target->password || $target->registered_at) return $bad($used);
        $sesDeleted = false;
        $err = null;
        DB::transaction(function () use ($target, $used, &$sesDeleted, &$err) {
            $id = (int) $target->id;
            // 判定のあとに本登録が済んでいないよう、行を鍵をかけて読み直して、もう一度判定する
            $now = DB::table('users')->where('id', $id)->lockForUpdate()->first();
            if (!$now) { $err = 'この方はすでにいません。'; return; }
            if ($now->password || $now->registered_at) { $err = $used; return; }
            $target = $now;
            if ($target->role === 'ses' && $target->ses_id) {
                $s = DB::table('ses_companies')->where('id', $target->ses_id)->lockForUpdate()->first();
                if ($s && (int) $s->rep_user_id === $id) {
                    if ($s->status !== 'provisional') { $err = '参加中の会社の SES は、取り消せません。'; return; }
                    $others = DB::table('users')->where('ses_id', $s->id)->where('id', '!=', $id)->count() + DB::table('engineers')->where('ses_id', $s->id)->count()
                        + DB::table('case_threads')->where('partner_ses_id', $s->id)->count(); // 案件のやり取りの相手に残っている会社は消さない
                    if ($others === 0) { DB::table('ses_companies')->where('id', $s->id)->delete(); $sesDeleted = true; }
                    else DB::table('ses_companies')->where('id', $s->id)->update(['rep_user_id' => null]);
                } elseif ($s && (int) $s->next_rep_user_id === $id) {
                    DB::table('ses_companies')->where('id', $s->id)->update(['next_rep_user_id' => null]);
                }
            }
            if ($target->role === 'eg' && $target->engineer_id) {
                $eid = (int) $target->engineer_id;
                foreach (['applications', 'case_offers', 'case_mails', 'contracts', 'messages', 'case_threads', 'engineer_files'] as $t) {
                    if (DB::table($t)->where('engineer_id', $eid)->exists()) { $err = '応募・配信・やり取りなどの記録があるため、取り消せません。'; return; }
                }
                // 技術データ（スキル・経験票など）が入っている行は、v0.2.0 より前からいる人の可能性があるので、技術登録は残してアカウントだけ消す
                $hasTech = false;
                foreach (['engineer_skills', 'engineer_skill_checks', 'engineer_projects', 'engineer_histories', 'engineer_certs'] as $t) if (DB::table($t)->where('engineer_id', $eid)->exists()) $hasTech = true;
                if (!$hasTech) DB::table('engineers')->where('id', $eid)->delete();
            }
            DB::table('app_notifications')->where('user_id', $id)->delete();
            DB::table('sessions')->where('user_id', $id)->delete();
            DB::table('users')->where('id', $id)->delete();
        });
        if ($err) return $bad($err);
        // 記録には名前・メールアドレスを書かない
        N::audit($actor->id ?? null, 'reg-cancel', lbl('role', $target->role).' #'.$target->id);
        return ['type' => 'ok', 'msg' => '仮登録を取り消しました。', 'sesDeleted' => $sesDeleted];
    }

    // ---------- 候補などから外す条件（K17） ----------
    // 本登録が済み、停止中でも退会済でもないエンジニアだけ
    public static function usableEngineers($query, string $alias = 'engineers')
    {
        return $query->whereNotNull("$alias.registered_at")->whereNull("$alias.suspended_at")->where("$alias.status", '!=', 'left');
    }

    // ---------- 入力の整えと検査（本登録の画面・足りない項目の画面） ----------
    // 入力を整える（前後の空白は全角も取る・郵便番号はそろえる）。入力欄が「見」の項目は無視する（改ざんに備える）。$only で対象の項目を絞れる
    public static function cleanInput(array $in, string $role, bool $isRep, ?array $only = null): array
    {
        $out = [];
        foreach (Requirements::fields($role, $isRep) as $key => $def) {
            if ($def['mode'] === Requirements::VIEW || ($only !== null && !in_array($key, $only, true))) continue;
            $raw = $in[$key] ?? null;
            if ($key === 'postal_code') {
                $n = is_string($raw) ? postal_norm($raw) : null;
                $out[$key] = $n === false ? $raw : $n;
            } else {
                $out[$key] = clean_name($raw);
            }
        }
        if ($c = Requirements::consent($role, $isRep)) {
            if ($only === null || in_array($c['key'], $only, true)) $out[$c['key']] = $in[$c['key']] ?? null; // 変換せず元の値を残し、検査（accepted）で見る
        }
        return $out;
    }

    // 検査の決まり：[規則, メッセージ, 項目の名前]
    public static function rules(string $role, bool $isRep, ?array $only = null): array
    {
        $rules = []; $attr = [];
        foreach (Requirements::fields($role, $isRep) as $key => $def) {
            if ($def['mode'] === Requirements::VIEW || ($only !== null && !in_array($key, $only, true))) continue;
            $base = match ($key) {
                'phone' => ['max:30', 'regex:/^[0-9+\-() ]*$/'],
                'postal_code' => ['regex:/^\d{3}-\d{4}$/'],
                'address' => ['max:200'],
                'company_name', 'ses_name', 'position' => ['max:100'],
                default => ['max:50'],
            };
            $rules[$key] = array_merge([$def['required'] ? 'required' : 'nullable', 'string'], $base);
            $attr[$key] = $def['label'];
        }
        if ($c = Requirements::consent($role, $isRep)) {
            if ($only === null || in_array($c['key'], $only, true)) { $rules[$c['key']] = 'accepted'; $attr[$c['key']] = $c['label']; }
        }
        $msg = ['postal_code.regex' => '郵便番号は7桁の数字で入れてください（例：123-4567）。', 'phone.regex' => '電話番号は、数字・+・-・()・空白で入れてください。'];
        return [$rules, $msg, $attr];
    }

    // 足りない項目の画面で入れた値を保存する。$only は足りなかった項目の名前、$d は cleanInput の結果。保存したら、足りなければ登録の日時も入れる
    public static function saveMissing(object $u, string $role, array $d, array $only): void
    {
        $isRep = Requirements::isRep((object) array_merge((array) $u, ['role' => $role]));
        $kind = Requirements::kind($role, $isRep);
        $now = now_str();
        $uu = []; $co = []; $en = [];
        foreach (Requirements::fields($role, $isRep) as $key => $def) {
            if ($def['mode'] === Requirements::VIEW || !in_array($key, $only, true) || !array_key_exists($key, $d)) continue;
            $val = $d[$key];
            if ($key === 'ses_name') { $co['name'] = $val; continue; }
            match ($def['store']) {
                'company' => $co[$key] = $val,
                // エンジニアの電話は、アカウントにも写す。ただし兼任の人（DB の役割が eg でない）の電話は会社のものかもしれないので、写さない
                'engineer' => [$en[$key] = $val, $key === 'phone' && $u->role === 'eg' ? $uu['phone'] = $val : null],
                default => $uu[$key] = $val,
            };
        }
        // 氏名・ふりがなは、つないだ列もそろえる。エンジニアは両方の表に入れる（片方だけ足りないときも、もう片方は今の値のまま）
        $eng = $kind === 'eg' && $u->engineer_id ? DB::table('engineers')->find($u->engineer_id) : null;
        foreach (['name' => ['name_sei', 'name_mei'], 'kana' => ['kana_sei', 'kana_mei']] as $full => [$a, $b]) {
            if (!array_key_exists($a, $uu) && !array_key_exists($b, $uu)) continue;
            $sei = array_key_exists($a, $uu) ? $uu[$a] : (($eng->$a ?? null) ?: $u->$a);
            $mei = array_key_exists($b, $uu) ? $uu[$b] : (($eng->$b ?? null) ?: $u->$b);
            $uu[$full] = join_name($sei, $mei);
            if ($eng) $en += [$a => $sei, $b => $mei, $full => $uu[$full]];
            // ふりがながそろったら、名前を伏せたスキルシートのイニシャル（T.Y）も入れる（まだ無いとき）
            if ($full === 'kana' && $eng && empty($eng->initials) && $uu['kana']) $en['initials'] = W::initialsFromKana($uu['kana']);
            // 兼任の人（DB の役割が eg でない）は、担当者・管理者のアカウントの氏名・ふりがなを変えない（技術登録だけに入れる）
            if ($eng && $u->role !== 'eg') unset($uu[$a], $uu[$b], $uu[$full]);
        }
        $c = Requirements::consent($role, $isRep);
        if ($c && !empty($d[$c['key']])) {
            match ($kind) {
                'rep' => [$co['agreed_at'] = $now, $uu['secrecy_agreed_at'] = $now],
                'staff' => $uu['secrecy_agreed_at'] = $now,
                default => [$en['consent_at'] = $now, $en['self_confirmed_at'] = $now],
            };
        }
        DB::transaction(function () use ($u, $kind, $uu, $co, $en, $now) {
            $uu += ['updated_at' => $now];
            if (empty($u->profile_completed_at)) $uu['profile_completed_at'] = $now;
            DB::table('users')->where('id', $u->id)->update($uu);
            if ($co && $u->ses_id) DB::table('ses_companies')->where('id', $u->ses_id)->update($co + ['updated_at' => $now]);
            if ($en && $u->engineer_id) DB::table('engineers')->where('id', $u->engineer_id)->update($en + ['updated_at' => $now]);
            if ($kind === 'rep' && (array_key_exists('name_sei', $uu) || array_key_exists('name_mei', $uu))) {
                $row = DB::table('users')->find($u->id);
                self::syncRepName((int) $u->ses_id, $row->name_sei, $row->name_mei);
            }
        });
        // エンジニアの立場で足りない項目がなくなったら、候補に出す（兼任の人は、ここで初めて出る）
        if ($role === 'eg' && $u->engineer_id && !Requirements::missing(DB::table('users')->find($u->id), $role)) {
            DB::table('engineers')->where('id', $u->engineer_id)->whereNull('registered_at')->update(['registered_at' => $now]);
        }
        N::audit($u->id, 'profile-complete', lbl('role', $role));
    }

    // ---------- 今いる行の移し替え（K32。新しい列だけを埋める） ----------
    public static function migrateExisting(): void
    {
        // 1. パスワードのある人は本登録済みにする
        DB::table('users')->whereNull('registered_at')->whereNotNull('password')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $u) {
                $at = $u->two_factor_confirmed_at ?: ($u->profile_completed_at ?: ($u->last_login ?: ($u->created_at ?: now_str())));
                DB::table('users')->where('id', $u->id)->update(['registered_at' => $at]);
            }
        });
        // 2. リンクの種類：パスワードがあれば再設定、なければ本登録
        DB::table('users')->whereNotNull('token')->whereNull('token_kind')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $u) DB::table('users')->where('id', $u->id)->update(['token_kind' => $u->password ? 'reset' : 'register']);
        });
        // 3. エンジニア：つながるアカウントにパスワードがあれば、その日時
        DB::table('engineers')->whereNull('registered_at')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $e) {
                $at = DB::table('users')->where('engineer_id', $e->id)->whereNotNull('password')->whereNotNull('registered_at')->min('registered_at');
                if ($at) DB::table('engineers')->where('id', $e->id)->update(['registered_at' => $at]);
            }
        });
        // 4. 会社の SES：使える担当者のうち、本登録済みで id が最小の人。いなければ使える仮登録の人で最小
        DB::table('ses_companies')->where('kind', 'ses')->whereNull('rep_user_id')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $s) {
                $usable = fn () => DB::table('users')->where('role', 'ses')->where('ses_id', $s->id)->where('active', 1)->whereNull('left_at')->orderBy('id');
                $id = $usable()->whereNotNull('registered_at')->value('id') ?: $usable()->value('id');
                if ($id) DB::table('ses_companies')->where('id', $s->id)->update(['rep_user_id' => $id]);
            }
        });
    }
}
