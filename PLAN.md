# PLAN：1つのログインで、エンジニアと SES 担当者・管理者を兼ねる（兼任ログイン）（2026-10-20）

> この節が今回の計画。下の「保留中の別の計画（テスト期間だけ重複を許す）」は、兼任のあとで別に作る計画で、消さずに残している（ぶつかり方は仕様の 12）。

## 背景

tezzo の要求（2026-10-20）：メールアドレスをたくさん持っている人は少ない。同じメールアドレスで SES 担当者とエンジニアを兼ねられるようにしたい。

きっかけ：公開版で、SES 担当者がご自身をエンジニアとして登録しようとすると、自分のメールアドレスが「このメールアドレスはすでに使われています。」で断られる（`EngineerController::store` の78行目）。`users.email` は一意で、`role` は1つだけ。

## 決まったこと（tezzo 2026-10-20）

- 決定1a：SES 担当者が兼ねられるのは、**同じ SES に所属する**エンジニアだけ。
- 決定2b：**管理者とエンジニア**の兼任も入れる。SES 担当者との兼任と同じしくみで扱う。
- 決定3b：「テスト期間だけ重複を許す」計画は消さず、兼任のあとで別に作る。
- 決定4b：立場は**ログインのときに選ぶだけ**。パスワードと確認コードのあと、兼任の人にだけ「どの立場で入りますか」を出す。入ったあとの切り替えボタンは作らない。変えるときはログアウトして入り直す。

## しくみ（案A：1行に両方を持たせる）

- `users` の1行に両方の紐づけを持たせる。兼任は次の2つ。
  - SES 兼任：`role='ses'`・`ses_id`＝会社・`engineer_id`＝本人のエンジニア（`engineers.ses_id` ＝ `users.ses_id`）。
  - 管理者兼任：`role='admin'`・`ses_id` は空・`engineer_id`＝本人のエンジニア（所属の SES はどこでもよい。直属も可）。
- `users` の列は足さない。`users.email` の一意はそのまま。管理者と SES 担当者の兼任は扱わない（今までどおり断る）。
- ログインで選んだ立場はセッションの `acting_role` に持ち、毎回の画面で `$u->role` に入れる。今の権限の決まり（`Perms`・`role:` ミドルウェア・各所の `$u->role`）をそのまま使えるので、立場ごとの見える範囲が混ざらない。

## 仮定（Planner が置いたもの）

- 仮定1：すでにログインできる人に役割を足すときは、招待メール（パスワード設定のリンク）は送らない。代わりにお知らせ（ベル＋メール）を送る。本人が自分で登録したときは、お知らせも送らない（画面の文で伝える）。
- 仮定2：個人情報の取り扱いへの同意（`engineers.consent_at`）は、初めてエンジニアとして入ったときにもらう（招待の画面を通らないため）。
- 仮定3：ひとつの出来事で両方の立場に知らせが出るとき（応募・選考・成約など）、兼任の人には2つ届く。どちらあてかを文に書く。まとめはしない。
- 仮定4：管理者にエンジニアの役割を足せるのは管理者だけ。SES が管理者のアドレスでエンジニアを登録しても、今までどおり断る（SES が管理者のアカウントに手を入れられないようにするため）。
- 仮定5：管理者兼任のエンジニアには、自動で「運営者の印」（`engineers.is_operator=1`）を付ける（公平性のため。外すのは今の「運営者の印」の切り替えで）。
- 仮定6：今ある「管理者のアカウント」と「エンジニアのアカウント」を別々のアドレスで使っている人は、そのまま使える。自動ではまとめない（「決めてほしいこと」の 1）。

## 受け入れ条件

- AC1：SES 担当者が、自社のエンジニアの登録で**自分のメールアドレス**を入れると、断られずに登録できる。`users` は増えず、その人の `users.engineer_id` に新しいエンジニアが入る。招待メール（kind=invite）は出ない。
- AC2：管理者・別の担当者が、SES X の担当者のアドレスで SES X のエンジニアを登録しても兼任になる。本人にお知らせが届く。エンジニアの詳細の「招待を送る」でも同じ（あとからアドレスを入れた場合）。
- AC3：管理者が、エンジニアの登録で管理者のアドレス（自分・ほかの管理者）を入れると、管理者兼任になる（`role='admin'` のまま、`engineer_id` が入る、運営者の印が付く）。ほかの管理者のときは本人にお知らせ。
- AC4：逆向き：管理者が SES X の「担当者を登録」で SES X のエンジニアのアドレスを入れると、SES 兼任になる（`role` が `ses`、`engineer_id` はそのまま）。管理者の登録でエンジニアのアドレスを入れると、管理者兼任になる（`role` が `admin`、`ses_id` は空、企業名などは登録の画面の値、運営者の印が付く）。どちらも `users` は増えない。
- AC5：次は今までどおりの文で断る：他社の SES 担当者のアドレス、他社のエンジニアのアドレスでの担当者の登録、SES が入れた管理者のアドレス、管理者と SES 担当者を兼ねる形になるもの、停止中・退会済の人、すでに別のエンジニアと兼任している人。SES の新規登録・申し込みの承認・管理者の編集は、今と同じ動き。
- AC6：兼任の人は、パスワードと確認コードを1回通ったあと、「どの立場で入りますか」の画面を通る。選んだ立場のメニュー・ホーム・帯になる。選ぶまでほかの画面には行けない。立場が1つの人には出ない。いま使えない立場は選択肢に出ない（使える立場が1つなら画面を出さずにその立場で入る）。
- AC7：入ったあとは立場を変えられない（切り替えのボタン・道は無い。選ぶ画面にもう一度送っても変わらない）。帯に「立場を変えるときは、ログアウトして入り直してください」。
- AC8：権限が混ざらない：
  - SES 担当者・管理者の立場では、エンジニア専用の画面（`/eg`・やり取りの本人側・面談メモ・「確認しました」など）が 403。
  - エンジニアの立場では、SES 専用・管理者専用の画面（`/ses/*`・`/admin/*`・案件の登録・請求など）が 403。見えるのは本人の分だけ（ほかのエンジニア・ほかの人の応募は 403）。管理者兼任でも同じ。
  - 兼任でない人が立場を送っても何も変わらない。DB の `users.role` は、どの立場で入っても書き換わらない。
