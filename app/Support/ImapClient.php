<?php

namespace App\Support;

use App\Contracts\MailSource;
use App\Services\Inbound;

// IMAP で受信箱を読む、最小の道具（v0.12.0）。外のライブラリや ext-imap は使わない。
// 使う命令は LOGIN・EXAMINE（読むだけで開く）・UID SEARCH・UID FETCH（BODY.PEEK＝既読にしない）・LOGOUT だけ。
// 消す・移す・旗を変える命令は、この中のどこにも無い。つなぐのは、メールを読むときだけ（テストでは偽物に差し替える）。
class ImapClient implements MailSource
{
    const TIMEOUT = 20;     // つなぐ・読むときの待つ秒数

    private $fp = null;
    private int $tagNo = 0;
    private ?int $validity = null;
    private ?int $uidnext = null;

    public function __construct(private array $cfg)
    {
    }

    public function __destruct()
    {
        $this->close();
    }

    public static function fromConfig(): self
    {
        return new self((array) config('park.inbound'));
    }

    public function uidvalidity(): int
    {
        $this->open();
        return (int) $this->validity;
    }

    public function lastUid(): int
    {
        $this->open();
        return max(0, (int) $this->uidnext - 1);
    }

    public function fetchSince(int $uid, int $limit): iterable
    {
        $this->open();
        try {
            $r = $this->command('UID SEARCH UID '.($uid + 1).':*');
            $uids = [];
            foreach ($r['lines'] as $l) {
                if (preg_match('/^\* SEARCH(.*)$/', $l['text'], $m)) {
                    foreach (preg_split('/\s+/', trim($m[1])) as $n) if ($n !== '' && (int) $n > $uid) $uids[] = (int) $n; // n:* は、範囲の外でも最後の 1 通を返すので、除く
                }
            }
            sort($uids);
            foreach (array_slice($uids, 0, $limit) as $u) {
                $raw = $this->fetchOne($u);
                if ($raw === '') continue; // 空の返事（読めなかった）は、空の記録を作らないよう飛ばす
                yield [$u, $raw];
            }
        } finally {
            $this->close();
        }
    }

    // 1通を読む。大きすぎるものは、ヘッダーだけ読む
    private function fetchOne(int $uid): string
    {
        $size = 0;
        foreach ($this->command("UID FETCH $uid (RFC822.SIZE)")['lines'] as $l) {
            if (preg_match('/RFC822\.SIZE (\d+)/', $l['text'], $m)) $size = (int) $m[1];
        }
        if ($size > Inbound::MAX_RAW_BYTES) {
            $head = $this->literal($this->command("UID FETCH $uid (BODY.PEEK[HEADER])"));
            $head = preg_replace('/^(Content-Type|Content-Transfer-Encoding|MIME-Version):[^\r\n]*(\r?\n[ \t][^\r\n]*)*\r?\n/mi', '', $head) ?? $head;
            return rtrim($head)."\r\n\r\n（大きすぎるため、本文は取り込んでいません。元のメールは受信箱にあります。）\r\n";
        }
        return $this->literal($this->command("UID FETCH $uid (BODY.PEEK[])"));
    }

    private function literal(array $r): string
    {
        foreach ($r['lines'] as $l) if ($l['literal'] !== null) return $l['literal'];
        return '';
    }

    // ---------- つなぐ ----------

