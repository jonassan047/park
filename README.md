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

`--seed` で入るのは、技術マスタ（辞書）だけ。見本データは入らない。

架空の見本データを入れたいときは、手元でだけ次を動かす（パスワードはどれも `park1234`。公開版では何も入らない）。

```
php artisan db:seed --class=SampleSeeder
```

| 役割 | メールアドレス |
|---|---|
| 管理者 | admin@park.example |
| SES | ses1@park.example（みどり情報システム）、ses2@、ses3@ |
| エンジニア | eg1@park.example（山田）、eg2@（佐藤）、eg3@（鈴木） |

本番のデータで始めるときは `php artisan migrate:fresh --seed`（見本なし。**最初の1回だけ、データが無いときだけ使う。実在のデータが全部消える**）にして、最初の画面で管理者のアカウントを作る。管理者のダッシュボードは「PARK をはじめましょう」から始まり、SES・エンジニア・案件を実際に登録していく。

テスト用のダミーデータを入れるコマンド：`php artisan park:seed-dummy`（`SampleSeeder` とは別で、公開版でも動く）。オプションは `--ses`（SES の社数。既定 8）・`--staff`（担当者の人数。既定 15）・`--eg`（エンジニアの人数。既定 80）・`--direct`（うち管理者の直属の人数。既定はエンジニアの1割）・`--seed`（同じ値なら同じ名前・状態になる。無ければ乱数で決めて出力する）・`--yes`。`--yes` なしは予定の件数を出すだけで、何も変えない（名前・メールアドレスは出さない）。`--yes` で、1つの transaction で入れる（途中で失敗したら何も残らない）。メールは一切送らず、管理者のベル通知も増やさない。SES は申込中・参加中・停止、担当者とエンジニアは 参加中（本登録済み）・参加中（必須項目が足りない）・仮登録・仮登録（期限切れ）・停止中 が混ざる（SES の1社は仮登録で、SES だけ）。SES の会社名の末尾には「（ダミー）」が付く。メールアドレスは `ses-d01@park.example`・`eg-d001@park.example` の形で、番号は続きから振る（二重に実行しても重ならない）。ダミーのアカウントのパスワードは乱数で誰も知らないので、ログインはできない。消すときは `park:purge-samples`。ダミーの SES に本物の人（`@park.example` ではない人）を登録したり、ダミーのエンジニアで案件・応募を作ったりすると、purge は守りで止まる（`--move-kept-to-direct` か、管理者に確かめる）。ダミーがいる間は、一斉メールを使わない・案件の配信でダミーのエンジニア・SES を選ばない（応募まで進むと purge が止まる）。

見本データを消すコマンド：`php artisan park:purge-samples`。まず `--yes` なしで動かすと、表ごとの「消す件数」と「残す件数」と守りの判定が出る（何も変えない。名前・メールアドレスは出さない）。`--yes` を付けると、消す行を `storage/app/backups/purge-samples-日時.json` に書き出してから、1つの transaction で消す。見本の人（メールアドレスが `@park.example` の人）以外に使える管理者がいない、見本の SES に見本ではない人が入っている（`--move-kept-to-direct` を付けたときだけ管理者の直属へ移して続ける）、見本ではない案件に見本のエンジニアの応募・契約がある、のどれかのときは、何も消さずに止まる。消すものが0件なら何も変えない。

氏名とふりがなは、姓と名に分けて持つ（`name_sei`・`name_mei`・`kana_sei`・`kana_mei`）。`name`・`kana` は、姓＋名をつないだもので、画面の表示・メールの宛名・検索はこちらを読む。登録済みの人は、マイグレーションで空白（半角・全角）を区切りに分けて入れる。自分の登録情報は、アカウントの「登録情報を見る」（`/account/profile`）で見られる。

## できること

