# PLAN：氏名を姓・名に分ける／登録情報を見る画面／見本データをなくして実データで始める（2026-10-19）

## 背景

tezzo の要求（要約。回答済み）：

1. 氏名とふりがなを入れる画面（`/welcome`・`/account`・エンジニアの登録と編集 `engineers/form`）の「氏名」「ふりがな」を、姓と名に分ける。登録済みの人は空白（半角・全角）で自動で分ける。並びは 氏名（姓・名）→ ふりがな（姓・名）→ 企業名（管理者）／SES名（SES）→ 所属・役職 → 電話 …。氏名を使う表示・メールの宛名・`user_label`・スキルシートは、姓＋名をつないで今までと同じ見え方にする。
2. 自分の登録情報を見るだけの画面（管理者・SES・エンジニア共通）。アカウントの画面から行けて、編集の画面へも行ける。
3. 見本データ（`@park.example` など `DatabaseSeeder` が入れたもの）に頼らない。管理者のダッシュボードは「管理者・SES を招待する」から始まり、PARK 全体の数と招待の状況が見える。SES・エンジニアのダッシュボード、ほかのタブも、データが空でも案内が出て、実データで動く。
4. 見本データを手元と公開版の両方から消す（tezzo 了承済み）。最初の管理者（実際に使う tezzo のアカウント）と実在の人（エンジニア id=10 など）は消さない。消す前にバックアップを取る。実行は JOE。`DatabaseSeeder` は見本を入れないようにする。
5. タブの「選択中」の不具合（修正済み）は、テストだけ足す。

前の計画（企業名・SES名・差出人）は実装済み。この計画で上書きした（経緯は 41_Park-Project の記録）。

守ること：Laravel 12・Blade・`DB::table`、業務の手続きは `app/Services`、画面の文は日本語のやさしい言い方、外部ライブラリは足さない。PLAN・コード・テストに実在の人の名前・メールアドレスを書かない（park は公開リポジトリ。テストは `@park.example` と `@example.com` だけ）。

## 受け入れ条件

### M. 氏名・ふりがなを姓・名に分ける

- M1. `users` と `engineers` に `name_sei`・`name_mei`・`kana_sei`・`kana_mei`（どれも string(50) null）がある。`name`・`kana` は残す（姓＋名をつないだもの。表示・メール・検索・並べ替えは今までどおり `name`・`kana` を読む）。
- M2. マイグレーションで、既存の全行の `name` を最初の空白（半角・全角、続く空白はまとめて1つ）で分け、前を `name_sei`、残り（前後の空白を取る）を `name_mei` に入れる。空白が無ければ全体を `name_sei`、`name_mei` は null。`kana` も同じく `kana_sei`・`kana_mei` に。`name`・`kana` の値は書き換えない（見え方が変わらない）。`down()` は4列を消す。
  - 例：「山田 太郎」→ 山田／太郎、「山田　太郎」（全角）→ 山田／太郎、「山田  太郎」→ 山田／太郎、「管理者」→ 管理者／null、「Mary Ann Smith」→ Mary／Ann Smith、null・空 → null／null。
- M3. 保存すると `name = 姓 + 半角空白 + 名`（名が空なら姓だけ）、`kana` も同じ。どの入口（`/welcome`・`/account`・エンジニアの登録・編集）でも同じ関数でつなぐ。
- M4. `/welcome` の欄の並び：氏名（姓）・氏名（名）→ ふりがな（姓）・ふりがな（名）→（管理者）企業名／（SES）SES名 → 所属・役職（エンジニア以外）→ 電話。姓・名は横に並べる（`grid2`）。
  - 必須：姓・名（どちらも）、企業名（管理者）、SES名（SES）。ふりがなは任意。
  - 初期値：`users` の姓・名・ふりがな。エンジニアで `users` の姓が空なら `engineers` の姓・名・ふりがな。
- M5. `/account`「基本」も M4 と同じ並び（「お名前」のラベルは「氏名（姓）」「氏名（名）」にそろえる）。必須にしない。姓・名がどちらも空で保存すると、今の氏名のまま。名だけ入れて姓が空ならエラー（「氏名（姓）」）。姓だけ（名が空）は保存できる（M2 で名が null になった人がそのまま保存できるように）。
- M6. エンジニアの登録（`/engineers/new`）・編集（`/engineers/{id}/edit`）：氏名（姓）・氏名（名）→ ふりがな（姓）・ふりがな（名）→（管理者だけ）所属 → メールで呼ぶ名前 → メールアドレス …（あとは今のまま）。登録は姓・名とも必須（無ければ今と同じ「所属と氏名を入れてください。」）。編集は M5 と同じ（姓は必須、名は空でも可）。ふりがなの hint「スキルシートのイニシャル（T.Y）に使います」はふりがな（名）の下に。
- M7. 長さ：姓・名・ふりがなの姓・名は各 50 文字まで（51 文字はエラー）。前後の空白（全角も）は取って保存する（`clean_name` を使う）。
- M8. イニシャル：`initials` が空のエンジニアは、つないだ `kana` から今の `initialsFromKana` で作る（「やまだ たろう」→ T.Y。今と同じ）。
- M9. 見え方が変わらない：ヘッダー右（`user_label`）、立場の帯、メールの宛名「○○ 様」、エンジニア一覧・詳細、スキルシート（名前あり／伏せる）、履歴書・業務経歴書（Excel の氏名・フリガナ）、通知の本文が、今までと同じ文字列（「山田 太郎」など）で出る。
- M10. 1つの欄しかない入口は、そのまま1つの欄で受け、保存のときに M2 の決まりで姓・名にも分けて入れる：初めの設定（`/setup`）、管理者の招待（`/admin/settings` と `park:invite-admin`）、SES の担当者の追加（`/admin/ses/{id}/users`）、SES の承認・登録で作る担当者（`approveSes`）、`createUser`。本人はあとで `/welcome` で姓・名を直す（仮定 a）。
- M11. 前の送り方（`name`・`kana` の1つの欄）で `POST` が来たとき（姓・名の欄が無いとき）は、サーバーで M2 の決まりで分けてから検証する（今のテストと、開いたままの古い画面から送られたときのため）。姓・名の欄があるときは、そちらを使う。

