# PLAN：最初の情報登録・ログイン中の立場の表示・管理者による登録・直属のエンジニア（2026-10-17）

## 背景

tezzo の要求（要約）：

1. 管理者（運営、role=admin）・SES・エンジニアとも、最初のログインで自分の情報を登録する画面を出す。
2. 色は今のまま（body.role-admin の青）で、「管理者でログインしている」ことを画面に明記する。
3. 管理者は管理者・SES・エンジニアを登録できる。
4. エンジニアは SES に紐づくが、例外として管理者に紐づく（直属の）エンジニアもいる。

方針（A〜G）は Leo が決めたもの。下の仕様はそれをそのまま形にしている。迷ったところは「仮定」として書き、末尾の「tezzo への質問」にまとめた。

守ること：Laravel 12・Blade・`DB::table`、業務の手続きは `app/Services`（主に `Workflow`）、画面の文は日本語のやさしい言い方。外部ライブラリは足さない。個人情報を PLAN・テスト・コメントに書かない（テストは見本データ `@park.example` だけ使う）。

## 受け入れ条件

### A. 最初の情報登録

- A1. `users` に `kana`・`phone`・`position`（いずれも string null）と `profile_completed_at`（datetime null）がある。マイグレーション後、既存の人は全員 `profile_completed_at` が null。
- A2. `profile_completed_at` が null の人が、2要素認証を済ませたあと、role ミドルウェアのかかる画面（例：`/admin`・`/ses`・`/eg`・`/cases/1`）を開くと `/welcome` へ転送される。
- A3. A2 の人でも `/welcome`・`/account`（`/account/password` を含む）は転送されずに開ける。`/logout`・`/manual` も使える。
- A4. 2要素認証が済んでいない人は、今までどおり `/two-factor/challenge`（または `/login`）へ回り、`/welcome` へは回らない。
- A5. `GET /welcome`（題「あなたの情報」）に、氏名（必須）・ふりがな・電話・所属・役職（admin・ses だけ）が出る。初期値：admin・ses は `users` の値。eg は `users.name`（空なら `engineers.name`）と `engineers.kana`。
- A6. 氏名が空で `POST /welcome` すると、保存されず、エラーが出る（`profile_completed_at` も null のまま）。
- A7. 正しく `POST /welcome` すると `users` の name・kana・phone・position（eg は position を保存しない）と `profile_completed_at` が入る。
- A8. 保存後の行き先：
  - admin → `/admin`
  - ses → 会社の `self_confirmed_at` が null なら `/ses/company`（メッセージ「会社の情報も確かめてください。」）、そうでなければ `/ses`
  - eg → `engineers.self_confirmed_at` が null なら `/engineers/{engineer_id}`（メッセージ「登録の内容を確かめて『確認しました』を押してください。」）、そうでなければ `/eg`
- A9. eg の `/welcome` の保存は `engineers` を書き換えない（`engineers.self_confirmed_at` も変わらない）。
- A10. `profile_completed_at` が入った人は、A2 の画面を開いても転送されない。`GET /welcome` を開くと `/account` へ転送される。
- A11. `/account` の「基本」で、氏名・ふりがな・電話・所属・役職（admin・ses だけ）を直せる。保存したとき `profile_completed_at` が null なら入れる。
- A12. `/setup` で最初の運営を作ると、`/welcome` へ転送される（`/admin` ではない）。`/welcome` の氏名には setup で入れた名前が入っている。
- A13. 見本データ（`DatabaseSeeder`）の人は `profile_completed_at` が入っていて、今のテストが転送で壊れない。

### B. ログイン中の立場の明記

- B1. admin が2要素認証を済ませて開いた画面のヘッダーの下に、帯で「管理者（運営）としてログインしています：{氏名}」が出る。
- B2. ses なら「SES（{会社名}）のご担当者としてログインしています」、eg なら「エンジニアとしてログインしています」が出る。
- B3. 2要素認証が済む前（`/two-factor/challenge`）と、ログイン前の画面には帯が出ない。
- B4. 印刷用の画面（`$print` が true。例：`/engineers/1/sheet`）には帯が出ない。
- B5. `lbl('role','admin')` は「運営」のまま。