- **管理者**：ダッシュボード（やること・面談の予定・終わりが近い契約・今月の成功報酬）、管理者の一覧・仮登録（企業名・氏名・メールの4つ。別画面）・詳細・編集・送り直し・取り消し（上のメニューの「管理者」）、SES の仮登録・承認・送り直し・取り消し・停止、担当者・エンジニアの仮登録、エンジニアの検索、案件の登録（**案件メールの本文から項目を自動で抜き出す**。外部には送らない）、**マッチング**（点数・合う点・足りない点）と配信（メール）、選考のボード、面談の設定と**面談対策の Q&A の自動作成**（直せる）、成約・延長・終了・成約後のフォロー、**請求書**（初期費用・月額費用・消費税、印刷・PDF）、メールの送信箱と一斉配信、分析（応募→成約のファネル、SES ごとの成約率、見送りの理由、延長率）、設定（率は仮）、操作の記録、「管理者」の印（管理者がエンジニアとしても入るときの公平性のため）。
- **SES**：ダッシュボード、担当者・エンジニアの仮登録（ご本人に仮登録のご案内。担当者の登録は SES だけ）、グループ全体の案件と**自社エンジニアとの相性**、本人への案内・代わりに応募、選考の状況、契約、請求書、会社情報。
- **エンジニア**：仮登録のご案内から本登録（ふりがな・電話・住所・パスワード・**個人情報の取り扱いに同意**）、届いた案件（相性を見て、**条件を確かめて応募**／理由を選んで見送り）、プロフィール・スキル（**スキル画面で案件に合うスキルにチェック**）・経験票、**スキルシート**（案件に合わせて並べ替え、名前をイニシャルで伏せる版、印刷・PDF）、面談対策の Q&A と練習メモ、配属後のひとこと、管理者とのやり取り。
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

管理者を仮登録するとき（サーバーの中から）：`/opt/php-8.3/bin/php artisan park:invite-admin メールアドレス 姓 名 --company=企業名`（`--company` は省略できる。本登録のリンクが出る。メールは送らない。ご本人は、ほかの管理者が仮登録した人と同じ本登録の画面で、企業名・お名前・電話・パスワードを入れる）。

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

全員、パスワードと、メールで届く6桁の確認コードでログインする（2026-10-16 に認証アプリ（TOTP・QR コード）からメールに変えた。携帯で QR コードを読めないため）。コードはセッションにだけハッシュで置き、10分で切れ、5回まちがえると送り直し（送り直しは1分あける）。本登録のリンクの入力をしたあとも、パスワード再設定のリンクでパスワードを決めたあとも、確認コードを聞く（本登録は、確認コードを入れたときに完了する）（2026-10-16 tezzo：初めてのログインも、メールアドレスとパスワードと確認コードで通る）。

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

## EG のスキル登録（v0.3.0。2026-11-03）

- スキル表（`engineer_skills`）に、最後に使った年月（`last_used_ym`）・得意（`is_primary`、5つまで）・ひとこと（`note`、200文字まで）を足した。レベルの文は `SkillTable::LEVELS` が正本（画面とマニュアルの文）。保存は `SkillTable::save`（検査を先に全部行い、transaction の中で書く。全部消して入れ直さず、残す行の id は変わらない）。
- 経歴の技術は、元の文字列に加えて、経歴ごとの索引 `engineer_project_skills`（技術マスタの言葉だけ）を持つ。作るのは `ProjectSkills::rebuild` の1つだけ（経歴の保存・AI の下書きの取り込み・移し替えが通る）。月数は保存せず、見るたびに数える（重なった月は1回）。
- 技術マスタの「統合」は `SkillTable::mergeTerms`（1つの transaction。索引・スキル表・別名を付け替えてから、マスタの言葉を消す）。「使っている人」は `SkillTable::usedCounts`（スキル表と経歴の両方）。管理者が言葉を追加・確認済みにしたら、`ProjectSkills::rebuildAll` で索引を作り直す。
- マッチングは `Matcher::skillIndexMany` で、人数によらず問い合わせ3回でスキルを読む（点数の計算は変えていない）。
- 見本データを消す処理（`park:purge-samples`）は、`engineer_project_skills` も消してバックアップに入れる。
- スキル画面の「案件に合わせてチェック」（2026-11-05。DB は変えない）：EG 本人だけに出る。`?case=` で案件を選ぶと、`Matcher::rowMarks` が行ごとに必須／尚可の印を出し、画面が checked にする（チェックには name が無く、保存されない）。一致の決まりは `Matcher::sameSkill`（`hasSkill` と共通）。選べる案件は、案内が届いた案件・応募した案件（下書きを除く。`Perms::caseView` の範囲）。募集中の全件には広げていない。画面に出すのは案件名と状態とスキル名だけ。

## 仮登録 → 本登録（v0.2.0。2026-10-31。段階 1：管理者 → SES、段階 2：SES・管理者 → 担当者・エンジニア）