- AC9：初めてエンジニアとして入ったとき、個人情報の取り扱いへの同意の画面が出る。同意するまで、エンジニアのほかの画面へは行けない。
- AC10：通知：立場ごとのあて先（`ses`／`admin`／`eg`）を記録する。ベルの数と通知の一覧は、いまの立場の分だけ。帯に、もう一方の立場の未読の数を出す。もう一方あての通知は開けず、「この立場で入り直すと見られます」と出す。兼任の人にも、エンジニアあての通知とメールが届く。メールの本文の頭に「【エンジニアあて】」「【SES 担当者あて】」「【管理者あて】」が付く。
- AC11：停止・退会：
  - エンジニアの停止 → エンジニアの立場で入れない（選択肢に出ない。もう一方の立場は使える）。再開で戻る。エンジニアの立場で入っている間に停止されたら、次の画面でログアウトして、ログイン画面に「この立場では、いまは入れません」。
  - エンジニアの退会 → 兼任が外れる（`engineer_id` を空に。もう一方の立場は使える）。
  - SES 担当者の退会 → SES 担当者の役割だけ外れ、エンジニアだけになる（`role` を `eg` に）。SES 担当者の停止 → ログインそのものが止まる（文で知らせる）。
  - 会社の停止・未承認 → SES 兼任の人はエンジニアとしてだけ入れる。会社の退会 → 今までどおり全員止まる（管理者兼任の人は、エンジニアが退会済になるので管理者としてだけ入れる）。
  - SES がエンジニアを停止・退会にしても、管理者兼任の人の管理者のログインは止まらない。
- AC12：管理者の自分の分：管理者の立場で自分のエンジニアに関わる操作（登録内容の編集・配信・応募・選考・契約）をすると、記録に「本人」の印が残り、応募・契約の画面に「管理者ご本人の応募」の札が出る（「決めてほしいこと」の 2 で変わる）。
- AC13：兼任の人がいなければ、今までと全く同じ動き。既存のテストは全部通る。
- AC14：管理者の画面で兼任が分かる（「招待の状況」、SES の詳細の担当者一覧、管理者の一覧・詳細、エンジニアの詳細のログインの欄）。SES の画面では、管理者兼任であることは出さない（「ログインあり」とだけ）。`park:status` に兼任の人数（SES 兼任・管理者兼任）。
- AC15：マニュアル（運営・SES・エンジニア）に、兼任のしかたと立場の選び方を書く。

## 仕様

### 1. データ

- `users` は変えない（上の「しくみ」）。
- マイグレーション `database/migrations/2026_10_20_000005_notification_for_role.php`：`app_notifications.for_role`（string(10)、null 可。`admin`／`ses`／`eg`／null）を足す。`down()` は列を外す。既存の行は null（どの立場でも出す）。

### 2. 手続き（`app/Services/Workflow.php` に足す）

DB の行（`DB::table('users')` の結果）で判定する関数：

- `isDual(object $u): bool`：`engineer_id` があり、`role='admin'`、または `role='ses'` で `ses_id` がある。
- `rolesOf(object $u): array`：その人が持つ立場（`['ses','eg']`／`['admin','eg']`／1つだけ）。
- `usableRoles(object $u): array`：`rolesOf` のうち、いま使えるもの。`eg`＝エンジニアがあり、退会済でも停止中でもない。`ses`＝会社が `status='active'` かつ `kind='ses'`。`admin`＝いつも使える。
- `egShareTarget(string $email, int $engineerSesId, $actor): ?object`：エンジニアの役割を足せる人。`email` が同じで、`engineer_id` なし、`active=1`、`left_at` なし、かつ次のどちらか。
  - `role='ses'` で `ses_id=$engineerSesId`。
  - `role='admin'` で、`$actor` が管理者（仮定4）。
- `sesShareTarget(string $email, int $sesId): ?object`：SES 担当者の役割を足せる人。`role='eg'`、`ses_id=$sesId`、`engineer_id` あり、`active=1`、`left_at` なし、そのエンジニアが `ses_id=$sesId` で退会済でない。
- `adminShareTarget(string $email): ?object`：管理者の役割を足せる人。`role='eg'`、`engineer_id` あり、`active=1`、`left_at` なし、そのエンジニアが退会済でない。
- `linkEngineer(int $userId, int $engineerId, $actor): void`：`users.engineer_id` を入れる。管理者なら `engineers.is_operator=1`（仮定5）。記録 `dual-link`（`#user {$userId} ← エンジニア #{$engineerId}`。アドレス・氏名は書かない）。操作した人が本人でなければ `N::notify($userId, 'エンジニアとしても登録されました', 'ログインのとき「エンジニア」を選ぶと、エンジニアの画面に入れます。', '/eg', true, 'dual', 'eg')`。
- `addSesRole(int $userId, $actor): void`：`role` を `ses` に（`engineer_id`・`ses_id` はそのまま）。記録 `dual-add-ses`。本人にお知らせ（「SES のご担当者としても登録されました。ログインのとき「SES 担当者」を選ぶと…」、`for_role='ses'`）。
- `addAdminRole(int $userId, array $d, $actor): void`：`role` を `admin`、`ses_id` を null、企業名・所属・電話・住所は `$d`（管理者の登録の画面の値。今の `invite` の管理者の項目と同じ扱い）。エンジニアに運営者の印。記録 `dual-add-admin`。本人にお知らせ（`for_role='admin'`）。

置き換え・足すところ：

| 場所 | 変更 |
|---|---|
| `EngineerController::store`（78行） | アドレスが使われていても、`egShareTarget($email, $sesId, $r->user())` があれば通す。無ければ今の文で断る |
| `Workflow::createEngineer` | 招待の前に `egShareTarget` を見る。あれば招待せず `linkEngineer`（`$invite` が false の管理者の登録でも紐づける）。紐づけたかを呼ぶ側に返す（参照の引数 `&$linked` を足すなど） |
| `EngineerController::store` の画面の文 | 自分を紐づけた：「ご自身をエンジニアとして登録しました。エンジニアの画面は、ログアウトして入り直し、「エンジニア」を選ぶと開けます。」／ほかの人：「登録しました。このメールアドレスの方のアカウントに、エンジニアの役割を足しました（招待メールは送っていません）。」 |
| `EngineerController::update`（131行） | 新しいアドレスが `egShareTarget(..., $e->ses_id, $r->user())` の人で、このエンジニアにまだログイン（`users.engineer_id = $id`）が無ければ通す。ここでは紐づけない |
| `Workflow::invite`（199行の前） | `role='eg'` の依頼で `egShareTarget` に当たれば `linkEngineer` してその人を返す（すでに同じエンジニアと兼任なら何もせず返す）。`role='ses'` で `sesShareTarget` なら `addSesRole`。`role='admin'` で `adminShareTarget` なら `addAdminRole`。それ以外は今のまま |
| `EngineerController::invite` の文 | 兼任になった：「この方のアカウントに、エンジニアの役割を足しました。」。今は SES だけが押せるので、管理者兼任はここを通らない（管理者兼任は登録のときに紐づける） |
| `AdminController::sesUsers`（225・226行） | 先に `sesShareTarget($email, $id)`。あれば `invite`（中で `addSesRole`）、文「このメールアドレスのエンジニアのアカウントに、SES 担当者の役割を足しました（招待メールは送っていません）。」。無ければ今のまま |
| `AdminController::admins`（450行） | 先に `adminShareTarget($email)`。あれば `invite`（中で `addAdminRole`）、文「このメールアドレスのエンジニアのアカウントに、管理者の役割を足しました（招待メールは送っていません）。」。無ければ今のまま |
| `AdminController::sesStore`（134）・`Workflow::updateAdmin`（250）・`approveSes` | 変えない（AC5） |

