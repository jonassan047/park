# PLAN：確認コードが通らない原因を調べるための記録とコマンド（2026-10-17）

## 背景

公開版で、まだ確認コードを一度も通っていないエンジニアが、最新のコードを10分以内に正しく入れても「コードの期限が切れたか、まちがえた回数が多すぎます」で弾かれる。
`TwoFactor::check()` は「pending が無い／user 不一致／期限切れ／5回まちがえ」を全部 `'expired'` にまとめているので区別できない。
疑い：セッションの pending が消えている（消えると `challengeForm` が新しいコードを送り直すので、どのコードも通らない）。
今回は**原因を見えるようにするだけ**。2要素認証の仕組み・画面の文言は変えない。

## 受け入れ条件

1. 確認コードで弾かれたとき、audit_log の `2fa-fail` の detail に理由が入る：`wrong`・`no-pending`・`user-mismatch`・`timeout`・`too-many`。
2. `2fa-fail` の detail からメールアドレスを外す（user_id で人は分かる。公開リポジトリの点検記録に写るおそれを減らす）。
3. コードを送ったとき `2fa-sent`、通ったとき `2fa-ok`、パスワードが通ったとき `login` を残す。`2fa-sent` には、どこから送ったか（`login`・`challenge-form`・`resend`・`invite`）を入れる。
4. audit のどこにも、確認コードそのもの・コードのハッシュ・セッション ID そのものを残さない。
5. 画面の文言・動き（リダイレクト先・期限10分・5回・送り直し60秒）は今のまま。今のテストがそのまま通る。
6. `php artisan park:twofactor {email}` で、その人の2要素認証まわりの状態を見られる（下の「コマンド」）。書き換えは一切しない。メールの件名・本文は出さない。
7. 41_Park-Project に `.claude/scripts/park-remote-twofactor.sh` を作る（JOE が作る）。
8. 上の 1・3・4・6 を tests/Feature で確かめる。

仮定：
- a. セッションが同じかどうかを見分けるため、detail に `s=` としてセッション ID の sha256 の先頭8文字を入れる（ID そのものではないので、なりすましには使えない）。`2fa-sent` と `2fa-fail` の `s=` が違えば、セッションが入れ替わった・消えたと分かる。
- b. `login` 系として足すのは、パスワードが通ったときの `login` だけ（失敗は user_id が無いので今回は残さない）。

## 仕様

### TwoFactor（app/Support/TwoFactor.php）

- `sid(Request): string` を足す：`substr(hash('sha256', $r->session()->getId()), 0, 8)`。
- `send(Request $r, string $from = 'login')`：引数 `$from` を足す。
  - コードを送ったら `N::audit($u->id, '2fa-sent', "from={$from} mail=#{$mailId} s={sid}")`（`$mailId` は `queueMail` の戻り値）。
  - 確認済み・省略で送らずに通したときは `2fa-sent` の代わりに `N::audit($u->id, '2fa-skip', "from={$from} ".(confirmed ? 'confirmed' : 'skipped'))`。
- `check()` の戻り値を次のどれかにする（呼んでいるのは `TwoFactorController::challenge` だけ）：
  - `'ok'`
  - `'wrong'`（違うコード。今の `'bad'`）
  - `'no-pending'`（セッションに PENDING_KEY が無い）
  - `'user-mismatch'`（PENDING_KEY はあるが user がログイン中の人と違う）
  - `'timeout'`（期限を過ぎた）
  - `'too-many'`（すでに5回まちがえている、またはこの1回で5回目になった）
  - 判定は `pending()` を通さず、セッションの生の値を見て上の順で決める。
- `check()` は理由の補足も返せるよう、最後の判定の情報を `public static array $last = []`（例：`['tries' => 2, 'age' => 640, 'pending_user' => 12]`）に入れる。コード・ハッシュは入れない。

### TwoFactorController（app/Http/Controllers/TwoFactorController.php）

- `challengeForm`：`TF::send($r, 'challenge-form')`。
- `resend`：`TF::send($r, 'resend')`。
- `challenge`：
  - `ok` 以外は `N::audit($u->id, '2fa-fail', "{$res} ".補足." s={sid}")`。補足の例：`wrong` は `tries=2`、`timeout` は `age=640`（送ってからの秒）、`user-mismatch` は `pending_user=12`、`too-many` は `tries=5`。メールアドレスは入れない。
  - 画面の文：`wrong` は今の「コードが違います…」、それ以外の4つは今の「コードの期限が切れたか…」。
  - 通ったら `N::audit($u->id, '2fa-ok', "s={sid}")`（`TF::pass` の前に sid を取る。pass で regenerate されるため）。

### PublicController（app/Http/Controllers/PublicController.php）

- `login`：`regenerate()` のあと `N::audit($u->id, 'login', '')`、`TwoFactor::send($r, 'login')`。
- `invite`：`TwoFactor::send($r, 'invite')`。

### コマンド park:twofactor（app/Console/Commands/TwoFactorStatus.php、新規）

