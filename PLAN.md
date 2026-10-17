# PLAN：最初の情報登録に「企業名」（管理者）・「SES名」（SES）を足す（2026-10-17）

## 背景

tezzo の要求（要約）：

1. 最初のログインの「あなたの情報」（`/welcome`）で、管理者は「企業名」、SES は「SES名」を必須にする。エンジニアは変わらない。
2. 管理者の企業名は保存先がないので `users` に列を足す。SES名は `ses_companies.name` に保存する（管理者が招待時に入れた略称が最初から入っている。正式名称は SES 自身が入れる）。
3. 対象は `profile_completed_at` が空の人だけ。登録済みの管理者・SES は「アカウント」（`/account`）で直せる（必須にはしない）。
4. （2026-10-17 tezzo の回答）管理者の企業名を、メールの差出人にも使う（L）。SES の名前の変更はベルだけで知らせる。過去の請求書の宛名は新しい名前で出るままでよい。

前の計画（最初の情報登録・立場の帯・直属）は実装済み。この計画はその上に足す。迷ったところは「仮定」に書いた。決まったことは末尾の「決まったこと」。決めてもらうことは、もう残っていない。

守ること：Laravel 12・Blade・`DB::table`、業務の手続きは `app/Services/Workflow`、画面の文は日本語のやさしい言い方。外部ライブラリは足さない。テストは見本データ（`@park.example`）だけ使う。

## 受け入れ条件

### H. 管理者の企業名

- H1. `users` に `company_name`（string(100) null）がある。マイグレーション後、既存の人は全員 null。
- H2. `profile_completed_at` が null の管理者の `GET /welcome` に「企業名」の欄（必須）が出る。初期値は `users.company_name`（null なら空）。欄の下に hint「メールの差出人の名前にも使います。」。
- H3. 企業名が空・空白だけ（半角・全角の空白）で `POST /welcome` すると、保存されず「企業名」のエラーが出る（`profile_completed_at` も null のまま）。
- H4. 101 文字以上でもエラー。100 文字ちょうどは保存できる。
- H5. 正しく `POST /welcome` すると、`users.company_name` に前後の空白を取った値が入り、`/admin` へ進む。
- H6. 登録済みの管理者の `/account`「基本」に「企業名」の欄（必須ではない。hint は H2 と同じ）があり、直して保存できる。空で保存すると今の値のまま（氏名と同じ扱い）。101 文字以上はエラー。
- H7. 立場の帯：企業名がある管理者は「管理者（{企業名}）としてログインしています：{氏名}」。無い管理者は今のまま「管理者としてログインしています：{氏名}」。
- H8. `/admin/settings` の管理者の一覧に「企業名」の列が出る（無い人は「—」）。

### I. SES の SES名

- I1. `profile_completed_at` が null の SES の `GET /welcome` に「SES名」の欄（必須）が出て、`ses_companies.name`（管理者が入れた名前）が入力済みで表示される。欄の下に hint「管理者が入れた名前です。正式名称に直してください。」。
- I2. SES名が空・空白だけで `POST /welcome` すると、保存されず「SES名」のエラーが出る。`ses_companies.name` も `profile_completed_at` も変わらない。
- I3. 101 文字以上でもエラー（`ses_companies.name` は 255 まで入るが、画面の上限は 100 にそろえる）。
- I4. 正しく `POST /welcome` すると、`ses_companies.name` が前後の空白を取った値になり、今までどおり `/ses/company`（会社の確認がまだのとき）か `/ses` へ進む。
- I5. 名前を変えたときだけ（前と違うとき）：
  - `ses_companies.updated_at` が今になる。`self_confirmed_at` は変えない（SES 自身が直したので）。
  - `audit_log` に action=`ses-rename`、detail=「旧名 → 新名」が1行入る（会社名は個人情報ではないので入れてよい）。
  - 管理者全員にベルの通知（メールは送らない。決まったこと 2）「SES の名前が変わりました」本文「{旧名} が名前を {新名} に変えました。」link=`/admin/ses/{id}`。（`app_notifications` には kind 列がないので、title「SES の名前が変わりました」で確かめる。kind は呼び出しの引数だけ。）