### N. 登録情報を見るだけの画面

- N1. `GET /account/profile`（ログインした人。立場を問わない。`role` ミドルウェアの群）で「登録情報」の画面が出る。入力欄は無い。
- N2. 出す項目（M4 と同じ並び）：氏名（姓 名）、ふりがな（姓 名）、（管理者）企業名／（SES）SES名、所属・役職（エンジニア以外）、電話、メールアドレス、立場（管理者・SES・エンジニア）、メールでの通知（受け取る／受け取らない）、登録した日（`profile_completed_at`）、最後のログイン。空の項目は「—」。
- N3. 立場ごとの追加：SES は「会社情報を見る」（`/ses/company`）、エンジニアは「プロフィール・経験票を見る」（`/engineers/{engineer_id}`）のリンク。
- N4. 画面に「編集する」ボタン（`/account` へ）。`/account` の「基本」の上に「登録情報を見る」リンク（`/account/profile` へ）。
- N5. 見えるのは自分の情報だけ（URL に id を取らない）。ログインしていなければ `/login` へ。最初の情報登録がまだの人は、今の決まりどおり開ける（`Role` は `account/*` を通す）が、中身は「まだ登録していません」と `/welcome` へのリンクを出す。

### P. ダッシュボードと空のときの画面

- P1. 管理者のダッシュボード（`/admin`）のいちばん上に「PARK をはじめましょう」のカードを出す。条件：参加中・申込中の SES（kind=ses）が0、かつエンジニアが0、かつ案件が0。中身は順番つきの手順と、それぞれのボタン：
  1. ほかの管理者を招待する（任意）→ `/admin/settings`（管理者のカード）
  2. SES を登録して、ご担当者を招待する → `/admin/ses/new`
  3. エンジニアを登録する（直属・SES の人）→ `/engineers/new`
  4. 案件を登録する → `/cases/new`
  - 済んだ手順には「済み」を付ける（管理者が2人以上・SES が1以上・エンジニアが1以上・案件が1以上）。条件を外れたら、このカードは出ない。
- P2. 全体の数（`x-stats`）を次にする：SES（参加中 n。下に「申込 n・招待中 n」）、エンジニア（n。下に「待機 n」）、案件（募集中 n。下に「全体 n」）、選考中、稼働中の契約、今月の成功報酬（税別）、請求（未入金 n件。下に「発行済み・入金待ち」）、招待中（n人。管理者・SES・エンジニアのうち、招待したがパスワードを決めていない人）。データが空なら全部 0（エラーにならない）。
- P3. 「招待の状況」カードを出す。行は、管理者と SES の担当者（`users` の role=admin・ses）と、担当者がまだいない参加中・申込中の SES。列：立場、名前、所属（管理者は企業名、SES は SES名）、状態、最後のログイン、操作。
  - 状態：使用中（パスワードあり）／招待中（パスワードなし・`token_expires` が今より後。「〜m/d まで」を添える）／期限切れ（パスワードなし・期限が過ぎた・無い）／停止（`active`=0）／担当者が未登録（SES の行）。
  - 操作：管理者の招待中・期限切れ → 「招待を送り直す」（今の `POST /admin/settings/admins/{id}/reinvite`）。SES の行 → 「開く」（`/admin/ses/{id}`。そこで担当者を招待できる）。
  - 自分の行（ログイン中の管理者）も出す。行が自分だけのときは「まだ誰も招待していません。上の手順から始めます。」を添える。
