<?php

namespace App\Contracts;

// 受信箱から、メールを順に読み出す元（v0.12.0）。本物は ImapClient、テストは配列を返す偽物に差し替える。
// 読むだけ。消す・移す・既読にする・旗を変える操作は持たない。
interface MailSource
{
    // 受信箱の UIDVALIDITY（これが変わったら、UID を最初から数え直す）
    public function uidvalidity(): int;

    // 受信箱にある一番新しいメールの UID（無ければ 0）
    public function lastUid(): int;

    // UID が $uid より大きいメールを、UID の小さい順に最大 $limit 通。1通は [UID, 元のメールの文]
    public function fetchSince(int $uid, int $limit): iterable;
}
