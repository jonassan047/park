# PLAN：管理者の情報を直す／管理者・SES の登録（会社名・担当者名・メール）と招待メール（2026-10-20）

## 背景

tezzo の要求（管理者でログインしたあとの点検から）：

1. 登録した管理者の情報を直せるようにする。
2. 管理者は、管理者と SES を登録できる。
3. 登録は「メール・会社名・担当者名」を入れて登録し、そのあと招待メールを送る。

前の計画（氏名の姓・名、登録情報の画面、見本データの片付け）は実装済み。この計画で上書きした（経緯は 41_Park-Project の記録）。

今のつくり（調べた結果）：

- 管理者の招待：`/admin/settings` の「管理者を招待」（名前・メールだけ）→ `POST /admin/settings/admins`（`AdminController::admins` → `W::invite`）。送り直しは `reinviteAdmin`。会社名（`users.company_name`）を入れる欄が無い。
- 登録済みの管理者を直す画面・処理が無い（自分の分は `/account` で直せるが、メールは直せない。マニュアルは「メールアドレスは、ほかの管理者にご連絡ください」と書いてあるのに、その手段が無い）。
- SES の登録：`/admin/ses/new` → `POST /admin/ses`（`sesStore`。会社名だけ必須。メールがあれば `W::applySes` → `W::approveSes` → `W::invite` で招待メール。無ければ warn の案内）。テストは `ProfileAndDirectTest` の D 群・`EmptyStateTest` にある。
- 招待メールの宛名は `user_label($u)`（管理者は「企業名 氏名」）。招待のときに `company_name` が入っていれば、宛名にも出る。

守ること：Laravel 12・Blade・`DB::table`、業務の手続きは `app/Services`、画面の文は日本語のやさしい言い方、外部ライブラリは足さない。PLAN・コード・テストに実在の人の名前・メールアドレスを書かない（park は公開リポジトリ。テストは `@park.example` と `@example.com` だけ）。マイグレーションは要らない（`users.company_name` は既にある）。

## 仮定（tezzo が違えば直す）

- a. 管理者の「会社名」は、アプリの中の呼び名に合わせて「**企業名**」と書く（`/welcome`・`/account`・設定の表と同じ。中身は `users.company_name`）。
- b. 招待の画面は1つの欄の「担当者名」で受ける（`nameCols` で姓・名に分けて入れる。前の計画の M10 と同じ）。直す画面は「氏名（姓）」「氏名（名）」の2つの欄（`clean_name`・`join_name`）。
- c. パスワード設定済み（使用中）の管理者のメールは、画面からは変えられない（レビュー H2 で変更。乗っ取りを防ぐため）。
- d. 招待中（パスワード未設定）の管理者のメールは、自分以外の管理者が変えられ、変えたら新しいアドレスに招待を送り直す（リンクが新しくなるので、古いアドレス宛のリンクは使えなくなる）。
- e. 停止中（`active = 0`）の管理者は、氏名・企業名だけ直せる（メールは変えられない。招待も送らない）。
- f. SES の登録で、メールを入れたときは「担当者名」も必須（招待メールの宛名になるため）。メールが空なら担当者名は空でもよい（今のまま、会社だけ登録して招待は送らない）。
- g. `park:invite-admin`（最初の1人用のコマンド）は変えない（企業名は本人が `/welcome` で入れる）。

## 受け入れ条件

### P. 管理者の招待（企業名・担当者名・メール）

- P1. `/admin/settings` の「管理者を招待」の欄は、**担当者名・企業名・メールアドレス**の3つ（どれも `required`）。ボタンは「登録して招待メールを送る」。
- P2. 3つを入れて送ると、`users` に `role=admin`・`company_name`（入れた企業名）・`name`（と `name_sei`・`name_mei`。`nameCols`）・`email`（小文字）・`password=null` の行ができ、`mail_outbox` に `kind=invite`・宛先がそのメールの行が1つ増える。本文の宛名は「企業名 担当者名 様」。差出人は今のまま（`admin_sender($actor)`）。画面は `/admin/settings` に戻り「登録し、招待メールを送りました。」（ok）。監査ログ `invite` が残る（今のまま）。
- P3. 担当者名が空 →「担当者名を入れてください。」、企業名が空 →「企業名を入れてください。」、メールが空・形が正しくない →「メールアドレスを正しく入れてください。」（どれも bad。入れた値は残す `withInput`）。どの場合も `users`・`mail_outbox` は増えない。
- P4. メールが既に使われている（使用中・招待中・停止中・SES・エンジニアのだれでも）→「このメールアドレスはすでに使われています。」（bad）。大文字小文字だけ違うもの（`New-Admin@Park.Example` と `new-admin@park.example`）も同じとみなす。増えない。
- P5. 企業名・担当者名は前後の空白（全角も）を取る（`clean_name`）。各 100 文字・50 文字×姓名を超えたら bad（企業名は 100 文字まで、担当者名は 100 文字まで）。
- P6. SES 担当者の招待（`/admin/ses/{id}/users`・`approveSes`）とエンジニアの招待は、`company_name` を入れない（今のまま）。`W::invite` は `role=admin` のときだけ `company_name` を入れる。
- P7. 招待中の人を送り直す（`reinviteAdmin`）は今のまま。企業名は消えない。