### C. 管理者の登録

- C1. `/admin/settings` のカード「管理者（運営メンバー）」の一覧に、名前・メール・状態（`password` が null なら「招待中」、あれば「使用中」、`active` が false なら「停止」）・最終ログインが出る。
- C2. 招待中の人の行にだけ「招待を送り直す」ボタンが出る。押すと `POST /admin/settings/admins/{id}/reinvite` で、その人の `token` が新しくなり、`mail_outbox` に招待メール（kind=invite）が1通増える。
- C3. 使用中の人・admin 以外の人・存在しない id に reinvite すると、何も変えずにエラーのメッセージで `/admin/settings` に戻る。
- C4. 今の「招待する」（`POST /admin/settings/admins`）はそのまま動く（新しい admin が招待中で一覧に出る）。
- C5. ses・eg は `/admin/settings` と reinvite を使えない（403）。

### D. SES の登録

- D1. admin が `/admin/ses/new` から登録すると、その会社は `status=active`・`joined_at=今日` になる。
- D2. D1 で担当者のメールがあれば、そのメールで role=ses・ses_id=その会社の users ができ、招待メール（kind=invite）が届く。メールが無ければ招待しない（会社だけ active で作る）。
- D3. D1 のとき、運営への「SES から参加の申し込みがありました」の通知と、SES への「お申し込みを受け付けました」のメールは出さない。
- D4. 公開の `/apply` は今までどおり `status=pending`、通知・受付メールも今までどおり。
- D5. `/admin/ses/new` の説明文が「登録すると参加中になり、担当の方に招待メールが届きます。」になる。

### E. 管理者に紐づくエンジニア（直属）

- E1. `ses_companies` に `kind`（string 10、既定 `'ses'`）がある。既存の行はすべて `'ses'`。
- E2. `W::directSesId()` を呼ぶと、`kind='direct'` の行を返す。無ければ「PARK 運営（直属）」の行を1つ作る（status=active・self_confirmed_at=今・joined_at=今日）。何度呼んでも行は1つ。
- E3. admin の `/engineers/new` の「所属」の選択肢の先頭に「管理者の直属（SES なし）」が出る。`kind='direct'` の行はほかの選択肢に重ねて出ない。SES の人の画面には所属の欄が出ない（今のまま）。
- E4. admin が「管理者の直属」を選んで登録すると、`engineers.ses_id` が直属の行の id になる。メールを入れたら招待が届き、作られる users は role=eg・ses_id=直属の行・engineer_id=そのエンジニア。
- E5. 直属のエンジニアが応募すると、`applications.ses_id` は直属の行の id。admin は選考（書類提出・面談・成約）を今の画面でできる。成約の `contracts.ses_id` も直属の行。
- E6. どの SES の担当者も、直属のエンジニア・その応募・契約を見られない（`/engineers/{id}`・`/applications/{id}` が 403）。
- E7. 直属の行は SES として扱わない：
  - `/admin/ses` の一覧に出ない。`/admin/ses/{直属の id}`（表示・保存・承認・停止・担当者の招待）は 404。
  - 運営のダッシュボードの「SES」の数に入らない。分析（`/admin/reports`）の SES ごとの行に出ない。
  - 案件の「持ち込んだ SES」の選択肢に出ない。
  - `php artisan park:status` の SES の数に入らない。
  - role=ses の人が直属の行に属していても、ログインできない（念のため）。
