<?php

// PARK の版（全画面の足元に出す。Git のタグは全段階の完了後に付ける）
return [
    'version' => 'v0.9.1',
    // 見本データ（@park.example）宛てのメールを、代わりに届ける受け皿のアドレス。空にすると、見本宛ては本来のアドレスへ送ろうとする
    'dummy_mailbox' => env('PARK_DUMMY_MAILBOX', 'park@jona.jp'),
];