### Q. 管理者の情報を直す

- Q1. 設定の「管理者」の表の各行に「直す」のリンク（`/admin/settings/admins/{id}`）。自分の行にも出る。
- Q2. `GET /admin/settings/admins/{id}`（管理者だけ）で「管理者の情報を直す」の画面。欄は 氏名（姓）・氏名（名）・企業名・メールアドレス。今の値が入っている。状態（使用中・招待中・停止）を上に出す。
- Q3. 保存 `POST /admin/settings/admins/{id}`：氏名（姓・名）・企業名を、自分・他人・停止中のだれの分でも直せる。`name = join_name(姓, 名)`。姓は必須（名は空でもよい）、企業名は必須。長さは姓・名 各 50 文字、企業名 100 文字まで。保存したら同じ画面に「保存しました。」。
- Q4. メールの欄：
  - 自分の行 → 欄は読むだけ（disabled）で「ご自分のメールは、ほかの管理者に頼んで変えてもらいます。」。サーバーでも、自分のメール変更は受け付けない（送られてきても無視ではなく bad「ご自分のメールアドレスは変えられません。」、ほかの項目も保存しない）。
  - 停止中の人 → 読むだけ。サーバーでも bad「停止中の人のメールアドレスは変えられません。」。
  - 招待中の他人 → 変えられる。変えたら、新しいアドレスに招待を送り直す（新しいリンク・7日。古いリンクは使えなくなる）。「保存し、新しいメールアドレスに招待を送り直しました。」。
  - 使用中（パスワード設定済み）の人 → 読むだけ。サーバーでも bad「使用中の方のメールアドレスは変えられません。」。画面の案内は「使用中の方のメールは変えられません。」。お知らせメール（`account-email`）は送らない（レビュー H2 で、変えられるのは招待中の他人だけにした）。
- Q5. メールの決まり：小文字にして比べる。空・形が正しくない → bad「メールアドレスを正しく入れてください。」。ほかの人（立場を問わない）が使っている → bad「このメールアドレスはすでに使われています。」。大文字小文字だけの違い（今と同じアドレス）は「変えていない」とみなし、メールも送らない。bad のときは何も保存しない（氏名・企業名も）。
- Q6. 何も変えずに保存 → 「保存しました。」だけ。監査ログもメールも出ない。二度押し（同じ内容をもう一度送る）でも、2回目は変化なしなのでメールは増えない。
- Q7. 変えたときは監査ログ `admin-edit` を1件：`#id 氏名：旧→新／企業名：旧→新／メール：旧→新`（変えた項目だけ。300 文字まで）。
- Q8. 対象が管理者でない（SES・エンジニアの `users.id`）・無い id → GET も POST も 404。SES・エンジニアがこの URL を開く → 403（`role:admin` の群）。
- Q9. 直した氏名・企業名は、設定の表・ダッシュボードの「招待の状況」・その人の `user_label`（ヘッダー右・メールの宛名・差出人）にそのまま出る。

### R. SES の登録（会社名・担当者名・メール）と招待メール

- R1. `/admin/ses/new` のフォームの「ご担当者名」は「**担当者名**」に（管理者の画面だけ。公開の申し込み `/apply` と SES の会社の画面は「ご担当者名」のまま）。並びは 会社名 → 担当者名 → メールアドレス → 代表者名 → 電話 …。担当者名の hint「メールアドレスを入れたときは必須です（招待メールの宛名になります）。」。
- R2. 会社名・担当者名・メールを入れて登録 → `ses_companies` が `active`、`users` に `role=ses`・その `ses_id`・`name` が担当者名の行、`mail_outbox` に `kind=invite` が1つ（宛名「会社名 担当者名 様」）。「登録し、ご担当者に招待メールを送りました。」（今のまま）。
- R3. メールが空 → 会社だけ登録、`users`・招待メールは増えない。「登録しました（メールアドレスが無いので、招待は送っていません）。」（warn。今のまま）。
- R4. メールがあるのに担当者名が空 → bad「担当者名を入れてください（招待メールの宛名になります）。」。`ses_companies`・`users`・`mail_outbox` は増えない（仮定 f）。
- R5. 会社名が空 → bad（今のまま）。メールが使用中・招待中の人と同じ（大文字小文字の違いも含む）→ bad（今のまま。大文字のテストを足す）。
- R6. SES の詳細での編集（`sesUpdate`）は今のまま（担当者名を必須にしない）。