- E8. 請求：`/admin/invoices/build` で直属の行の請求書は作られない。`Billing::build(直属の id, 月)` は null を返し、invoices に行を作らない。`Billing::monthTotal` に直属の契約は入らない。
- E9. 通知：`N::toSes(直属の id, …)` は admin 全員に届く（ベルとメール）。同じ出来事で運営への通知がすでに出ているところ（下の「直すファイル」の Workflow・CaseMail の印のところ）では、二重に出さない。
- E10. 宛名：直属のエンジニアのメールの宛名は「{氏名} 様」（「PARK 運営（直属） 氏名 様」にしない）。
- E11. `/admin/engineers?ses=direct` で直属のエンジニアだけに絞れる。一覧の所属に「直属」と分かる印が出る。
- E12. 既存のエンジニアとその所属は変わらない（マイグレーションで ses_id を書き換えない）。

### F. マニュアル

- F1. `resources/manuals/` の admin・ses・engineer（`*.md` と `*-steps.md`）に、最初の情報登録の説明がある。admin には加えて、帯の表示・管理者の招待と送り直し・SES の登録（すぐ参加中になる）・直属のエンジニアの登録が書いてある。

### G. テスト

- G1. A〜E の受け入れ条件を `tests/Feature/ProfileAndDirectTest.php`（新規）でたどる（手順は下の「テストの手順」）。
- G2. `php artisan test` が全部通る（今のテストを弱めない）。

## 仕様

### データ（マイグレーション `database/migrations/2026_10_17_000001_profile_and_direct.php`）

- `users`：`kana` string null、`phone` string(30) null、`position` string null、`profile_completed_at` datetime null。
- `ses_companies`：`kind` string(10) default `'ses'`（`index` を付ける）。
- 直属の行はマイグレーションで作らない（`W::directSesId()` が必要になったときに作る）。
- `down()` は足した列を消す。

### 業務の手続き（`app/Services/Workflow.php`）

- `directSesId(): int`（E2）。
- `isDirect(?int $sesId): bool`：`ses_companies.kind === 'direct'`。
- `saveProfile($user, array $d): void`：name・kana・phone を保存。position は role が admin・ses のときだけ。`profile_completed_at` が null なら今を入れる。`updated_at` も。`N::audit($user->id, 'profile', '')`（detail に個人情報を入れない）。
- `profileHome($user): string`：A8 の行き先と、出すメッセージ（または null）を返す（`[$url, $msg]`）。
- `applySes(array $d, $actor = null)`：`$actor` が admin のときは、D3 の通知・受付メールを出さない。そのあと呼び出し側で `approveSes`。
- `apply()`：`toSes($e->ses_id, …)`（295 行目）は直属なら呼ばない（すぐ上で toAdmins 済み）。
- `distribute()`：`$bySes` のうち直属の分は `toSes` しない（操作したのが運営なので）。
- `setStatus()`：直属で status が `withdrawn` のときは、`toAdmins` の行（336 行目）を呼ばない（toSes が運営に届くので）。
- `createUser()` は今のまま（seeder 用）。

### 通知（`app/Services/Notifier.php`）

- `toSes($sesId, …)`：`W::isDirect($sesId)` なら `toAdmins($title, $body, $link, $mail, $kind)` を呼んで終わる。そうでなければ今のまま。
- `sesUsers()` は今のまま（直属に ses の人はいない）。

### 請求（`app/Services/Billing.php`）

- `build()`：直属なら何もせず null（既存の下書きがあっても触らない）。
- `monthTotal()`：`where('kind','ses')` の行だけ足す。

### ミドルウェア（`app/Http/Middleware/Role.php`）

- 2要素認証の判定のあと・役割の判定の前に：`$u->profile_completed_at` が null で、`$request->is('welcome', 'account', 'account/*')` でなければ `redirect('/welcome')`。
- 403 より先に転送する（役割が合わない画面でも、まず情報の登録へ）。

### 画面とルート

`routes/web.php` の `Route::middleware('role')` の群に：

- `GET /welcome` → `AccountController@welcomeForm`、`POST /welcome` → `AccountController@welcome`。

`role:admin` の `admin` 群に：