### 3. いまの立場（新しいミドルウェア `app/Http/Middleware/ActingRole.php`）

- `bootstrap/app.php` で web の群の最後に足す（`$middleware->web(append: [ActingRole::class])`）。ルートの `role` より先に動く。
- ログインしていて、DB の行で `isDual` のとき：
  1. `$request->attributes` に `park_dual=true` と `park_base_role`（DB の `role`）を入れる。
  2. `$acting = session('acting_role')`。
  3. `$acting` があり、`usableRoles` に入っていれば、`$u->role = $acting; $u->syncOriginalAttribute('role');`（保存されても DB の `role` を書き換えないため）。
  4. `$acting` があるのに使えなくなっていたら（入っている間に停止されたなど）、ログアウトしてセッションを消し、`/login` へ「この立場では、いまは入れません。もう一度ログインしてください。」。
  5. `$acting` がまだ無いときは何もしない（`Role` が選ぶ画面へ送る）。
- 補助の関数（`app/helpers.php`）：`is_dual(): bool`、`base_role(): ?string`（`request()->attributes` から）。画面と手続きはこれで兼任を見分ける（`$u->role` はいまの立場になっているため）。

### 4. ログインと立場を選ぶ画面

- 流れ：パスワード（`POST /login`）→ 確認コード（今のまま。確認済みの人は省く）→ **立場を選ぶ**（兼任の人だけ）→ 最初の情報登録（まだの人）→ ホーム。確認コードは1回（`users.id` が1つなので、今の `TwoFactor` のまま）。
- `PublicController::login`（37行）：SES の会社が使えないときの断りは、兼任でエンジニアが使えるなら断らない（選ぶ画面でエンジニアだけになる）。管理者兼任は会社の判定をしない。
- `Role` ミドルウェア：確認コードの判定（17〜30行）のすぐあと、最初の情報登録の判定（32行）の前に足す。
  - 兼任で `acting_role` が無い：使える立場が0 → ログアウトして `/login`「このアカウントは、いまは使えません。管理者にご連絡ください。」。1つ → その立場を `acting_role` に入れて、`$u->role` にも入れて先へ（画面は出さない）。2つ → `/login/role` と `logout` 以外は `/login/role` へ。
  - 兼任で、いまの立場が `eg`、エンジニアの `consent_at` が空：`eg/consent`・`account`・`account/*`・`logout` 以外は `/eg/consent` へ。
- `GET /login/role`・`POST /login/role`（`role` の群。新しい `PublicController::roleForm`／`role` か、`AccountController` に置く）：
  - GET：兼任でない、または `acting_role` がすでにある → ホームへ（立場は変えない。AC7）。画面（新しい Blade `resources/views/public/login-role.blade.php`。ログイン画面と同じ見た目）：「このアカウントは、いくつかの立場で使えます。どの立場で入りますか。」。ボタンは `usableRoles` の分だけ：「管理者」「SES 担当者（会社名）」「エンジニア」。下に「立場を変えるときは、ログアウトして入り直してください。」。メールアドレスは出さない。
  - POST（`to`）：兼任でない、`acting_role` がすでにある → ホームへ（何も変えない）。`to` が `usableRoles` に無い → GET に戻して「この立場では、いまは入れません。」。通れば `acting_role` を入れ、記録 `login-role`（立場だけ）。行き先は `Workflow::profileHome`（いまの立場を入れた `$u` で呼ぶ。エンジニアで同意がまだなら `/eg/consent`）。`intended` は使わない（別の立場の画面に送られて 403 になるのを防ぐ）。
  - `throttle:10,1`。
- 最初の情報登録（`/welcome`）は今のまま（`profile_completed_at` は1人に1つ。項目はいまの立場のもの）。
- ログアウトでセッションを消すので、次のログインでまた選ぶ。ブラウザに残ったログインの記録（自動ログイン）で戻ってきたときも、セッションが新しければ選ぶ画面を通る。

### 5. 同意の画面

- `GET/POST /eg/consent`（`role:eg` の群）→ `EgController::consentForm`／`consent`。兼任でない・同意済みなら `profileHome` へ。
- 画面は、招待の画面と同じ同意の文（`resources/views/public/invite.blade.php` の同意の部分を `resources/views/partials/consent.blade.php` に切り出して両方で使う）とチェック欄。POST は `consent` が必須、`engineers.consent_at` を入れ、記録 `consent`。画面に「ログアウト」も出す（同意しないで戻る道）。

### 6. 画面（`resources/views/layouts/app.blade.php` ほか）

- 帯（54〜60行）：兼任のとき、文のうしろに「（エンジニアも兼任。立場を変えるときは、ログアウトして入り直してください）」など。もう一方の立場あての未読があれば「エンジニアあてのお知らせが N 件あります」。ボタンは出さない。
- `$passed`（10行）：兼任で `acting_role` がまだ無い間は、メニューを出さない（選ぶ画面・同意の画面）。
- ベルの数（25行）と `/notifications`：兼任のとき、`for_role` が null かいまの立場の分だけ。
- `AccountController::openNotification`：兼任で、通知の `for_role` がいまの立場と違えば、開かずに `/notifications` へ戻して「このお知らせは「エンジニア」あてです。ログアウトして、エンジニアとして入り直すと開けます。」。既読にもしない。
- `/account`：兼任のとき「このアカウントの立場：SES 担当者（会社名）・エンジニア」（管理者なら「管理者・エンジニア」）の1行。
- 管理者の画面：「招待の状況」（`AdminController::inviteRows` 53行）の役割を「SES（エンジニア兼任）」「管理者（エンジニア兼任）」に。SES の詳細（`admin/ses-show`）の担当者一覧、管理者の一覧・詳細（`adminsIndex`・`adminShow`）に「エンジニア兼任」の札とエンジニアの詳細へのリンク。エンジニアの詳細のログインの欄（`EngineerController::show` の `eu`）は、`engineer_id` で `role` を問わず探し、管理者の画面では「SES 担当者と兼任」「管理者と兼任」を出す。
- SES の画面：`SesController::engineers`（39行）と、SES が見るエンジニアの詳細のログインの欄は、`engineer_id` で探して「使用中」などの状態だけを出す（管理者兼任であることは出さない。AC14）。`ses/company` の担当者一覧には「エンジニア兼任」の札（自社の人なので出してよい）。