### S. マニュアル

- S1. `resources/manuals/admin.md`・`admin-steps.md` の「管理者を増やす」「SES を登録」「メールアドレスはほかの管理者に」を、P・Q・R の流れに直す。

## 仕様（画面・処理）

### ルート（`routes/web.php` の admin 群に足す）

```
Route::get('/settings/admins/{id}', [Adm::class, 'adminEdit']);
Route::post('/settings/admins/{id}', [Adm::class, 'adminUpdate']);
```

`/settings/admins/{id}/reinvite` より後ろでも前でもよい（POST の URL が違うので重ならない）。

### 手続き（`app/Services/Workflow.php`）

- `invite()`：新しく行を作るとき、`$d['role'] === 'admin'` なら `company_name => clean_name($d['company_name'] ?? null)` も入れる（`queueMail` より前に入るので、宛名 `user_label($u)` に企業名が出る）。それ以外は今のまま。
- 新しく `updateAdmin(int $id, array $d, $actor): array` を足す。戻り値は `['type' => 'ok'|'bad'|'warn', 'msg' => '…']`。中身：
  1. 対象を `users` から `role=admin` で取る（無ければコントローラが 404 にするので、ここでは来ない前提。念のため bad）。
  2. `clean_name` で姓・名・企業名を整える。姓・企業名の必須と長さを見る。
  3. メール：小文字・trim。今のメールと同じなら「変えない」。違えば、自分・停止中は bad、形と重複（`where('email', $new)->where('id', '!=', $id)`）を見る。
  4. 変わった項目だけを `DB::transaction` で更新（`updated_at`）。
  5. メールが変わった：招待中 → `issueToken` して新しいアドレスに招待（`invite()` の本文をそのまま使う。`invite()` は「同じメールの行があれば、それを使う」ので、先に email を更新してから `invite(['email' => $new, 'name' => $u->name, 'role' => 'admin', 'company_name' => …], $actor)` を呼べばよい）。使用中 → `N::queueMail` で古い・新しいアドレスに「PARK のログイン用メールアドレスが変わりました」（本文：誰の分が、いつ、管理者のだれが変えたか。心当たりが無ければ管理者に連絡を。差出人 `admin_sender($actor)`）。
  6. 変化があれば `N::audit($actor->id, 'admin-edit', …)`（Q7）。

### コントローラ（`AdminController`）

- `admins()`：P3〜P5 を見てから `W::invite([... 'company_name' => …, 'role' => 'admin'])`。メッセージは P2・P3・P4 の文。
- `adminEdit(int $id)`：対象の管理者を取り、無ければ 404。`view('admin.admin-edit', ['u' => $u, 'self' => $u->id === auth()->id()])`。
- `adminUpdate(Request $r, int $id)`：対象が無ければ 404 → `W::updateAdmin` → bad なら `back()->withInput()`、ほかは `go("/admin/settings/admins/$id", msg, type)`。
- `sesStore()`：メールがあって `contact_name` が空なら R4 の bad（`withInput`）。ほかは今のまま。

### 画面

- `resources/views/admin/settings.blade.php`：表の最後の列に「直す」（`btn ghost small` のリンク）と、今の「招待を送り直す」。招待フォームは `grid2` に 担当者名（`name`）・企業名（`company_name`）・メールアドレス（`email`）。ボタン「登録して招待メールを送る」。フォームの上に一言「登録すると、招待メール（7日間有効のリンク）が届きます。」。
- `resources/views/admin/admin-edit.blade.php`（新規）：`layouts.app`（title「管理者の情報を直す」）。状態の表示、氏名（姓）・氏名（名）は `grid2`、企業名、メールアドレス（自分・停止中は disabled と案内、招待中は hint「変えると、新しいアドレスに招待を送り直します。」、使用中は hint「変えると、古いアドレスと新しいアドレスにお知らせが届きます。」）。「保存する」と「設定に戻る」。
- `resources/views/admin/ses-form.blade.php`：R1 の並びとラベル・hint。`sesShow` の編集でも同じ部品を使うが、サーバーの必須は `sesStore` だけ。

## 作業の手順

