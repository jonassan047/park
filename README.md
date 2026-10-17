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
| 管理者 | admin@park.example |
| SES | ses1@park.example（みどり情報システム）、ses2@、ses3@ |
| エンジニア | eg1@park.example（山田）、eg2@（佐藤）、eg3@（鈴木） |

本番のデータで始めるときは `php artisan migrate:fresh`（見本なし）にして、最初の画面で管理者のアカウントを作る。

## できること

- **管理者**：ダッシュボード（やること・面談の予定・終わりが近い契約・今月の成功報酬）、SES の承認・停止・担当者の招待、エンジニアの検索、案件の登録（**案件メールの本文から項目を自動で抜き出す**。外部には送らない）、**マッチング**（点数・合う点・足りない点）と配信（メール）、選考のボード、面談の設定と**面談対策の Q&A の自動作成**（直せる）、成約・延長・終了・成約後のフォロー、**請求書**（初期費用・月額費用・消費税、印刷・PDF）、メールの送信箱と一斉配信、分析（応募→成約のファネル、SES ごとの成約率、見送りの理由、延長率）、設定（率は仮）、操作の記録、「管理者」の印（管理者がエンジニアとしても入るときの公平性のため）。
- **SES**：ダッシュボード、エンジニアの登録（ご本人に招待メール）、グループ全体の案件と**自社エンジニアとの相性**、本人への案内・代わりに応募、選考の状況、契約、請求書、会社情報。
- **エンジニア**：招待からパスワード設定（**個人情報の取り扱いに同意**）、届いた案件（相性を見て、**条件を確かめて応募**／理由を選んで見送り）、プロフィール・スキル・経験票、**スキルシート**（案件に合わせて並べ替え、名前をイニシャルで伏せる版、印刷・PDF）、面談対策の Q&A と練習メモ、配属後のひとこと、管理者とのやり取り。
- 共通：通知（ベル）とメール、パスワードの変更・再設定。
- 同じ案件に、グループの中で同じ人が二重に応募することはできない。

## 公開サーバー（https://www.jona.jp/park/）

2026-10-16 から公開。Xserver（jona.jp）に、muzzo と同じ形で置いている。

- アプリ本体：`~/park`（GitHub から clone）。`~/jona.jp/public_html/park` は `~/park/public` へのシンボリックリンク（設定ファイルなどは外から見えない）。
- PHP：サイトは 8.3。コマンドは `/opt/php-8.3/bin/php` を使う（`php` だけだと 8.0 になる）。
- DB：`jonassan_park`（サーバーパネルで作成。ユーザーは muzzo と同じ）。見本データは入れていない。
- `.env`：本番用（`APP_DEBUG=false`、`APP_URL=https://www.jona.jp/park`、メールは `sendmail` で送信元 park@jona.jp。2026-10-16 から実際に送っている）。Git には上げない。
- **ルートのキャッシュ（`route:cache`）は使わない**（使うとトップの `/park/` が 405 になる）。
- 最初の管理者アカウントは、画面の「はじめの設定」ではなく、サーバーで `park:invite-admin` で作る（公開中に他人に先に使われないように）。

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

管理者を増やすとき：`/opt/php-8.3/bin/php artisan park:invite-admin メールアドレス 名前`（招待のリンクが出る）。

## エンジニアの細かい登録・技術マスタ・データ領域（2026-10-16）

- エンジニアには、履歴書・業務経歴書の項目（生年月日・住所・電話・最寄りの路線と徒歩・最終学歴・職務概要・希望業務・語学・学歴/職歴・資格・アピール・本人希望）と、経歴の案件ごとの「機種・OS・ツール」「言語」「DB」を持たせる。
- **技術マスタ**（`skill_terms`）：案件とスキルを同じ「正式な名前」にそろえる辞書（分類・別名）。最初の辞書は `app/Support/SkillCatalog.php`、DB へは `php artisan park:skills-sync`。案件・スキルにマスタに無い言葉が出たら自動で足し「確認待ち」にする（管理者の「技術マスタ」画面で確かめる）。
- **データ領域**：エンジニアごとに、最新の履歴書・業務経歴書（名前を伏せた版も）を Excel で書き出して保存・ダウンロードできる。アップロードもできる。置き場所は `storage/app/private/engineers/{id}/`（公開されない・Git に上げない）。見られるのは本人・所属 SES・管理者だけ。
- Excel の書き出しに PhpSpreadsheet を使う。PHP の `gd`・`zip` が要る（XAMPP は php.ini で有効にした）。

## デザイン

緑系・丸いフォント（M PLUS Rounded 1c、Google Fonts）の公園風。見本は 41_Park-Project の `99_Data/index.html`（2026-10-16 に適用）。トップの写真（`public/img/`）は Unsplash のフリー画像を取り込んだもの（外部からは読み込まない）。

## マニュアル

管理者・SES 企業さま・エンジニアの3種類。正本は `resources/manuals/`（`admin.md`・`ses.md`・`engineer.md`）で、アプリの「使い方」（`/manual`）から読める。SES・エンジニア向けはログイン前でも読める。管理者向けは管理者だけ。画面や流れを変えたら、マニュアルも直す。マニュアルとこの README の写しを、41_Park-Project の `04_Manuals` に置いている（WIZ から読むため）。直したら写し直す。

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

7. 書類を返したら「書類を返した（応募した）」で応募を作る → 面談依頼が来たら SES が記録（日時・場所・先方が確かめたいこと）→ 案件とスキルを突き合わせた面談スクリプトを作る（`Qa::generate`）→ 結果 → 成約。SES は自社の応募について、管理者と同じ手続きができる