- P4. 今あるカードは残し、空のときの文を出す：「いま管理者がやること」（今のまま「ありません。」）、「募集中の案件」→「募集中の案件はありません。「案件を登録」から入れます。」、「応募 → 成約（全体）」→ 応募が0件なら「まだ応募はありません。」、「面談の予定」（今のまま）。「参加の申し込み」「1か月以内に終わる契約」「未読のメッセージ」は今どおり、無ければ出さない（`x-table` に `:count` を渡す）。
- P5. SES のダッシュボード（`/ses`）：所属のエンジニアが0なら「エンジニアを登録しましょう」のカード（文：「貴社のエンジニアを登録すると、合う案件が届くようになります。」、ボタン `/engineers/new`）。「新しい案件（グループ全体）」が0なら「まだ案件はありません。案件が入ると、ここに出ます。」。数（`x-stats`）は0・成約率は「—」で出る。
- P6. エンジニアのホーム（`/eg`）：届いた案件・応募が0でも今の空の文が出る。経験票が0件なら「経験票（これまでの案件）を入れると、合う案件が届きやすくなります。」とボタン（`/engineers/{id}/projects/new`）のカードを出す。
- P7. ほかのタブを、見本データなし（管理者1人だけ）で開くと、どれも 200 で、空の文が出る：管理者の SES・エンジニア・案件・応募・選考・契約・請求・やり取り・メール・分析・AI・設定・記録・技術マスタ、SES の エンジニア・案件・応募・選考・契約・請求・会社情報、エンジニアの 届いた案件・応募・面談・プロフィール・スキルシート・やり取り。空の文が無い表は `:count` と `empty` を足す（応募・選考のボードは各列「0」と「終わったもの：まだありません。」）。
- P8. 実データで動く：見本なしの状態から、管理者が SES を登録 → 担当者がログイン → エンジニアを登録 → 案件を登録 → 配信 → 応募、までがテストで通る（P のテストの手順）。

### Q. 見本データを消す

- Q1. `DatabaseSeeder` は技術マスタの同期（`SkillCatalog::sync()`）だけにする。見本は新しい `Database\Seeders\SampleSeeder` に移す（中身は今の `DatabaseSeeder` のまま）。`SampleSeeder` は `app()->isProduction()` なら何も入れずにメッセージを出して終わる。
- Q2. テストは今までどおり見本を使う：`tests/TestCase.php` に `protected $seeder = \Database\Seeders\SampleSeeder::class;` を足す（各テストの `$seed = true` はそのまま）。
- Q3. 新しいコマンド `php artisan park:purge-samples {--yes} {--move-kept-to-direct}`。`--yes` が無ければ見るだけ（何も変えない）。
- Q4. 見本の決め方（これ以外は消さない）：
  - 見本の人 U：`users.email` が `@park.example` で終わる人。
  - 見本の SES S：`ses_companies` の kind=ses で、`email` が `@park.example` で終わる会社。
  - 見本のエンジニア E：`engineers.email` が `@park.example` で終わる人、または `email` が空で S に所属する人。
  - 見本の案件 C：`cases.registered_by` が U の案件。
  - 管理者の直属（kind=direct）の行は消さない。
- Q5. 消してはいけないもの（守り）。どれかに当たれば、何も消さずにエラーで終わる：
  - U 以外に、使える管理者（role=admin・`active`=1・パスワードあり）が1人もいない（最初の管理者を残すため）。
  - U 以外の人（`users`）、または E 以外のエンジニアが、S に所属している（例：実在のエンジニアが見本の SES に入っている）。`--move-kept-to-direct` を付けたときだけ、その人たち（`users.ses_id`・`engineers.ses_id`）を管理者の直属に移してから続ける（決めてもらうこと 2）。
  - C 以外の案件が、E の応募・契約を持っている（実在の案件に見本の人が応募している）。この場合は消さず、件数を出して止まる（JOE が tezzo に確かめる）。
- Q6. 見るだけのとき、表ごとの「消す件数」と「残す件数」（users・ses_companies・engineers・cases・applications・contracts・invoices・mail_outbox・app_notifications・audit_log など）、守りの判定（Q5）を出す。名前・メールアドレスは出さない（id と数だけ）。
- Q7. `--yes` のとき：
  1. 先に、消す行を全部（表ごとに全列）JSON に書き出す：`storage/app/backups/purge-samples-YYYYmmdd-His.json`（Git に入らない場所）。書き出せなければ止まる。
  2. 1つの `DB::transaction` で消す。順番（外部キーのため）：E と C に関わる `ai_jobs`（ref が E の書類・その面談）→ `contracts`（E・C・S のもの。`follow_ups` は一緒に消える）→ `invoices`（S）→ `applications`（E・C・S のもの。`application_events`・`interviews` は一緒に消える）→ `case_offers`・`case_mails`（E・C）→ `messages`（E）→ `engineers`（E。スキル・経験票・学歴・資格・書類の行は一緒に消える）→ `cases`（C）→ C 以外の案件の `source_ses_id` が S なら null に → `users`（U。`app_notifications` は一緒に消える）→ `sessions`・`password_reset_tokens`（U）→ `ses_companies`（S）→ `mail_outbox`（宛先が `@park.example`）→ `audit_log`（`user_id` が U）。
  3. 残った人の通知のうち、リンクが消したもの（`/engineers/{E}`・`/cases/{C}`・`/applications/…`・`/interviews/…`・`/contracts/…`・`/admin/ses/{S}`・`/invoices/…` の消した id）を指すものを消す。
  4. 書類のファイル（E の `engineer_files` の実物。`EngineerDocs::DISK`）を消す（transaction が通ったあと）。
  5. `audit_log` に action=`purge-samples`、detail=表ごとの件数（名前なし）を1行入れる。
  6. 最後に件数と、バックアップの場所を出す。
