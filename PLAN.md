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
