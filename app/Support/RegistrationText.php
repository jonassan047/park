<?php

namespace App\Support;

// 仮登録・本登録の文面を1か所に集める（メールの件名・本文、立場の言葉、同意、知らせ、断りの文）。
// 文面はこのファイルだけを直せばよい。Blade・手続きには文を書かない。
// 仮登録メールと同意の文は、41_Park-Project の要件定義書（付録）の案のまま（TEZZO 承認 2026-10-31）。
// 件名の頭の【PARK】は、メールを送るとき Notifier が [PARK] を付けるので、ここには書かない。
class RegistrationText
{
    // ---------- 仮登録メール ----------
    const PROVISIONAL_SUBJECT = '仮登録のご案内：本登録をお願いします';

    // $v：company（宛先の企業名）・sei・mei・by_company・by_name（登録した人）・role_name・link・required（必須項目の行）
    public static function provisionalBody(array $v): string
    {
        return trim("{$v['company']} {$v['sei']} {$v['mei']}")." 様\n\n"
            .(!empty($v['note']) ? $v['note']."\n\n" : '')
            .trim("{$v['by_company']} {$v['by_name']}")." 様が、あなたを PARK の{$v['role_name']}として仮登録しました。\n"
            ."次のリンクから、7日以内に本登録をお願いします。\n\n"
            ."{$v['link']}\n\n"
            ."本登録の画面で、企業名・お名前をお確かめのうえ、次の項目をお入れください。\n"
            .$v['required']."\n\n"
            ."本登録が済むまで、PARK のほかの画面はご利用いただけません。\n"
            ."期限が切れたときは、登録した方に送り直しをご依頼ください。\n"
            .'お心当たりがない場合は、リンク先の「心当たりがない」を押すか、このメールを破棄してください。';
    }

    // その立場の必須項目（メールの「{その立場の必須項目}」に入る行）
    public static function requiredLines(string $role, bool $isRep = false): string
    {
        $lines = [];
        foreach (Requirements::required($role, $isRep) as $key => $def) {
            if ($def['mode'] === Requirements::SELF) $lines[] = '・'.$def['label'];
        }
        $lines[] = '・パスワード（8文字以上）';
        if ($c = Requirements::consent($role, $isRep)) $lines[] = '・'.self::consent($role, $isRep)['mail'];
        return implode("\n", $lines);
    }

    // ---------- 同意の文（本登録の画面） ----------
    // text：画面に出す同意の文／mail：メールの必須項目に出す言い方
    public static function consent(string $role, bool $isRep = false): ?array
    {
        return match (Requirements::kind($role, $isRep)) {
            'rep' => ['text' => '会社を代表して、PARK で知り得た情報の守秘義務に同意します。', 'mail' => '守秘義務への同意（会社として）'],
            'staff' => ['text' => 'PARK で知り得た情報の守秘義務に同意します。', 'mail' => '守秘義務への同意'],
            'eg' => ['text' => '個人情報の取り扱いに同意し、登録の内容が事実であることを確認しました。', 'mail' => '個人情報の取り扱いへの同意（登録の内容の確認を兼ねます）'],
            default => null,
        };
    }

    // ---------- 承認の知らせ（公開の申し込みを承認したとき） ----------
    const APPROVAL_NOTE = 'このたびの PARK への参加のお申し込みを、承認しました。';
    const APPROVAL_SUBJECT = '参加のお申し込みを承認しました';

    public static function approvalBody(string $company): string
    {
        return "{$company} のご担当者さま\n\nこのたびの PARK への参加のお申し込みを、承認しました。\n"
            ."代表者としてお知らせいただいた方に、仮登録のご案内を送っています。本登録が済むと、PARK をご利用いただけます。";
    }

    // ---------- 画面の文 ----------
    const LINK_UNUSABLE_TITLE = 'このリンクは使えません';
    const LINK_UNUSABLE = 'このリンクは使えません。登録した方に送り直しをご依頼ください。';
    const LINK_RENEWED = 'リンクが新しくなりました。メールで届いた新しいリンクから、もう一度お進みください。';
    const DECLINED_DONE = 'リンクを無効にしました。ご連絡ありがとうございました。登録した方にお知らせします。';
    const FORGOT_DONE = '登録があれば、メールを送りました。届いたメールの案内に沿って進めてください。';
    const REFUSED_EMAIL = 'このアドレスでは登録できません。';

    // 本登録が済んでいない人が「パスワードを忘れた」を押したとき、本人に送る文
    const HELP_SUBJECT = '本登録のご案内について';
    const HELP_BODY = "PARK の本登録が、まだ済んでいません。\n本登録のリンクは、登録した方に送り直しをご依頼ください。\nこのメールに心当たりがない場合は、破棄してください。";

    // ---------- 管理者が担当者・エンジニアを仮登録したとき、所属の代表者に送る知らせ ----------
    public static function adminRegisteredTitle(string $who, string $roleName): string
    {
        return "管理者が{$roleName}を仮登録しました：{$who}";
    }

    public static function adminRegisteredBody(string $who, string $roleName, string $by): string
    {
        return "管理者の{$by}が、{$who} さんを PARK の{$roleName}として仮登録しました。\n本登録が済むと、お知らせします。\n心当たりがない場合は、管理者にご連絡ください。";
    }

    // ---------- 代表者の交代の知らせ ----------
    const REP_CHANGED_TITLE = '代表者が変わりました';

    public static function repChangedBody(string $company, string $newRep, string $oldRep): string
    {
        return "{$company} の代表者が、{$oldRep} さんから {$newRep} さんに変わりました。\n前の代表者は担当者になります。";
    }

    // ---------- 上位への知らせ ----------
    // 本登録が済んだとき：$who は「{立場} {氏名}」、$changes は修正の前後の行（無ければ空）
    public static function doneTitle(string $who): string
    {
        return "本登録が完了しました：{$who}";
    }

    public static function doneBody(string $who, string $roleName, array $changes = [], ?string $warn = null): string
    {
        return "{$who} さんが、PARK の{$roleName}として本登録を完了しました。\n参加中になりました。"
            .($changes ? "\n\n本登録のときに直された内容：\n".implode("\n", $changes) : '')
            .($warn ? "\n\n{$warn}" : '');
    }

    public static function declinedTitle(string $who): string
    {
        return "「心当たりがない」と回答がありました：{$who}";
    }

    public static function declinedBody(string $who, string $roleName): string
    {
        return "{$who} さん（{$roleName}）あての仮登録のメールに、「心当たりがない」と回答がありました。\nリンクは無効にしました。宛先のメールアドレスが正しいか、確かめてください。必要なら、宛先を直して送り直してください。";
    }
}