### 7. 通知とメール（`app/Services/Notifier.php`）

- `notify(...)` の最後に `?string $forRole = null` を足し、`app_notifications.for_role` に入れる。`toSes` は `'ses'`、`toEg` は `'eg'`、`toAdmins` は `'admin'` を渡す（直属で `toSes` が管理者に回るときは `'admin'`）。
- 宛先が兼任（DB の行で `isDual`）で `$forRole` があるとき、メール本文の頭に「【SES 担当者あて】」「【管理者あて】」「【エンジニアあて】」の1行。
- `egUsers`：`where('engineer_id', $id)->whereIn('role', ['eg', 'ses', 'admin'])->where('active', 1)` に、そのエンジニアが停止中・退会済でない条件（`whereExists` で `engineers`）を足す。
- `admins`・`sesUsers`：今のまま（兼任の人も `role` で入る）。
- `AdminController::broadcast`：`eg`・`eg_available` は「`engineer_id` があり、エンジニアが使える人」（`role` を問わない）にする。既定（全員）も同じく足す。1人1行なので二重にはならない。

### 8. 停止・退会（AC11）

- エンジニアの停止・再開（`Workflow::suspendEngineer`／`resumeEngineer`）：今のまま（`role='eg'` の行だけ止める）。兼任の人は `users` を止めず、`usableRoles` で `eg` を外す。SES がした停止でも、管理者兼任の人の管理者のログインは止まらない。
- エンジニアの退会（`EngineerController::destroy` 168行）：`role='eg'` の行は今のまま。兼任の行は `engineer_id` を null にする（`active` はそのまま）。記録 `dual-unlink`。
- SES 担当者の退会（`Workflow::leaveSesUser`）：兼任なら `role` を `eg` にするだけ（`active`・`left_at` は変えない）。文「{氏名} さんを SES 担当者から外しました。エンジニアとしては引き続き入れます。」。記録は今と同じ `ses-user-leave`。最後の1人のときの断り（`onlyUsableUser`）は今のまま。
- SES 担当者の停止（`suspendSesUser`）：今のまま（ログインが止まる）。兼任なら文に「（エンジニアとしても入れなくなります）」を足す。再開も今のまま。
- 管理者の停止・退会：今は画面が無いので、変えない（作るときは「兼任なら `role` を `eg` に」を SES と同じにする）。
- 会社の停止・退会（`sesSuspend`・`sesDelete`）：今のまま。`sesDelete`（186行）は `role` が `ses`・`eg` の行だけ止めるので、管理者兼任の人は管理者として残り、エンジニアが退会済になるので `eg` が使えなくなる。

### 9. 権限と個人情報

- 権限の判定（`Perms`・`role:` ミドルウェア・各コントローラーの `$u->role`）は変えない。いまの立場が `role` に入るので、SES 担当者・管理者の立場ではその立場に見えるものだけ、エンジニアの立場では本人に見えるものだけになる。
- **管理者兼任の注意**：
  - 管理者は全部が見える立場なので、エンジニアの立場では `role='eg'` になり、本人の分だけに絞られる（ほかのエンジニア・管理者の画面・メール・記録は 403）。ベル・通知の一覧も、エンジニアあての分だけ。
  - 管理者の立場では、自分のエンジニアの情報・応募も、ほかと同じく見え、操作できる。公平性のため、自分のエンジニアに関わる操作（編集・配信・応募・選考の状態・面談の結果・契約・延長・終了）の記録（`audit_log` の `detail`）の頭に「［本人］」を付け、応募・契約の詳細に「管理者ご本人の応募」の札を出す（AC12。判定は `base_role()==='admin'` かつ相手のエンジニアの id ＝ 自分の `engineer_id`）。
  - 管理者の立場で自分のエンジニアの情報を直すと、今の決まりどおり「確認しました」が外れる（`editedBy`）。エンジニアとして入り直して確認する。
  - SES の画面には、管理者兼任であることを出さない（AC14）。
- SES 担当者の立場で自分のエンジニアの情報を直したときも、同じく「確認しました」が外れる。マニュアルに書く。
- 立場はサーバーのセッションで持ち、毎回 `usableRoles` で確かめる。画面から送られた値だけで立場は変わらず、選んだあとは変えられない。
- 記録（`audit_log`）にはアドレス・氏名を書かない（id と立場だけ）。

### 10. 点検

- `app/Console/Commands/Status.php` に `'兼任（SES 担当者）'`・`'兼任（管理者）'`（それぞれ `role` が `ses`・`admin` で `engineer_id` ありの数）を足す。

### 11. マニュアル（正本は park の `resources/manuals/`）

- `ses.md`・`ses-steps.md`：「ご自身もエンジニアとして登録する」（エンジニアの登録で自分のアドレスを入れる → ログアウトして入り直し、「エンジニア」を選ぶ → 初回の同意 → 本人の確認）。SES の立場で自分のエンジニアの情報を直すと確認がやり直しになること。
- `engineer.md`・`engineer-steps.md`：「SES 担当者・管理者と兼ねている方」（ログインのときに立場を選ぶ、変えるときは入り直す、お知らせは立場ごと、どちらあてのメールか）。
- `admin.md`・`admin-steps.md`：管理者がエンジニアを兼ねる方法（エンジニアの登録で自分のアドレス）、運営者の印と「［本人］」の記録、兼任の見え方、担当者・管理者の登録でエンジニアのアドレスを入れたとき、停止と退会の違い（AC11）。
- `README.md` には何も足さない（設定は無い）。写し（41_Park-Project の `04_Manuals`）は JOE が直す。

### 12. 保留中の「テスト期間だけ重複を許す」計画とのぶつかり方（決定3b）