1. `W::invite()` に管理者の `company_name` を足す。
2. `AdminController::admins()` に P3〜P5 の検証と文を足す。`settings.blade.php` の招待フォームを3つの欄にする。
3. 既存のテストで管理者を招待している所（`MailSenderTest` 80 行目、`ProfileAndDirectTest` 210・244・294 行目、`EmptyStateTest` 92 行目、`NameSplitTest` 269 行目）に `company_name` を足す（`@park.example`・`@example.com` のまま）。
4. `W::updateAdmin()` を足す。ルート2本・`adminEdit`・`adminUpdate`・`admin-edit.blade.php`・表の「直す」を足す。
5. `sesStore()` に R4、`ses-form.blade.php` に R1。既存のテストで SES をメールあり・担当者名なしで登録している所（`EmptyStateTest` 137・244・257 行目）に `contact_name` を足す。
6. 新しいテスト `tests/Feature/AdminAccountTest.php`（下の表）。
7. マニュアル `admin.md`・`admin-steps.md` を直す（S1）。41_Park-Project の `04_Manuals` の写しは JOE が写し直す。
8. `php artisan test` が全部通ること。

公開版への反映は、push のあと JOE が `park-deploy.sh` で行う（常時了承の範囲。マイグレーションは無い）。テストの中のメールは `mail_outbox` に入るだけで、実際には送らない。手元・公開版で実際の人に招待・お知らせメールを送るのは、使う人（管理者）が画面で行うことで、実装・テストでは送らない。

## テスト（`tests/Feature/AdminAccountTest.php`。用意は `ProfileAndDirectTest` と同じ）

| 条件 | 手順 | 確かめること |
|---|---|---|
| P1 | 管理者で `GET /admin/settings` | 「担当者名」「企業名」「メールアドレス」「登録して招待メールを送る」が出る |
| P2 | `POST /admin/settings/admins` に `name=招待 一郎`・`company_name=テスト企業`・`email=inv-a@park.example` | `users` に role=admin・company_name=テスト企業・password=null・name_sei=招待。`mail_outbox` invite が +1、本文に「テスト企業 招待 一郎 様」。flash ok |
| P3 | 担当者名だけ空／企業名だけ空／メール空／`email=abc` を1回ずつ | どれも flash bad、`users`・`mail_outbox` の数が変わらない |
| P4 | 既存の `ses1@park.example`、招待中の人のメール、`INV-A@Park.Example`（P2 の後） | どれも bad、数が変わらない |
| P6 | `POST /admin/ses/{id}/users` で担当者を招待 | その行の company_name は null |
| P7 | P2 の人に reinvite | company_name が残る、token が変わる |
| Q1・Q2 | `GET /admin/settings` → 「直す」の URL を `GET` | 200、今の姓・名・企業名・メールが入っている |
| Q3 | 他人（使用中）の姓・名・企業名を変えて POST | `users.name`=姓＋半角空白＋名、company_name が新しい値。flash ok。`audit_log` admin-edit +1 |
| Q3 自分 | 自分の企業名だけ変えて POST | 保存される。設定の表と `user_label` に新しい企業名 |
| Q3 必須 | 姓を空／企業名を空／姓 51 文字 | bad、何も変わらない |
| Q4 自分 | 自分の行に別のメールを送る | bad、メール・氏名とも変わらない。GET の画面の欄が disabled |
| Q4 停止中 | `active=0` の管理者のメールを変えて POST | bad。氏名だけの変更は ok |
| Q4 招待中 | 招待中の人のメールを `moved@park.example` に | email が変わる、token が新しくなる、`mail_outbox` invite が新しいアドレス宛に +1、古いアドレス宛は増えない |
| Q4 使用中 | 使用中の他人のメールを `moved2@park.example` に | email が変わる、password は同じ、`mail_outbox` account-email が古い・新しいアドレスに各1。新しいメールとパスワードでログインできる |
| Q5 | 他人が使っているメール／大文字小文字だけ違う同じメール | 前者は bad で何も変わらない。後者は ok でメールが増えない |
| Q6 | 同じ内容で2回 POST | 2回とも ok、`audit_log`・`mail_outbox` が増えない |
| Q8 | SES 利用者の id・99999 で GET/POST（管理者）、SES・エンジニアで GET | 404・404、403 |
| R1 | `GET /admin/ses/new` | 「担当者名」が出て「ご担当者名」が出ない。`GET /apply` は「ご担当者名」のまま |
| R2 | `POST /admin/ses` に 会社名・担当者名・`reg-r@park.example` | users role=ses、invite +1、本文に「会社名 担当者名 様」 |
| R3 | メール空で登録 | users・invite が増えない、flash warn |
| R4 | メールあり・担当者名空 | bad、`ses_companies`・`users`・`mail_outbox` が増えない |
| R5 | `SES1@Park.Example` で登録 | bad、増えない |
