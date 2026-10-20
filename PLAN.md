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