- `POST /admin/settings/admins/{id}/reinvite` → `AdminController@reinviteAdmin`。

#### `/welcome`（新規 `resources/views/account/welcome.blade.php`）

- 題「あなたの情報」。説明「はじめに、あなたの情報を登録してください。あとから『アカウント』で直せます。」
- 欄：氏名（必須）・ふりがな・電話・所属・役職（`@unless($u->role === 'eg')`）。ボタン「登録する」。
- 検証：name 必須・100 文字まで、kana 100 文字まで、phone 30 文字まで・`/^[0-9+\-() ]*$/`、position 100 文字まで。属性名は日本語（氏名・ふりがな・電話・所属・役職）。
- `welcomeForm`：完了済みなら `/account` へ（A10）。
- `welcome`：`W::saveProfile` → `W::profileHome` の行き先へ `go()`。メッセージが無いときは「登録しました。」。

#### `/account`（`account/show.blade.php`・`AccountController@update`）

- 「基本」に ふりがな・電話・所属・役職（eg 以外）を足す。保存は `W::saveProfile` を使う（name が空なら今の名前のまま。今の動きを保つ）。`mail_opt_in` は今のまま別に保存。

#### `/setup`（`PublicController@setup`）

- 作ったあとの行き先を `/welcome` にし、メッセージを「運営のアカウントを作りました。続けて、あなたの情報を登録してください。」にする。

#### 帯（`resources/views/layouts/app.blade.php`）

- `@unless($print)` の中、`</header>` のすぐ後に、`$u` があり2要素認証が済んでいる（`$nav` を空にしている条件の逆）ときだけ：
  - `<div class="loginbar"><div class="wrap">…</div></div>`
  - 文：admin「管理者（運営）としてログインしています：{{ $u->name }}」／ses「SES（{{ 会社名 }}）のご担当者としてログインしています」／eg「エンジニアとしてログインしています」。会社名は `DB::table('ses_companies')->where('id',$u->ses_id)->value('name')`。
- `public/css/park.css` に `.loginbar { background: var(--blue); color: #fff; font-weight: 700; font-size: 13px; padding: 4px 0; }` を足す（役割ごとの `--blue` をそのまま使うので、admin は今の青）。

#### 管理者（`admin/settings.blade.php`・`AdminController`）

- カードの題を「管理者（運営メンバー）」に。表の列：名前・メール・状態・最終ログイン・（操作）。状態は `badge` ではなく文字でよい（招待中／使用中／停止）。
- 招待中の行に `<form method="post" action="/admin/settings/admins/{id}/reinvite">` の「招待を送り直す」。
- 招待のボタンの文は「管理者を招待」。
- `reinviteAdmin(Request $r, int $id)`：`role='admin'`・`password` が null・存在、でなければ「この人には送り直せません。」（bad）。そうなら `W::invite(['email'=>$u->email,'name'=>$u->name,'role'=>'admin'], $r->user())` → 「招待を送り直しました。」。
- 「停止」の操作は今回作らない（自分自身を止めないための仕掛けも不要）。

#### SES の登録（`AdminController@sesStore`・`admin/ses-new.blade.php`）

- `$id = W::applySes($r->all() + ['agree'=>1], $r->user()); W::approveSes($id, $r->user());`
- メッセージ：会社のメールがあれば「登録し、ご担当者に招待メールを送りました。」、無ければ「登録しました（メールアドレスが無いので、招待は送っていません）。」。
- 担当者のメールがすでに使用中の users にあるときは、`W::invite` が招待しない（今の動き）。そのときは「登録しました。このメールアドレスはすでに使われているため、招待は送っていません。」（warn）。
- `ses-new.blade.php` の説明文を D5 に。

#### 直属のエンジニア

