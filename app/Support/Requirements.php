<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

// 必須項目の決まり（4つの立場：管理者・SES の代表者・担当者・エンジニア）。台帳 8章の表をそのままコードにしたもの。
// 本登録の画面・足りない項目の画面・編集の入口が、すべてここを見る。
// 「足りないか」は保存した印ではなく、今の値と立場から毎回計算する。
//
// 項目ごとの mode：
//   confirm＝上位が入れた値を出し、本人が確かめて直せる（必須）／self＝本人が入れる（必須）
//   view＝本人は見るだけ／opt＝任意
// store：その値をしまう場所（users／company＝ses_companies／engineer＝engineers）
class Requirements
{
    const CONFIRM = 'confirm';
    const SELF = 'self';
    const VIEW = 'view';
    const OPT = 'opt';

    // 立場の名前（画面・メールで使う）
    const ROLE_NAMES = ['admin' => '管理者', 'rep' => 'SES の代表者', 'staff' => 'SES のご担当者', 'eg' => 'エンジニア'];

    // 同意の入力欄の名前（管理者は同意なし）
    const CONSENT_KEYS = ['rep' => 'agree', 'staff' => 'agree', 'eg' => 'consent'];

    // 立場の種類：admin・rep（SES の代表者）・staff（SES の担当者）・eg
    public static function kind(string $role, bool $isRep): string
    {
        return $role === 'ses' ? ($isRep ? 'rep' : 'staff') : $role;
    }

    // 項目の表。キーは入力欄の名前
    public static function fields(string $role, bool $isRep = false): array
    {
        $f = fn (string $label, string $mode, string $store) => ['label' => $label, 'mode' => $mode, 'required' => in_array($mode, [self::CONFIRM, self::SELF], true), 'store' => $store];
        $name = ['name_sei' => $f('氏名（姓）', self::CONFIRM, 'users'), 'name_mei' => $f('氏名（名）', self::CONFIRM, 'users')];
        $kana = fn (string $mode) => ['kana_sei' => $f('ふりがな（姓）', $mode, 'users'), 'kana_mei' => $f('ふりがな（名）', $mode, 'users')];
        switch (self::kind($role, $isRep)) {
            case 'admin':
                return ['company_name' => $f('企業名', self::CONFIRM, 'users')] + $name + ['email' => $f('メールアドレス', self::VIEW, 'users')] + $kana(self::OPT)
                    + ['phone' => $f('電話番号', self::SELF, 'users'), 'postal_code' => $f('郵便番号', self::OPT, 'users'), 'address' => $f('住所', self::OPT, 'users'), 'position' => $f('所属・役職', self::OPT, 'users')];
            case 'rep':
                return ['ses_name' => $f('企業正式名称', self::CONFIRM, 'company')] + $name + ['email' => $f('メールアドレス', self::VIEW, 'users')] + $kana(self::OPT)
                    + ['phone' => $f('会社の電話番号', self::SELF, 'company'), 'postal_code' => $f('会社の郵便番号', self::SELF, 'company'), 'address' => $f('会社の住所', self::SELF, 'company'),
                        'position' => $f('所属・役職', self::OPT, 'users')];
            case 'staff':
                return ['company_name' => $f('企業名', self::VIEW, 'company')] + $name + ['email' => $f('メールアドレス', self::VIEW, 'users')] + $kana(self::OPT)
                    + ['phone' => $f('電話番号', self::SELF, 'users'), 'position' => $f('所属・役職', self::OPT, 'users')];
            default: // eg
                return ['company_name' => $f('企業名', self::VIEW, 'company')] + $name + ['email' => $f('メールアドレス', self::VIEW, 'users')] + $kana(self::SELF)
                    + ['phone' => $f('電話番号', self::SELF, 'engineer'), 'postal_code' => $f('郵便番号', self::SELF, 'engineer'), 'address' => $f('住所', self::SELF, 'engineer')];
        }
    }

