# PARK

SES 企業どうしを1つのグループにまとめ、届いた案件に、グループ全体のエンジニアからいちばん合う人を出して、**エンジニアの成約率を上げる**ための Web アプリ。成約したときだけ成功報酬（初期費用・月額費用）をいただく仕組み。

構想・企画は [41_Park-Project](https://github.com/jonassan047/41_Park-Project)。2026-10-15 に、Node.js の試作（41_Park-Project の `81_PARKアプリ`）から PHP（Laravel 12）＋ MySQL（MariaDB）に移した。

## 動かし方（XAMPP）

- 場所：`D:\xampp\htdocs\park`、URL：http://localhost/park/
- DB：`jonassan_park`（MariaDB、utf8mb4）
- Apache：`httpd-vhosts.conf` に `Alias /park "D:/xampp/htdocs/park/public"` と、`public` の `<Directory>` に `RewriteOptions MergeBase`・`RewriteBase /park/`（ほかの Laravel アプリと同じ形）。

新しい PC で用意するとき：

```
composer install
copy .env.example .env
php artisan key:generate
php artisan migrate --seed
```

`--seed` を付けると、架空の見本データが入る（パスワードはどれも `park1234`）。

| 役割 | メールアドレス |
|---|---|
| 運営 | admin@park.example |
| SES | ses1@park.example（みどり情報システム）、ses2@、ses3@ |
| エンジニア | eg1@park.example（山田）、eg2@（佐藤）、eg3@（鈴木） |

本番のデータで始めるときは `php artisan migrate:fresh`（見本なし）にして、最初の画面で運営のアカウントを作る。

## できること

- **運営**：ダッシュボード（やること・面談の予定・終わりが近い契約・今月の成功報酬）、SES の承認・停止・担当者の招待、エンジニアの検索、案件の登録（**案件メールの本文から項目を自動で抜き出す**。外部には送らない）、**マッチング**（点数・合う点・足りない点）と配信（メール）、選考のボード、面談の設定と**面談対策の Q&A の自動作成**（直せる）、成約・延長・終了・成約後のフォロー、**請求書**（初期費用・月額費用・消費税、印刷・PDF）、メールの送信箱と一斉配信、分析（応募→成約のファネル、SES ごとの成約率、見送りの理由、延長率）、設定（率は仮）、操作の記録、「運営者」の印（運営がエンジニアとしても入るときの公平性のため）。
- **SES**：ダッシュボード、エンジニアの登録（ご本人に招待メール）、グループ全体の案件と**自社エンジニアとの相性**、本人への案内・代わりに応募、選考の状況、契約、請求書、会社情報。
- **エンジニア**：招待からパスワード設定（**個人情報の取り扱いに同意**）、届いた案件（相性を見て、**条件を確かめて応募**／理由を選んで見送り）、プロフィール・スキル・経験票、**スキルシート**（案件に合わせて並べ替え、名前をイニシャルで伏せる版、印刷・PDF）、面談対策の Q&A と練習メモ、配属後のひとこと、運営とのやり取り。
- 共通：通知（ベル）とメール、パスワードの変更・再設定。
- 同じ案件に、グループの中で同じ人が二重に応募することはできない。

## マニュアル

運営・SES 企業さま・エンジニアの3種類。正本は `resources/manuals/`（`admin.md`・`ses.md`・`engineer.md`）で、アプリの「使い方」（`/manual`）から読める。SES・エンジニア向けはログイン前でも読める。運営向けは運営だけ。画面や流れを変えたら、マニュアルも直す。

## ログイン（2要素認証）

全員、パスワードと認証アプリ（TOTP）の6桁のコードでログインする。初めてのログインで設定（QR コード）し、予備のコードが8個出る。スマホをなくした人は、運営が画面から2要素認証を解除して、設定し直してもらう。

## メールを実際に送るには

最初は `MAIL_MAILER=log` で「保存のみ」（運営の「メール」画面で中身を確認できる）。送るときは `.env` を設定する（`.env` は Git に上げない）。

```
MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=465
MAIL_SCHEME=smtps
MAIL_USERNAME=送信に使うアドレス
MAIL_PASSWORD=アプリ パスワード
MAIL_FROM_ADDRESS=送信に使うアドレス
MAIL_FROM_NAME="PARK 運営事務局"
```

設定したら `php artisan config:clear` をして、運営の「設定」→「テストメールを送る」で確かめる。

## テスト

```
php artisan test
```

テスト用の DB（SQLite のメモリ）に見本データを入れ、3つの役割で主な画面と操作（申込・承認・取り込み・配信・応募・二重応募の拒否・面談・成約・請求・一斉配信・招待と同意・権限）をたどる。

## つくり

| 場所 | 中身 |
|---|---|
| `app/Services/Workflow.php` | 業務の手続き（招待・登録・配信・応募・選考・成約・延長） |
| `app/Services/Matcher.php` | マッチングの点数 |
| `app/Services/Qa.php` | 面談対策の Q&A、スキルシートの完成度 |
| `app/Services/CaseParser.php` | 案件メールの取り込み |
| `app/Services/Billing.php` | 成功報酬の計算 |
| `app/Services/Notifier.php` | メールの送信箱・通知・操作の記録 |
| `app/Support/Perms.php` | 誰が何を見られるか |
| `app/helpers.php` | 表示用の言葉・金額・日付・スキル名の正規化 |
| `app/Http/Controllers/` | 画面の処理（Public・Account・Case・Engineer・Pipeline・Admin・Ses・Eg） |
| `resources/views/` | 画面（Blade） |
| `database/migrations/2026_10_15_000001_create_park_tables.php` | テーブル |

## まだやっていないこと

- インターネットへの公開（tezzo が「公開する」と決めた。2026-10-15）。サーバー・HTTPS・バックアップ・送信用のメールアドレスを用意してから。
- 案件メールの自動受信（いまは本文を貼り付ける）。
- AI による Q&A・スキルシートの下書き（個人情報・案件を外に出さない約束との兼ね合いを決めてから）。
- 誓約書・利用規約の画面、ファイルの添付。