- I6. 名前が同じ（変えずに登録）なら、`ses_companies` は書き換えず、I5 の記録・通知も出さない。
- I7. 変えた名前は、保存していない所すべてに出る（どこも `ses_companies.name` を join して表示しているため）：立場の帯、管理者の SES 一覧・詳細、エンジニア一覧の所属、案件・応募・契約・請求書の画面、`user_label`（ヘッダー右・メールの宛名）、`/ses/company`。発行済みの請求書の宛名も新しい名前で出る（決まったこと 3）。
- I8. ほかの SES と同じ名前でも保存できる。そのときは保存のあとのメッセージを warn にし「登録しました。同じ名前の SES がほかにもあります。見分けられるよう、正式名称で入れてください。」を出す（`/welcome` のときは、行き先の画面にこの文を出す。`/ses/company` に進む場合も、この文を優先する）。I5 の通知の本文に「（同じ名前の SES がほかにもあります）」を足す。比べるのは自分以外の `ses_companies`（kind を問わない）で、前後の空白を取った完全一致。
- I9. 同じ SES に担当者が2人以上いるとき：
  - SES名は会社に1つなので、2人目の `/welcome` には、1人目が直したあとの名前が入力済みで出る。
  - 2人目がそのまま登録すれば I6（何も起きない）。直せば I5（後から保存した名前になる）。
  - 1人目の `profile_completed_at` には影響しない。
- I10. 登録済みの SES の `/account`「基本」に「SES名」の欄（必須ではない、今の `ses_companies.name` が入っている）がある。直して保存すると I5・I8 と同じ。空で保存すると今の名前のまま。101 文字以上はエラー。
- I11. `/ses/company` の「会社名」の欄は今のまま（保存の先は同じ `ses_companies.name`）。ただしラベルを「SES名（会社名）」にそろえ、ここで名前を変えたときも I5 の記録・通知を出す（三つの入口で同じ動きにする）。
- I12. `/admin/ses/new`・`/admin/ses/{id}` の名前の欄に hint「略称でもかまいません。正式名称は、SES のご担当者が最初のログインで入れます。」を出す。管理者が名前を直す動き（`sesUpdate`）は今のまま（`self_confirmed_at` を null に戻す）。

### J. エンジニアと登録済みの人

- J1. エンジニアの `/welcome`・`/account` に「企業名」「SES名」の欄は出ない。送っても、`users.company_name`・`ses_companies.name` は変わらない。
- J2. 管理者の `/welcome` に「SES名」、SES の `/welcome` に「企業名」は出ない。送っても保存しない（SES の `users.company_name` は null のまま。管理者の POST に `ses_name` があっても `ses_companies` は変わらない）。
- J3. `profile_completed_at` が入っている人は、今までどおり `/welcome` を開くと `/account` へ転送される（企業名・SES名が空でも、画面は出さない）。
- J4. 管理者の直属（kind=direct）の行には role=ses の人がいない（ログインもできない）ので、`/welcome` から直属の行の名前は変わらない（念のため、`saveProfile` は kind=ses の行だけ書き換える）。

### L. 管理者の企業名をメールの差出人に使う（決まったこと 1）

今の差出人の決まり方（コードで確かめた）：

| どこ | 今 | 決めている所 |
|---|---|---|
| ① メールの From（差出人の名前・アドレス） | 全メール同じ。`.env` の `MAIL_FROM_NAME`・`MAIL_FROM_ADDRESS` | `config/mail.php`。`Notifier::deliver` は from を指定していない |
| ② 本文の最後の署名 | 全メール同じ。設定「管理者の名前（メールの署名）」＝ `setting('operator_name')`（既定「PARK 管理者」） | `Notifier::queueMail` 20 行目 |
| ③ 案件の案内メールの差し込み `{送り主}` | 管理者は `operator_name` だけ（氏名なし）。SES は「SES名 氏名」 | `CaseMailController@preview` 49 行目（`CaseMail::send` 92 行目の `$from` は使われていない） |
| ④ 宛名「○○ 様」・ヘッダー右 | 管理者は「`operator_name` 氏名」 | `user_label`（`helpers.php` 52 行目） |
| ⑤ 件名の頭 | `[app_name]` | `Notifier::queueMail` |

