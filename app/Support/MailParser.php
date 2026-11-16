<?php

namespace App\Support;

// 受け取ったメール（.eml の生の文）を読む道具（v0.12.0）。外のライブラリや ext-imap は使わず、必要なところだけ自前で読む。
// 読めた分だけを返す。読めない形式でも、落ちずに空に近い結果を返す（元のメールは別に保存してある）。
class MailParser
{
    const BODY_LIMIT = 50000;   // 本文として残す最大の字数
    const MAX_DEPTH = 8;        // multipart の入れ子を読む深さ

    // 文字コードの呼び名 → mbstring の名前
    private const CHARSETS = [
        'iso-2022-jp' => 'ISO-2022-JP', 'iso-2022-jp-ms' => 'ISO-2022-JP', 'csiso2022jp' => 'ISO-2022-JP',
        'shift_jis' => 'SJIS-win', 'shift-jis' => 'SJIS-win', 'sjis' => 'SJIS-win', 'x-sjis' => 'SJIS-win', 'windows-31j' => 'SJIS-win', 'cp932' => 'SJIS-win', 'ms932' => 'SJIS-win', 'ms_kanji' => 'SJIS-win',
        'euc-jp' => 'EUC-JP', 'eucjp' => 'EUC-JP', 'x-euc-jp' => 'EUC-JP', 'euc-jp-ms' => 'eucJP-win', 'eucjp-win' => 'eucJP-win',
        'utf-8' => 'UTF-8', 'utf8' => 'UTF-8', 'us-ascii' => 'ASCII', 'ascii' => 'ASCII',
    ];

    // $headersOnly：ヘッダーだけ読む（大きすぎるメール用）
    public static function parse(string $raw, bool $headersOnly = false): array
    {
        [$headText, $body] = self::split($raw);
        $h = self::parseHeaders($headText);
        [$fromEmail, $fromName] = self::firstAddress(self::header($h, 'from'));
        $to = [];
        foreach (['to', 'cc', 'delivered-to', 'x-original-to'] as $name) {
            foreach ($h[$name] ?? [] as $v) foreach (self::addresses($v) as [$email]) $to[] = $email;
        }
        $date = self::header($h, 'date');
        $ts = $date !== '' ? strtotime($date) : false;
        $text = '';
        $attachments = [];
        if (!$headersOnly) {
            $acc = ['text' => '', 'html' => '', 'files' => []];
            try {
                self::entity($headText, $body, 0, $acc);
            } catch (\Throwable $e) {
                // 読めない形式。読めた分だけを使う
            }
            $text = trim($acc['text']) !== '' ? $acc['text'] : self::htmlToText($acc['html']);
            $text = trim(str_replace("\0", '', $text));
            if (mb_strlen($text) > self::BODY_LIMIT) $text = mb_substr($text, 0, self::BODY_LIMIT);
            $attachments = $acc['files'];
        }
        // DB に入れる文字は、最後に全部 UTF-8 として正しい文字列にそろえる（符号化されていない 8bit のヘッダーなどが混ざっても、保存で落ちないように）
        foreach ($attachments as &$f) $f['name'] = self::clean((string) $f['name']);
        unset($f);
        return [
            'headers' => $h,
            'from_email' => self::clean($fromEmail),
            'from_name' => self::clean($fromName),
            'to' => array_values(array_unique(array_map([self::class, 'clean'], $to))),
            'subject' => self::clean(self::decodeWords(self::header($h, 'subject'))),
            'message_id' => self::messageIds(self::clean(self::header($h, 'message-id')))[0] ?? null,
            'in_reply_to' => self::clean(self::header($h, 'in-reply-to')),
            'references' => self::clean(self::header($h, 'references')),
            'date' => $ts === false ? null : date('Y-m-d H:i:s', $ts),
            'content_type' => strtolower(self::header($h, 'content-type')),
            'body_text' => self::clean($text),
            'attachments' => $attachments,
        ];
    }

    // UTF-8 として正しい文字列にする。正しくなければ文字コードを調べて変換し、それでも無理なら読めない所を落とす
    public static function clean(string $s): string
    {
        if ($s === '' || mb_check_encoding($s, 'UTF-8')) return $s;
        $out = self::toUtf8($s, null);
        return mb_check_encoding($out, 'UTF-8') ? $out : mb_scrub($out, 'UTF-8');
    }

    // ヘッダーの値の中の <Message-ID> を、<> なしで並べる
    public static function messageIds(string $value): array
    {
        preg_match_all('/<([^<>\s]+)>/', $value, $m);
        return array_values(array_unique($m[1]));
    }