- 同じ所を触る：アドレスの重複の判定（`EngineerController` 78・131行、`AdminController` 134・226・450行、`Workflow::updateAdmin` 250行、`Workflow::invite` 199行）とログイン（`PublicController::login`）。
- 後で作るときの注意：
  - 重複の判定（`emailTaken`）は、**先に兼任の判定**（`egShareTarget`・`sesShareTarget`・`adminShareTarget`）を通す。兼任にできるときは、2行目を作らず紐づける。重複の行を作るのは、兼任にできない組み合わせ（管理者と SES 担当者、他社どうしなど）だけにする。
  - 立場を選ぶ画面は1つにまとめる。今回の `/login/role`（確認コードのあと、1行の中の立場を選ぶ）と、前の計画の `/login/choose`（確認コードの前に、同じアドレスの行を選ぶ）を別々に作らない。「行と立場の組」を並べ、確認コードは選んだ行で行う形に作り直す。
  - マイグレーションの番号は、今回の `000005` より後にする（前の計画の `000004` は使わない）。
  - `park:status` の兼任の数と、重複の数は別の項目のまま。

## 実装の手順

1. マイグレーション（1）を作り、手元で `php artisan migrate`。
2. `Workflow` に 2 の関数を足す。`is_dual()`・`base_role()` を `app/helpers.php` に足す。
3. `ActingRole` ミドルウェアを作り、`bootstrap/app.php` に足す。`Role` に、立場を選ぶ画面と同意の画面への振り分けを足す。
4. `PublicController::login` を 4 のとおり直す。
5. `/login/role` と `/eg/consent`（ルート・コントローラー・Blade。同意の文は partial に切り出す）。
6. 2 の表のとおり、登録・招待・担当者の登録・管理者の登録を直す。
7. `Notifier`（7）と、`broadcast`・一覧（6・7）を直す。
8. 停止・退会（8）を直す。
9. 画面（6）：帯・メニューを出す条件・ベル・通知・アカウント・管理者と SES の一覧。管理者兼任の「［本人］」の記録と札（9）。
10. `park:status`（10）。
11. `tests/Feature/DualRoleTest.php`（下）を作り、`php artisan test` で全部通す（既存の `AdminAccountTest`・`EngineerDetailTest`・`PurgeSamplesTest`・`RememberedLoginTest` などもそのまま通ること）。
12. マニュアル（11）を直す。
13. （JOE）commit・push、`park-deploy.sh` で公開版へ反映（列の追加だけなので常時の了承の範囲）。反映のあと、公開版で断られた SES 担当者の方にもう一度エンジニアの登録をしてもらうよう、tezzo から伝えてもらう（JOE からはメールを送らない）。04_Manuals の写しを直す。

## テスト（`tests/Feature/DualRoleTest.php`）

見本の SES 担当者・管理者（`SampleSeeder`）を `actingAs` し、`TwoFactor::SESSION_KEY` を入れる（`AdminAccountTest` の `as()` と同じ）。兼任の人の立場は `withSession(['acting_role' => ...])` で入れるか、`POST /login/role` で選ぶ。メールは `mail_outbox`、知らせは `app_notifications` で見る。

- AC1：SES 担当者で `POST /engineers`（自分のアドレス）→ ok、`users` の数が同じ、自分の `engineer_id` が新しいエンジニアの id、kind=invite が増えない、`audit_log` に `dual-link`。
- AC2：管理者で `POST /engineers`（`ses_id`＝その会社、担当者のアドレス）→ 兼任、担当者に `for_role='eg'` の通知とメール。アドレスなしのエンジニアに `POST /engineers/{id}`（担当者のアドレス）→ 保存でき、SES で `POST /engineers/{id}/invite` → 兼任。
- AC3：管理者で `POST /engineers`（自分のアドレス）→ `role='admin'` のまま `engineer_id` が入り、`is_operator=1`、お知らせなし。ほかの管理者のアドレス → その人にお知らせ。
- AC4：管理者で `POST /admin/ses/{id}/users`（そのエンジニアのアドレス）→ `role='ses'`、`engineer_id` そのまま、`users` の数が同じ。`POST /admin/admins`（エンジニアのアドレス、企業名あり）→ `role='admin'`、`ses_id` が null、`company_name` が入り、`is_operator=1`。
- AC5：他社の担当者・他社のエンジニア（担当者の登録）・停止中の人・すでに兼任の人のアドレスで断られ、`users`・`engineers` が増えない。SES で管理者のアドレスの `POST /engineers` → 断られる。SES 担当者のアドレスで `POST /admin/admins` → 断られる（管理者と SES 担当者は兼ねない）。
- AC6：兼任の人で `POST /login` → 確認コード → 通したあと `/login/role` へ。`GET /ses`・`GET /eg` → `/login/role` へ（選ぶ前は行けない）。画面に2つの立場が出て、アドレスは出ない。`POST /login/role`（`eg`、同意済み）→ `/eg` を開ける、メニューに「届いた案件」。立場が1つの人は `/login/role` を通らない。エンジニアを停止した兼任の人は、選ぶ画面を出さずに SES 担当者で入る。
- AC7：選んだあとに `POST /login/role`（別の立場）→ ホームへ、`acting_role` は変わらない。`/switch-role` は無い（404）。ログアウト → ログインで、また選べる。
- AC8：SES の立場で `GET /eg` → 403。エンジニアの立場で `GET /ses`・`GET /ses/invoices`・`GET /cases/new`・同じ会社のほかのエンジニア `GET /engineers/{他}` → 403。管理者兼任でエンジニアの立場：`GET /admin`・`GET /admin/mail`・ほかのエンジニア・ほかの人の応募 → 403、自分の `/engineers/{自分}` は見える。兼任でない人が `POST /login/role` → 何も変わらない。どの立場でも DB の `users.role` は変わらない。
- AC9：`consent_at` が空の兼任の人で `eg` を選ぶ → `/eg/consent`。`GET /eg` → `/eg/consent` へ。同意なしの POST → 断る。同意あり → `consent_at` が入り、`/eg` を開ける。
- AC10：兼任の人のエンジニアに `Workflow::distribute` → `for_role='eg'` の通知、メールの頭に「【エンジニアあて】」。SES の立場ではベルの数・一覧に入らず、帯に「エンジニアあてのお知らせが 1 件」。`GET /notifications/{id}` → 開かずに戻り、既読にならない。管理者兼任の人には `toAdmins` が `for_role='admin'` で入る。エンジニアを停止すると、そのあとの `distribute` で知らせが届かない。
- AC11：エンジニアを停止 → 選択肢に出ない。エンジニアの立場で入っている間に停止 → 次の画面でログアウト、`/login` に文。`POST /engineers/{id}/delete` → `engineer_id` が null、`active=1`。担当者の退会（ほかに担当者がいる）→ `role='eg'`、`active=1`、エンジニアで入れる。担当者の停止 → `active=0`、ログインできない。会社の停止 → エンジニアとしてだけ入れる（選ぶ画面なし）。SES が管理者兼任のエンジニアを停止 → 管理者として入れる。会社の退会 → 管理者兼任の人は管理者として入れる。
- AC12：管理者兼任の人が管理者の立場で、自分のエンジニアの応募の状態を変える → `audit_log` の `detail` が「［本人］」で始まり、`GET /applications/{id}` に「管理者ご本人の応募」。ほかの管理者が同じ操作をしても「［本人］」は付かない。
- AC13：既存のテストが全部通る。
- AC14：管理者で `GET /admin` に「SES（エンジニア兼任）」「管理者（エンジニア兼任）」、`GET /admin/ses/{id}` と `GET /admin/admins` に「エンジニア兼任」、`GET /engineers/{id}` に「管理者と兼任」。SES で同じエンジニアの画面に「管理者」の文字が出ない。`park:status --json` に2つの兼任の数があり、合う。
- AC15：`GET /manual/ses` に「ご自身もエンジニアとして登録する」、`GET /manual/engineer` に「SES 担当者・管理者と兼ねている方」、`GET /manual/admin` に管理者の兼任の説明が出る。