8. 案件の画面の案内の入口は「エンジニアに案内する」だけ（2026-10-16 に「本人に案内」「応募を出す」「管理者の配信」をやめた）。持ち込んでいない SES は「推薦する」（名前を伏せて知らせる）
9. 案内メールの最後に返事のリンク（`/r/{token}/{answer}`）。開くだけでは記録せず（メールソフトが先に開くことがあるため）、画面のボタンで記録して送った人に知らせる

## 今やること（2026-10-16）

ホームのいちばん上に、立場ごとの「今やること」を出す（`app/Services/Todo.php`）。

## 状態の点検（2026-10-16）

`php artisan park:status` で、メール・AI の仕事・選考・確認待ちなどを数で出す（個人情報は出さない）。PARK 担当のエージェント（41_Park-Project の `.claude/agents/park-manager.md`）が点検に使う。公開サーバーでは `/opt/php-8.3/bin/php artisan park:status`。
`php artisan park:twofactor {メールアドレス}` で、その人の確認コードまわり（送った記録・失敗の理由・セッションの設定）を見られる（見るだけ。メールの件名・本文は出さない）。

## AI の仕事の箱（2026-10-16）

AI は手元の PC（FOCUS）で動かす。公開サーバー（Xserver）では AI を動かせないため。案件や個人の情報は、手元の PC の外に出さない。

- PARK は仕事（指示と材料）を `ai_jobs` に入れるだけ（`app/Services/AiJobs.php`）。手元の PC の `tools/ai-worker/park_ai_worker.py` が `/ai/worker/next` に取りに来て、Ollama（手元の AI）に渡し、`/ai/worker/{id}/result` に返す。合言葉は `.env` の `PARK_AI_TOKEN`（同じものを手元の PC にも設定する）。
- 仕事は2つ：面談スクリプトの言い回し（`script`）、経歴書（Excel・Word・テキスト）から経験票の下書き（`resume`）。
- 結果は下書き。人が「採用する」「経験票に足す」を押したときだけ使う。JSON で返らなかったものは「失敗」。30分返ってこない仕事は順番待ちに戻す。
- 手元の PC で動かす：

```
winget install Ollama.Ollama
ollama pull qwen2.5:7b
set PARK_URL=https://www.jona.jp/park
set PARK_AI_TOKEN=（.env と同じ合言葉）
python tools/ai-worker/park_ai_worker.py
```

FOCUS では、Windows の起動で自動で動く（2026-10-16〜）。Ollama は自分のショートカットで、つなぎ役は「スタートアップ」の「PARK AI つなぎ役」（`pythonw.exe -I park_ai_worker.py --log`。窓は出ない）。公開版の URL と合言葉は `~/.park-ai/config.json`（この PC にだけ置く。Git には上げない）。記録は `~/.park-ai/worker.log`。止めるときは、タスクマネージャーで pythonw.exe を終わらせる。

まだやっていないこと：返信を PARK が受け取って AI に渡すこと（受信用のメールボックスと、手元の AI が要る）。

## 登録と、内容の責任（2026-10-16 tezzo）

- 立場は ADM（管理者。今は2人）・SES・EG（エンジニア）。登録できるのは上の立場の人だけ：管理者が管理者・SES を、SES（と管理者）がエンジニアを登録する。
- 上の立場の人が入れた情報も、本人が責任を持つ。本人が「確認しました」を押すまで `self_confirmed_at` は空で、エンジニアは応募できない。管理者・SES が書き換えると空に戻る（`engineers`・`ses_companies`）。
- 最初の情報登録（2026-10-17）：`users` に `kana`・`phone`・`position`・`profile_completed_at` を足した。`profile_completed_at` が空の人は、2要素認証のあと、`Role` ミドルウェアが `/welcome`（あなたの情報）へ回す（`/account`・ログアウト・使い方は開ける）。ログイン中の画面の上に、立場の帯（管理者／SES／エンジニア）を出す。管理者は `/admin/settings` で管理者を招待・送り直しでき、管理者が登録した SES はすぐ参加中になる。
- 管理者の直属のエンジニア（2026-10-17）：`ses_companies.kind`（`ses`／`direct`）。`direct` の行（`W::directSesId()` が必要なときに1つ作る）は SES として扱わず、一覧・数・請求・SES ログインから外す。直属の知らせ（`N::toSes`）は管理者全員に届く。直属の行の名前は「管理者の直属」。
- エンジニアは案件ごとに経歴を更新する。成約するとホームに「経歴の更新」が出て、書くと `contracts.history_updated_at` が入る。契約を終了にすると本人に知らせ、終了日より前に書いたものは書き直してもらう。

**メールを実際に送れないと、誰もログインできない**（下の「メールを実際に送るには」）。手元（`APP_ENV=local` でメールは保存のみ）のときだけ、確認コードを画面に出す。users の `two_factor_*` の列は、認証アプリのころの名残（使っていない）。

## メールを実際に送るには

最初は `MAIL_MAILER=log` で「保存のみ」（管理者の「メール」画面で中身を確認できる）。送るときは `.env` を設定する（`.env` は Git に上げない）。

```
MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=465
MAIL_SCHEME=smtps
MAIL_USERNAME=送信に使うアドレス
MAIL_PASSWORD=アプリ パスワード
MAIL_FROM_ADDRESS=送信に使うアドレス
MAIL_FROM_NAME="PARK 管理者"
```

設定したら `php artisan config:clear` をして、管理者の「設定」→「テストメールを送る」で確かめる。

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