管理者は複数いるので、「どの管理者の企業名か」を次のように決める。

- **送り主がはっきりするメール**（操作した管理者が1人に決まる）→ その管理者本人の企業名：
  - 管理者が送る案件の案内メール（`/cases/{id}/mail/send`）
  - 一斉送信（`/admin/mail/broadcast`）
  - テストメール（`/admin/settings/testmail`）
  - 管理者が出す招待（SES の招待・管理者の招待と再招待・SES の承認・管理者がエンジニアを招待。`Workflow::invite` の `$actor` が admin のとき）
- **送り主がはっきりしないメール**（通知・確認コード・パスワード再設定・参加申し込みの受付・SES が出す招待と案内メール）→ 今までどおり（①は `.env`、②は「運営の名前」）。代表の管理者を決めるかは tezzo に決めてもらう（質問 4）。
- **企業名が空の管理者**は、送り主がはっきりするメールでも今までどおり（①は `.env`、②③は「運営の名前」）。

受け入れ条件：

- L1. `mail_outbox` に `from_name`（string(150) null）がある。マイグレーション後、既存の行は全部 null。
- L2. 企業名が「見本運営株式会社」の管理者がテストメールを送ると、`mail_outbox.from_name` が「見本運営株式会社」、本文の最後の署名が「見本運営株式会社」になる。
- L3. 同じ管理者の一斉送信・招待のメールも L2 と同じ（`from_name` と署名が企業名）。
- L4. 同じ管理者が案件の案内メールを送ると、プレビューと送った本文の `{送り主}` が「見本運営株式会社 {氏名}」、`from_name` と署名が企業名。SES が送る案内メールは今のまま（`from_name` null、`{送り主}` は「SES名 氏名」、署名は「運営の名前」）。
- L5. 企業名が空の管理者のメールは、`from_name` が null、署名が「運営の名前」、`{送り主}` が「運営の名前」（今までと同じ）。
- L6. 送り主がはっきりしないメール（通知・確認コード・パスワード再設定・申し込みの受付）は、管理者に企業名があっても `from_name` が null、署名が「運営の名前」。
- L7. 実際に送るとき（`Notifier::deliver`。再送も同じ）、`from_name` があれば From の名前をそれにする。アドレスは `MAIL_FROM_ADDRESS` のまま変えない（送信ドメインの認証で迷惑メールにされないため）。`from_name` が null なら今までどおり（`.env` の名前）。
- L8. `user_label`：企業名がある管理者は「{企業名} {氏名}」、無い管理者は今のまま「{運営の名前} {氏名}」（ヘッダー右、管理者あてのメールの宛名に出る）。
- L9. 管理者の「メール」画面（`/admin/mail` の一覧と `/admin/mail/{id}`）に「差出人」を出す（`from_name`、null なら「既定」）。
- L10. 設定の「管理者の名前（メールの署名）」のラベルを「運営の名前（企業名が無いとき・自動のメールの署名）」にする（設定の key `operator_name` は変えない）。

### K. マニュアル・README・テスト

- K1. `resources/manuals/` の admin・ses（`*.md` と `*-steps.md`）の初回ログインの説明に、企業名・SES名（必須）が書いてある。SES には「管理者が入れた名前が入っています。正式名称に直してください。」。アカウントの節に、あとから直せることが書いてある。engineer の2つは変えない。
- K2. README の「最初の情報登録」の段落に、`users.company_name` と SES名の保存先を1行足す。
- K3. `tests/Feature/ProfileAndDirectTest.php` の既存のテストが通る（管理者・SES の `POST /welcome` に企業名・SES名を足す。テストを弱めない）。H〜J・L を新しいテストでたどる（下の「テストの手順」）。
- K4. `php artisan test` が全部通る。
- K5. admin のマニュアル（`admin.md`・`admin-steps.md` の設定の表 42 行目あたり）に、L の決まり（企業名が入っていれば、自分が送るメール・招待の差出人と署名が企業名になる。自動のメールは「運営の名前」）を書く。README のメールの設定（152・153 行目あたり）に「`MAIL_FROM_NAME` は企業名の無い管理者と自動のメールの差出人。アドレスは全メール共通」を1行足す。