- Q8. 消したあと：`park:status` の「見本データ（@park.example）」が 0。U 以外の管理者は今までどおりログインでき、ダッシュボードが P1〜P3 の形で出る（実データがあればその数）。エンジニア id=10（実在）とその経験票・書類・ログインはそのまま。
- Q9. 2回目に `--yes` で動かしても、消すものが0件と出て、何も変えない。

### R. タブの「選択中」（修正済み。テストだけ）

- R1. 管理者が `/admin/ses` を開くと、タブの「SES」が選択中（`class="on"`）で、「ダッシュボード」は選択中でない。`/admin` では「ダッシュボード」だけが選択中。
- R2. SES が `/ses/engineers` を開くと「エンジニア」だけ選択中。`/ses` では「ダッシュボード」だけ。
- R3. エンジニアが `/eg/offers` を開くと「届いた案件」だけ選択中。`/eg` では「ホーム」だけ。

### K. マニュアル・README・テスト

- K1. `resources/manuals/` の6つ（admin・ses・engineer の `*.md` と `*-steps.md`）：「あなたの情報」の説明を「氏名（姓・名。必須）・ふりがな（姓・名）・…」の並びに直す。アカウントの節に「登録情報を見る」（見るだけの画面）を足す。admin の2つに、ダッシュボードの「PARK をはじめましょう」と「招待の状況」を書く。ses の2つ、エンジニアを登録する節の「氏名・ふりがな」を「氏名（姓・名）・ふりがな（姓・名）」に。
- K2. README：「見本データ」の節（19〜30 行目あたり）を、`php artisan migrate --seed` は技術マスタだけ、見本は `php artisan db:seed --class=SampleSeeder`（手元だけ。公開版では入らない）に直す。`park:purge-samples` の説明（見るだけ → `--yes`、バックアップの場所、守り）を1段落足す。
- K3. `php artisan test` が全部通る。今のテストは弱めない（M11 があるので、今の `name` を送るテストは直さなくてよい）。

## 仕様

### データ（マイグレーション `database/migrations/2026_10_19_000001_split_names.php`）

- `users`：`name_sei`・`name_mei`（`name` の後）、`kana_sei`・`kana_mei`（`kana` の後）。string(50) null。
- `engineers`：同じ4列。
- `up()` の最後に、`users`・`engineers` の全行を `chunkById` で読み、`split_name($row->name)`・`split_name($row->kana)` を入れる（`name`・`kana` は書かない）。
- 50 文字を超える部分は、切らずにそのまま入れる（列は 50 なので、`split_name` の結果が 50 を超えたら `mb_substr(…, 0, 50)`。超える人は実際にはいない想定。仮定 d）。
- sqlite（テスト）でも動く書き方にする（`after()` は sqlite では無視される）。

### 道具（`app/helpers.php`）

- `split_name(?string $full): array` → `[姓|null, 名|null]`。`clean_name` で前後を整え、`preg_split('/[\s　]+/u', $s, 2)`。
- `join_name(?string $sei, ?string $mei): ?string` → 両方あれば `"$sei $mei"`、片方ならそれ、無ければ null。

### 入力の受け方

- `AccountController`：
  - `trimNames` の対象に `name_sei`・`name_mei`・`kana_sei`・`kana_mei` を足す。
  - その前に `legacyNames($r)`：`name_sei`・`name_mei` が両方とも送られていなくて `name` が送られていれば、`split_name(name)` を merge。ふりがなも同じ（M11）。
  - `profileRules($required, $role)`：`name`・`kana` の規則を外し、`name_sei` → `($required ? 'required' : 'nullable|required_with:name_mei').'|max:50'`、`name_mei` → `($required ? 'required' : 'nullable').'|max:50'`、`kana_sei`・`kana_mei` → `nullable|max:50`。企業名・SES名・電話・所属は今のまま。
  - `profileNames()`：`name_sei` 氏名（姓）、`name_mei` 氏名（名）、`kana_sei` ふりがな（姓）、`kana_mei` ふりがな（名）。
  - `welcomeForm`：`$v` に4つを足す（M4 の初期値）。
  - 新しく `profile(Request $r)`：`account.profile` を返す（SES名・エンジニアの id を渡す）。
- `Workflow::saveProfile`：
  - `name_sei`・`name_mei` のどちらかが入っていれば、4列と `name = join_name(…)` を更新。どちらも空なら、氏名の列は触らない（M5）。ふりがなは `kana_sei`・`kana_mei`・`kana = join_name(…)` を更新（今と同じく、空なら null にする）。
  - ほかは今のまま。