- プレーヤーは 管理者・SES・担当者・エンジニア の4つ。上の立場の人が、下の人を「企業名・姓・名・メール」だけで**仮登録**する。メール（文面は `app/Support/RegistrationText.php` の1か所）のリンクから、本人が**本登録**（確かめる項目・必須項目・パスワード・同意を1画面で）を入れ、**確認コードを通ったときに**保存して「参加中」になる（途中でやめたら何も残らず、リンクは7日まで使える）。
- 状態は `users.registered_at`（本登録の完了の日時。空＝仮登録）で決まる。`user_state()` が 退会済・停止・仮登録（期限切れ・心当たりなし）・参加中 を判定する。会社には `provisional`（仮登録）を足した（公開の `pending`＝申込中は別に残す）。仮登録のエンジニアは候補・配信・応募・一斉メールから外れる（条件は `Registration::usableEngineers` の1か所）。
- 必須項目は `app/Support/Requirements.php` の1か所（台帳 8章の表）。足りない人は、いまの立場ごとに `/register/complete`（足りない項目の画面）へ回る（`role` ミドルウェア）。必須の欄は、本人も上位も空にできない。企業名・SES の欄を変えられるのは SES と管理者だけ。
- 手続きは `app/Services/Registration.php`（リンク・本登録の完了・送り直し・取り消し・権限 `canManage`・今いる行の移し替え）。リンクの種類は `register`（本登録）と `reset`（パスワードの再設定。`/reset/{token}`）。「心当たりがない」（`/invite/{token}/decline`）でリンクを無効にして上位に知らせる。
- 送り直し・取り消し：`POST /people/{id}/resend`・`/cancel`。取り消しはパスワードも本登録の日時も無い行だけ。
- 版は `config/park.php`（全画面の足元に出す）。今いる行は `2026_10_31_000002` が新しい列だけを埋める。`park:status` に状態ごとの人数が出る。
- 段階 2（担当者・エンジニア）：入口は `Registration::provisionalPerson`（会社・姓・名・メールの4つだけ。担当者は `/ses/company/users/new`（SES だけ）・`/admin/ses/{id}/users/new`、エンジニアは `/engineers/new`。管理者は参加中の SES を選ぶ。直属は選べない）。送り直し・直し・取り消し・停止などの権限は `Registration::canManage` と `W::isRep`（SES は `ses_companies.rep_user_id`）に1か所。担当者の停止・再開・退会は SES と管理者だけ（SES は交代のあとでなければ止められない）。SES の交代は管理者だけ（`POST /admin/ses/{id}/rep`。担当者から選ぶ、または担当者0人なら次の SES を仮登録＝`next_rep_user_id`。本登録の完了で入れ替わる）。仮登録中のエンジニアは、上位が技術・住所・書類・AI を使えない（直せるのは姓・名・メール・所属の SES）。担当者・エンジニアの本登録・再設定・送り直しは、会社が参加中のときだけ。
- エンジニアの氏名・ふりがな・電話は、アカウント（users）と技術登録（engineers）でそろえる（`W::syncEngineerFromUser`・`syncUsersFromEngineer`。兼任の人は対象外）。今ある食い違いは `php artisan park:sync-eg-names`（既定は見るだけ。`--apply` で書き換え。公開版で流すのは TEZZO の了承を取ってから）。
- 管理者 → 管理者・`/setup`・`park:invite-admin` の仮登録は段階 3（今の入口から出たリンクも、新しい本登録の画面に入る）。

## 登録と、内容の責任（2026-10-16 tezzo）

