# PLAN：管理者の情報を直す／管理者・SES の登録（会社名・担当者名・メール）と招待メール（2026-10-20）

## 背景

tezzo の要求（管理者でログインしたあとの点検から）：

1. 登録した管理者の情報を直せるようにする。
2. 管理者は、管理者と SES を登録できる。
3. 登録は「メール・会社名・担当者名」を入れて登録し、そのあと招待メールを送る。

前の計画（氏名の姓・名、登録情報の画面、見本データの片付け）は実装済み。この計画で上書きした（経緯は 41_Park-Project の記録）。

今のつくり（調べた結果）：

- 管理者の招待（2026-10-20 に設定から「管理者」メニューへ移した。下の「追記」）：`/admin/admins` の「管理者を招待」（名前・メールだけ）→ `POST /admin/admins`（`AdminController::admins` → `W::invite`）。送り直しは `reinviteAdmin`。会社名（`users.company_name`）を入れる欄が無い。
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

- P1. `/admin/admins` の「管理者を招待」の欄は、**担当者名・企業名・メールアドレス**の3つ（どれも `required`）。ボタンは「登録して招待メールを送る」。
- P2. 3つを入れて送ると、`users` に `role=admin`・`company_name`（入れた企業名）・`name`（と `name_sei`・`name_mei`。`nameCols`）・`email`（小文字）・`password=null` の行ができ、`mail_outbox` に `kind=invite`・宛先がそのメールの行が1つ増える。本文の宛名は「企業名 担当者名 様」。差出人は今のまま（`admin_sender($actor)`）。画面は `/admin/admins` に戻り「登録し、招待メールを送りました。」（ok）。監査ログ `invite` が残る（今のまま）。
- P3. 担当者名が空 →「担当者名を入れてください。」、企業名が空 →「企業名を入れてください。」、メールが空・形が正しくない →「メールアドレスを正しく入れてください。」（どれも bad。入れた値は残す `withInput`）。どの場合も `users`・`mail_outbox` は増えない。
- P4. メールが既に使われている（使用中・招待中・停止中・SES・エンジニアのだれでも）→「このメールアドレスはすでに使われています。」（bad）。大文字小文字だけ違うもの（`New-Admin@Park.Example` と `new-admin@park.example`）も同じとみなす。増えない。
- P5. 企業名・担当者名は前後の空白（全角も）を取る（`clean_name`）。各 100 文字・50 文字×姓名を超えたら bad（企業名は 100 文字まで、担当者名は 100 文字まで）。
- P6. SES 担当者の招待（`/admin/ses/{id}/users`・`approveSes`）とエンジニアの招待は、`company_name` を入れない（今のまま）。`W::invite` は `role=admin` のときだけ `company_name` を入れる。
- P7. 招待中の人を送り直す（`reinviteAdmin`）は今のまま。企業名は消えない。

### Q. 管理者の情報を直す