## 決まったこと・まだ決めていないこと

決まったこと（tezzo 2026-10-20）：1a（同じ SES の中だけ）、2b（管理者とエンジニアの兼任も入れる）、3b（テスト期間の計画は兼任のあとで別に作る）、4b（立場はログインのときに選ぶだけ。切り替えボタンは作らない）。

まだ決めていないこと：

**1** 今ある別々のアカウントをまとめるか
管理者とエンジニアを、今は別のアドレスで使っている人がいます（tezzo さんご自身）。兼任ができても、今の2つのアカウントは自動ではまとめません（仮定6）。まとめると、エンジニアの側の通知・記録の持ち主の付け替えと、エンジニア側のアカウントの停止が要り、データの書き換えになります。
a. まとめない。今の2つのアカウントのまま使う（推奨）
b. まとめる（別の手順として、了承をいただいてから行う）

**2** 管理者が自分の選考を進めてよいか
管理者兼任の人は、管理者の立場で自分の応募の状態・面談の結果・契約を動かせます。計画では、記録に「［本人］」を残し、画面に札を出すだけにしています（AC12）。直属のエンジニアは管理者しか選考を進められないので、止めると、管理者が1人のときに進められなくなります。
a. 進めてよい。記録と札で見えるようにするだけ（推奨）
b. 自分の選考・契約は進められないようにする（ほかの管理者か所属の SES が進める）

---

# 保留中の別の計画（テスト期間だけ重複を許す）

> 2026-10-20 に書かれ、まだ実装していない計画。tezzo の決定（3b）で、兼任ログインのあとで別に作る。作るときは、上の兼任の計画の「仕様 12」に従って見直す（兼任の判定を先に通す、立場を選ぶ画面を1つにまとめる、マイグレーションの番号を 000005 より後にする）。

# PLAN：テスト期間だけ、メールアドレスの重複を許す（2026-10-20）

## 背景

tezzo の要求（2026-10-20）：テスト期間だけ、同じメールアドレスで管理者・SES 担当者・エンジニアを作れるようにしたい。

決定済み（tezzo）：
- 使うのは公開版（jona.jp/park）。手元は任意。
- 外す範囲は、管理者・SES 担当者・エンジニアの3種類すべて。
- 切り替えは `.env` の `PARK_ALLOW_DUP_EMAIL=true`。コードのチェックは残し、true の間だけ飛ばす。
- DB の `users.email` の一意制約（unique）は、マイグレーションで外す。戻すときは、重複を整理して付け直す（重複があれば付け直しを断り、一覧を出す）。

## 仮定（Planner が置いたもの）

- 仮定1：重複を許すのは「立場が違う」ときだけ。同じアドレスで同じ立場（管理者同士、同じ SES の担当者同士、同じエンジニア）は、設定が true でも作らない（招待の送り直しになる）。同じ人が二重にできて混乱するのを防ぐため。
- 仮定2：別の SES の担当者どうし、別のエンジニアどうしなら、同じアドレスでよい（立場が違う扱い）。
- 仮定3：一意制約は、設定が false に戻っても自動では付け直さない。付け直しは、tezzo が重複を整理したあとにコマンドで行う。
- 仮定4：ログインで、同じアドレス・同じパスワードの人が2人以上いたら、「どの立場で入るか」を選ぶ画面を出す（仕様の 4）。
- 仮定5：`password_reset_tokens` は使っていない（再設定は `users.token` で行う。`SamplePurge` が読むだけ）。主キーが email でも影響なし。変更しない。

## 受け入れ条件

- AC1：`PARK_ALLOW_DUP_EMAIL` が無い・false のときは、今までと全く同じ動き（「このメールアドレスはすでに使われています。」などの文もそのまま。既存のテストが全部通る）。
- AC2：true のとき、管理者の登録・SES の新規登録・SES 担当者の追加・エンジニアの新規登録・エンジニアの編集・管理者の編集（招待中の人のメール変更）で、ほかの立場がすでに使っているアドレスを使える。
- AC3：true でも、同じアドレス・同じ立場の人は二重に作らない（仮定1）。
- AC4：true のとき、同じアドレスの人が何人いても、パスワードが合う「使える人」（active=1・パスワード設定済み）でログインできる。合う人が1人ならその人で、2人以上なら立場を選ぶ画面を通る。違う人で入ってしまうことはない。
- AC5：パスワードの再設定は、true のとき、そのアドレスの使える人それぞれに再設定のメールを送る（立場が分かる文を入れる）。
- AC6：マイグレーションで `users.email` の一意制約を外す。`down()` は重複があれば失敗し、無ければ付け直す。
- AC7：戻す用のコマンドがある。重複があれば付け直しを断り、一覧（個人情報を伏せた形）を出す。無ければ付け直す。
- AC8：`park:status` に、設定の状態・一意制約の有無・重複しているアドレスの数を出す。朝の点検が拾える。
- AC9：2要素認証・招待のリンク・セッションは、選んだ1人に対して働く（別の人のコードやリンクにならない）。

## 仕様

### 1. 設定

- `config/app.php` に `'allow_dup_email' => (bool) env('PARK_ALLOW_DUP_EMAIL', false),`（`skip_2fa` の並び）。公開版でも効く（`skip_2fa` と違い、環境で縛らない）。
- 判定は1か所：`Workflow::allowDupEmail(): bool`（`config('app.allow_dup_email')`）。
- `.env.example` に `PARK_ALLOW_DUP_EMAIL=false` と説明のコメント（テスト期間だけ true）。