- `Workflow::createUser`・`invite`・`InviteAdmin`：`name` を入れるときに `split_name` で `name_sei`・`name_mei` も入れる（M10）。共通の小さな関数 `W::nameCols(string $full): array`（`['name'=>…, 'name_sei'=>…, 'name_mei'=>…]`）にまとめる。
- `Workflow::ENG_FIELDS` に `name_sei`・`name_mei`・`kana_sei`・`kana_mei` を**末尾に**足す。
  - `EngineerController::saveProfile` の `array_slice(W::ENG_FIELDS, 15)` は、新しい定数 `W::ENG_PROFILE_FIELDS`（今の 15 番目から `nickname` までを名前で並べたもの）に置き換える（末尾に足した姓・名が詳しいプロフィールの保存で消えないように）。
  - `engValues`：`$d` に `name_sei` か `name_mei` があれば、それで `name` をつなぐ。無くて `name` があれば `split_name` で姓・名を作る（見本・AI・古い画面のため）。ふりがなも同じ。どちらも無ければ前の値（`$old`）のまま。
- `EngineerController::store`：氏名の確認を「つないだ name が空でない、かつ姓・名が両方ある（姓・名の欄で送られたとき）」にする。`legacyNames` と同じ扱い（`name` だけで来たら分ける）。`update`：姓・名の欄で送られて姓が空なら「氏名（姓）を入れてください。」で戻す。名は空でよい。長さは 50 まで（越えたら戻す）。

### 画面

- `account/welcome.blade.php`・`account/show.blade.php`：M4・M5 の並び。姓・名は `<div class="grid2">` で横に。`show` の上に「登録情報を見る」リンク。
- `account/profile.blade.php`（新規）：`x-card` の中に `<dl>` か2列の表で N2 の項目。下に「編集する」（`/account`）と N3 のリンク。`profile_completed_at` が null なら N5 の文。
- `engineers/form.blade.php`：M6 の並び。
- `admin/dashboard.blade.php`：P1（`@if($start)` のカード。`x-todo` の上）、P2（stats）、P3（「招待の状況」カードを右の列の先頭に）、P4（空の文）。
- `AdminController::dashboard`：`start`（P1 の条件と各手順の済み）、`counts` に `sesPending`・`sesInvited`・`casesAll`・`invUnpaid`・`invited` を足す、`invites`（P3 の行。users と SES を合わせた配列を作る。担当者のいない SES は `whereNotExists users role=ses`）。
- `ses/dashboard.blade.php`・`eg/home.blade.php`：P5・P6。`EgController::home` に経験票の件数（`projects`）を渡す。
- P7 で空の文が無い画面があれば、`x-table` に `:count`・`empty` を足す（今見た限り、`admin/dashboard` の「参加の申し込み」「1か月以内…」「未読」と `eg/home` の「経歴の更新」「面談の予定」以外は `:count` 済み。その5つは外側の `@if` で隠れているので、そのままでよい）。
- `layouts/app.blade.php`：変えない（R はテストだけ）。

### ルート（`routes/web.php`）

- `role` の群に `Route::get('/account/profile', [Acc::class, 'profile']);` を足す（`/account` の下）。

### 見本データ

- `database/seeders/SampleSeeder.php`（新規）：今の `DatabaseSeeder::run` の中身を移す。先頭で `if (app()->isProduction()) { $this->command?->warn('公開版には見本データを入れません。'); return; }`。
- `database/seeders/DatabaseSeeder.php`：`SkillCatalog::sync()` だけ。コメントに「見本は `db:seed --class=SampleSeeder`（手元だけ）」。
- `tests/TestCase.php`：Q2。
- `app/Console/Commands/PurgeSamples.php`（新規）：Q3〜Q9。業務の手続きは `app/Services/SamplePurge.php`（`plan(): array` で U・S・E・C と件数・守りの判定を返し、`run(bool $moveKept): array` で消す）に置き、コマンドは表示だけ。`routes/console.php` か自動登録（今の `Status` と同じ置き方）。

### 正常系・境界（まとめ）

| 場面 | 動き |
|---|---|
| 名前に空白が無い（「管理者」） | 姓＝全体、名＝空。`/account` はそのまま保存できる（M5）。`/welcome` は名が要る |
| 全角の空白・空白が2つ以上 | 1つの区切りとして扱う（M2） |
| 名に空白を含む（「Ann Smith」） | 最初の空白で切るので、残りは全部名（M2） |
| 古い形（`name` だけ）で送られた | 分けてから検証・保存（M11） |
| 51 文字以上 | エラー（M7） |
| 姓・名の両方が空で `/account` を保存 | 今の氏名のまま（M5） |
| 見本も実データも無い | 管理者のダッシュボードに「はじめましょう」。ほかのタブは空の文（P1・P7） |
| 見本の SES に実在の人がいる | 止まる。`--move-kept-to-direct` のときだけ直属へ移して続ける（Q5） |
| 実在の案件に見本の人の応募・契約 | 止まる（Q5） |
| U 以外に使える管理者がいない | 止まる（Q5） |
| 2回目の実行 | 0件で何も変えない（Q9） |
| 公開版で `db:seed --class=SampleSeeder` | 何も入れない（Q1） |
| 登録情報の画面を他人の分で見る | URL に id が無いので、できない（N5） |

## 直すファイル