- Q1. 「管理者」の表（`/admin/admins`）の各行に「直す」のリンク（`/admin/admins/{id}`）。自分の行にも出る。
- Q2. `GET /admin/admins/{id}`（管理者だけ）で「管理者の情報を直す」の画面。欄は 氏名（姓）・氏名（名）・企業名・メールアドレス。今の値が入っている。状態（使用中・招待中・停止）を上に出す。
- Q3. 保存 `POST /admin/admins/{id}`：氏名（姓・名）・企業名を、自分・他人・停止中のだれの分でも直せる。`name = join_name(姓, 名)`。姓は必須（名は空でもよい）、企業名は必須。長さは姓・名 各 50 文字、企業名 100 文字まで。保存したら同じ画面に「保存しました。」。
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
Route::get('/admins/{id}', [Adm::class, 'adminEdit']);
Route::post('/admins/{id}', [Adm::class, 'adminUpdate']);
```

`/admins/{id}/reinvite` より後ろでも前でもよい（POST の URL が違うので重ならない）。

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
- `adminUpdate(Request $r, int $id)`：対象が無ければ 404 → `W::updateAdmin` → bad なら `back()->withInput()`、ほかは `go("/admin/admins/$id", msg, type)`。
- `sesStore()`：メールがあって `contact_name` が空なら R4 の bad（`withInput`）。ほかは今のまま。

### 画面

- `resources/views/admin/admins.blade.php`（もとは settings.blade.php。下の「追記」で移した）：表の最後の列に「直す」（`btn ghost small` のリンク）と、今の「招待を送り直す」。招待フォームは `grid2` に 担当者名（`name`）・企業名（`company_name`）・メールアドレス（`email`）。ボタン「登録して招待メールを送る」。フォームの上に一言「登録すると、招待メール（7日間有効のリンク）が届きます。」。
- `resources/views/admin/admin-edit.blade.php`（新規）：`layouts.app`（title「管理者の情報を直す」）。状態の表示、氏名（姓）・氏名（名）は `grid2`、企業名、メールアドレス（自分・停止中は disabled と案内、招待中は hint「変えると、新しいアドレスに招待を送り直します。」、使用中は hint「変えると、古いアドレスと新しいアドレスにお知らせが届きます。」）。「保存する」と「管理者の一覧に戻る」（`/admin/admins`）。
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
| P1 | 管理者で `GET /admin/admins` | 「担当者名」「企業名」「メールアドレス」「登録して招待メールを送る」が出る |
| P2 | `POST /admin/admins` に `name=招待 一郎`・`company_name=テスト企業`・`email=inv-a@park.example` | `users` に role=admin・company_name=テスト企業・password=null・name_sei=招待。`mail_outbox` invite が +1、本文に「テスト企業 招待 一郎 様」。flash ok |
| P3 | 担当者名だけ空／企業名だけ空／メール空／`email=abc` を1回ずつ | どれも flash bad、`users`・`mail_outbox` の数が変わらない |
| P4 | 既存の `ses1@park.example`、招待中の人のメール、`INV-A@Park.Example`（P2 の後） | どれも bad、数が変わらない |
| P6 | `POST /admin/ses/{id}/users` で担当者を招待 | その行の company_name は null |
| P7 | P2 の人に reinvite | company_name が残る、token が変わる |
| Q1・Q2 | `GET /admin/admins` → 「直す」の URL を `GET` | 200、今の姓・名・企業名・メールが入っている |
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

## 追記（2026-10-20）：管理者を設定から「管理者」メニューへ

- 管理者メニューの「ダッシュボード」と「SES」の間に「管理者」（`/admin/admins`）を足した。一覧・登録・直す・招待の送り直しはここ。設定画面の「管理者」カードは削除。
- ルートは `/admin/admins`（GET 一覧・POST 登録）、`/admin/admins/{id}`（GET 直す・POST 更新）、`/admin/admins/{id}/reinvite`。`AdminController::adminsIndex()` を足した。登録・送り直しの戻り先は `/admin/admins`、更新の戻り先は `/admin/admins/{id}`。
- ダッシュボードの開始カード「ほかの管理者を招待する」のリンクも `/admin/admins`。
- テストの `/admin/settings/admins` は `/admin/admins` に置き換えた。設定画面に管理者カードが出ないこと・ナビの順は `AdminAccountTest` で確かめる。

---

# 2026-10-20 画面の統一

## 要求（tezzo の原文）

管理者・SES・EG 共通
・デザインに統一性を持たせる
一覧は管理者の画面。ただし「登録すると...」以下は外し、SES のように「管理者を登録」ボタンにする。
直すではなく文言を修正にする。
登録の際、メールは必須。
氏名で姓と名が違う行にある場合がある。同じ行にするように。
管理者から直接エンジニアは登録出来ない。
管理者がエンジニアを登録するのは SES 画面で SES を選択してから。

この節は上の P〜S（実装済み）を一部書き換える。ぶつかるところは、この節を正とする（P1 の「一覧の下の招待フォーム」、Q1・Q2 の「直す」、R3 の「メールが空なら会社だけ登録」、admin.md の「管理者もエンジニアを登録できます」）。

## 今のつくり（コードで確かめた）

- 管理者の一覧 `admin/admins.blade.php`：`x-card title="管理者"` の中に表、その下に「登録すると、招待メール（7日間有効のリンク）が届きます。」と登録フォーム（担当者名（姓）・（名）・企業名・メール。ボタン「登録して招待メールを送る」）。行の操作は `btn ghost small` の「直す」。
- SES の一覧 `admin/ses-index.blade.php`：カードなし。表の上に `<p><a class="btn small">SES を登録</a></p>`、行末は文字のリンク「詳細」。登録は別画面 `/admin/ses/new`（`ses-new.blade.php`：一言の説明 `p.muted` ＋ `x-card` にフォーム）。
- 「直す」がボタン・題名に出るのは2か所だけ：`admins.blade.php` の行の「直す」、`admin-edit.blade.php` の題名とカード見出し「管理者の情報を直す」。ほかの画面は「編集」（エンジニアの「編集」「スキルを編集」、案件の「編集」、SES 詳細の「会社情報の編集」、登録情報の「編集する」、題名「プロフィールの編集」「案件の編集」など）。
- メールの必須：管理者の登録（必須済み）、SES 担当者の追加 `/admin/ses/{id}/users`（必須済み）、はじめの設定 `/setup`（必須済み）、公開の申し込み `/apply`（必須済み）。**任意なのは** 管理者の SES 登録 `/admin/ses/new`（空なら会社だけ登録・warn）と、エンジニアの登録 `/engineers/new`（空なら招待なし。形の検査も無い）。
- 姓・名が別の行になる所（`grid2` は2列。前に1つだけの欄があると、姓が右・名が次の行の左にずれる。`/setup` は grid が無く縦に並ぶ）：
  - `admin/ses-form.blade.php`（SES の登録・SES 詳細の編集）：会社名 →担当者（姓）が1行目右、（名）が2行目左。
  - `ses/company.blade.php`（SES の会社情報）：代表者・ご担当者の両方がずれる。
  - `public/apply.blade.php`（参加のお申し込み）：代表者・ご担当者の両方がずれる。
  - `public/setup.blade.php`（はじめの設定）：縦に並ぶ。
  - `account/profile.blade.php`（登録情報の表示）：`dl` で「氏名（姓）」「氏名（名）」「ふりがな（姓）」「ふりがな（名）」が別の行。
  - ずれていない：`engineers/form`・`account/show`・`account/welcome`・`admin/admins`・`admin/admin-edit`・`admin/ses-show` の担当者追加。ただし `grid2` は幅 860px 以下で1列になるので、スマホでは全部縦になる。
- 管理者がエンジニアを登録する入口：メニュー「エンジニア」（`admin/engineers.blade.php` の「エンジニアを登録」→ `/engineers/new`）、ダッシュボードの開始カード「エンジニアを登録する（直属・SES の人）」（`AdminController::dashboard` 76 行目 → `/engineers/new`）、SES 詳細のエンジニアのカード（`/engineers/new?ses={id}`）。`/engineers/new`・`POST /engineers` は `role:admin,ses` の群。`EngineerController::create` は管理者に「所属」の選択（先頭に「管理者の直属（SES なし）」＋参加中の SES）を出す。`store` は管理者なら `ses_id`（参加中の SES か `direct`）を見る。
- 管理者の直属：`ses_companies.kind='direct'` の入れ物（`W::directSesId()` が初めての登録のときに作る）。SES の一覧・詳細には出ない（`sesIndex` は `kind='ses'` だけ、`sesOrFail` は直属を 404）。エンジニアの一覧には「直属」の印と「直属だけ」の絞り込みがある。tezzo が運営と EG の二役で入るときは、直属のエンジニアになる想定（`is_operator` の印）。
- 状態の言葉：管理者の一覧・ダッシュボードは「使用中」、SES 詳細の担当者・SES のエンジニア一覧・エンジニア詳細は「利用中」。
- 欄の名前：SES 詳細の担当者の追加だけ「メール」（ほかは「メールアドレス」）。
- ボタンの言葉：保存は「保存する」が多いが、`account/show` は「保存」「変更」。

## 仮定（tezzo が違えば直す。下の「tezzo に確かめること」と対応）

- h. 「修正」にするのは、**「直す」と書いたボタン・リンク・題名**（管理者の一覧の行と、管理者の修正画面）。ほかの画面の「編集」はこの回では変えない（確認 2）。文の中の「直してください」などの言い回しも変えない。
- i. メール必須にするのは「**新しく登録する**画面」すべて：管理者（済み）・SES（`/admin/ses/new`）・SES 担当者の追加（済み）・エンジニア（`/engineers/new`。管理者・SES とも）。修正の画面（SES 詳細の会社情報の編集・エンジニアのプロフィールの編集）は今のまま任意（確認 3）。エンジニアを必須にする理由：メールが無いとご本人がログインできず、「確認しました」が押せないので案件に応募できない（今の仕組みでは、メールの無いエンジニアは実際に使えない）。
- j. 管理者の直属のエンジニアは、SES の一覧の表の最後に「管理者の直属（SES なし）」の行を出し、その行の「エンジニアを登録」から登録する（＝「SES 画面で選んでから」と同じ形にする）。確認 1 の b。
- k. 管理者の登録画面（新規）にも、SES の登録画面と同じく一言の説明「登録すると、担当の方に招待メール（7日間有効のリンク）が届きます。」を出す。一覧からは外す（確認 4）。
- l. 登録情報の表示（`account/profile`）も、氏名・ふりがなを1行にする（「氏名」に「姓 名」）。

## 受け入れ条件

### U1. 管理者の一覧と登録の画面

- U1-1. `GET /admin/admins`：題名「管理者」。表の上に `btn small` の「**管理者を登録**」（`/admin/admins/new`）。表はカードで包まない（SES の一覧と同じ）。表の下に説明文・登録フォームは**出ない**（「登録すると」「登録して招待メールを送る」が画面に無い）。
- U1-2. 表の列は今のまま（名前・企業名・メール・状態・最終ログイン・操作）。行末は文字のリンク「**修正**」（`/admin/admins/{id}`）と、招待中の人だけ `btn ghost small` の「招待を送り直す」（今のまま）。
- U1-3. `GET /admin/admins/new`（管理者だけ。SES・エンジニアは 403）：題名「管理者の登録」。一言の説明（仮定 k）＋ `x-card` にフォーム。欄は 担当者名（姓）・担当者名（名）（同じ行）、企業名、メールアドレス（姓・企業名・メールに「必須」）。ボタン `btn`「登録して招待メールを送る」。
- U1-4. 登録 `POST /admin/admins` の検証・保存・メールは P2〜P5 のまま。bad のときは `/admin/admins/new` に戻り、入れた値が残る。ok のときは `/admin/admins`（一覧）に戻り「登録し、招待メールを送りました。」。
- U1-5. ダッシュボードの開始カードの「管理者を招待する」は `/admin/admins/new` へ（文言は「管理者を登録する」）。

### U2. 「直す」→「修正」

- U2-1. 管理者の一覧の行のリンクは「修正」（「直す」は出ない）。
- U2-2. `GET /admin/admins/{id}` の題名は「**管理者の情報の修正**」。カードの見出しは付けない（題名と重なるため）。中身・動き（Q3〜Q9）は今のまま。
- U2-3. アプリの画面（`resources/views`）のボタン・リンク・題名に「直す」が残らない（`grep '>直す<'`・`'を直す'` の題名が0件。ただし「送り直す」「作り直す」「スクリプトを直す」は別の意味なので対象外。「スクリプトを直す」は確認 2 の結果しだい）。
- U2-4. マニュアル（`admin.md`・`admin-steps.md`）の「直す」ボタンの説明を「修正」に。

### U3. 登録のときのメールは必須

- U3-1. `/admin/ses/new`：メールアドレスと担当者名（姓）に「必須」（`required`）。`POST /admin/ses` でメールが空 → bad「メールアドレスを入れてください（担当の方に招待メールを送ります）。」、`ses_companies`・`users`・`mail_outbox` は増えない（R3 の warn の道はなくす）。形・重複・担当者名の検査は今のまま（R4・R5）。
- U3-2. SES 詳細の「会社情報の編集」（同じ `ses-form` を使う）では、メール・担当者名は必須にしない（`isset($s->id)` のときは `required` を付けない。`sesUpdate` も今のまま）。
- U3-3. `/engineers/new`（管理者・SES どちらも）：メールアドレスに「必須」。hint「ご本人に招待メールが届きます」。`POST /engineers` でメールが空・形が正しくない → bad「メールアドレスを正しく入れてください。」、`engineers`・`users`・`mail_outbox` は増えない。重複は今のまま bad。
- U3-4. エンジニアのプロフィールの編集（`/engineers/{id}/edit`）は今のまま（メールは任意）。
- U3-5. 成功の文：SES 登録は「登録し、ご担当者に招待メールを送りました。」、エンジニア登録は「登録し、ご本人に招待メールを送りました。」（どちらも今の文）。

### U4. 姓・名は同じ行

- U4-1. 新しい CSS の部品 `.pair`（`public/css/park.css`）：2列（`grid-template-columns: 1fr 1fr; gap: 0 16px;`）で、外側が `grid2` のときは1行全部を使う（`grid-column: 1 / -1`）。**幅が狭くても2列のまま**（860px 以下の1列化の対象にしない）。
- U4-2. 姓・名の組はすべて `<div class="pair">…（姓）…（名）</div>` で包む。対象：`admin/ses-form`（担当者・代表者）、`admin/admins` の登録（新しい `admin-new`）、`admin/admin-edit`、`admin/ses-show` の担当者追加、`ses/company`（代表者・ご担当者）、`public/apply`（代表者・ご担当者）、`public/setup`、`engineers/form`（氏名・ふりがな）、`account/show`（氏名・ふりがな）、`account/welcome`（氏名・ふりがな）。今の `<div class="grid2">` で2つだけを包んでいる所も `.pair` に替える。
- U4-3. どの画面でも、HTML の中で「（姓）」の欄の直後に「（名）」の欄が来て、両方が同じ `.pair` の中にある（テストで確かめる）。
- U4-4. `account/profile`（表示）は「氏名」に `join_name(姓, 名)`、「ふりがな」に同じく1行で出す（「氏名（姓）」「氏名（名）」の行はなくす。空なら「—」）。
- U4-5. 欄の `name`（`name_sei` など）と、サーバーの処理は変えない。

### U5. 管理者は、SES を選んでからだけエンジニアを登録できる

- U5-1. 管理者のメニュー「エンジニア」の一覧（`/admin/engineers`）から「エンジニアを登録」のボタンをなくす。
- U5-2. ダッシュボードの開始カードの手順3は「エンジニアを登録する（SES を選んでから）」で、リンクは `/admin/ses`（ボタン「SES を選ぶ」）。
- U5-3. 管理者が `GET /engineers/new` を、`ses` が無い・参加中の SES の id でない・（仮定 j のとき）`direct` でない値で開く → `/admin/ses` へ戻し、warn「エンジニアは、SES の画面で SES を選んでから登録します。」。登録の画面は出さない。
- U5-4. 管理者が `GET /engineers/new?ses={参加中の SES の id}` を開く → 登録の画面。「所属」の選択は**出さない**。代わりに読むだけの「所属：（SES の名前）」と、`ses_id` の隠し欄。`?ses=direct` のときは「所属：管理者の直属（SES なし）」。
- U5-5. 管理者の `POST /engineers`：`ses_id` が参加中の SES（または `direct`）でなければ bad「所属と氏名を入れてください。」（今のまま）。保存後は `/engineers/{id}`（今のまま）。
- U5-6. SES 詳細（`/admin/ses/{id}`）のエンジニアのカードの「エンジニアを登録」（`/engineers/new?ses={id}`）は今のまま。停止中・承認待ちの SES の詳細では、このボタンを出さない（開いても U5-3 で戻る）。
- U5-7. SES の一覧（`/admin/ses`）の表の最後に「管理者の直属（SES なし）」の行（仮定 j）：会社の欄に「管理者の直属（SES なし）」、担当・状態・稼働中・参加日は「—」、エンジニアの欄はその人数、行末は「エンジニアを登録」（`/engineers/new?ses=direct`）と「一覧」（`/admin/engineers?ses=direct`）。直属の入れ物がまだ無くても行は出す（人数 0）。この行は SES の数（ダッシュボードの数など）には入れない。
- U5-8. SES の担当者の画面（`/ses/engineers` の「エンジニアを登録」・`/engineers/new`）は今のまま（所属は自社に決まる）。

### U6. デザインの統一（この回で直す範囲）

やりすぎない。今回触る画面と、すぐ揃えられる言葉だけ。

- U6-1. 一覧の画面の形：題名 → 表の上に `<p><a class="btn small">〇〇を登録</a></p>` → `x-table`（カードで包まない）。行末の「詳細」「修正」は文字のリンク、POST の操作（送り直す等）は `btn ghost small`。今回は管理者の一覧をこの形にする（SES・案件・SES のエンジニア一覧は既にこの形）。
- U6-2. 登録の画面の形：題名「〇〇の登録」 → `p.muted` の一言 → `x-card`（見出しなし）にフォーム → 主ボタンは `btn`。招待メールが出る登録のボタンは「**登録して招待メールを送る**」にそろえる（管理者・SES・エンジニアの新規。SES 登録の `ses-form` は新規のときだけこの文、編集のときは「保存する」。エンジニアの新規も同じ）。
- U6-3. 修正の画面の形：題名「〇〇の修正」（または今の「〇〇の編集」。確認 2）、カードの見出しは題名と重ねない、主ボタン「保存する」＋必要なら `btn ghost` の「〇〇の一覧に戻る」。
- U6-4. 状態の言葉は「使用中／招待中／停止」にそろえる（「利用中」→「使用中」：`admin/ses-show`・`SesController::engineers`・`engineers/show`）。
- U6-5. 欄の名前は「メールアドレス」にそろえる（`admin/ses-show` の担当者追加の「メール」）。表の見出しの「メール」は短いのでそのまま。
- U6-6. ボタンの言葉：`account/show` の「保存」→「保存する」、「変更」→「変更する」。
- U6-7. 今回は直さない（記録だけ。必要なら次の回）：`admin/engineers` の絞り込みとボタンの並び、ダッシュボードのカードの順、SES 詳細の担当者の追加フォームを別画面にするか、「編集」全体の言葉。

### U7. マニュアル

- U7-1. `resources/manuals/admin.md`・`admin-steps.md`：管理者は「管理者」→「管理者を登録」で別画面に進む／一覧の「修正」／SES の登録はメール・担当者名が必須（会社だけの登録はできない）／エンジニアは「SES」→ その SES の「詳細」→「エンジニアを登録」、直属は SES の一覧の「管理者の直属（SES なし）」の行から（「管理者もエンジニアを登録できます（所属を選ぶ）」「エンジニアを登録の所属で直属を選ぶ」の記述は消す）。開始カードの説明も直す。
- U7-2. `ses.md`・`ses-steps.md`：エンジニアの登録でメールアドレスが必須になったことを書く。
- U7-3. `engineer.md`・`engineer-steps.md`：画面の流れが変わらないので、言葉（「利用中」など）が出ていれば合わせるだけ。
- U7-4. 41_Park-Project の `04_Manuals` の写しは JOE が写し直す。

## 変更するファイル

| ファイル | 変更 |
|---|---|
| `routes/web.php` | admin 群に `Route::get('/admins/new', [Adm::class, 'adminNew']);`（`{id}` は数字だけなので、どこに置いてもぶつからない） |
| `app/Http/Controllers/AdminController.php` | `adminNew()` を足す（`view('admin.admin-new')`）。`admins()` の bad は今の `back()` のままで `/admin/admins/new` に戻る。`sesStore()`：メール空を bad に（U3-1）、warn の道を消す。`sesIndex()`：直属の行のための `direct`（`kind='direct'` の行と人数。無ければ人数 0）を view に渡す。`dashboard()` の `start`：手順1を `/admin/admins/new`・「管理者を登録する」、手順3を `/admin/ses`・「SES を選ぶ」（U5-2） |
| `app/Http/Controllers/EngineerController.php` | `create()`：管理者は `ses` を確かめ、だめなら `redirect('/admin/ses')` ＋ warn（U5-3）。よければ `sesList` の代わりに `sesFixed`（id と表示名）を渡す。`store()`：メール必須と形の検査（U3-3。`filter_var`）。管理者の `ses_id` の検査は今のまま |
| `app/Http/Controllers/SesController.php` | 42 行目「利用中」→「使用中」 |
| `public/css/park.css` | `.pair` を足す（U4-1） |
| `resources/views/admin/admins.blade.php` | U1-1・U1-2 の形に（カード・説明文・フォームを外し、ボタンを上に） |
| `resources/views/admin/admin-new.blade.php`（新規） | U1-3 |
| `resources/views/admin/admin-edit.blade.php` | 題名・見出し（U2-2）、`.pair` |
| `resources/views/admin/ses-index.blade.php` | 直属の行（U5-7） |
| `resources/views/admin/ses-form.blade.php` | `.pair`（担当者・代表者）。新規のときだけ担当者名（姓）・メールに `required`、ボタンの文（U6-2）。並び：会社名（`.span2` 相当の1行）→担当者（pair）→メールアドレス→電話番号→代表者（pair）→所在地・人数・得意な分野 |
| `resources/views/admin/ses-new.blade.php` | 一言の説明を「登録すると参加中になり、担当の方に招待メール（7日間有効のリンク）が届きます。」に（形は今のまま） |
| `resources/views/admin/ses-show.blade.php` | 「利用中」→「使用中」、担当者追加の `.pair` と「メールアドレス」、エンジニアを登録のボタンは `status==='active'` のときだけ |
| `resources/views/admin/engineers.blade.php` | 「エンジニアを登録」を消す |
| `resources/views/engineers/form.blade.php` | `.pair`、管理者の「所属」を読むだけの表示＋隠し欄に（U5-4）、新規のメールに `required`、新規のボタン「登録して招待メールを送る」、説明文を「メールアドレスを入れて登録すると…」から「登録すると、ご本人に招待メールが届き…」に |
| `resources/views/engineers/show.blade.php` | 「利用中」→「使用中」 |
| `resources/views/ses/company.blade.php`・`public/apply.blade.php`・`public/setup.blade.php`・`account/show.blade.php`・`account/welcome.blade.php` | `.pair`（U4-2）。`account/show` はボタンの文（U6-6） |
| `resources/views/account/profile.blade.php` | 氏名・ふりがなを1行に（U4-4） |
| `resources/manuals/admin.md`・`admin-steps.md`・`ses.md`・`ses-steps.md`（必要なら `engineer*.md`） | U7 |
| テスト | 下の「既存のテストの直し」と新しい `tests/Feature/UiUnifyTest.php` |

マイグレーションは要らない。外部ライブラリも足さない。

## 作業の手順

1. `park.css` に `.pair` を足し、U4-2 の画面を順に `.pair` で包む。`account/profile` を1行表示に。
2. 管理者の一覧を U1 の形に、`admin-new.blade.php` とルート・`adminNew()` を足す。`admin-edit` の題名。ダッシュボードの開始カード。
3. `sesStore()` のメール必須、`ses-form` の `required`（新規だけ）とボタンの文・並び、`ses-new` の一言。
4. `EngineerController::create/store`（U5-3・U5-4・U3-3）、`engineers/form`、`admin/engineers` のボタンを消す、`ses-show` のボタンの条件。
5. `sesIndex()` と `ses-index` に直属の行（確認 1 が b のとき。a なら行は作らず、`direct` を受けない。c なら別の作業になるので、tezzo の返事を待つ）。
6. 言葉：「利用中」→「使用中」、「メール」→「メールアドレス」（欄）、「保存」「変更」。
7. 既存のテストを直し、`UiUnifyTest` を足す。`php artisan test` が全部通ること。
8. マニュアル（U7）。写しは JOE。

公開版への反映は、push のあと JOE が `park-deploy.sh` で行う（常時了承の範囲。マイグレーションなし）。テストのメールは `mail_outbox` に入るだけで送らない。データの削除はしない（既にある「メールの無い SES・エンジニア」はそのまま残す）。

## 既存のテストの直し（わかっている所）

- `ProfileAndDirectTest` 214 行目：「登録して招待メールを送る」は `/admin/admins/new` で見る。`/admin/admins` では `assertDontSee('登録すると')`。
- `ProfileAndDirectTest` 338〜343 行目・`NameSplitTest` 192 行目：管理者の `GET /engineers/new` は `/engineers/new?ses=direct`（または SES の id）にする。「管理者の直属（SES なし）」の選択肢の確かめは「所属：管理者の直属（SES なし）」の表示に置き換える。
- `ParkTest` 48 行目・`EmptyStateTest` 125 行目の、管理者で開く画面の一覧の `/engineers/new` は `?ses=direct` を付ける（付けないと 302 になる）。
- `EmptyStateTest` 59〜60 行目：開始カードの href の順は `/admin/admins/new`・`/admin/ses/new`・`/admin/ses`・`/cases/new`。文言も合わせる。
- エンジニアをメールなしで登録している所（`ProfileAndDirectTest` 369、`NameSplitTest` 170・247・314、`EmptyStateTest` 150・250 など）に `email`（`@park.example` か `@example.com`）を足す。もとから bad を確かめている所（空の名前・所属なし・長すぎ）はそのままでよい（bad の理由がメールにならないよう、メールも入れておく）。
- SES をメールなしで登録している所（`EmptyStateTest` の R3・137・244・257 行目付近、`AdminAccountTest` の R3）：R3 は「メール空 → bad、何も増えない」に書き換え。ほかは `email`・`contact_sei` を足す。
- `AdminAccountTest` 189・191 行目：「直す」「管理者の情報を直す」→「修正」「管理者の情報の修正」。
- 「利用中」を期待しているテストがあれば「使用中」に。

## テスト（新しく `tests/Feature/UiUnifyTest.php`。用意は `AdminAccountTest` と同じ）

| 条件 | 手順 | 確かめること |
|---|---|---|
| U1-1 | 管理者で `GET /admin/admins` | 200。「管理者を登録」と `href=".../admin/admins/new"` が出る。「登録すると」「登録して招待メールを送る」が出ない |
| U1-2・U2-1 | 同じ画面 | 各行に `href=".../admin/admins/{id}"` で文字「修正」。「>直す<」が無い |
| U1-3 | `GET /admin/admins/new`／SES・エンジニアで同じ URL | 200 で「管理者の登録」「登録して招待メールを送る」「必須」。SES・エンジニアは 403 |
| U1-4 | `/admin/admins/new` から（`from()`）`POST /admin/admins` を企業名なしで／正しく | 前者は `/admin/admins/new` へ戻り bad、`users` 増えない。後者は `/admin/admins` へ、invite +1 |
| U1-5・U5-2 | データが空の状態で `GET /admin` | 開始カードの href が `/admin/admins/new`・`/admin/ses/new`・`/admin/ses`・`/cases/new` の順 |
| U2-2 | `GET /admin/admins/{他人の id}` | 「管理者の情報の修正」が出る。「直す」が出ない |
| U3-1 | `POST /admin/ses` に会社名・担当者名だけ（メール空） | bad。`ses_companies`・`users`・`mail_outbox` 増えない。`GET /admin/ses/new` のメール欄に `required` |
| U3-2 | SES 詳細で会社情報をメール空のまま保存 | ok（今のまま）。詳細の編集フォームのメール欄に `required` が無い |
| U3-3 | 管理者で `?ses={SES の id}` から、SES 担当者で、それぞれ `POST /engineers` をメール空／`email=abc` で | どれも bad、`engineers`・`users`・`mail_outbox` 増えない |
| U3-3 正常 | 同じくメールありで | `engineers` +1、`users` role=eg +1、invite +1、「登録し、ご本人に招待メールを送りました。」 |
| U3-4 | エンジニアの編集でメールを空にして保存 | ok（今のまま） |
| U4-3 | 各画面（`/admin/admins/new`・`/admin/admins/{id}`・`/admin/ses/new`・`/admin/ses/{id}`・`/engineers/new?ses=..`・`/engineers/{id}/edit`・`/account`・`/welcome`（未登録の人）・SES で `/ses/company`・ログアウトで `/apply`・管理者が居ないときの `/setup`）を開く | HTML に `class="pair"` があり、`name="…_sei"` の直後（同じ `.pair` の中）に `name="…_mei"` がある。正規表現で `<div class="pair">.*?name="X_sei".*?name="X_mei".*?</div>` を確かめる |
| U4-4 | `GET /account/profile`（登録済みの管理者） | 「氏名」の行に「姓 名」。「氏名（姓）」「氏名（名）」が無い |
| U5-1 | 管理者で `GET /admin/engineers` | `href=".../engineers/new"` が無い |
| U5-3 | 管理者で `GET /engineers/new`・`?ses=99999`・`?ses={停止中の SES}`・`?ses={承認待ちの SES}` | どれも `/admin/ses` へ 302、flash warn |
| U5-4 | 管理者で `GET /engineers/new?ses={参加中の SES}` | 200。「所属：その SES の名前」、`name="ses_id"` の隠し欄（その id）。`<select name="ses_id"` が無い |
| U5-4 直属 | `GET /engineers/new?ses=direct` | 「所属：管理者の直属（SES なし）」 |
| U5-5 | 管理者で `POST /engineers` に `ses_id` なし／停止中の SES | bad、増えない（今のまま） |
| U5-6 | 参加中・停止中の SES の詳細 | 参加中は `/engineers/new?ses={id}` のボタンあり、停止中は無い |
| U5-7 | 管理者で `GET /admin/ses` | 表の最後に「管理者の直属（SES なし）」、`href=".../engineers/new?ses=direct"`。直属のエンジニアがいれば人数が出る |
| U5-8 | SES 担当者で `GET /engineers/new` | 200（今のまま。所属の表示・選択なし） |
| U6-4 | SES 詳細の担当者（パスワード設定済み）・`/ses/engineers`・エンジニア詳細 | 「使用中」が出て「利用中」が出ない |
| U6-5 | SES 詳細の担当者追加 | 欄の名前が「メールアドレス」 |

## tezzo に確かめること（実装の前に返事がほしいもの）

**1** 管理者の直属のエンジニアの登録（今は「エンジニアを登録」の所属で「管理者の直属」を選ぶ。直属は SES の一覧・詳細に出ない入れ物なので、「SES を選んでから」だけにすると直属を登録する入口が無くなる。tezzo が運営と EG の二役で入るときも直属の想定）
a. 直属は新しく登録しない（今いる直属はそのまま）
b. SES の一覧の最後に「管理者の直属（SES なし）」の行を出し、そこから登録する（この計画の仮定。おすすめ）
c. 直属にも SES と同じ詳細画面を作り、そこから登録する（作業が大きい）

**2** 「修正」にする範囲（「直す」はボタン・題名に2か所だけ。ほかの画面は「編集」が10か所ほど。統一するなら「編集」も「修正」にそろえるか）
a. 「直す」だけ「修正」に（この計画の仮定）
b. 「編集」も全部「修正」に（エンジニア・案件・スキル・会社情報・登録情報。マニュアルも広く直る）
あわせて、面談の「スクリプトを直す」も「スクリプトを修正」にするか（a のままなら変えない）。

**3** メールを必須にする範囲（今、任意なのは SES の登録とエンジニアの登録。エンジニアはメールが無いとログインも応募もできない）
a. 新しく登録する画面だけ必須（SES・エンジニアとも。修正の画面は任意のまま。この計画の仮定）
b. 修正の画面でも空にできないようにする（今メールが無い SES・エンジニアは、保存のときにメールを入れないと保存できなくなる）
c. エンジニアは今のまま任意にする（SES の登録だけ必須）

**4** 「登録すると、招待メール（7日間有効のリンク）が届きます。」の一言（一覧からは外す）
a. 新しい「管理者の登録」画面に出す（SES の登録画面と同じ形。この計画の仮定）
b. どこにも出さない

**5** 登録情報の表示（`/account/profile`）の氏名・ふりがな（入力の欄ではなく、表示の行が「氏名（姓）」「氏名（名）」と別の行になっている）
a. 「氏名」1行に「姓 名」で出す（この計画の仮定）
b. 今のまま（入力の欄だけ同じ行にする）

---

# 2026-10-20 画面の統一（2）

上の「画面の統一」（U1〜U7）は実装済み（未コミット）。tezzo の回答と追加の要求を受けて、続きを決める。**上の節（と P〜S）とぶつかるところは、この節を正とする**。主に書き換わるところ：U1-2・U2・U6-3 の「修正」（→ 一覧「詳細」→ 詳細画面 → 「編集」）、仮定 j・U5-3〜U5-5・U5-7・U7-1 の「管理者の直属」（→ 新しく登録する入口をなくす）、上の「テスト」の U5-4 直属・U5-7 の行。

## tezzo の回答（上の確認 1〜5）

- 1：管理者の直属は無い。「管理者の直属（SES なし）」の行・`?ses=direct`・直属の登録の入口は要らない。エンジニアは必ず SES を選んでから登録する。今ある直属のデータは消さず、表示だけ無理なく扱う。
- 2：「修正」ではなく、ほかと同じ「一覧 → 詳細 → 編集」にする。一覧の行は「詳細」、詳細画面（新しく `/admin/admins/{id}`）から「編集」へ、編集画面は今の修正画面。SES・エンジニア・案件と文言・ボタンの置き方をそろえる。「編集」がアプリの統一の言葉。
- 3〜5：上の仮定どおり（3＝新しく登録する画面だけメール必須、4＝招待メールの一言は登録画面、5＝登録情報の氏名は1行）。

追加の要求：管理者・SES・エンジニアのすべてに郵便番号・住所を足す（登録・編集・詳細に出す。任意。表示は「〒123-4567 住所」。形の検査は7桁の数字・ハイフン可、保存の形はそろえる）。管理者は項目が少ないので、実際に使うものを少し足す。住所は個人情報なので、管理者以外に他人の住所が見えないようにする。

## 今のつくり（コードで確かめた。2026-10-20 時点の未コミットの状態）

- 直属：`EngineerController::create` が `?ses=direct` を受けて「所属：管理者の直属（SES なし）」を出す。`store` は `ses_id=direct` で `W::directSesId()`（無ければ作る）。`AdminController::sesIndex` が `$direct`（人数）を渡し、`admin/ses-index.blade.php` 6 行目に直属の行（「エンジニアを登録」「一覧」）。`admin/engineers.blade.php` に「直属」の印（`ses_kind === 'direct'`）と「直属だけ」ボタン（`?ses=direct`）。`W::directSesId()` は `park:purge-samples --move-kept-to-direct`（`SamplePurge`）でも使う。直属の人の知らせは管理者に届く、`user_label` は会社名を付けない、などの扱いは残っている。
- 管理者：ルートは `GET /admin/admins/{id}` → `adminEdit`（`admin/admin-edit.blade.php`、題名「管理者の情報の修正」、ボタン「保存する」「管理者の一覧に戻る」）、`POST /admin/admins/{id}` → `adminUpdate`（ok は `/admin/admins/{id}`、bad は `back()`）。一覧の行は「修正」。詳細画面は無い。
- ほかの流れ：SES は一覧「詳細」→ `/admin/ses/{id}`（題名は会社名。編集は同じ画面のカード「会社情報の編集」）。エンジニアは一覧「詳細」→ `/engineers/{id}`（題名は氏名。カード「基本情報」の右上に `btn ghost small`「編集」）→ `/engineers/{id}/edit`（題名「プロフィールの編集」）。案件は詳細の `btn ghost small`「編集」→ 題名「案件の編集：案件名」。
- 管理者の列（`users`）：`name`・`name_sei/mei`・`kana`・`kana_sei/mei`・`phone`（30）・`position`（画面の名前は「所属・役職」）・`company_name`（100）がある。`phone`・`position`・`kana` は本人の `/welcome`・`/account` でだけ入れられ、ほかの管理者の画面（登録・修正）には出ていない。
- 管理者の情報の使われ方：メールの差出人の名前と署名は `company_name`（`admin_sender`。無ければ設定の `operator_name`）。宛名は「企業名 氏名」（`user_label`）。請求書（`pipeline/invoice.blade.php`）の発行者は設定の `operator_name` だけで、管理者個人の情報は使っていない。電話・所属・役職・住所を使っている所は無い。
- 住所の列：`engineers` には `postal_code`（10）・`address`・`address_kana` がある（詳しいプロフィール `/engineers/{id}/profile` で入れ、詳細の「詳しいプロフィール」に「〒… 住所」で出る。履歴書の Excel にも出る。名前を伏せた業務経歴書には出ない）。`ses_companies` は `address`（画面の名前は「所在地」）だけで郵便番号が無い。`users` には住所の列が無い。郵便番号の形の検査・そろえはどこにも無い。
- 見える範囲：管理者の画面は `role:admin`。エンジニアの詳細・詳しいプロフィール・書類は `Perms::engineer`（管理者・所属の SES・本人）。SES の会社情報 `/ses/company` は自社だけ。
- 残り（上の U6-4 の取りこぼし）：`engineers/show.blade.php` 21 行目と `SesController.php` 42 行目に「利用中」が残っている。`UiUnifyTest::test_u6_wording` と食い違うので、この回で「使用中」に直す。

## 仮定（tezzo が違えば直す。最後の「確かめること」と対応）

- m. 今ある直属のデータ（`kind='direct'` の入れ物とそのエンジニア）は消さず、動かさない。エンジニアの一覧の「直属」の印は残し、「直属だけ」のボタンは直属の人が1人以上いるときだけ出す。詳細の「所属 SES」は今のまま（「管理者の直属」）。直属の人の編集・応募・選考・成約・知らせはこれまでどおり。SES へ移す画面はこの回では作らない（確認 3）。`W::directSesId()` と見本の片付けのオプションは残す。
- n. 管理者の詳細の題名はその人の氏名（SES・エンジニアと同じ）。編集画面の題名は「**管理者の編集：氏名**」（案件の「案件の編集：案件名」と同じ形）。
- o. 管理者に足す項目は、すでに列がある **所属・役職**（`position`）・**電話番号**（`phone`）と、新しい **郵便番号・住所**。部署と役職は分けない（列を増やさない。画面の名前は今の「所属・役職」のまま）。ふりがなは本人が `/account` で入れるので、ほかの管理者の画面には足さない。この回は保存して見せるだけで、メールの署名・請求書には使わない（確認 2）。
- p. 住所の見える範囲：管理者はみんなの分、本人は自分の分。SES の担当者は**自社の会社の所在地**と、**自社のエンジニアの住所**が見える（履歴書の書き出しに住所が要り、今も「見られるのは、本人・所属の SES・管理者だけ」と画面に書いてあるため）。ほかの SES・ほかのエンジニア・管理者の住所は見えない（確認 1）。
- q. 郵便番号の保存の形は `123-4567`（3桁＋ハイフン＋4桁）。今あるエンジニアの郵便番号（ハイフン付きが多い）をそのまま使え、履歴書の書き出しも直さずに済む。今あるデータは書き換えない（マイグレーションでそろえない）。表示のときにそろえ、次に保存したときに保存の形になる。
- r. 住所は200文字まで。会社の住所の画面の名前は今の「所在地」、エンジニアは今の「現住所」、管理者は「住所」。
- s. 最初の1人の `/setup` と、`park:invite-admin` には住所を足さない（あとで `/welcome`・`/account` で入れる）。

## 受け入れ条件

### V1. 管理者の直属は新しく作らない

- V1-1. 管理者が `GET /engineers/new?ses=direct` を開く → `/admin/ses` へ 302、warn「エンジニアは、SES の画面で SES を選んでから登録します。」（ほかのだめな値と同じ。上の U5-3 から `direct` の例外を消す）。
- V1-2. 管理者の `POST /engineers` に `ses_id=direct` → bad「所属と氏名を入れてください。」。`engineers`・`users`・`mail_outbox` は増えず、直属の入れ物（`ses_companies` の `kind='direct'`）も作られない。
- V1-3. SES の一覧（`/admin/ses`）に「管理者の直属（SES なし）」の行が出ない。`?ses=direct` へのリンクも無い。SES が0件のときは今の「SES はまだありません。…」の文だけ（`count` は `$list->count()` に戻す）。
- V1-4. 今ある直属のエンジニア（テストでは `W::createEngineer(W::directSesId(), …)` で作る）は、エンジニアの一覧に「直属」の印つきで出る。詳細・編集・詳しいプロフィールが今までどおり開けて保存できる。直属の人がいるときだけ「直属だけ」のボタンが出て、`/admin/engineers?ses=direct` で絞り込める。直属の人がいないときはボタンが出ない（URL を直接開いても 200 で0件）。
- V1-5. ダッシュボード・分析・案件の登録・請求に「管理者の直属」が出ない（今のまま）。
- V1-6. 見本の片付け（`park:purge-samples --move-kept-to-direct`）は今のまま動く（`W::directSesId()` は残す）。

### V2. 管理者：一覧 → 詳細 → 編集

- V2-1. `GET /admin/admins`：行末は文字のリンク「**詳細**」（`/admin/admins/{id}`）と、招待中の人だけ `btn ghost small`「招待を送り直す」（今のまま）。「修正」「直す」は出ない。
- V2-2. `GET /admin/admins/{id}`（新しい詳細画面。管理者だけ。SES・エンジニアは 403。管理者でない id・無い id は 404）：題名はその人の氏名。`x-card title="基本情報"` の右上（`x-slot:actions`）に `btn ghost small`「**編集**」（`/admin/admins/{id}/edit`）と、招待中の人だけ「招待を送り直す」（`x-post`、`cls="ghost small"`）。中身は `dl`：状態（使用中／招待中／停止）・氏名・企業名・所属・役職・電話番号・住所（「〒123-4567 住所」。空なら「—」）・メールアドレス・最終ログイン。自分の詳細も開ける。
- V2-3. `GET /admin/admins/{id}/edit`（今の修正画面を移す）：題名「**管理者の編集：氏名**」。欄は 氏名（姓・名。同じ行）・企業名・所属・役職・電話番号・郵便番号・住所・メールアドレス（メールの扱いは Q4 のまま）。ボタン `btn`「保存する」と `btn ghost`「戻る」（`/admin/admins/{id}`）。権限・404 は V2-2 と同じ。
- V2-4. 保存 `POST /admin/admins/{id}`（URL は今のまま）：ok は詳細 `/admin/admins/{id}` へ「保存しました。」。bad は編集画面へ戻り、入れた値が残る。Q3〜Q8 は今のまま（足した項目も「何も変えなければ保存しました。だけ・監査ログなし」に入れる。監査ログには変えた項目の名前だけを足し、電話・住所の中身は書かない）。
- V2-5. 「修正」は画面のボタン・リンク・題名から無くなる（`resources/views` に `>修正<`・`の修正` が0件）。マニュアルの「修正」も「詳細」→「編集」の説明に直す。
- V2-6. 招待の送り直し（`POST /admin/admins/{id}/reinvite`）は、一覧から押したら一覧へ、詳細から押したら詳細へ戻る（`back()`。来た所が分からないときは一覧へ）。

### V3. 管理者の項目を増やす

- V3-1. 登録 `/admin/admins/new`：今の欄（担当者名・企業名・メールアドレス）の下に、任意の **所属・役職**（`position`、100文字まで）・**電話番号**（`phone`、30文字まで、数字・`+`・`-`・`(`・`)`・空白だけ。`/account` と同じ決まり）・**郵便番号**・**住所** を足す。
- V3-2. 登録で入れた値は `users` の行に入る（`W::invite` が新しく管理者の行を作るときだけ。すでにある行への送り直しでは書き換えない）。
- V3-3. 編集（V2-3）でも同じ4つを直せる。検査も同じ。だめなときは bad、何も保存しない。
- V3-4. 本人の `/welcome`・`/account`（管理者のとき）に郵便番号・住所を足す（所属・役職と電話番号はすでにある）。`/account/profile` に「住所」の行（管理者のときだけ）。SES・エンジニアの `/welcome`・`/account` には住所の欄を出さない（SES は会社の所在地、エンジニアは現住所で持つため）。
- V3-5. メールの署名・差出人・宛名、請求書は今のまま（足した項目を使わない）。

### V4. 郵便番号・住所（管理者・SES・エンジニア共通）

- V4-1. 形：前後と途中の空白を取り、全角の数字は半角に、全角のハイフン・長音（`－` `ー` `‐` `−`）は `-` にし、頭の「〒」は取る。そのうえで「3桁の数字＋（ハイフンあり・なし）＋4桁の数字」なら `123-4567` の形で保存。空なら null（任意）。それ以外（6桁・8桁・文字が入る・`12-34567` など）は bad「郵便番号は7桁の数字で入れてください（例：123-4567）。」で、何も保存しない。
- V4-2. 住所：前後の空白を取り、200文字まで（超えたら bad「住所は200文字までにしてください。」。会社は「所在地は200文字までにしてください。」、エンジニアは「現住所は200文字までにしてください。」）。空なら null。
- V4-3. 表示：郵便番号と住所の両方 →「〒123-4567 東京都…」、郵便番号だけ →「〒123-4567」、住所だけ → 住所、どちらも無い →「—」。保存の形でない古い郵便番号（例：`1460082`）も、そろえられれば `〒146-0082` と出し、そろえられなければ入っているとおりに出す。
- V4-4. 管理者：V3 のとおり（登録・編集・詳細・本人の画面）。
- V4-5. SES：管理者の SES の登録 `/admin/ses/new`・SES の詳細の「会社情報の編集」・SES の会社情報 `/ses/company`・公開の申し込み `/apply` に「郵便番号」を「所在地」の前に足す。SES の詳細（`/admin/ses/{id}`）の「所在地」は V4-3 の形で出す。保存は `W::applySes`・`sesUpdate`・`SesController::saveCompany` で同じ検査（bad は `withInput` で戻る）。
- V4-6. エンジニア：登録 `/engineers/new`（管理者・SES とも）に任意の「郵便番号」「現住所」を足す。編集は今の詳しいプロフィール（`/engineers/{id}/profile`。すでに欄がある）で、ここにも同じ検査を入れる（placeholder は `123-4567`）。`/engineers/{id}/edit`（基本情報の編集）には足さない（同じ列を2か所で直さないため）。詳細の「現住所」は V4-3 の形（今の表示を共通の関数に替える）。
- V4-7. 今あるデータは書き換えない。マイグレーションは列を足すだけ。

### V5. 住所の見える範囲（個人情報）

- V5-1. 管理者の住所（`users.postal_code`・`address`）が出るのは、管理者の画面（`/admin/admins/{id}`・`/edit`。`role:admin`）と、本人の `/account`・`/account/profile` だけ。SES・エンジニアが `/admin/admins/{id}` を開くと 403。管理者の一覧（`/admin/admins`）とダッシュボードの「招待の状況」にも住所は出さない。
- V5-2. SES の会社の郵便番号・所在地が出るのは、管理者の SES の詳細と、その SES の担当者の `/ses/company` だけ。ほかの SES の担当者・エンジニアの画面には出ない。SES の一覧にも出さない。
- V5-3. エンジニアの郵便番号・現住所が出るのは、`Perms::engineer` が通る人（管理者・所属の SES・本人）の詳細・詳しいプロフィール・履歴書（今のまま）。ほかの SES の担当者・ほかのエンジニアは、詳細・詳しいプロフィールとも 403（今のまま。テストで確かめる）。エンジニアの一覧（管理者・SES）・スキルシート・名前を伏せた業務経歴書・案件・応募・メールの本文には出ない。
- V5-4. メールの本文・署名、ベルの知らせ、監査ログに住所・郵便番号の中身を書かない。

### V6. 文言の残り

- V6-1. 「利用中」→「使用中」：`engineers/show.blade.php` 21 行目、`SesController.php` 42 行目。

## 仕様

### マイグレーション（新規 `database/migrations/2026_10_20_000002_add_postal_address.php`）

```php
// 郵便番号・住所（管理者は users、SES は ses_companies。エンジニアは engineers に既にある）（2026-10-20）
up():
  Schema::table('users', function (Blueprint $t) {
      $t->string('postal_code', 8)->nullable()->after('company_name');
      $t->string('address', 200)->nullable()->after('postal_code');
  });
  Schema::table('ses_companies', function (Blueprint $t) {
      $t->string('postal_code', 8)->nullable()->after('phone');
  });