- 立場は 管理者・SES・担当者・EG（エンジニア）の4つ。登録（仮登録）できるのは上の立場の人だけ：管理者が管理者・SES・担当者・エンジニアを、SES が担当者・エンジニアを、担当者がエンジニアを仮登録する（管理者がエンジニアを登録するときは、SES の画面で SES を選んでから。管理者の直属を新しく登録する入口はない。2026-10-20）。仮登録は 企業名（または選ぶ）・姓・名・メールアドレスだけを入れる（メールアドレスが必須。仮登録のご案内を送るため）。ご本人が7日以内に本登録（残りの必須項目・同意・パスワード）をし、確認コードを入れると参加中になる。
- 上の立場の人が入れた情報も、本人が責任を持つ。本人が「確認しました」を押すまで `self_confirmed_at` は空で、エンジニアは応募できない。管理者・SES が書き換えると空に戻る（`engineers`・`ses_companies`）。
- 最初の情報登録（2026-10-17）：`users` に `kana`・`phone`・`position`・`profile_completed_at` を足した。`profile_completed_at` が空の人は、2要素認証のあと、`Role` ミドルウェアが `/welcome`（あなたの情報）へ回す（`/account`・ログアウト・使い方は開ける）。ログイン中の画面の上に、立場の帯（管理者／SES／エンジニア）を出す。管理者は上のメニューの「管理者」（`/admin/admins`。2026-10-20 に設定から移した）で管理者を登録（`/admin/admins/new`）・送り直しでき、一覧の「詳細」（`/admin/admins/{id}`）から「編集」（`/admin/admins/{id}/edit`）に進んで直せる（一覧 → 詳細 → 編集。SES・エンジニア・案件と同じ形）。管理者には所属・役職・電話番号・郵便番号・住所も持たせられる（保存して見せるだけ。メールの署名・請求書には使わない）。管理者が登録した SES は、2026-10-31 から仮登録になり、SES の本登録で参加中になる（上の「仮登録 → 本登録」。`profile_completed_at` による判定と `/welcome` も、足りない項目の画面 `/register/complete` に替わった）。最初の情報登録では、管理者は企業名（`users.company_name`。メールの差出人にも使う）、SES は SES名（`ses_companies.name`。変えると管理者にベルで知らせる）も必須で入れる。
- 管理者の直属のエンジニア（2026-10-17）：`ses_companies.kind`（`ses`／`direct`）。`direct` の行（`W::directSesId()` が必要なときに1つ作る）は SES として扱わず、一覧・数・請求・SES ログインから外す。直属の知らせ（`N::toSes`）は管理者全員に届く。直属の行の名前は「管理者の直属」。直属を新しく登録する入口は2026-10-20になくした（`?ses=direct`・`ses_id=direct` は受けない。今いる直属の人と `W::directSesId()`・見本の片付けのオプションは残す）。エンジニアの一覧の「直属だけ」は、直属の人がいるときだけ出る。姓・名の欄は CSS の `.pair`（狭い画面でも同じ行）で包む（2026-10-20）。
- 郵便番号・住所（2026-10-20）：管理者は `users.postal_code`・`address`、SES は `ses_companies.postal_code`（住所は既存の `address`＝所在地）、エンジニアは `engineers` の既存の列。郵便番号は `postal_norm()`（7桁の数字・ハイフン可・全角可 → `123-4567`。だめなら false）でそろえ、`W::addressCols()` で検査して保存（住所は200文字まで）。表示は `postal_address()`（「〒123-4567 住所」）。今あるデータは書き換えず、表示のときにそろえる。見える範囲：管理者の住所は管理者の画面（`/admin/admins/{id}`）と本人の `/account`・`/account/profile` だけ、SES の会社の所在地は管理者と自社の担当者だけ、エンジニアの現住所は `Perms::engineer` が通る人（管理者・所属の SES・本人）だけ。メール・知らせ・操作の記録には住所の中身を書かない。
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

`MAIL_FROM_NAME` は、企業名を入れていない管理者のメールと、自動のメール（通知・確認コードなど）の差出人の名前です。企業名を入れた管理者が自分で送るメールは、差出人の名前がその企業名になります。アドレス（`MAIL_FROM_ADDRESS`）は全メール共通です。

設定したら `php artisan config:clear` をして、管理者の「設定」→「テストメールを送る」で確かめる。

## テスト

```
php artisan test
```

テスト用の DB（SQLite のメモリ）に見本データを入れ、3つの役割で主な画面と操作（申込・承認・取り込み・配信・応募・二重応募の拒否・面談・成約・請求・一斉配信・仮登録と本登録・同意・権限）をたどる。

## つくり

| 場所 | 中身 |
|---|---|
| `app/Services/Workflow.php` | 業務の手続き（登録・配信・応募・選考・成約・延長） |
| `app/Services/Registration.php` | 仮登録 → 本登録の手続き（リンク・完了・送り直し・取り消し・権限） |
| `app/Support/Requirements.php` | 必須項目の決まり（4つの立場） |
| `app/Support/RegistrationText.php` | 仮登録メール・同意・知らせの文面 |
| `app/Services/SkillTable.php` | スキル表（保存・同じ技術のまとめ・技術マスタの統合・使っている人の数） |
| `app/Services/ProjectSkills.php` | 経歴ごとの技術の索引と、経歴から数えた月数 |
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