- `Status.php` と同じ書き方。`protected $signature = 'park:twofactor {email}'`、説明は「2要素認証まわりの状態を見る（書き換えない）」。
- メールアドレスは `strtolower(trim())` して users を探す。いなければ「その人はいません。」を出して `return 1`。
- 出すもの（`DB::table` で読むだけ）：
  1. 人：`id`・`role`・`active`・`two_factor_confirmed_at`・`last_login`（名前は出さない）
  2. mail_outbox：`to_email = その人`・`kind = '2fa'` の新しい順10件の `id`・`status`・`created_at`・`sent_at`・`error`（**subject・body は select しない**）
  3. audit_log：`user_id = その人` かつ action が `2fa%` か `login%` か `password-set` の新しい順20件の `action`・`detail`・`at`
  4. サーバー：今の時刻（`now_str()`）と `app.timezone`
  5. 設定：`session.driver`・`session.lifetime`・`session.cookie`・`session.domain`・`session.secure`・`session.same_site`・`app.url`・`app.env`・2要素の省略ドメインが設定されているか（あり／なし）
- 表は `$this->table()` でよい。`--json` は付けない（要るなら Status と同じ形で後で足す）。

### 41_Park-Project/.claude/scripts/park-remote-twofactor.sh（JOE が作る）

- `park-remote-status.sh` と同じ形。引数1つ（メールアドレス）。無ければ使い方を出して終わる。
- 引数は `^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+$` に合わなければ断る（ssh の先でコマンドを差し込まれないように）。
- `ssh ... "cd ~/park && /opt/php-8.3/bin/php artisan park:twofactor '$1'"`。
- `.claude/settings.json` の許可に足すかは JOE が判断（見るだけなので park-remote-status.sh と同じ扱いでよい）。
- 結果を `40_動作確認` などに書くときは、メールアドレス・エラー文に含まれるアドレスを書かない（41_Park-Project は公開）。

### マニュアル

- 画面と流れは変わらないので、`resources/manuals/` は直さない。
- README の「点検・コマンド」の所に `park:twofactor` を1行足す。写し（41_Park-Project の `04_Manuals`）は JOE が写し直す。

## 作業手順

1. `TwoFactor` に `sid()`・`$last` を足し、`send()` に `$from` と `2fa-sent`／`2fa-skip` の audit を足す。
2. `check()` を理由ごとの戻り値にする。
3. `TwoFactorController` を直す（`2fa-fail` の detail、`2fa-ok`、send の `$from`）。
4. `PublicController` の `login`・`invite` を直す（`login` の audit、send の `$from`）。
5. `app/Console/Commands/TwoFactorStatus.php` を作る。
6. テストを足す（下）。`php artisan test` で全部通す（今の `test_two_factor_by_email_code` も）。
7. README に1行足す。
8. （JOE）commit・push、公開版へ反映（常時了承の範囲）、`park-remote-twofactor.sh` を作って対象のエンジニアで実行。

## テスト（tests/Feature/TwoFactorAuditTest.php、新規。ParkTest と同じく seed あり）

エンジニアは見本の `eg1@park.example`（確認コードがまだの人）を使う。コードは ParkTest の `lastCode()` と同じく mail_outbox の件名から取る。

| 条件 | 手順 | 確かめること |
|---|---|---|
| 3 送信・成功 | `/login` を POST → 正しいコードで `/two-factor/challenge` を POST | audit に `login`、`2fa-sent`（detail に `from=login`）、`2fa-ok` がある。`/eg` に入れる |
| 1 wrong | ログイン → 違うコード | `2fa-fail` の detail が `wrong` で始まる。画面は「コードが違います」 |
| 1 no-pending | `actingAs(eg1)` だけ（pending なし）で `/two-factor/challenge` を POST | `2fa-fail` の detail が `no-pending` で始まる |
| 1 user-mismatch | `actingAs(eg1)->withSession([PENDING_KEY => ['user' => 別の人の id, 'hash' => 'x', 'expires' => 未来, 'tries' => 0, 'sent' => 今]])` で POST | detail が `user-mismatch` で始まる |
| 1 timeout | ログイン → `travel(11)->minutes()` → 正しいコード | detail が `timeout` で始まる |
| 1 too-many | ログイン → 違うコード5回 → 正しいコード | 5回目と6回目の detail が `too-many` で始まる。`/eg` に入れない |
| 3 challenge-form | ログイン後に `forget(PENDING_KEY)` 相当（pending なしで `actingAs`）→ `/two-factor/challenge` を GET | `2fa-sent` の detail に `from=challenge-form` |
| 2・4 個人情報・コード | 上の全部のあと | `2fa%`・`login` の audit の detail に、メールアドレス・送ったコード（6桁）が含まれない |
| 6 コマンド | ログイン（コード送信まで）→ `artisan('park:twofactor', ['email' => 'eg1@park.example'])` | 終了コード 0。出力に `2fa-sent`・`session.driver` が含まれ、送ったコード・`確認コード`（件名）・本文の一部（`ログインするための`）が含まれない |
| 6 書き換えない | コマンドの前後で users・mail_outbox・audit_log の件数と、その人の `two_factor_confirmed_at`・`last_login` を比べる | 変わらない |
| 6 いない人 | `park:twofactor nobody@park.example` | 終了コード 1 |

## 守ること

- コード・ハッシュ・セッション ID そのものはどこにも残さない・出さない。
- 公開版でコマンドを実行した結果を、公開リポジトリにそのまま貼らない。
- データの削除・`.env` の書き換え・メールの送信はこの作業に含まない（要るときは tezzo の了承を取る）。
