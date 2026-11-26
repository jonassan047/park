<?php

// PARK の版（全画面の足元に出す。Git のタグは全段階の完了後に付ける）
return [
    'version' => 'v0.21.0',
    // 見本データ（@park.example）宛てのメールを、代わりに届ける受け皿のアドレス。空にすると、見本宛ては本来のアドレスへ送ろうとする
    'dummy_mailbox' => env('PARK_DUMMY_MAILBOX', 'park@jona.jp'),
    // 案内メールへの返信を受け取る設定（v0.12.0）。host・user・password が全部そろうまでは、受信は何もしない
    'inbound' => [
        'host' => env('PARK_IMAP_HOST'),
        'port' => (int) env('PARK_IMAP_PORT', 993),
        'user' => env('PARK_IMAP_USER'),
        'password' => env('PARK_IMAP_PASSWORD'),
        'mailbox' => env('PARK_IMAP_MAILBOX', 'INBOX'),
        'reply_base' => env('PARK_REPLY_BASE'), // 空なら MAIL_FROM_ADDRESS
    ],
    // 案件の画面の「AI の読み直し」の欄を「（現在工事中）」のタイトル行だけにする（2026-11-26 TEZZO）。戻すときは false
    'case_ai_wip' => env('PARK_CASE_AI_WIP', true),
];