| ファイル | 直すこと |
|---|---|
| `database/migrations/2026_10_19_000001_split_names.php`（新規） | M1・M2 |
| `app/helpers.php` | `split_name`・`join_name` |
| `app/Http/Controllers/AccountController.php` | M4・M5・M7・M11、`profile` |
| `app/Services/Workflow.php` | `saveProfile`・`createUser`・`invite`・`nameCols`・`ENG_FIELDS`・`ENG_PROFILE_FIELDS`・`engValues` |
| `app/Http/Controllers/EngineerController.php` | `store`・`update`・`saveProfile`（定数） |
| `app/Console/Commands/InviteAdmin.php` | 姓・名も入れる（M10） |
| `app/Http/Controllers/AdminController.php` | `dashboard` |
| `app/Http/Controllers/EgController.php` | `home` に経験票の件数 |
| `routes/web.php` | `/account/profile` |
| `resources/views/account/welcome.blade.php`・`show.blade.php`・`profile.blade.php`（新規） | M4・M5・N |
| `resources/views/engineers/form.blade.php` | M6 |
| `resources/views/admin/dashboard.blade.php` | P1〜P4 |
| `resources/views/ses/dashboard.blade.php`・`eg/home.blade.php` | P5・P6 |
| P7 で空の文が足りない画面 | `:count`・`empty` |
| `database/seeders/DatabaseSeeder.php`・`SampleSeeder.php`（新規） | Q1 |
| `tests/TestCase.php` | Q2 |
| `app/Services/SamplePurge.php`・`app/Console/Commands/PurgeSamples.php`（新規） | Q3〜Q9 |
| `resources/manuals/*.md`（6つ）・`README.md` | K1・K2 |
| `tests/Feature/NameSplitTest.php`・`AccountProfileTest.php`・`EmptyStateTest.php`・`PurgeSamplesTest.php`・`TabTest.php`（新規） | 下のテスト |

変えなくてよいと確かめたところ：`name`・`kana` を読む所（`user_label`・帯・メールの宛名・一覧・検索・並べ替え・スキルシート・`EngineerDocs`・`Todo`・通知の本文）は、`name`・`kana` を残すので変えない（M9）。`ses_companies` の `rep_name`・`contact_name`（会社の情報で、人の登録ではない）と、参加の申し込み（`/apply`）は変えない（仮定 b）。`Role` ミドルウェア（`account/*` を通す）。`layouts/app.blade.php`（R は修正済み）。

## 作業の手順

1. `split_name`・`join_name` を足し、マイグレーションを書いて `php artisan migrate`（手元）。
2. `Workflow`（`nameCols`・`createUser`・`invite`・`saveProfile`・`ENG_FIELDS`・`ENG_PROFILE_FIELDS`・`engValues`）と `InviteAdmin`。
3. `AccountController`（`legacyNames`・`trimNames`・規則・`welcomeForm`・`profile`）とルート。
4. 画面：`welcome`・`show`・`profile`（新規）・`engineers/form`、`EngineerController` の `store`・`update`・`saveProfile`。
5. ここで `php artisan test`（今のテストが M11 で通ることを確かめる）。
6. `SampleSeeder` に移し、`DatabaseSeeder` を技術マスタだけにし、`TestCase` に `$seeder`。`php artisan test` が通ることを確かめる。
7. ダッシュボード（管理者・SES・エンジニア）と、P7 で足りない空の文。
8. `SamplePurge` とコマンド。
9. 新しいテスト（下）。`php artisan test` が全部通ること。
10. マニュアル6つと README。
11. reviewer・tester に回す。

### 公開版と手元で見本を消す手順（JOE が行う。tezzo 了承済み。順番を守る）

この手順は**データの削除**なので、各段で結果を見てから次へ進む。メールは1通も出ない。`.env` は書き換えない。

1. push → `bash .claude/scripts/park-deploy.sh`（コードの更新・**migrate**（姓・名の列と分け入れ）・キャッシュ）。手元も `php artisan migrate`。
2. **手元のバックアップ**：MariaDB が動いていることを確かめ、`C:\xampp\mysql\bin\mysqldump.exe` で `jonassan_park` を `~/db-backup/park-before-purge-YYYYMMDD.sql` に書き出す（ユーザー・パスワードは `.env` から。コマンドの記録・PLAN・ログに残さない）。DB-sync（OneDrive）には書かない。
3. **公開版のバックアップ**：ssh（`park-remote-status.sh` と同じ入り方）で、サーバーの `mysqldump` を使ってホームの下（公開ディレクトリの外）に書き出す。パスワードはサーバーの `.env` から読み、コマンドラインに出さない（`--defaults-extra-file` に一時ファイルを作り、終わったら消す）。できなければ、ここで止めて tezzo に「コマンドの JSON バックアップ（Q7）だけで進めてよいか」を確かめる。Xserver の自動バックアップがあるかも、このとき確かめて記録する。
4. 見るだけで実行：手元 `php artisan park:purge-samples`、公開版 `/opt/php-8.3/bin/php artisan park:purge-samples`。確かめること：
   - 残す管理者が1人以上（tezzo のアカウント）。
   - エンジニア id=10 が「残す」側にいる（E に入っていない）。
   - 守り（Q5）に当たっていない。当たったら止めて tezzo に伝える（決めてもらうこと 2）。
   - 消す件数が、見本（管理者1・SES 4社・SES の担当者3・エンジニア4・案件3 前後）と大きくずれていない。ずれていたら止める。