    // 「名前 <a@b>, c@d」を [[メール, 名前], …] にする（メールは小文字）
    public static function addresses(string $value): array
    {
        $out = [];
        foreach (self::splitAddressList($value) as $part) {
            $part = trim($part);
            if ($part === '') continue;
            if (preg_match('/<([^<>]*)>/', $part, $m)) {
                $email = trim($m[1]);
                $name = trim(str_replace($m[0], '', $part));
            } else {
                $email = trim(preg_replace('/\([^)]*\)/', '', $part));
                $name = '';
            }
            if (!str_contains($email, '@')) continue;
            $name = self::decodeWords(trim($name, " \t\"'"));
            $out[] = [strtolower($email), $name];
        }
        return $out;
    }

    private static function firstAddress(string $value): array
    {
        return self::addresses($value)[0] ?? ['', ''];
    }

    // カンマで区切る（"" と <> の中のカンマでは区切らない）
    private static function splitAddressList(string $value): array
    {
        $parts = [];
        $cur = '';
        $quote = false;
        $angle = 0;
        $len = strlen($value);
        for ($i = 0; $i < $len; $i++) {
            $ch = $value[$i];
            if ($ch === '"') $quote = !$quote;
            elseif (!$quote && $ch === '<') $angle++;
            elseif (!$quote && $ch === '>' && $angle > 0) $angle--;
            if ($ch === ',' && !$quote && $angle === 0) {
                $parts[] = $cur;
                $cur = '';
                continue;
            }
            $cur .= $ch;
        }
        $parts[] = $cur;
        return $parts;
    }

    // 改行をそろえて、ヘッダーと本体に分ける
    private static function split(string $raw): array
    {
        $raw = str_replace("\r", "\n", str_replace("\r\n", "\n", $raw));
        if (str_starts_with($raw, "\n")) return ['', substr($raw, 1)];
        $pos = strpos($raw, "\n\n");
        if ($pos === false) return [$raw, ''];
        return [substr($raw, 0, $pos), substr($raw, $pos + 2)];
    }

    // ヘッダーの折り返しを戻して、名前（小文字）→ 値の並び にする
    private static function parseHeaders(string $text): array
    {
        $out = [];
        $cur = null;
        foreach (explode("\n", $text) as $line) {
            if ($line === '') continue;
            if (($line[0] === ' ' || $line[0] === "\t") && $cur !== null) {
                $out[$cur][count($out[$cur]) - 1] .= ' '.trim($line);
                continue;
            }
            $p = strpos($line, ':');
            if ($p === false) {
                $cur = null;
                continue;
            }
            $cur = strtolower(trim(substr($line, 0, $p)));
            $out[$cur][] = trim(substr($line, $p + 1));
        }
        return $out;
    }

    public static function header(array $h, string $name): string
    {
        return (string) ($h[$name][0] ?? '');
    }

    // 「=?ISO-2022-JP?B?…?=」のような符号化された語を読む
    public static function decodeWords(string $s): string
    {
        if (!str_contains($s, '=?')) return $s;
        $s = preg_replace('/\?=\s+=\?/', '?==?', $s) ?? $s;
        return preg_replace_callback('/=\?([^?\s]+)\?([bBqQ])\?([^?]*)\?=/', function ($m) {
            $charset = preg_replace('/\*.*$/', '', $m[1]); // RFC2231 の言語の指定を外す
            $data = strtolower($m[2]) === 'b' ? base64_decode($m[3]) : quoted_printable_decode(str_replace('_', ' ', $m[3]));
            return self::toUtf8((string) $data, $charset);
        }, $s) ?? $s;
    }

    // 文字コードを UTF-8 にする。分からない・読めないときは、読める形に寄せる
    public static function toUtf8(string $s, ?string $charset): string
    {
        $cs = $charset ? strtolower(trim($charset)) : '';
        $enc = self::CHARSETS[$cs] ?? null;
        try {
            if ($enc === null && $cs !== '') {
                foreach (mb_list_encodings() as $name) {
                    if (strtolower($name) === $cs) {
                        $enc = $name;
                        break;
                    }
                }
            }
            if ($enc === null) {
                $enc = mb_detect_encoding($s, ['ASCII', 'ISO-2022-JP', 'UTF-8', 'SJIS-win', 'EUC-JP'], true) ?: 'UTF-8';
            }
            if ($enc === 'UTF-8') return mb_convert_encoding($s, 'UTF-8', 'UTF-8');
            return mb_convert_encoding($s, 'UTF-8', $enc);
        } catch (\Throwable $e) {
            return mb_convert_encoding($s, 'UTF-8', 'UTF-8');
        }
    }