## 仕様

### データ（マイグレーション `database/migrations/2026_10_17_000003_user_company_name.php`）

- `users`：`company_name` string(100) null（`position` の後）。
- `mail_outbox`：`from_name` string(150) null（`to_name` の後）。
- `down()` で2つとも消す。
- `ses_companies` は変えない（`name` は string 255、NOT NULL、unique なし）。
- seeder は変えない（見本の管理者の企業名は null のまま。今の帯・メールのテストが通るように）。

### 入力の決まり（`AccountController`）

- 先に、`company_name`・`ses_name` の前後の空白（半角・全角の空白、改行）を取って `$r->merge()` する。`preg_replace('/^[\s　]+|[\s　]+$/u', '', ...)`。空になれば `null`。Laravel の空白の取り除きは全角の空白を取らないため。
- `profileRules(bool $required, string $role)` にする：
  - 今の4つ（name・kana・phone・position）はそのまま。
  - role=admin：`company_name` → `$required ? 'required' : 'nullable'` ＋ `|max:100`。
  - role=ses：`ses_name` → 同じ。
  - role=eg：足さない。
- `profileNames()` に `company_name => '企業名'`、`ses_name => 'SES名'` を足す。
- `welcome()`・`update()` とも、`$r->user()->role` を渡す。

### 業務の手続き（`app/Services/Workflow.php`）

- `saveProfile($user, array $d): ?string` に変える（戻り値は warn のメッセージ、無ければ null）：
  - admin：`company_name` が null でなければ `users.company_name` に入れる（null なら今の値のまま。`/welcome` では required なので必ず入る）。
  - ses：`ses_name` が null でなければ `self::renameSes($user->ses_id, $d['ses_name'], $user)` を呼び、その戻り値を返す。
  - eg：どちらも触らない。
  - ほかは今のまま。`users` の更新と `renameSes` は `DB::transaction` でまとめる。
- 新しく `renameSes(int $sesId, string $name, $actor): ?string`：
  - 行が無い・kind が `ses` でない → 何もせず null（J4）。
  - 前と同じ名前 → 何もせず null（I6）。
  - 違う → `ses_companies` の name・updated_at を更新、`N::audit($actor->id, 'ses-rename', "旧 → 新")`、`N::toAdmins('SES の名前が変わりました', 本文, "/admin/ses/$sesId", false, 'ses-rename')`（I5）。
  - 自分以外に同じ名前があれば、I8 の文を返す（同じ名前のとき、通知の本文にも足す）。無ければ null。
- `SesController@saveCompany`：名前の部分を `W::renameSes(...)` に任せ、ほかの列は今のまま更新する。戻り値があれば warn で返す（I11）。
- `AdminController@sesUpdate` は変えない（管理者が直すときは通知しない）。
- `invite($d, $actor, $note)`：`N::queueMail(...)` に `fromName: admin_sender($actor)` を渡す（L3。`$actor` が null・SES ならば null になる）。

### メールの差出人（L）

- `app/helpers.php`：
  - 新しく `admin_sender(?object $u): ?string`：`$u` が role=admin で `company_name` が空でなければ、その値（前後の空白を取る）。それ以外は null。
  - `user_label`：admin の行を `trim((admin_sender($u) ?? setting('operator_name')).' '.$u->name)` にする（L8）。
- `app/Services/Notifier.php`：
  - `queueMail(..., ?string $replyTo = null, ?string $fromName = null)`（引数を最後に足す。今の呼び出しは変えなくてよい）。
  - 署名：`($fromName ?: setting('operator_name'))`。`mail_outbox.from_name` に `$fromName` を入れる。
  - 新しく `fromOf(object $m): ?array`：`from_name` があれば `[config('mail.from.address'), $m->from_name]`、無ければ null。
  - `deliver`：`fromOf` が配列なら `$msg->from(...$from)`。null なら何もしない（今まで）。