down():
  users の postal_code・address、ses_companies の postal_code を dropColumn
```

データは書き換えない（仮定 q）。公開版では `park-deploy.sh` の migrate で列が足される（常時了承の範囲。列を足すだけで、消すものは無い）。

### 共通の関数（`app/helpers.php`）

- `postal_norm($v): string|null|false`：V4-1。空 → null、正しい → `'123-4567'`、だめ → false。
- `postal_address(?string $postal, ?string $address): string`：V4-3。`postal_norm` でそろえられれば `〒123-4567`、そろえられなければ入っているとおり。

### 手続き（`app/Services/Workflow.php`）

- 新しく `addressCols(array $d, string $label = '住所'): array`：戻り値は `['ok' => ['postal_code' => …, 'address' => …]]` か `['bad' => 'メッセージ']`。`$d` に `postal_code`・`address` のキーが無ければ、その列は返さない（送られてこなかった画面では今の値のまま）。SES・エンジニア・管理者の保存はみなこれを使う。
- `invite()`：`role === 'admin'` で新しく行を作るときだけ、`position`・`phone`・`postal_code`・`address` も入れる（呼ぶ側で検査済みの値を渡す）。
- `updateAdmin()`：`position`・`phone`・`postal_code`・`address` を足す。キーが来たものだけ比べて、変わったものだけ更新。検査（V3-3・V4-1・V4-2）は保存の前に全部見て、1つでもだめなら何も保存しない。監査ログ（Q7）には「所属・役職」「電話番号」「住所」と項目の名前だけを足す（中身は書かない）。
- `saveProfile()`（`/welcome`・`/account`）：管理者のときだけ `postal_code`・`address` を保存。
- `applySes()`：`postal_code` を足す（`addressCols` を通した値）。

### コントローラ

- `routes/web.php`（admin 群）：`Route::get('/admins/{id}', [Adm::class, 'adminShow']);`、`Route::get('/admins/{id}/edit', [Adm::class, 'adminEdit']);`。`POST /admins/{id}` と `/reinvite` は今のまま。
- `AdminController`：
  - `adminShow(int $id)`（新規）：管理者でなければ 404。`view('admin.admin-show', ['u' => $u])`。
  - `adminEdit()`：今のまま（view だけ）。
  - `adminUpdate()`：ok の戻り先は今の `/admin/admins/$id`（＝詳細）のまま。`only([...])` に `position`・`phone`・`postal_code`・`address` を足す。
  - `admins()`：電話の形・所属・役職の長さ・`addressCols` を見てから `W::invite`。
  - `reinviteAdmin()`：戻り先を `back()`（V2-6）。
  - `sesIndex()`：`$direct` を消す。
  - `engineers()`：`hasDirect`（直属のエンジニアがいるか）を view に渡す。
  - `sesStore()`・`sesUpdate()`：`addressCols($r->all(), '所在地')` を通す。bad は `back()->withInput()`。
- `EngineerController`：
  - `create()`：`$ses === 'direct'` の分岐を消す（V1-1）。
  - `store()`：`ses_id === 'direct'` の分岐を消し、参加中の SES の検査だけに（V1-2）。`addressCols($body, '現住所')` を通す。
  - `saveProfile()`：`addressCols` を通し、bad は `back()->withInput()`。
- `SesController::saveCompany()`：`addressCols($r->all(), '所在地')`。42 行目の「利用中」→「使用中」。
- `PublicController::apply()`：`addressCols($r->all(), '所在地')`。
- `AccountController`：管理者のときだけ、検証の前に `postal_code` を `postal_norm` した値（だめなら元の文字）に入れ替え、決まりに `'postal_code' => ['nullable', 'regex:/^\d{3}-\d{4}$/']`、`'address' => 'nullable|max:200'` を足す（メッセージは V4-1・V4-2 の文。項目名は「郵便番号」「住所」）。

### 画面

- `admin/admins.blade.php`：行のリンクの文字を「詳細」に。
- `admin/admin-show.blade.php`（新規）：V2-2。
- `admin/admin-edit.blade.php`：題名「管理者の編集：{{ $u->name }}」。欄を足す（V2-3）。郵便番号と住所は `.pair` で同じ行にしない（住所は長いので1行全部）。戻るは詳細へ。
- `admin/admin-new.blade.php`：所属・役職・電話番号・郵便番号・住所（任意）を足す。
- `admin/ses-index.blade.php`：直属の行を消す。`:count="$list->count()"`。
- `admin/engineers.blade.php`：「直属だけ」のボタンは、`hasDirect` のとき（または `?ses=direct` で開いているとき）だけ出す。
- `admin/ses-form.blade.php`・`ses/company.blade.php`・`public/apply.blade.php`：「郵便番号」（`postal_code`、placeholder `123-4567`）を「所在地」の前に。
- `admin/ses-show.blade.php`：「所在地」を `postal_address($s->postal_code, $s->address)` に。
- `engineers/form.blade.php`：新規のときだけ「郵便番号」「現住所」。`sesFixed` の「管理者の直属」の扱いは消える（コントローラが渡さなくなる）。
- `engineers/profile.blade.php`：郵便番号の placeholder を `123-4567` に。
- `engineers/show.blade.php`：「現住所」を `postal_address(...)` に。21 行目の「利用中」→「使用中」。
- `account/show.blade.php`・`account/welcome.blade.php`：管理者のときだけ「郵便番号」「住所」。`account/profile.blade.php`：管理者のときだけ「住所」の行。

## 変更するファイル

| ファイル | 変更 |
|---|---|
| `database/migrations/2026_10_20_000002_add_postal_address.php`（新規） | 列を足す（上のとおり） |
| `app/helpers.php` | `postal_norm`・`postal_address` |
| `app/Services/Workflow.php` | `addressCols`（新規）、`invite`・`updateAdmin`・`saveProfile`・`applySes` |
| `routes/web.php` | `/admins/{id}` を `adminShow` に、`/admins/{id}/edit` を足す |
| `app/Http/Controllers/AdminController.php` | `adminShow`（新規）、`admins`・`adminUpdate`・`reinviteAdmin`・`sesIndex`・`sesStore`・`sesUpdate`・`engineers`（`hasDirect`） |
| `app/Http/Controllers/EngineerController.php` | `create`・`store`（直属を消す・住所）、`saveProfile`（住所の検査） |
| `app/Http/Controllers/SesController.php` | `saveCompany`（郵便番号）、「利用中」 |
| `app/Http/Controllers/PublicController.php` | `apply`（郵便番号） |
| `app/Http/Controllers/AccountController.php` | 管理者の郵便番号・住所の検証 |
| `resources/views/admin/admin-show.blade.php`（新規） | V2-2 |
| `resources/views/admin/admins.blade.php`・`admin-edit.blade.php`・`admin-new.blade.php` | V2・V3 |
| `resources/views/admin/ses-index.blade.php`・`engineers.blade.php` | V1 |
| `resources/views/admin/ses-form.blade.php`・`ses-show.blade.php`・`ses/company.blade.php`・`public/apply.blade.php` | V4-5 |
| `resources/views/engineers/form.blade.php`・`profile.blade.php`・`show.blade.php` | V4-6・V6 |
| `resources/views/account/show.blade.php`・`welcome.blade.php`・`profile.blade.php` | V3-4 |
| `resources/manuals/admin.md`・`admin-steps.md` | 管理者は「詳細」→「編集」、足した項目、直属の登録の記述を消す（今いる直属の人は一覧に「直属」の印で出る、の1文だけ残す）、SES の郵便番号、住所の見える範囲 |
| `resources/manuals/ses.md`・`ses-steps.md` | 会社情報・エンジニアの登録に郵便番号・住所、自社のエンジニアの住所が見える範囲 |
| `resources/manuals/engineer.md`・`engineer-steps.md` | 詳しいプロフィールの郵便番号の形（7桁）、住所を見られるのは本人・所属の SES・管理者だけ。「直属の人は管理者」の言い回しは、今いる人のために残す |
| テスト | 下の「既存のテストの直し」と新しい `tests/Feature/PostalAddressTest.php` |

外部ライブラリは足さない。

## 作業の手順

1. マイグレーションと `postal_norm`・`postal_address`・`W::addressCols` を足す。`php artisan migrate`（手元）。
2. 直属の入口を消す（`create`・`store`・`sesIndex`・`ses-index`）。`admin/engineers` の「直属だけ」の出し方。
3. 管理者の詳細を足し、ルートを分ける（`adminShow`・`/edit`）。一覧の「詳細」、編集の題名・戻る、送り直しの戻り先。
4. 管理者の項目（登録・編集・`invite`・`updateAdmin`）、本人の `/welcome`・`/account`・`/account/profile`。
5. SES（登録・詳細の編集・会社情報・申し込み・詳細の表示）とエンジニア（登録・詳しいプロフィール・詳細の表示）の郵便番号・住所。
6. 「利用中」の残り。
7. 既存のテストを直し、`PostalAddressTest` を足す。`php artisan test` が全部通ること。
8. マニュアル。41_Park-Project の `04_Manuals` の写しは JOE が写し直す。

公開版への反映は push のあと JOE が `park-deploy.sh` で行う（常時了承の範囲。今回はマイグレーションあり、列を足すだけ）。データの削除・書き換えはしない（直属の人・古い形の郵便番号はそのまま）。テストのメールは `mail_outbox` に入るだけで送らない。

## 既存のテストの直し（わかっている所）

- 直属を `POST /engineers` の `ses_id=direct` で作っている所は、`W::createEngineer(W::directSesId(), [...], $admin)` で直接作るか、参加中の SES（`ses_id=1`）に替える：`ProfileAndDirectTest` 36（`directEngineer()`）・370・377、`NameSplitTest` 170・183〜187・237・247・314、`EmptyStateTest` 150・231、`UiUnifyTest` 242。直属の扱い（知らせ・`user_label`・一覧の印・分析に入らない）を確かめているテストは、直接作る形にして残す。
- `?ses=direct` を開いて 200 を期待している所は、302（`/admin/ses`）を確かめる形に：`ProfileAndDirectTest` 339・343、`NameSplitTest` 192（並びの確かめは `?ses=1` で行う。「所属：」の後ろは SES の名前）、`UiUnifyTest` 212。`ParkTest` 48・`EmptyStateTest` 125 の画面の一覧の `/engineers/new?ses=direct` は `/engineers/new?ses=1`（見本なしの `EmptyStateTest` は先に SES を1つ作ってその id）。
- `ses_id=direct` の POST が「直属の行を作る」ことを確かめている所（`ProfileAndDirectTest` 360〜378、`NameSplitTest` 176・187）は、「bad で、直属の行も作られない」に書き換え。
- SES の一覧の直属の行を期待している所（`UiUnifyTest::test_u5_ses_list_has_direct_row`、`ProfileAndDirectTest` 416〜417）は、「出ない」を確かめる形に書き換え。
- 管理者の修正画面を `GET /admin/admins/{id}` で開いている所は `/admin/admins/{id}/edit` に。`/admin/admins/{id}` は詳細を確かめる形に：`AdminAccountTest` 189〜192（「>修正</a>」→「>詳細</a>」、「管理者の情報の修正」→ 編集画面の「管理者の編集：」）、`UiUnifyTest` 56〜58・85・154。bad のときの戻り先を確かめている所は `from('/admin/admins/{id}/edit')` で。
- 「所属・役職」「電話」の並びを見ている所（`AccountProfileTest` 38・43、`NameSplitTest` 101〜105・164）は、管理者のときだけ「電話」の後ろに「郵便番号」「住所」が入るので、並びの確かめを合わせる。
- `UiUnifyTest::test_u6_wording` は V6-1 で通るようになる（今は「利用中」が残っていて食い違う）。

## テスト（新しく `tests/Feature/PostalAddressTest.php`。用意は `UiUnifyTest` と同じ）

| 条件 | 手順 | 確かめること |
|---|---|---|
| V1-1 | 管理者で `GET /engineers/new?ses=direct` | `/admin/ses` へ 302、flash warn |
| V1-2 | 管理者で `POST /engineers` に `ses_id=direct`・氏名・メール | bad。`engineers`・`users`・`mail_outbox` 増えない。`ses_companies` の `kind='direct'` が0件のまま |
| V1-3 | 管理者で `GET /admin/ses` | 「管理者の直属」と `ses=direct` が出ない |
| V1-4 | `W::createEngineer(W::directSesId(), …)` で1人作り、`/admin/engineers`・`/engineers/{id}`・`/engineers/{id}/edit` を開き、編集を保存 | 一覧に「直属」の印と「直属だけ」。詳細・編集 200、保存 ok。直属の人がいないときは「直属だけ」が出ない |
| V2-1 | 管理者で `GET /admin/admins` | 各行に `href=".../admin/admins/{id}">詳細</a>`。「修正」「直す」が無い |
| V2-2 | `GET /admin/admins/{他人の id}`／自分の id／SES・エンジニアで同じ URL／SES 担当者の users.id・99999 | 200 で題名に氏名、「基本情報」「編集」（`href=".../admin/admins/{id}/edit"`）、住所の行。自分も 200。403。404 |
| V2-2 招待中 | 招待中の管理者の詳細 | 「招待を送り直す」が出る。使用中の人には出ない |
| V2-3 | `GET /admin/admins/{id}/edit` | 「管理者の編集：氏名」、欄（所属・役職・電話番号・郵便番号・住所）、「戻る」が詳細へ |
| V2-4 | 編集から正しく保存／姓を空で保存 | 前者は `/admin/admins/{id}` へ ok。後者は編集へ戻り bad、何も変わらない |
| V2-5 | `resources/views` を読む | `>修正<`・`の修正` が0件 |
| V2-6 | 詳細から（`from()`）reinvite | 詳細へ戻る |
| V3-1・V3-2 | `POST /admin/admins` に所属・役職・電話・`postal_code=１２３４５６７`・住所も入れる | 行に position・phone・`postal_code=123-4567`・address。invite +1 |
| V3-3 | 編集で郵便番号 `12345`／電話 `abc`／住所201文字 | どれも bad、何も保存されない（氏名を同時に変えても変わらない） |
| V3-4 | 管理者で `/welcome`（未登録）・`/account` に郵便番号・住所を入れて保存、`/account/profile` を開く | 保存される。「住所」の行に「〒123-4567 …」。SES・エンジニアの `/account` には `name="postal_code"` が無い |
| V3-5 | 住所を入れた管理者が招待を送る | `mail_outbox` の本文に住所・郵便番号が無い |
| V4-1 | `postal_norm` に `1234567`・`123-4567`・`〒123-4567`・`１２３ー４５６７`・`' 123 4567 '`・`''`・`123456`・`12345678`・`12-34567`・`abc-defg` | 前の5つは `123-4567`、空は null、残りは false |
| V4-3 | `postal_address` に（`123-4567`,住所）・（`1460082`,null）・（null,住所）・（null,null）・（`abc`,null） | 「〒123-4567 住所」「〒146-0082」「住所」「—」「abc」 |
| V4-5 | `/admin/ses/new`・SES 詳細の編集・`/ses/company`・`/apply` で郵便番号 `1000001` と所在地を入れて保存 | `ses_companies.postal_code=100-0001`。SES の詳細に「〒100-0001 …」。郵便番号 `99` は bad、何も増えない・変わらない |
| V4-6 | 管理者が `?ses=1` から、SES 担当者が、郵便番号・現住所つきでエンジニアを登録。詳しいプロフィールで郵便番号 `1-2` を保存 | `engineers.postal_code` が `xxx-xxxx`、詳細に「〒… 現住所」。`1-2` は bad で変わらない。`/engineers/{id}/edit` に `name="postal_code"` が無い |
| V5-1 | SES・エンジニアで `/admin/admins/{id}` | 403。管理者の一覧・ダッシュボードの HTML に、管理者の住所の文字が無い |
| V5-2 | ses1 の担当者で `/ses/company` | 自社の所在地が出る。別の SES（id 2）の所在地の文字は、ses1 のどの画面（`/ses`・`/ses/company`・`/ses/engineers`）にも出ない |
| V5-3 | エンジニア（ses1 所属）に住所を入れ、ses1 の担当者・管理者・本人で詳細を開く／別の SES の担当者・別のエンジニアで詳細・詳しいプロフィールを開く | 前の3人は住所が出る。後ろは 403。管理者の `/admin/engineers`・ses1 の `/ses/engineers`・`/engineers/{id}/sheet?anon=1` に住所の文字が無い |
| V5-4 | 住所を入れたあとの `audit_log`・`notifications`・`mail_outbox` | 住所・郵便番号の文字が無い |
| V6-1 | 使用中の EG がいるエンジニアの詳細、ses1 の `/ses/engineers` | 「使用中」、「利用中」が無い |
| マイグレーション | `RefreshDatabase` で全マイグレーションが通る | `users` に `postal_code`・`address`、`ses_companies` に `postal_code` がある（`Schema::hasColumn`） |

## 確かめること（返事がなくても、下の仮定で進める）

**1** SES の担当者に、自社のエンジニアの住所を見せるか（「管理者以外に他人の住所が見えない」を文字どおりにすると、SES は自社のエンジニアの住所も見られなくなる。ただ、今は SES が履歴書（住所入り）を書き出して出す流れがあり、画面にも「見られるのは、本人・所属の SES・管理者だけ」と書いてある）
a. 見せる（今のまま。この計画の仮定 p）
b. 見せない（SES の画面の住所を隠し、SES が書き出す履歴書の住所も空にする。前に管理者が書き出した書類は SES も開けるので、その扱いも別に決める）

**2** 管理者に足した項目を、どこかで使うか（今は企業名だけがメールの差出人・署名に出て、請求書の発行者は設定の「運営の名前」だけ）
a. この回は保存して見せるだけ（この計画の仮定 o）
b. 管理者が送るメールの署名に「所属・役職」「電話番号」も出す
c. 請求書の発行者の住所・電話を出す（管理者個人ではなく、設定に「運営の住所・電話」を足す形がよい）

**3** 今ある直属のエンジニア（公開版にいるかは、JOE が `park:status` などで確かめる）
a. そのまま（この計画の仮定 m）
b. SES へ移す画面を次の回で作る（tezzo がどの SES に移すか決める）

**4** 「所属・役職」の名前（tezzo の例は「部署・役職」）
a. 今の「所属・役職」のまま（この計画の仮定。SES の担当者の画面とも同じ）
b. 全部「部署・役職」に変える（テストとマニュアルも直す）