    // 「type/subtype; a=b; c="d"」を [type, [a => b, …]] にする
    private static function params(string $value): array
    {
        $parts = [];
        $cur = '';
        $quote = false;
        $len = strlen($value);
        for ($i = 0; $i < $len; $i++) {
            $ch = $value[$i];
            if ($ch === '"') $quote = !$quote;
            if ($ch === ';' && !$quote) {
                $parts[] = $cur;
                $cur = '';
                continue;
            }
            $cur .= $ch;
        }
        $parts[] = $cur;
        $main = strtolower(trim((string) array_shift($parts)));
        $params = [];
        foreach ($parts as $p) {
            $e = strpos($p, '=');
            if ($e === false) continue;
            $k = strtolower(trim(substr($p, 0, $e)));
            $v = trim(substr($p, $e + 1));
            if (strlen($v) >= 2 && $v[0] === '"' && substr($v, -1) === '"') $v = stripslashes(substr($v, 1, -1));
            if (str_ends_with($k, '*')) { // name*=utf-8''%E3… の形
                $k = rtrim($k, '*');
                $v = preg_match("/^([^']*)'[^']*'(.*)$/s", $v, $m) ? self::toUtf8(rawurldecode($m[2]), $m[1]) : rawurldecode($v);
            } else {
                $v = self::decodeWords($v);
            }
            $params[$k] = $v;
        }
        return [$main, $params];
    }

    // 1つの部分（ヘッダー＋本体）を読む。本文は $acc に足し、添付は名前・種類・大きさだけを足す
    private static function entity(string $headText, string $body, int $depth, array &$acc): void
    {
        $h = self::parseHeaders($headText);
        [$type, $p] = self::params(self::header($h, 'content-type') ?: 'text/plain');
        [$dtype, $dp] = self::params(self::header($h, 'content-disposition'));
        $cte = strtolower(trim(self::header($h, 'content-transfer-encoding')));

        if (str_starts_with($type, 'multipart/') && !empty($p['boundary']) && $depth < self::MAX_DEPTH) {
            foreach (self::splitMultipart($body, $p['boundary']) as $part) {
                [$ph, $pb] = self::split($part);
                self::entity($ph, $pb, $depth + 1, $acc);
            }
            return;
        }
        $filename = $dp['filename'] ?? $p['name'] ?? null;
        $isText = in_array($type, ['text/plain', 'text/html'], true);
        if ($isText && $dtype !== 'attachment' && $filename === null) {
            $text = self::toUtf8(self::decodeBody($body, $cte), $p['charset'] ?? null);
            $text = str_replace("\r\n", "\n", $text);
            $acc[$type === 'text/html' ? 'html' : 'text'] .= ($acc[$type === 'text/html' ? 'html' : 'text'] !== '' ? "\n" : '').$text;
            return;
        }
        $size = $cte === 'base64'
            ? (int) floor(strlen(preg_replace('/\s+/', '', $body) ?? '') * 3 / 4)
            : strlen($body);
        $acc['files'][] = ['name' => $filename !== null && $filename !== '' ? $filename : '（名前なし）', 'type' => $type, 'size' => $size];
    }

    private static function decodeBody(string $body, string $cte): string
    {
        return match ($cte) {
            'base64' => (string) base64_decode(preg_replace('/\s+/', '', $body) ?? ''),
            'quoted-printable' => quoted_printable_decode($body),
            default => $body,
        };
    }

    private static function splitMultipart(string $body, string $boundary): array
    {
        $parts = explode('--'.$boundary, $body);
        array_shift($parts); // 最初の前置きは使わない
        $out = [];
        foreach ($parts as $p) {
            if (str_starts_with($p, '--')) break; // 終わりの印
            $nl = strpos($p, "\n");
            if ($nl === false) continue;
            $p = substr($p, $nl + 1);
            $out[] = str_ends_with($p, "\n") ? substr($p, 0, -1) : $p;
        }
        return $out;
    }

    // html だけのメールの本文を、タグを外した文にする
    private static function htmlToText(string $html): string
    {
        if (trim($html) === '') return '';
        $html = preg_replace('#<(script|style)\b.*?</\1>#is', '', $html) ?? $html;
        $html = preg_replace('#<br\s*/?>|</(p|div|tr|li|h[1-6])>#i', "\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace("/\n{3,}/", "\n\n", $text) ?? $text);
    }
}