    // 同意の項目（無ければ null）。key は入力欄の名前、column はしまう列
    public static function consent(string $role, bool $isRep = false): ?array
    {
        return match (self::kind($role, $isRep)) {
            'rep' => ['key' => 'agree', 'label' => '守秘義務への同意', 'table' => 'ses_companies', 'column' => 'agreed_at'],
            'staff' => ['key' => 'agree', 'label' => '守秘義務への同意', 'table' => 'users', 'column' => 'secrecy_agreed_at'],
            'eg' => ['key' => 'consent', 'label' => '個人情報の取り扱いへの同意', 'table' => 'engineers', 'column' => 'consent_at'],
            default => null,
        };
    }

    // 本人が入れる（または確かめる）必須の項目だけ
    public static function required(string $role, bool $isRep = false): array
    {
        return array_filter(self::fields($role, $isRep), fn ($x) => $x['required']);
    }

    // 立場の見出し（メールの「立場」に入る言葉）
    public static function roleName(string $role, bool $isRep = false): string
    {
        return self::ROLE_NAMES[self::kind($role, $isRep)];
    }

    // その人が、会社の代表者（または次の代表者）か
    public static function isRep(?object $u): bool
    {
        if (!$u || ($u->role ?? '') !== 'ses' || empty($u->ses_id)) return false;
        return DB::table('ses_companies')->where('id', $u->ses_id)->where(fn ($q) => $q->where('rep_user_id', $u->id)->orWhere('next_rep_user_id', $u->id))->exists();
    }

    // いまの値（項目 => 値）。$u は users の行、$role はいまの立場
    public static function values(object $u, string $role, ?bool $isRep = null): array
    {
        $isRep ??= self::isRep((object) array_merge((array) $u, ['role' => $role]));
        $kind = self::kind($role, $isRep);
        $out = [];
        $company = $u->ses_id ? DB::table('ses_companies')->find($u->ses_id) : null;
        $eng = $kind === 'eg' && $u->engineer_id ? DB::table('engineers')->find($u->engineer_id) : null;
        foreach (self::fields($role, $isRep) as $key => $def) {
            $out[$key] = match (true) {
                $key === 'ses_name' => $company->name ?? null,
                $key === 'company_name' && $def['store'] === 'company' => $company->name ?? null,
                $def['store'] === 'company' => $company->$key ?? null,
                $def['store'] === 'engineer' => $eng->$key ?? null,
                $kind === 'eg' && in_array($key, ['name_sei', 'name_mei', 'kana_sei', 'kana_mei'], true) => ($eng->$key ?? null) ?: ($u->$key ?? null),
                default => $u->$key ?? null,
            };
        }
        return $out;
    }

    // 足りない項目の名前（入力欄の名前の一覧。同意が未了なら 'consent' ではなく同意の欄の名前を入れる）
    public static function missing(?object $u, string $role): array
    {
        if (!$u) return [];
        $isRep = self::isRep((object) array_merge((array) $u, ['role' => $role]));
        if ($role === 'eg' && !($u->engineer_id && DB::table('engineers')->where('id', $u->engineer_id)->exists())) return [];
        $miss = [];
        foreach (self::values($u, $role, $isRep) as $key => $val) {
            $def = self::fields($role, $isRep)[$key];
            if ($def['required'] && ($val === null || trim((string) $val) === '')) $miss[] = $key;
        }
        if ($c = self::consent($role, $isRep)) {
            $has = DB::table($c['table'])->where('id', $c['table'] === 'users' ? $u->id : ($c['table'] === 'engineers' ? $u->engineer_id : $u->ses_id))->value($c['column']);
            if (!$has) $miss[] = $c['key'];
        }
        return $miss;
    }

    // 必須の欄を空にする保存を断る（K20）。$input に入っている欄だけ調べる。$stores で、調べる「しまう場所」を絞れる。
    // 断るときは文を返す。通すときは null
    public static function checkNotEmpty(string $role, bool $isRep, array $input, ?array $stores = null): ?string
    {
        foreach (self::required($role, $isRep) as $key => $def) {
            if ($stores !== null && !in_array($def['store'], $stores, true)) continue;
            if (!array_key_exists($key, $input)) continue;
            $v = $input[$key];
            if ($v === null || (is_string($v) && trim($v) === '')) return $def['label'].'は空にできません。';
        }
        return null;
    }
}
