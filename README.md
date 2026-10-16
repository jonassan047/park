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

## 公開サーバー（https://www.jona.jp/park/）

2026-10-16 から公開。Xserver（jona.jp）に、muzzo と同じ形で置いている。

- アプリ本体：`~/park`（GitHub から clone）。`~/jona.jp/public_html/park` は `~/park/public` へのシンボリックリンク（設定ファイルなどは外から見えない）。
- PHP：サイトは 8.3。コマンドは `/opt/php-8.3/bin/php` を使う（`php` だけだと 8.0 になる）。
- DB：`jonassan_park`（サーバーパネルで作成。ユーザーは muzzo と同じ）。見本データは入れていない。
- `.env`：本番用（`APP_DEBUG=false`、`APP_URL=https://www.jona.jp/park`、メールは `log`）。Git には上げない。
- **ルートのキャッシュ（`route:cache`）は使わない**（使うとトップの `/park/` が 405 になる）。
- 最初の運営アカウントは、画面の「はじめの設定」ではなく、サーバーで `park:invite-admin` で作る（公開中に他人に先に使われないように）。

更新するとき（この PC で commit・push したあと、サーバーで）：

```
ssh -p 10022 jonassan@jonassan.xsrv.jp
cd ~/park
git pull --ff-only
/opt/php-8.3/bin/php /usr/bin/composer install --no-dev --optimize-autoloader
/opt/php-8.3/bin/php artisan migrate --force
/opt/php-8.3/bin/php artisan park:skills-sync
/opt/php-8.3/bin/php artisan config:cache
/opt/php-8.3/bin/php artisan view:cache
```

運営を増やすとき：`/opt/php-8.3/bin/php artisan park:invite-admin メールアドレス 名前`（招待のリンクが出る）。

## エンジニアの細かい登録・技術マスタ・データ領域（2026-10-16）

- エンジニアには、履歴書・業務経歴書の項目（生年月日・住所・電話・最寄りの路線と徒歩・最終学歴・職務概要・希望業務・語学・学歴/職歴・資格・アピール・本人希望）と、経歴の案件ごとの「機種・OS・ツール」「言語」「DB」を持たせる。
- **技術マスタ**（`skill_terms`）：案件とスキルを同じ「正式な名前」にそろえる辞書（分類・別名）。最初の辞書は `app/Support/SkillCatalog.php`、DB へは `php artisan park:skills-sync`。案件・スキルにマスタに無い言葉が出たら自動で足し「確認待ち」にする（運営の「技術マスタ」画面で確かめる）。
- **データ領域**：エンジニアごとに、最新の履歴書・業務経歴書（名前を伏せた版も）を Excel で書き出して保存・ダウンロードできる。アップロードもできる。置き場所は `storage/app/private/engineers/{id}/`（公開されない・Git に上げない）。見られるのは本人・所属 SES・運営だけ。
- Excel の書き出しに PhpSpreadsheet を使う。PHP の `gd`・`zip` が要る（XAMPP は php.ini で有効にした）。

## デザイン

緑系・丸いフォント（M PLUS Rounded 1c、Google Fonts）の公園風。見本は 41_Park-Project の `99_Data/index.html`（2026-10-16 に適用）。トップの写真（`public/img/`）は Unsplash のフリー画像を取り込んだもの（外部からは読み込まない）。

## マニュアル

運営・SES 企業さま・エンジニアの3種類。正本は `resources/manuals/`（`admin.md`・`ses.md`・`engineer.md`）で、アプリの「使い方」（`/manual`）から読める。SES・エンジニア向けはログイン前でも読める。運営向けは運営だけ。画面や流れを変えたら、マニュアルも直す。マニュアルとこの README の写しを、41_Park-Project の `04_Manual` に置いている（WIZ から読むため）。直したら写し直す。

## ログイン（2要素認証）

全員、パスワードと、メールで届く6桁の確認コードでログインする（2026-10-16 に認証アプリ（TOTP・QR コード）からメールに変えた。携帯で QR コードを読めないため）。コードはセッションにだけハッシュで置き、10分で切れ、5回まちがえると送り直し（送り直しは1分あける）。招待・パスワード再設定のリンクでパスワードを決めたあとも、確認コードを聞く（2026-10-16 tezzo：初めてのログインも、メールアドレスとパスワードと確認コードで通る）。

## 案件の案内メール（2026-10-16 tezzo）

SES が受けた案件メールから、エンジニアへの案内メールを出し、返事を受けて、案件をくれた先へ書類を返すまでの流れ（`app/Services/CaseMail.php`・`CaseMailController`）。

1. SES が案件を登録（案件メールの本文を貼って取り込む）
2. 候補を絞る：得意分野（スキル・経験票）から点数順に提案し、SES がチェックで足す・外す。自社のエンジニア（名前あり）と、グループのほかの人（名前を伏せる。プラスα）で画面を分ける
3. メールを作る：同じ内容／一人ずつ変える。差し込み（{呼び名} など）。呼び名は `engineers.nickname`（愛称）
4. 送る前に提案を出す（点数・足りないスキル・単価や開始日のずれ・過去の見送り）→ 送る。自社の人へは返信先（Reply-To）を SES の担当者にする。グループの人へは PARK から送り、その人の SES にも知らせる
5. 返事：自社の人は SES に直接返信が届き、SES が画面で記録する。本人が PARK の画面で応募・見送りをしたら自動で記録（`case_mails`）
6. 書類：「応募したい」の人の最新の履歴書・業務経歴書を、その場で Excel にしてダウンロード（グループの人は名前を伏せた業務経歴書だけ）

まだやっていないこと：返信を PARK が受け取って AI に渡すこと（受信用のメールボックスと、手元の AI が要る）。

## 登録と、内容の責任（2026-10-16 tezzo）

- 立場は ADM（運営。今は2人）・SES・EG（エンジニア）。登録できるのは上の立場の人だけ：運営が運営・SES を、SES（と運営）がエンジニアを登録する。
- 上の立場の人が入れた情報も、本人が責任を持つ。本人が「確認しました」を押すまで `self_confirmed_at` は空で、エンジニアは応募できない。運営・SES が書き換えると空に戻る（`engineers`・`ses_companies`）。
- エンジニアは案件ごとに経歴を更新する。成約するとホームに「経歴の更新」が出て、書くと `contracts.history_updated_at` が入る。契約を終了にすると本人に知らせ、終了日より前に書いたものは書き直してもらう。

**メールを実際に送れないと、誰もログインできない**（下の「メールを実際に送るには」）。手元（`APP_ENV=local` でメールは保存のみ）のときだけ、確認コードを画面に出す。users の `two_factor_*` の列は、認証アプリのころの名残（使っていない）。

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