- 呼び出しの変更：
  - `CaseMailController@preview` 49 行目：admin のとき `admin_sender($u) ? admin_sender($u).' '.$u->name : setting('operator_name')`（SES は今のまま）。
  - `CaseMail::send`：`queueMail(..., $replyTo, $u->role === 'admin' ? admin_sender($u) : null)`。使っていない `$from = user_label($u)`（92 行目）は消す。
  - `AdminController@broadcast`（275 行目）・`testMail`（317 行目）：`fromName: admin_sender($r->user())`。
  - `Workflow::invite`：上のとおり。
  - そのほか（`Notifier::notify`、`TwoFactor`、`PublicController` のパスワード再設定、`Workflow` 110 行目の申し込みの受付）は変えない（L6）。

### 画面とルート（ルートは変えない）

#### `/welcome`（`account/welcome.blade.php`・`welcomeForm`・`welcome`）

- `welcomeForm`：`$v` に `company_name`（admin：`$u->company_name`）、`ses_name`（ses：`ses_companies.name`）を足す。
- 欄の並び：氏名 → ふりがな → 電話 → （admin）企業名（required、hint は H2）／（ses）SES名（required、hint は I1）→ 所属・役職（eg 以外）。
- `welcome()`：`$warn = W::saveProfile(...)`。`[$to, $msg] = W::profileHome($u)`。`$warn` があれば `go($to, $warn, 'warn')`、無ければ今のまま。

#### `/account`（`account/show.blade.php`・`update`）

- 「基本」に、admin は「企業名」（hint は H2）、ses は「SES名」（今の名前を入れる。`show()` で `ses_companies.name` を渡す）。どちらも required を付けない。
- `update()`：`$warn` があれば `go('/account', '保存しました。'.' '.$warn, 'warn')`。

#### 立場の帯（`layouts/app.blade.php`）

- admin の行：`$u->company_name` があれば「管理者（{{ $u->company_name }}）としてログインしています：{{ $u->name }}」、無ければ今の文（H7）。

#### 管理者の設定（`admin/settings.blade.php`）

- 管理者の一覧の表の列を 名前・企業名・メール・状態・最終ログイン・（操作）にする（H8）。
- `operator_name` の欄のラベルを L10 の文にする。

#### メールの画面（`admin/mail.blade.php`・`admin/mail-show.blade.php`）

- 「差出人」を出す（L9）。

#### SES の画面

- `ses/company.blade.php`：ラベル「会社名」→「SES名（会社名）」（I11）。
- `admin/ses-new.blade.php`・SES の詳細の画面（名前の欄）：hint を足す（I12）。ファイル名は実物に合わせる。

### 正常系・境界（まとめ）

| 場面 | 動き |
|---|---|
| 空欄・空白だけ（`/welcome`） | エラー。何も保存しない（H3・I2） |
| 空欄（`/account`・`/ses/company`） | 今の値のまま（H6・I10） |
| 101 文字以上 | エラー（H4・I3・H6・I10） |
| 前後の空白 | 取って保存（H5・I4） |
| SES名を変えた | ses_companies に反映、記録・管理者へのベル。表示は全画面で新しい名前（I5・I7） |
| 変えなかった | 何も書き換えない（I6） |
| 他の SES と同じ名前 | 保存はする。warn と通知に一言（I8） |
| 同じ SES の担当者が複数 | 名前は会社に1つ。後から保存した名前になる（I9） |
| 登録済みの人 | `/welcome` は出ない。`/account` で任意に直す（J3・H6・I10） |
| エンジニア・別の立場の欄を送った | 無視（J1・J2） |
| 二重の POST `/welcome` | 2回目は `profile_completed_at` を上書きしない（今のまま）。名前が同じなら I6 |
| 権限 | 変わらない（`/welcome`・`/account` は role ミドルウェアの群。他社の SES名は自分の `ses_id` の行しか触れない） |
| 管理者が2人以上・企業名が違う | 送り主がはっきりするメールは、それぞれ本人の企業名。自動のメールは「運営の名前」（L） |
| 企業名が空の管理者 | メールは今までどおり（L5） |
| 企業名を変えた後の再送 | `mail_outbox.from_name` は作ったときの名前のまま送る（L7） |

## 直すファイルと箇所の一覧