    private function open(): void
    {
        if ($this->fp) return;
        $host = (string) ($this->cfg['host'] ?? '');
        $port = (int) ($this->cfg['port'] ?? 993);
        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $fp = @stream_socket_client('ssl://'.$host.':'.$port, $errno, $errstr, self::TIMEOUT, STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) throw new \RuntimeException("受信サーバーにつながりません（{$errno}）");
        stream_set_timeout($fp, self::TIMEOUT);
        $this->fp = $fp;
        if (!str_starts_with($this->line(), '* OK')) throw new \RuntimeException('受信サーバーの応答が正しくありません');
        $r = $this->command('LOGIN '.$this->quote((string) ($this->cfg['user'] ?? '')).' '.$this->quote((string) ($this->cfg['password'] ?? '')), false);
        if ($r['status'] !== 'OK') throw new \RuntimeException('受信サーバーにログインできませんでした');
        $r = $this->command('EXAMINE '.$this->quote((string) ($this->cfg['mailbox'] ?? 'INBOX')), false);
        if ($r['status'] !== 'OK') throw new \RuntimeException('受信箱を開けませんでした');
        foreach ($r['lines'] as $l) {
            if (preg_match('/\[UIDVALIDITY (\d+)\]/', $l['text'], $m)) $this->validity = (int) $m[1];
            if (preg_match('/\[UIDNEXT (\d+)\]/', $l['text'], $m)) $this->uidnext = (int) $m[1];
        }
        // Courier-IMAP（Xserver）は、開いたときの応答に UIDNEXT を含めない。足りなければ STATUS で聞く
        if ($this->validity === null || $this->uidnext === null) {
            $r = $this->command('STATUS '.$this->quote((string) ($this->cfg['mailbox'] ?? 'INBOX')).' (UIDNEXT UIDVALIDITY)', false);
            foreach ($r['lines'] as $l) {
                if (preg_match('/UIDVALIDITY (\d+)/', $l['text'], $m)) $this->validity = (int) $m[1];
                if (preg_match('/UIDNEXT (\d+)/', $l['text'], $m)) $this->uidnext = (int) $m[1];
            }
        }
        if ($this->validity === null || $this->uidnext === null) throw new \RuntimeException('受信箱の情報を読めませんでした');
    }

    private function close(): void
    {
        if (!$this->fp) return;
        try {
            $this->command('LOGOUT', false);
        } catch (\Throwable $e) {
            // 閉じるだけなので、失敗しても気にしない
        }
        @fclose($this->fp);
        $this->fp = null;
    }

    private function quote(string $s): string
    {
        return '"'.addcslashes($s, "\"\\").'"';
    }

    // ---------- 読み書き ----------

    private function line(): string
    {
        $s = fgets($this->fp);
        if ($s === false) throw new \RuntimeException('受信サーバーの応答がありません（時間切れ）');
        return rtrim($s, "\r\n");
    }

    private function bytes(int $n): string
    {
        $buf = '';
        while (strlen($buf) < $n) {
            $chunk = fread($this->fp, $n - strlen($buf));
            if ($chunk === false || $chunk === '') throw new \RuntimeException('受信サーバーの応答がありません（時間切れ）');
            $buf .= $chunk;
        }
        return $buf;
    }

    // 命令を 1 つ送って、その終わり（同じ印の行）までを読む。戻り：['status' => OK|NO|BAD, 'lines' => [['text' => 行, 'literal' => 本体 or null], …]]
    // $failOnNo が true なら、OK 以外は例外にする
    private function command(string $cmd, bool $failOnNo = true): array
    {
        $tag = 'P'.str_pad((string) ++$this->tagNo, 3, '0', STR_PAD_LEFT);
        fwrite($this->fp, "$tag $cmd\r\n");
        $lines = [];
        while (true) {
            $text = $this->line();
            if (str_starts_with($text, $tag.' ')) {
                $status = strtoupper(explode(' ', $text)[1] ?? 'BAD');
                if ($failOnNo && $status !== 'OK') throw new \RuntimeException('受信サーバーが命令を受け付けませんでした');
                return ['status' => $status, 'lines' => $lines];
            }
            $literal = null;
            // 行の終わりが {n} なら、そのあとに n バイトの本体が続く。本体のあとの残りの行も、同じ応答の続き
            while (preg_match('/\{(\d+)\}$/', $text, $m)) {
                $literal = $this->bytes((int) $m[1]);
                $text .= ' '.$this->line();
            }
            $lines[] = ['text' => $text, 'literal' => $literal];
        }
    }
}