- `EngineerController@create`：admin の `$sesList` を `['direct' => '管理者の直属（SES なし）'] + （kind='ses' かつ active の id=>name）` にする。`?ses=direct` なら初期値 `direct`。
- `EngineerController@store`：admin のとき `ses_id` が `'direct'` なら `W::directSesId()`。数字なら、その行が `kind='ses'` かつ active であることを確かめる（違えば「所属と氏名を入れてください。」と同じ扱いで戻す）。
- `engineers/form.blade.php`：`$sesList` の欄の label を「所属」に、hint「SES に属さない人は『管理者の直属』を選びます」。
- `AdminController@engineers`：select に `s.kind as ses_kind`。`?ses=direct` なら `ses_kind === 'direct'` だけ。`admin/engineers.blade.php` の所属の列は、直属なら「直属」の印（`<span class="badge info">直属</span>`）。絞り込みのリンク「直属だけ」を足す。
- `PipelineController` の選考の操作は、ルートが `role:admin,ses` で admin は全部通るので、変えない。

#### 直属の行を SES から外す

- `user_label()`（`app/helpers.php`）：role が eg で、所属が直属なら `$u->name` だけ返す。
- `PublicController@login`：role=ses で会社が直属なら、停止と同じメッセージでログインさせない。

### 正常系・境界

- 0件：直属の行がまだ無いとき、`/admin/engineers?ses=direct` は0件で表示できる（行を作らない）。`/engineers/new` を開いても行は作らない（作るのは store のとき）。
- 二重の操作：`POST /welcome` を2回しても `profile_completed_at` は最初の日時のまま（上書きしない）。reinvite を2回押すと token は2回とも新しくなる（前のリンクは使えなくなる）。`directSesId()` は1行だけ。
- 権限：`/welcome` は role ミドルウェアの群なので、ログイン前は `/login` へ。reinvite は admin だけ。
- 期限切れ：招待の期限（7日）は今のまま。送り直すと7日に戻る。

## 直すファイルと箇所の一覧

| ファイル | 箇所 | 直すこと |
|---|---|---|
| `database/migrations/2026_10_17_000001_profile_and_direct.php` | 新規 | users の4列、ses_companies.kind |
| `database/seeders/DatabaseSeeder.php` | 末尾 | 全 users に `profile_completed_at = now` |
| `app/Http/Middleware/Role.php` | 2要素認証の後 | `/welcome` への転送 |
| `routes/web.php` | role 群・admin 群 | `/welcome` GET/POST、reinvite |
| `app/Http/Controllers/AccountController.php` | 新規 `welcomeForm`・`welcome`、`update` | 情報の登録・直す |
| `app/Http/Controllers/PublicController.php` | `setup`（72 行目）、`login`（37 行目） | 行き先を `/welcome`、直属の ses を弾く |
| `app/Http/Controllers/AdminController.php` | `dashboard` 46 行目 | SES の数を kind=ses だけに |
| 同上 | `sesIndex` 72 行目 | `where('s.kind','ses')` |
| 同上 | `sesStore` 81 行目 | active にして招待 |
| 同上 | `sesShow`・`sesUpdate`・`sesApprove`・`sesSuspend`・`sesUsers` | 直属なら 404 |
| 同上 | `engineers` 129 行目 | `ses_kind`・`?ses=direct` |
| 同上 | `buildInvoices` 188 行目 | `where('kind','ses')` |
| 同上 | `reports` 262 行目 | `where('kind','ses')` |
| 同上 | `settings`・新規 `reinviteAdmin` | 管理者の一覧と送り直し |
| `app/Http/Controllers/EngineerController.php` | `create` 28 行目・`store` 34 行目 | 直属の選択肢と登録 |
| `app/Http/Controllers/CaseController.php` | 19 行目（案件の SES の選択肢） | `where('kind','ses')` |
| `app/Services/Workflow.php` | 新規 `directSesId`・`isDirect`・`saveProfile`・`profileHome`、`applySes`、`apply` 295 行目、`distribute` 262 行目、`setStatus` 336 行目 | 上の仕様 |
| `app/Services/Notifier.php` | `toSes` 71 行目 | 直属なら運営へ |
| `app/Services/CaseMail.php` | `send` 114 行目 | 直属のときは、運営あての文を「グループの案件が、直属のエンジニアに届きました」にする（toSes は呼ぶ。運営が送ると `$own` なので、もともと呼ばれない） |
| `app/Services/Billing.php` | `build` 42 行目・`monthTotal` 57 行目 | 直属を外す |
| `app/Services/Todo.php` | `admin` 97 行目 | `where('kind','ses')` を足す（念のため） |
| `app/Console/Commands/Status.php` | 28・29 行目 | `where('kind','ses')`。「エンジニア：直属」の数の行を足す |
| `app/helpers.php` | `user_label` 52 行目 | 直属の eg は氏名だけ |
| `resources/views/layouts/app.blade.php` | `</header>` の後 | 立場の帯 |
| `public/css/park.css` | 末尾 | `.loginbar` |
| `resources/views/account/welcome.blade.php` | 新規 | あなたの情報 |
| `resources/views/account/show.blade.php` | 「基本」 | 項目を足す |
| `resources/views/admin/settings.blade.php` | 運営メンバーのカード | 状態・送り直し |
| `resources/views/admin/ses-new.blade.php` | 3 行目 | 説明文 |
| `resources/views/admin/engineers.blade.php` | 所属の列・絞り込み | 直属の印とリンク |
| `resources/views/engineers/form.blade.php` | 6 行目 | 所属の label・hint |
| `resources/manuals/admin.md`・`admin-steps.md` | — | F1 |
| `resources/manuals/ses.md`・`ses-steps.md` | — | 最初の情報登録 |
| `resources/manuals/engineer.md`・`engineer-steps.md` | — | 最初の情報登録 |
| `README.md` | データの説明・流れ | `profile_completed_at`・`kind`・直属の説明を1〜2行 |
| `tests/Feature/ProfileAndDirectTest.php` | 新規 | G1 |

