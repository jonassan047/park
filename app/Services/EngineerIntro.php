<?php

namespace App\Services;

use App\Support\IntroSkills;
use Illuminate\Support\Facades\DB;

// 推薦文（v0.19.0）：SES・担当者が貼り付けるエンジニアの紹介文。
// 本文を読む・書くのはここだけ（画面は engineers/show と engineers/intro）。EG 本人・他社には見せない。
// メール・お知らせ・書類・検索・手元の AI には使わない。監査ログにも本文・氏名・スキルの名前は書かない（番号・文字数・件数だけ）。
class EngineerIntro
{
    const MAX = 10000;      // 本文の長さ（文字）
    const TABLE = 'engineer_intros';
    const LEFT = '退会済みのエンジニアの推薦文は直せません。';
    const NONE = '推薦文はありません。';

    // 読めるか：管理者と、そのエンジニアが今自社の SES・担当者。EG 本人は読めない。兼任の人（管理者を兼ねる人も）も、自分自身の分は読めない
    public static function canView($u, object $e): bool
    {
        if (!$u || self::isSelf($u, $e)) return false;
        if ($u->role === 'admin') return true;
        return self::sameCompany($u, $e);
    }

    // 登録・更新・消せるか：自社のエンジニアの SES・担当者だけ（管理者は読むだけ）。退会済は直せない
    public static function canEdit($u, object $e): bool
    {
        return $u && self::sameCompany($u, $e) && $e->status !== 'left';
    }

    // その人自身のエンジニアか。紐づけ（users.engineer_id）のほか、メールアドレスが同じなら本人とみなす（退会で紐づけが外れたあとも読めないように）
    private static function isSelf($u, object $e): bool
    {
        if ((int) ($u->engineer_id ?? 0) === (int) $e->id) return true;
        $a = mb_strtolower(trim((string) ($e->email ?? '')));
        $b = mb_strtolower(trim((string) ($u->email ?? '')));
        return $a !== '' && $a === $b;
    }

    private static function sameCompany($u, object $e): bool
    {
        return $u->role === 'ses' && (int) $u->ses_id === (int) $e->ses_id && !self::isSelf($u, $e);
    }

    // SES・担当者が読める行（自社が書いた分。エンジニアが今自社のときだけ呼ぶ）。無ければ null
    public static function rowFor($u, object $e): ?object
    {
        if (!self::canView($u, $e) || $u->role === 'admin') return null;
        return self::rows((int) $e->id, (int) $u->ses_id)->first();
    }

    // 管理者が読める、全社の分（会社名つき。今の所属の会社が先）
    public static function rowsForAdmin(object $e): \Illuminate\Support\Collection
    {
        return self::rows((int) $e->id, null)->map(function ($r) use ($e) {
            $r->former = (int) $r->ses_id !== (int) $e->ses_id; // 前の所属の分
            return $r;
        })->sortBy(fn ($r) => $r->former ? 1 : 0)->values();
    }

    private static function rows(int $engineerId, ?int $sesId): \Illuminate\Support\Collection
    {
        $q = DB::table(self::TABLE.' as i')->leftJoin('ses_companies as s', 's.id', '=', 'i.ses_id')->leftJoin('users as u', 'u.id', '=', 'i.updated_by')
            ->where('i.engineer_id', $engineerId)->select('i.*', 's.name as ses_name', 'u.name as updater_name')->orderBy('i.id');
        if ($sesId !== null) $q->where('i.ses_id', $sesId);
        return $q->get();
    }

    // 本文を整える：改行をそろえ、制御文字（改行・タブを除く）を消し、前後の空白を取る。戻り値は [整えた本文, エラーの文か null]
    public static function check(?string $body): array
    {
        $tooLong = '推薦文は'.number_format(self::MAX).'文字までにしてください。';
        $b = (string) $body;
        // 長すぎるものは、重い処理の前にバイト数で止める（1文字は最大4バイト）
        if (strlen($b) > self::MAX * 4 + 16) return ['', $tooLong];
        if (!mb_check_encoding($b, 'UTF-8')) return ['', '推薦文の文字を読み取れませんでした。'];
        $b = str_replace(["\r\n", "\r"], "\n", $b);
        $b = (string) preg_replace('/[^\P{Cc}\n\t]/u', '', $b);
        $b = self::trimAll($b);
        if ($b === '') return [$b, '推薦文を入れてください。'];
        if (mb_strlen($b) > self::MAX) return [$b, $tooLong];
        return [$b, null];
    }