5. `--yes` で実行（手元 → 公開版の順）。出た件数とバックアップの場所を `40_動作確認` に記録（名前・メールは書かない）。
6. 確かめ：`park:status` の「見本データ」が 0。公開版で tezzo のアカウントでログインし、ダッシュボードが出ること（JOE はログインできないので、tezzo に見てもらう）。`park-remote-status.sh` の数。
7. 41_Park-Project の `04_Manuals` の写し（管理者・SES・エンジニアの `マニュアル.md`・`操作手順書.md`）を写し直す。README の写しも。

## テストの手順

テストのデータは `@park.example` と `@example.com` だけ。`as()`（2要素認証を通した状態）は今のテストと同じ書き方。

### `NameSplitTest`（見本あり）

- M1：`Schema::hasColumns` を `users`・`engineers` で。
- M2：マイグレーションの分け方は `split_name` を直接たどる（表の例を全部 `assertSame`）。加えて、見本のあと `users` の admin@park.example を `name='管理者　太郎'`（全角）・姓名 null にしてから、マイグレーションの分け入れの関数（マイグレーションから呼ぶ static 関数を `Workflow` か helper に置いて、それを呼ぶ）を動かすと 管理者／太郎、`name` は書き換わらない。
- M4：eg1 を unfinish → `GET /welcome` で「氏名（姓）」「氏名（名）」「ふりがな（姓）」が出て、並びが 氏名 → ふりがな → 電話 の前（`assertSeeInOrder(['氏名（姓）','氏名（名）','ふりがな（姓）','ふりがな（名）','電話'])`）。admin は `['…','ふりがな（名）','企業名','所属・役職','電話']`、ses は `SES名`。
- M4 必須：admin を unfinish、`name_sei` だけ（`name_mei` 空）で `POST /welcome` → `assertSessionHasErrors('name_mei')`、`profile_completed_at` null。
- M3：`name_sei=' 山田　'`, `name_mei='太郎'`, `kana_sei='やまだ'`, `kana_mei='たろう'` → `users.name='山田 太郎'`、`kana='やまだ たろう'`、姓・名の列も入る。
- M5：登録済みの admin で `POST /account`（姓・名なし・`kana` なし）→ 氏名そのまま。`name_mei` だけ → `name_sei` のエラー。`name_sei='管理者'` だけ → `name='管理者'`、`name_mei` null。
- M6：admin で `POST /engineers`（`ses_id=direct`・`name_sei`・`name_mei`・`kana_sei`・`kana_mei`）→ `engineers.name='直属 一郎'`、`initials` が作られる。`name_mei` 無しで新規 → 「所属と氏名を入れてください。」。`/engineers/{id}/edit` で姓だけ変える → `name` が新しい姓＋前の名。
- M6 守り：`POST /engineers/{id}/profile`（詳しいプロフィール）で姓・名を送らないと、姓・名・`name` が変わらない（`ENG_PROFILE_FIELDS`）。
- M7：51 文字の `name_sei` → エラー。50 文字は保存。
- M9：eg1 の `/engineers/{id}/sheet` に「山田 太郎」、`?anon=1` に出ない。admin のヘッダー右に今の `user_label`。
- M10：admin で `/admin/settings/admins` に `name='見本 花子'` で招待 → 作られた `users` の姓・名が 見本／花子。
- M11：今のテストの `POST /welcome`（`name` だけ）が通ることで確かめる。加えて `name='佐藤 美咲'` だけで送ると姓・名の列が入る。

### `AccountProfileTest`（見本あり）

- N1・N2：admin（企業名を入れておく）・ses1・eg1 で `GET /account/profile` → 200、氏名・ふりがな・企業名／SES名・メール・立場が出て、`<input` が無い（`assertDontSee('<input', false)`。ログアウトのボタンは `button` なので当たらない）。
- N3：ses1 に `/ses/company`、eg1 に `/engineers/{id}` へのリンク。
- N4：`/account/profile` に `/account` へのリンク、`/account` に `/account/profile` へのリンク。
- N5：ログインしていない → `/login` へ。unfinish の人 → 200 で「まだ登録していません」と `/welcome`。

### `EmptyStateTest`（`$seed = false`。見本なし）

- 準備：`W::createUser('first@example.com', '最初 管理者', 'admin', 'x', …)` に `profile_completed_at` を入れ、2要素認証を通した状態にする。
- P1：`GET /admin` に「PARK をはじめましょう」と4つの手順のリンク（`/admin/settings`・`/admin/ses/new`・`/engineers/new`・`/cases/new`）。
- P2：「招待中」などの数が 0 で出る。
- P3：「招待の状況」に自分の行と「まだ誰も招待していません」。
- P7：管理者のタブを全部 GET → 200。それぞれ空の文（「まだありません。」など）が出る。
- P1 消える：`POST /admin/ses`（`name`・`email=ses-new@example.com`）→ `/admin` に「はじめましょう」が出ない（SES が1）、P3 に「招待中」の行と、`mail_outbox` に kind=invite が1件（送信はしない。テストの mailer）。
- P8：上の SES の担当者をパスワード設定済み・登録済みにしてログイン → `/ses` が 200 で「エンジニアを登録しましょう」。SES がエンジニアを登録（`name_sei` など）→ 管理者が案件を登録（`POST /cases`）→ `W::distribute` か `POST /cases/{id}/distribute` → エンジニアの users を用意して `/eg` に案件が出る。SES・エンジニアのタブも全部 200。
- P6：上のエンジニアの `/eg` に経験票の案内。