変えなくてよいと確かめたところ（直属の行が active なので、そのまま動く）：`Matcher::forCase`（直属も配信候補に入る）、`EngineerController::load`、`Workflow::loadApp`、`PipelineController`（契約・請求書の表示の join）、`AdminController` の applications・contracts・invoices の join、`broadcast`（eg 宛てに直属も入る。ses 宛てには直属の人がいない）、`Perms`（ses_id が違うので SES からは見えない）、`SesController`（直属に ses の人はいない）。

## 作業の手順

1. マイグレーションを書き、`php artisan migrate`（手元）。seeder の末尾に `profile_completed_at` を入れる。
2. `Workflow` に `directSesId`・`isDirect`・`saveProfile`・`profileHome` を足す。
3. `Role.php` に転送を足し、`routes/web.php` に `/welcome` を足す。`AccountController` に `welcomeForm`・`welcome` と `welcome.blade.php` を作る。
4. `/account` の「基本」と `update` を直す。`/setup` の行き先を直す。
5. ここで `php artisan test` を回し、今のテストが通ることを確かめる（壊れたら、テストを弱めずに原因を直す。新しく users を作って画面を開くテストは、`/welcome` を通る手順を足すか、`profile_completed_at` を入れる）。
6. 帯（layout と css）。
7. 管理者の一覧と reinvite。
8. SES の登録（`applySes` の `$actor`、`sesStore`、`ses-new` の文）。
9. 直属：`EngineerController`・form、`Notifier::toSes`、二重の通知の止め、`Billing`、AdminController の SES の画面・数・請求・分析、CaseController の選択肢、Todo・Status、`user_label`、login の弾き、admin のエンジニア一覧の絞り込み。
10. マニュアル（6 ファイル）と README。
11. `php artisan test` が全部通ることを確かめ、reviewer・tester に回す。
12. 公開版への反映は、JOE が push 後に `park-deploy.sh` で行う（migrate を含む。常時了承の範囲）。メールの一斉送信・データの削除・`.env` の書き換えは、この件では行わない。41_Park-Project の `04_Manuals` のマニュアルの写しは JOE が写し直す。