| ファイル | 箇所 | 直すこと |
|---|---|---|
| `database/migrations/2026_10_17_000003_user_company_name.php` | 新規 | `users.company_name`・`mail_outbox.from_name` |
| `app/Http/Controllers/AccountController.php` | `show`・`update`・`profileRules`・`profileNames`・`welcomeForm`・`welcome` | 欄・検証・空白の取り除き・warn |
| `app/Services/Workflow.php` | `saveProfile`、新規 `renameSes`、`invite`（43 行目） | 保存・記録・通知・同名の判定・招待の差出人 |
| `app/helpers.php` | 新規 `admin_sender`、`user_label`（52 行目） | L8 |
| `app/Services/Notifier.php` | `queueMail`（17〜27 行目）・`deliver`（34 行目）、新規 `fromOf` | L1・L2・L7 |
| `app/Services/CaseMail.php` | `send`（92・106 行目） | L4 |
| `app/Http/Controllers/CaseMailController.php` | `preview`（49 行目） | L4 |
| `app/Http/Controllers/AdminController.php` | `broadcast`（275 行目）・`testMail`（317 行目） | L2・L3 |
| `app/Http/Controllers/SesController.php` | `saveCompany`（87 行目） | 名前は `renameSes` に任せる |
| `resources/views/account/welcome.blade.php` | 欄 | 企業名・SES名 |
| `resources/views/account/show.blade.php` | 「基本」 | 企業名・SES名 |
| `resources/views/layouts/app.blade.php` | 帯の admin の行（44 行目） | 企業名 |
| `resources/views/admin/settings.blade.php` | 5 行目のラベル・管理者の一覧 | L10・H8 |
| `resources/views/admin/mail.blade.php`・`mail-show.blade.php` | 一覧・詳細 | L9 |
| `resources/views/ses/company.blade.php` | 4 行目 | ラベル |
| `resources/views/admin/ses-new.blade.php`・SES の詳細の画面 | 名前の欄 | hint |
| `resources/manuals/admin.md`（29・30 行目）・`admin-steps.md`（12 行目・3-1・42 行目） | — | K1・K5 |
| `resources/manuals/ses.md`（24 行目・7 章）・`ses-steps.md`（12 行目・3-2） | — | K1 |
| `README.md` | 135 行目の段落・152〜153 行目 | K2・K5 |
| `tests/Feature/ProfileAndDirectTest.php` | 既存の `POST /welcome`（106・113・117 行目、146・150 行目が admin なら） | `company_name`・`ses_name` を足す |
| 同上 | 新規のテスト | H〜J・L |

変えなくてよいと確かめたところ：`Role` ミドルウェア（`profile_completed_at` だけで判断）、`profileHome`、`config/mail.php` と `.env`（アドレス・既定の名前はそのまま）、件名の頭 `[app_name]`、請求書の画面の `operator_name`（メールではない）、通知・確認コード・パスワード再設定・申し込みの受付のメール（L6）、名前を join している各画面（I7。保存していないので自動で新しい名前になる）。

## 作業の手順

1. マイグレーションを書き、`php artisan migrate`（手元）。
2. `Workflow::renameSes` と `saveProfile` の変更。
3. `AccountController`（空白の取り除き・`profileRules`・`profileNames`・`welcomeForm`・`welcome`・`show`・`update`）。
4. `welcome.blade.php`・`show.blade.php`。
5. ここで既存のテストの `POST /welcome`（admin・ses）に `company_name`・`ses_name` を足し、`php artisan test` が通ることを確かめる。
6. `SesController@saveCompany` と `ses/company.blade.php` のラベル。
7. 帯（layout）と管理者の一覧（settings）。SES の登録・詳細の hint。
8. メールの差出人（L）：`admin_sender`・`user_label` → `Notifier`（`queueMail`・`fromOf`・`deliver`）→ 呼び出し（`CaseMailController@preview`・`CaseMail::send`・`broadcast`・`testMail`・`invite`）→ 設定のラベル・メールの画面。ここで `php artisan test` を回す（`user_label` の変更で既存のテストが落ちないか。見本の管理者は企業名 null なので変わらないはず）。
9. 新しいテスト（下の手順）。
10. マニュアル（admin・ses の4ファイル）と README。
11. `php artisan test` が全部通ることを確かめ、reviewer・tester に回す。
12. 公開版への反映は、JOE が push 後に `park-deploy.sh` で行う（**migrate を含む**。`users.company_name`・`mail_outbox.from_name` を足すため。常時了承の範囲）。
    - 公開版で見た目が変わる点：管理者が企業名を入れたあと、その管理者が送る案内メール・一斉送信・テストメール・招待の**差出人の名前と署名が企業名に変わる**。宛名・ヘッダー右の管理者の表示も「企業名 氏名」になる。企業名を入れるまでは何も変わらない。
    - この件は**メールの一斉送信ではない**（反映しただけでメールは1通も出ない。I5 の通知もベルだけ）。`.env` の書き換え・データの削除も無い。
    - 41_Park-Project の `04_Manuals` の写し（`管理者`・`SES` の `マニュアル.md`・`操作手順書.md`）は JOE が写し直す（`エンジニア` は変わらない）。