### `PurgeSamplesTest`（見本あり）

- 準備：実在の役として `real-admin@example.com`（admin・パスワードあり・active）と、直属のエンジニア `real-eg@example.com`（`W::directSesId()`、users も）。
- Q6：`artisan('park:purge-samples')` → 終わりのコード 0、DB の件数がどれも変わらない。出力に `@park.example` の文字が無い。
- Q5-1：`real-admin@example.com` を消してから実行 → 失敗のコード、何も消えない。
- Q5-2：`real-eg2@example.com` のエンジニアを見本の SES（ses1 の会社）に足す → `--yes` でも失敗・何も消えない。`--yes --move-kept-to-direct` → その人は直属に移って残り、見本は消える。
- Q5-3：`real-admin@example.com` が案件を登録し、見本の eg1 をその案件に応募させる（`W::apply`）→ `--yes` は止まる。
- Q7・Q8：`--yes` → `users` に `@park.example` が0、`ses_companies` の kind=ses が0（直属の行は残る）、見本のエンジニア・案件・応募・契約・請求が0、real の2人と直属のエンジニアは残る、`audit_log` に `purge-samples` が1行、`storage/app/backups/purge-samples-*.json` ができる（テストの最後に消す）。`park:status` の出力の見本の行が 0。real-admin で `/admin` が 200 で「PARK をはじめましょう」は出ない（直属のエンジニアがいるので）。
- Q9：もう一度 `--yes` → 0件、`audit_log` の件数は `purge-samples` の1行が増えるだけ（または増やさない。どちらかに決めてテストにする：**0件のときは記録しない**）。
- Q1：`app()->detectEnvironment(fn () => 'production')` にして `SampleSeeder` を呼ぶ → `users` が増えない。`DatabaseSeeder` を呼ぶ → `users` が増えない（技術マスタだけ）。

### `TabTest`（見本あり）

- R1〜R3：`url('/admin/ses')` のリンクに `class="on"`、`url('/admin')` のリンクに無い（`assertSee('href="'.url('/admin/ses').'" class="on"', false)` と `assertDontSee('href="'.url('/admin').'" class="on"', false)`）。`/admin` では逆。SES・エンジニアも同じ。

## 仮定

- a. 1つの欄しかない入口（初めの設定・管理者の招待・SES の担当者の追加・`park:invite-admin`）は1つの欄のまま。保存のときに分ける（M10）。本人が `/welcome` で直す。
- b. SES の会社の情報の「代表者名」「ご担当者名」と参加の申し込み（`/apply`）は、人の登録ではないので分けない。
- c. ふりがなは、ひらがな・カタカナの形を確かめない（今も確かめていない）。
- d. 姓・名は 50 文字まで。今の `name` は 100 まで入れられたが、分ければ 50 を超える人はいない想定。
- e. エンジニアの `users.name` と `engineers.name` は、今と同じく別々に持つ（`/welcome` で直しても `engineers` は変えない。エンジニアの画面で直しても `users` は変えない）。
- f. 消した見本のバックアップ（JSON）は、公開版のサーバーの `storage/app/backups` に残す（Web からは見えない）。いつ消すかは後で決める。
- g. 「PARK をはじめましょう」の条件は「SES・エンジニア・案件がどれも0」。どれか1つでも入れば消える（済み・未済みの一覧は「招待の状況」と数で見える）。

## 決めてもらうこと（tezzo）

**1** 1つの欄しかない招待の画面も姓・名に分けるか
背景：要求は「氏名とふりがなを入れる全画面」。管理者の招待・SES の担当者の追加・初めの設定は「名前」の1欄だけ（ふりがなは無い）。招待された人は最初のログインで姓・名を入れ直す。
答え方：
a. 1欄のまま（この計画。保存のときに空白で分ける）
b. 招待の画面も「姓」「名」の2欄にする

**2** 見本の SES に実在の人が入っていたときの扱い
背景：消すコマンドは、見本の SES に実在の人（エンジニア id=10 など）が所属していると止まる。id=10 がどの所属かは、手元・公開版の DB を見ないとわからない（計画の時点では見ていない）。
答え方：
a. 止まったら、その人を管理者の直属に移して続けてよい（`--move-kept-to-direct`）
b. 止まったら、その SES ごと残す（見本の SES を1社残す。JOE が tezzo に報告して終わる）

**3** `/welcome` で名（下の名前）を必須にするか
背景：名が無い人（海外の人・1語の名前）がいると、登録が済ませられない。`/account` とエンジニアの編集では名を空でも保存できるようにした。
答え方：
a. 必須にする（この計画）
b. 姓だけ必須にする