    // 前後の空白（全角の空白も）を取る。正規表現の「末尾」の指定は長い文で遅くなるので、バイトで取る（UTF-8 と確かめたあとだけ使う）
    private static function trimAll(string $s): string
    {
        $full = "\xE3\x80\x80"; // 全角の空白
        do {
            $len = strlen($s);
            $s = trim($s, " \t\n\r\0\x0B\f");
            while (str_starts_with($s, $full)) $s = substr($s, 3);
            while (str_ends_with($s, $full)) $s = substr($s, 0, -3);
        } while (strlen($s) !== $len);
        return $s;
    }

    // 保存して、スキルを抜き出し直す。戻り値：['forbidden' => true]、['bad' => 文]、
    // か ['ok' => true, 'chars' => 文字数, 'added' => […], 'updated' => […], 'kept' => […], 'removed' => […], 'unplaced' => […], 'skippedLines' => 読まなかった行数]
    public static function save($u, object $e, ?string $body): array
    {
        if (!$u || !self::sameCompany($u, $e)) return ['forbidden' => true];
        if ($e->status === 'left') return ['bad' => self::LEFT];
        [$b, $err] = self::check($body);
        if ($err) return ['bad' => $err];
        $x = IntroSkills::extract($b);
        $res = DB::transaction(function () use ($u, $e, $b, $x) {
            // 同じエンジニアの保存が同時に来ても（初めての保存の2回押しも）、順番に処理する（sqlite では何も起きない）
            DB::table('engineers')->where('id', $e->id)->lockForUpdate()->first();
            $now = now_str();
            $cur = DB::table(self::TABLE)->where('engineer_id', $e->id)->where('ses_id', $u->ses_id)->first();
            $row = ['body' => $b, 'updated_by' => $u->id, 'updated_at' => $now];
            if ($cur) DB::table(self::TABLE)->where('id', $cur->id)->update($row);
            else DB::table(self::TABLE)->insert($row + ['engineer_id' => $e->id, 'ses_id' => $u->ses_id, 'created_by' => $u->id, 'created_at' => $now, 'picked' => null]);
            $r = SkillChecks::syncIntro((int) $e->id, $x['rows']);
            DB::table(self::TABLE)->where('engineer_id', $e->id)->where('ses_id', $u->ses_id)->update(['picked' => json_encode($x['picked'], JSON_UNESCAPED_UNICODE)]);
            return $r;
        });
        $chars = mb_strlen($b);
        Notifier::audit($u->id, 'eg-intro-save', "エンジニア #{$e->id}：推薦文 {$chars} 文字、スキル +".(count($res['added'])).' −'.count($res['removed']));
        return ['ok' => true, 'chars' => $chars, 'added' => $res['added'], 'updated' => $res['updated'], 'kept' => $res['kept'], 'removed' => $res['removed'],
            'unplaced' => $x['unplaced'], 'skippedLines' => $x['skipped']];
    }

    // 推薦文を消す（推薦文から入れて、まだ誰も確かめていないスキルも外す）。EG にはお知らせ・メールを出さない。自社の推薦文が無ければ何もしない
    public static function delete($u, object $e): array
    {
        if (!$u || !self::sameCompany($u, $e)) return ['forbidden' => true];
        if ($e->status === 'left') return ['bad' => self::LEFT];
        $removed = DB::transaction(function () use ($u, $e) {
            DB::table('engineers')->where('id', $e->id)->lockForUpdate()->first();
            if (!DB::table(self::TABLE)->where('engineer_id', $e->id)->where('ses_id', $u->ses_id)->exists()) return null;
            DB::table(self::TABLE)->where('engineer_id', $e->id)->where('ses_id', $u->ses_id)->delete();
            return SkillChecks::syncIntro((int) $e->id, [])['removed'];
        });
        if ($removed === null) return ['bad' => self::NONE];
        Notifier::audit($u->id, 'eg-intro-delete', "エンジニア #{$e->id}：推薦文を消す、スキル −".count($removed));
        return ['ok' => true, 'removed' => $removed];
    }

    // 保存済みの「推薦文から入れたスキル」の名前
    public static function picked(?object $row): array
    {
        $a = $row && $row->picked ? json_decode($row->picked, true) : [];
        return is_array($a) ? $a : [];
    }
}