## テストの手順（`tests/Feature/ProfileAndDirectTest.php` に足す。L は `tests/Feature/MailSenderTest.php` を新しく作ってもよい）

今と同じく `as()`・`unfinish()` を使う。

- H1：`Schema::hasColumn('users','company_name')`。seeder のあと全員 null。
- H2：admin を unfinish → `GET /welcome` に「企業名」と「メールの差出人の名前にも使います」。
- H3：`POST /welcome` name あり・`company_name` が `''`、`'   '`、`'　　'`（全角）→ それぞれ `assertSessionHasErrors('company_name')`、`profile_completed_at` null。
- H4：`str_repeat('あ',101)` はエラー、100 文字は `/admin` へ。
- H5：`company_name => '  見本運営株式会社　'` → DB に `見本運営株式会社`、`/admin` へ。
- H6：登録済みの admin で `POST /account` `company_name=新社名` → 保存。`company_name` 無しで `POST /account` → 前の値のまま。
- H7：`company_name` を入れた admin の `/admin` に「管理者（見本運営株式会社）としてログインしています：」。null の admin は今の文（既存の test_b はそのまま通る）。
- H8：`/admin/settings` に「企業名」と値。
- I1：ses1 を unfinish → `GET /welcome` に「SES名」と「みどり情報システム」（value に入っている）。
- I2：`ses_name` 空・空白だけ → エラー。`ses_companies.name` が「みどり情報システム」のまま、`profile_completed_at` null。
- I3：101 文字 → エラー。
- I4・I5：`ses_name => '株式会社みどり情報システム'` → `ses_companies.name` が変わり、`self_confirmed_at` は前と同じ。`audit_log` に action=ses-rename が1行。admin の `app_notifications` に title「SES の名前が変わりました」が1件。`mail_outbox` は増えない。
- I6：名前を変えずに登録 → `ses_companies.updated_at` が変わらず、ses-rename の記録・通知が増えない。
- I7：I4 のあと、ses1 の `/ses` の帯に「SES（株式会社みどり情報システム）」、admin の `/admin/ses` にも新しい名前。
- I8：もう1つの見本の SES の名前に変える → 保存され、`session('flash')` が warn で「同じ名前の SES がほかにもあります」。通知の本文にも同じ一言。
- I9：同じ ses_id で role=ses の users を1人足し（`W::createUser` か insert、`profile_completed_at` null）、ses1 が名前を変えたあと、その人の `GET /welcome` に新しい名前。そのまま `POST` → 通知が増えない。ses1 の `profile_completed_at` は変わらない。
- I10：登録済みの ses1 で `POST /account` `ses_name=新しい名前` → 反映・通知。`ses_name` 無し → 今のまま。
- I11：ses1 で `POST /ses/company` の name を変える → 反映・ses-rename の記録。`/ses/company` に「SES名（会社名）」。
- I12：admin の `/admin/ses/new` に「略称でもかまいません」。
- J1：eg1 を unfinish → `/welcome` に「企業名」「SES名」が無い。`company_name`・`ses_name` を送って登録 → eg1 の `company_name` null、所属 SES の名前は変わらない。
- J2：admin の `POST /welcome` に `ses_name` を混ぜても `ses_companies` が変わらない。ses1 の POST に `company_name` を混ぜても `users.company_name` null。
- J3：登録済みの admin（company_name null）の `GET /welcome` → `/account`。
- J4：`W::renameSes(W::directSesId(), 'x', admin)` → null を返し、直属の行の名前が「管理者の直属」のまま。
- L1：`Schema::hasColumn('mail_outbox','from_name')`。
- L2：admin の `company_name` を「見本運営株式会社」にして `POST /admin/settings/testmail`（to は `@park.example`）→ 最新の `mail_outbox` の `from_name` が「見本運営株式会社」、`body` の最後に「見本運営株式会社」があり「PARK 管理者」が無い。
- L3：同じ admin で `POST /admin/mail/broadcast`（見本データだけが相手）→ 増えた `mail_outbox` が全部 `from_name`＝企業名。同じ admin で管理者を招待（`/admin/settings/admins` の招待）→ kind=invite の行の `from_name`＝企業名。
- L4：同じ admin で、ある案件の `/cases/{id}/mail/preview` に `{送り主}` を含む本文を送る → 画面に「見本運営株式会社 {氏名}」。続けて `/send` → kind=case-mail の行の `from_name`＝企業名。ses1 が同じことをする → `from_name` null、本文に「みどり情報システム {氏名}」。
- L5：企業名 null の admin で L2 → `from_name` null、署名が「PARK 管理者」（設定の既定）。L4 の preview で `{送り主}` が「PARK 管理者」。
- L6：企業名を入れた admin がいる状態で、SES の操作で管理者あての通知メールが出る場面（例：I10 とは別に、メールありの通知が出る操作）か `N::notify($adminId, 't', 'b')` を直接呼ぶ → `from_name` null、署名が「PARK 管理者」。宛名は「見本運営株式会社 {氏名} 様」（L8）。
- L7：`Notifier::fromOf((object)['from_name' => 'A社'])` が `[config('mail.from.address'), 'A社']`、`from_name` null で null。
- L8：企業名を入れた admin のヘッダー右に「見本運営株式会社 {氏名}」。null の admin は「PARK 管理者 {氏名}」。
- L9：`/admin/mail` に「差出人」と「見本運営株式会社」（L2 のあと）。
- L10：`/admin/settings` に新しいラベルの文。