## テストの手順（`tests/Feature/ProfileAndDirectTest.php`）

`RefreshDatabase`・`$seed = true`。ログインは ParkTest と同じく `actingAs` ＋ `withSession([TwoFactor::SESSION_KEY => id])`。

- A1・A13：`Schema::hasColumns('users', [...])`、`Schema::hasColumn('ses_companies','kind')`。seeder の admin の `profile_completed_at` が null でない。
- A2：admin@park.example の `profile_completed_at` を null にし、`/admin`・`/cases/1` が `/welcome` へ転送。ses1・eg1 も同じく `/ses`・`/eg` が `/welcome` へ。
- A3：同じ状態で `/welcome`・`/account` が 200、`POST /account/password`（今のパスワードを違えて）が `/welcome` へ転送されない。
- A4：セッションに SESSION_KEY を入れず（pending も無し）`/admin` → `/login` へ（`/welcome` ではない）。
- A5：`/welcome` を開き、admin は「所属・役職」が見える、eg は見えない。eg の氏名の初期値が見える。
- A6：`POST /welcome` name 空 → エラー、`profile_completed_at` null のまま。
- A7・A8：admin → `/admin`。ses1（会社の `self_confirmed_at` を null にして）→ `/ses/company`、確認済みなら `/ses`。eg1（`engineers.self_confirmed_at` を null にして）→ `/engineers/1`、確認済みなら `/eg`。DB に kana・phone・position・profile_completed_at。
- A9：eg の保存の前後で `engineers` の行が同じ。
- A10：完了済みで `GET /welcome` → `/account`。2回目の `POST /welcome` で `profile_completed_at` が変わらない。
- A11：`POST /account` で kana・phone・position が保存される。eg は position を送っても保存されない。
- A12：`users` を空にし（admin がいない状態を作る）`POST /setup` → `/welcome` へ。続けて `GET /welcome` に setup の名前が見える。
- B1・B2：admin の `/admin` に「管理者（運営）としてログインしています」と名前。ses1 の `/ses` に「SES（みどり情報システム）のご担当者としてログインしています」。eg1 の `/eg` に「エンジニアとしてログインしています」。
- B3：パスワードだけ通った状態（`TwoFactor::send` 後）の `/two-factor/challenge` に「としてログインしています」が無い。`/login` にも無い。
- B4：admin の `/engineers/1/sheet` に帯の文が無い。
- B5：`lbl('role','admin') === '運営'`。
- C1・C2：admin が `POST /admin/settings/admins`（name・email）で招待 → `/admin/settings` に「招待中」と「招待を送り直す」。reinvite で token が変わり、kind=invite のメールが1通増える。
- C3：使用中の admin（自分）・ses の人の id に reinvite → token もメールの数も変わらない。
- C4：上の招待で users に role=admin・password null の行がある。
- C5：ses1 で `/admin/settings` と reinvite が 403。
- D1〜D3：admin が `POST /admin/ses`（name・contact_name・email）→ その会社が active・joined_at=今日、users に role=ses、`mail_outbox` に kind=invite。kind=ses-apply のメールと「参加の申し込み」の通知は増えない。email 無しでも active で、users は増えない。
- D4：`POST /apply` は pending（ParkTest の既存の手順と同じ）。
- D5：`/admin/ses/new` に新しい説明文。
- E1・E2：既存の行の kind が全部 ses。`W::directSesId()` を2回呼んで同じ id、kind=direct の行は1つ、status=active・self_confirmed_at あり。
- E3：admin の `/engineers/new` に「管理者の直属（SES なし）」。ses1 の `/engineers/new` には無い。
- E4：admin が `POST /engineers`（ses_id=direct・name・email）→ engineers.ses_id=直属の id、users に role=eg・ses_id=直属・engineer_id、招待メール。
- E5：そのエンジニアを確認済み（self_confirmed_at）にして、eg として `POST /cases/{open の案件}/apply` → applications.ses_id=直属。admin が status（submitted）・interview・contract を進められ、contracts.ses_id=直属。
- E6：ses1 で `/engineers/{直属}`・`/applications/{直属の応募}` が 403。
- E7：admin の `/admin/ses` に「PARK 運営（直属）」が無い、`/admin/ses/{直属}` が 404。`/cases/new` の選択肢に無い。`Artisan::call('park:status', ['--json'=>true])` の「SES：会社の情報の確認がまだ」等に直属が数えられない。直属の行に role=ses の users を作って `POST /login` → ログインできない。
- E8：直属の契約がある月で `POST /admin/invoices/build` → 直属の invoices が無い。`Billing::build(直属, 月)` が null。`Billing::monthTotal(月)` に直属の契約の額が入らない。
- E9：直属のエンジニアの応募を ses_id に持つとき、admin の status 変更（doc_passed）で、admin に通知が届く（app_notifications）。直属の応募で、応募1回あたりの admin への「応募がありました」の通知が1件だけ（二重でない）。
- E10：直属の eg の招待メールの本文が「{氏名} 様」で始まり、「PARK 運営（直属）」を含まない。
- E11：`/admin/engineers?ses=direct` に直属の人だけが出て、山田 太郎は出ない。
- E12：マイグレーション後、見本データの engineers.ses_id が seeder のまま。