### 2. アドレスの使用チェックを1つの手続きにまとめる

`app/Services/Workflow.php` に足す：

```php
// $as：作ろうとしている立場 ['role'=>..., 'ses_id'=>..., 'engineer_id'=>...]。$exceptId：自分（編集のとき）
public static function emailTaken(string $email, array $as, ?int $exceptId = null): bool
```

- false のとき：今までどおり `users.email = $email`（`$exceptId` を除く）があれば true。
- true のとき：同じアドレスで、同じ立場（role が同じ、かつ `ses_id`・`engineer_id` を null→0 にそろえて同じ）の人がいれば true。それ以外は false。

置き換える場所（文言はそのまま。チェックの中身だけ `emailTaken` にする）：

| 場所 | 今 | true のとき |
|---|---|---|
| `AdminController::sesStore`（134行） | email があれば拒否 | 新しい会社なので常に通る |
| `AdminController::sesUsers`（225・226行） | 停止中のアドレス／パスワード設定済みのアドレスは拒否 | 同じ会社の SES 担当者（role=ses, ses_id=$id）に限って、同じ2つの判定をする |
| `AdminController::admins`（450行） | email があれば拒否 | role=admin で判定 |
| `EngineerController::store`（78行） | email があれば拒否 | 新しいエンジニアなので常に通る |
| `EngineerController::update`（131行） | email があれば拒否 | 通す（エンジニアの台帳のアドレスで、users は変えないため） |
| `Workflow::updateAdmin`（250行） | email があれば拒否 | role=admin、`$exceptId=$id` で判定 |
| `InviteAdmin` コマンド（20〜27行） | email で探す | role=admin に絞って探す |

### 3. `Workflow::invite` を立場で探すようにする

- false：今のまま（email だけで探す）。
- true：`email` と立場（role・ses_id・engineer_id）がそろう人を探す。
  - いれば、今と同じ扱い（停止中→null、パスワード設定済み→その人を返す、未設定→招待を送り直す）。
  - いなければ、新しい行を作って招待する（ほかの立場の人がいても作る。今の「立場が違えば null」の判定は true のときは使わない）。
- `reinviteAdmin`（491行）は、その人（id）に送り直す。`invite` に `$d['user_id']` を渡せるようにし、あればその行を使う（false のときも同じ行になるので動きは変わらない）。

### 4. ログイン（`PublicController::login`）

- false：今のまま `Auth::attempt`。
- true：
  1. `users` から `email`・`active=1`・`password` あり の人を全員取り、`Hash::check` で合う人に絞る。
  2. SES 担当者のうち、会社が参加中でない・直属の入れ物の人は外す。外したことで0人になり、外した人がいたら「会社の参加がまだ承認されていないか、停止されています。」。
  3. 0人：「メールアドレスかパスワードが違います。」（従来の文）。
  4. 1人：`Auth::loginUsingId($id, true)` のあと、今の流れ（セッション再生成・`park-last-email` の Cookie・`login` の記録・2要素認証）。この流れは private メソッドにまとめ、選ぶ画面の POST からも使う。
  5. 2人以上：セッションに `login_choices`（id の配列と期限＝5分）を置き、`/login/choose` へ。
- 立場を選ぶ画面 `GET /login/choose`（新しい Blade `resources/views/public/login-choose.blade.php`。ログイン画面と同じ見た目）：「このメールアドレスは、いくつかの立場で登録されています。どの立場で入りますか。」。ボタンは立場の名前だけ（「管理者」「SES 担当者：会社名」「エンジニア：所属の SES 名」）。メールアドレス・氏名は出さない。
- `POST /login/choose`（`uid`。`throttle:10,1`）：セッションの一覧にあり、期限内で、まだ使える人（active=1、SES なら会社が参加中）なら、4 の 4. と同じ流れでログインし、一覧を消す。一覧に無い・期限切れ・停止された：一覧を消して `/login` に戻し「もう一度ログインしてください。」。
- 一覧が無いのに `/login/choose` を開いた、または設定が false：`/login` へ。
- 2要素認証は選んだ人の id で動く（今の `TwoFactor` は id で見ているので変更なし）。

### 5. パスワードの再設定（`PublicController::forgot`）

- false：今のまま。
- true：そのアドレスの使える人（active=1）を全員取り、1人ずつトークンを出してメールを送る。本文の宛名の下に「（{立場} としてのアカウントです）」の1行を足す。画面の文は従来のまま（登録の有無を漏らさない）。

### 6. マイグレーション

`database/migrations/2026_10_20_000004_drop_users_email_unique.php`：

- `up()`：一意制約があれば外す（`Schema::getIndexes('users')` で `users_email_unique` を探し、あれば `$t->dropUnique('users_email_unique')`）。代わりに普通の索引 `users_email_index` を付ける（ログイン・招待の検索が遅くならないように）。
- `down()`：`lower(email)` で2件以上ある行があれば `RuntimeException`（「メールアドレスの重複が N 件あります。php artisan park:email-unique --check で確かめ、整理してから戻してください。」）。無ければ普通の索引を外して unique を付ける。
- 手元の MySQL と、テストの sqlite の両方で動くこと。

### 7. 戻す用のコマンド `park:email-unique`

`app/Console/Commands/EmailUnique.php`、`php artisan park:email-unique {--check} {--restore} {--set-aside=} {--yes}`

- 引数なし／`--check`：一意制約の有無、設定の状態、重複の一覧を出す。一覧はアドレスごとに、伏せたアドレス（`TwoFactor::maskedEmail`）・id・立場・active・パスワードの有無・最後のログイン日。氏名は出さない。
- `--restore`：
  - 設定がまだ true なら断る（「先に .env の PARK_ALLOW_DUP_EMAIL を false にしてください」。終了コード 1）。
  - 重複があれば断り、一覧を出す（終了コード 1）。
  - 無ければ一意制約を付け直す（普通の索引は外す）。すでにあれば「付いています」で終了コード 0。
- `--set-aside=ID`（整理の手伝い）：その人を停止（`active=0`）にし、アドレスを `dup-{ID}@park.invalid` に変える。行は消さない。`--yes` が無ければ内容を見せて止まる。**データの書き換えなので、tezzo の了承を取ってから実行する**。記録（`N::audit`）に「重複整理 #ID」と残す（元のアドレスは書かない）。

### 8. `park:status`

