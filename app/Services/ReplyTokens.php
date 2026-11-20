<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

// 案内メールの「返信先トークン」（v0.12.0）。送ったメール 1 通ごとに 1 つ発行し、返ってきたメールを、どの案内メールへの返信か探すのに使う。
// 返事のリンク（case_mails.token。/r/…）とは別。こちらは Reply-To（park+トークン@…）と件名・本文の印に使う。
class ReplyTokens
{
    const KIND_CASE_MAIL = 'case-mail';
    const KIND_PROVIDER = 'provider';      // プロバイダーへ送った返信（v0.13.0）
    const LENGTH = 20;     // トークンの字数（英小文字と数字。メールアドレスの + のあとに使える文字だけ）
    const TAG_LENGTH = 10;  // 件名・本文の印に使う、トークンの頭の字数
    const ALPHABET = 'abcdefghijklmnopqrstuvwxyz0123456789';

    // 新しいトークンの文字列（推測できない乱数）
    public static function generate(): string
    {
        $out = '';
        $max = strlen(self::ALPHABET) - 1;
        for ($i = 0; $i < self::LENGTH; $i++) $out .= self::ALPHABET[random_int(0, $max)];
        return $out;
    }

    // 発行して行を作る。戻り：[行の id, トークン]
    // $viaPark：送ったとき受信が使えて、返信先を PARK に向けたか（他社のエンジニアの返信を自動で紐付けてよいかの判断に使う）
    public static function issue(string $kind, int $caseId, ?int $sesId, ?int $engineerId, bool $viaPark = false): array
    {
        for ($try = 0; $try < 5; $try++) {
            $token = self::generate();
            if (DB::table('reply_tokens')->where('token', $token)->exists()) continue;
            $id = DB::table('reply_tokens')->insertGetId(['token' => $token, 'kind' => $kind, 'case_id' => $caseId, 'ses_id' => $sesId, 'engineer_id' => $engineerId,
                'via_park' => $viaPark, 'sent_at' => now_str(), 'created_at' => now_str()]);
            return [(int) $id, $token];
        }
        throw new \RuntimeException('reply token could not be made');
    }

    // 送ったメールの情報を書き足す（case_mail_id・provider_mail_id・outbox_id・message_id だけ）
    public static function attach(int $id, array $fields): void
    {
        $fields = array_intersect_key($fields, array_flip(['case_mail_id', 'provider_mail_id', 'outbox_id', 'message_id']));
        if ($fields) DB::table('reply_tokens')->where('id', $id)->update($fields);
    }

    // 件名・本文の印に使う、トークンの頭の字
    public static function tagOf(string $token): string
    {
        return substr($token, 0, self::TAG_LENGTH);
    }

    // 件名の最後に付ける印
    public static function subjectMark(string $token): string
    {
        return '[PARK-'.self::tagOf($token).']';
    }

    // プロバイダーあての件名の印（PARK の名前を出さない）
    public static function providerMark(string $token): string
    {
        return '[No.'.self::tagOf($token).']';
    }

    public static function find(string $token): ?object
    {
        if (!preg_match('/^[a-z0-9]{'.self::LENGTH.'}$/', $token)) return null;
        return DB::table('reply_tokens')->where('token', $token)->first();
    }
}