## 仮定

- a. 「所属・役職」は1つの欄（`users.position`）にまとめる。eg には出さない（所属は SES か直属で決まるため）。
- b. 情報の登録で直せるのは氏名・ふりがな・電話・所属・役職。メールアドレスは今までどおり運営に連絡して変える。
- c. `/welcome` を済ませるまで、通知（`/notifications`）も開けない（転送する）。要求の「最初に登録」を優先した。
- d. 直属の行の名前は「PARK 運営（直属）」の固定。設定の「運営の名前」とは連動しない。
- e. 直属のエンジニアへの案件の案内メール（CaseMail）は、SES の人から見ると「グループのほかの人」（名前を伏せる）になる。
- f. 運営への通知が二重になるところだけ止め、それ以外の `toSes`（面談・成約・延長など）は、直属なら運営全員に届く（操作した本人にも届く）。
- g. 分析の「SES ごとの行」に直属は出さない（全体のファネルには入る）。
- h. 帯の文は方針のとおり（eg は名前なし）。ヘッダー右の「運営のアカウント」の表示はそのまま残す。
- i. 管理者を止める（active=false）操作は今回作らない。一覧に「停止」の表示だけ用意する。

## tezzo への質問

**1** 呼び名「運営」と「管理者」
画面の帯は「管理者（運営）」にしましたが、メニュー・役割の呼び名（`lbl('role','admin')`）は「運営」のままです。
a. このまま（帯だけ「管理者（運営）」）
b. 全体を「管理者」に変える

**2** 直属のエンジニアの通知
直属のエンジニアの選考の知らせ（面談が決まった・成約した など）は、SES の代わりに管理者全員に届くようにしました。操作した本人にも届きます。
a. このまま
b. 操作した本人には送らない

**3** 直属の成約の売上
直属のエンジニアの成約は、請求書を作らず、ダッシュボードの「今月の成功報酬」にも入れていません（PARK 自身の契約なので）。
a. このまま
b. 別の数字（直属の売上）として見たい

**4** 最初の情報登録の項目
氏名・ふりがな・電話・所属・役職にしました。ほかに入れたい項目があれば教えてください（例：住所・生年月日。個人情報が増えるので、最小にしています）。
a. このまま
b. 足したい項目がある

**5** SES を運営が登録したとき
運営が登録した SES は、すぐ「参加中」になり、担当者のメールに招待がすぐ届きます（承認の手順を省きます）。
a. このまま
b. 招待を送る前に、もう一度確かめる手順がほしい