`app/Console/Commands/Status.php` の `$d` に足す（数と状態だけ。アドレスは出さない）：
- `'設定：メールの重複'` → `許可中（テスト用）` / `通常`
- `'users.email の一意制約'` → `あり` / `なし`
- `'メールアドレスの重複（件数）'` → `lower(email)` で2件以上のアドレスの数

点検での読み方（JOE が `41_Park-Project/.claude/agents/park-manager.md` に足す）：許可中・一意制約なしは「テスト期間中」として報告に必ず書く。設定が通常なのに一意制約なし、または重複が1件以上なら「戻し忘れ」として返事待ちに上げる。

### 9. 境界の挙動

- 設定 false：全部従来どおり（AC1）。一意制約が外れていても、コードのチェックで重複は増えない。
- true → false に戻した：重複している既存の人は、ログインが従来の `Auth::attempt`（先に見つかった1人）になる。だから戻す前に重複を整理する（下の「戻し方」）。
- 同じアドレス・同じパスワードで片方が停止中：停止中は候補に入らない。
- 同じアドレス・違うパスワード：合う人だけで入る（選ぶ画面は出ない）。
- 二重送信：`/login/choose` の POST は、ログイン後に一覧を消すので、2回目は `/login` に戻る。
- 0件：候補が0人なら従来の誤りの文。
- 権限：選ぶ画面はログイン前の画面。一覧に入っている id 以外は選べない（id を書き換えても入れない）。

### 10. マニュアル・説明

- 利用者向けのマニュアル（`resources/manuals/` の運営・SES・エンジニア）は直さない。テスト期間だけの設定で、選ぶ画面も true の間しか出ないため。
- park の `README.md` の設定の項に `PARK_ALLOW_DUP_EMAIL` と、戻し方（下）を書く。
- `41_Park-Project/04_Manuals/運用手順.md` にも同じ要点を足す（JOE が行う）。

## 戻し方（README・運用手順に書く手順）

1. 公開版で `php artisan park:email-unique --check` を見る。
2. 重複があれば、使わない方を管理者の画面で停止・退会にしたうえで、`--set-aside=ID`（tezzo の了承が要る）で外す。
3. `.env` の `PARK_ALLOW_DUP_EMAIL` を false にし、`php artisan config:cache`（**.env の書き換えは tezzo の了承が要る**）。
4. `php artisan park:email-unique --restore`。重複が残っていれば断られるので 2 に戻る。
5. `park:status` で「通常」「あり」「0」を確かめる。

## 実装の手順

1. `config/app.php` と `.env.example` に設定を足す。
2. `Workflow::allowDupEmail()`・`emailTaken()` を足し、2 の表の場所を置き換える。既存テストで false の動きが変わらないことを確かめる。
3. `Workflow::invite` を 3 のとおり直し、`reinviteAdmin` で id を渡す。`InviteAdmin` コマンドも直す。
4. マイグレーション（6）を作り、手元で `migrate` → `migrate:rollback --step=1` → `migrate` を確かめる。
5. `PublicController::login` を 4 のとおり直し、`/login/choose`（GET・POST）のルートと Blade を足す。
6. `PublicController::forgot` を 5 のとおり直す。
7. `park:email-unique` コマンド（7）を作る。
8. `park:status`（8）に3行足す。
9. `tests/Feature/DupEmailTest.php` を作る（下）。`php artisan test` で全部通す。
10. README を直す。
11. （JOE）commit・push。公開版への反映は `park-deploy.sh` だが、**このマイグレーションは一意制約を外す変更で、常時の了承（列・テーブルの追加）の範囲を超えるので、反映の前に tezzo に一言確認する**。公開版の `.env` に `PARK_ALLOW_DUP_EMAIL=true` を書く（と `config:cache`）のも tezzo の了承が要る。テストで実際に招待メールを送るのも同じ。
12. （JOE）`運用手順.md` と park-manager の指示を直す。

## テスト（`tests/Feature/DupEmailTest.php`）

テストでは `config(['app.allow_dup_email' => true])` で切り替える。メールは `mail_outbox` に入るかで見る。

- AC1：設定 false で、管理者がいるアドレスを使って、SES 担当者の追加（`POST /admin/ses/{id}/users`）・管理者の登録（`POST /admin/admins`）・エンジニアの新規登録（SES で `POST /engineers`）をすると、今までの文で断られ、`users` が増えない。既存の `AdminAccountTest` の重複のテスト（134・306・450行付近）がそのまま通る。
- AC2：設定 true で、管理者 A のアドレスを使って、管理者で SES を新規登録 → `users` に role=ses の行が増え、招待が `mail_outbox` に入る。SES で同じアドレスのエンジニアを登録 → role=eg の行が増える。エンジニアの編集で、ほかの人が使うアドレスに変えても保存できる。
- AC3：設定 true で、管理者 A と同じアドレスで管理者を登録 → 「すでに使われています」で断られる。招待中の管理者 B のメールを A と同じに変える → 断られる。同じ SES に同じアドレスの担当者を2回追加 → 2回目は行が増えない。
- AC4：設定 true で、同じアドレス・同じパスワードの管理者と SES 担当者を作り `POST /login` → `/login/choose` へ。画面に「管理者」「SES 担当者」が出て、アドレスは出ない。`POST /login/choose`（SES の id）→ ログインした人がその id で、2要素認証へ進む。パスワードが SES の方だけ合う → 選ぶ画面を出さず SES で入る。一覧に無い id を送る → `/login` に戻り、ログインしていない。期限切れ（5分超。`$this->travel(6)->minutes()`）も同じ。片方を停止 → 選ぶ画面を出さずもう片方で入る。SES の会社を停止 → 管理者で入る。
- AC5：設定 true で、同じアドレスの使える人2人に `POST /forgot` → `mail_outbox` に2通、`users.token` が2人とも別に入る。設定 false では1通。
- AC6：マイグレーション後、同じアドレスの行を `DB::table('users')->insert` で2行入れられる。重複がある状態で `down()` を呼ぶと例外。
- AC7：`park:email-unique --restore` を、設定 true のとき → 断る（終了コード 1）。設定 false・重複あり → 断り、出力に伏せたアドレスと id が出る（生のアドレスは出ない）。重複なし → 付け直し、そのあと同じアドレスの insert が失敗する。`--set-aside=ID --yes` → その人が停止・アドレスが `dup-ID@park.invalid` になり、`--restore` が通る。`--yes` なしでは何も変わらない。
- AC8：`park:status --json` に3つのキーがあり、設定・一意制約・重複の数が実際と合う。
- AC9：AC4 で SES を選んだあと、セッションの `two_factor_pending` の user が SES の id。招待のリンク（`/invite/{token}`）は、トークンを出した人の行のパスワードだけを変える。