## 仮定

- a. 管理者の企業名は、帯・アカウント・管理者の一覧・ヘッダー右・メールの宛名と、送り主がはっきりするメールの差出人（From の名前・署名・`{送り主}`）に使う（L）。
- b. 管理者の企業名の初期値は空（設定の「運営の名前」を入れておくことはしない）。
- c. 同じ名前の SES は止めない（略称が重なることも、正式名称がたまたま同じこともあるため）。warn と通知で知らせる。
- d. SES が名前を変えても、`self_confirmed_at` は消さない（SES 自身が直したので）。管理者が直したときは今のまま消す。
- e. 上限は 100 文字（氏名などとそろえる）。
- f. From のアドレスは変えない（名前だけ）。From の名前は企業名だけ（氏名は付けない）。氏名は本文の `{送り主}` に出る。
- g. SES が送るメールの差出人（From の名前・署名）は、今回は変えない（tezzo の回答は管理者の企業名についてなので）。
- h. 再送は、作ったときの `from_name` のまま送る（あとで企業名を変えても、出したメールの差出人は変えない）。

## 決まったこと（2026-10-17 tezzo の回答）

- **1** 管理者の企業名を、メールの差出人にも使う（b）。→ L に反映した。
- **2** SES が名前を変えたときは、管理者全員にベルで知らせるだけ（a）。メールは送らない。→ I5 のとおり。
- **3** 過去の請求書の宛名は、新しい名前で出るままでよい（a）。請求書に名前を保存する仕組みは作らない。→ I7 のとおり。
- **4** 自動で出るメール（通知・確認コードなど）の差出人は、今までどおり設定の「運営の名前」のまま（a）。「代表の管理者」を選ぶ仕組みは作らない。
