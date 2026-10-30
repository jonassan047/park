# PLAN：テスト用のにぎやかしダミーデータを入れる `park:seed-dummy`（2026-10-30）

> この節が今の計画。下の「エンジニアの氏名・ふりがな・電話をそろえる（2026-10-28）」は前の計画で、そのまま残している。

## 背景

PARK v0.2.x（仮登録 → 本登録）のテストのため、公開版（https://www.jona.jp/park/）の一覧に、いろいろな状態の SES・担当者・エンジニアを大量に並べたい（TEZZO が公開版に入れると決めた）。消すときは今ある `park:purge-samples` でまとめて消す。

## 前提（コードで確かめたこと）

- v0.2.0 の「仮登録・本登録」の状態の列は**まだ無い**（v0.2.0 は②の段階。K04〜K11 未決）。今ある列だけで状態を表す（下の対応表）。v0.2.0 で状態の列が足されたら、このコマンドも合わせて直す（別の計画）。
- 今ある状態の値
  - SES の会社 `ses_companies.status`：`pending`（申込中）・`active`（参加中）・`suspended`（停止）・`left`（退会済）。`kind` は `ses`／`direct`（管理者の直属）。
  - アカウント `users`：`user_state()`（helpers.php）＝ `left_at` あり→退会済、`active=0`→停止、`password` あり→使用中、なし→招待中。ほかに `profile_completed_at`（最初の情報登録）・`two_factor_confirmed_at`・`token`/`token_expires`（招待リンク）。
  - エンジニア `engineers`：`status`（available 待機中・proposing・working・unavailable 休止・left）、`suspended_at`（停止）、`consent_at`（同意）、`self_confirmed_at`（本人の確認）。
- `SamplePurge::plan()` が消すもの：`users.email LIKE '%@park.example'`、`ses_companies` の `kind='ses'` かつ email が `@park.example`、`engineers.email` が `@park.example`（または email 空で見本の SES 所属）、それらにつながる skills・通知・sessions・mail_outbox（宛先が @park.example）・audit_log（user_id が見本の人）など。
- `W::createEngineer()` は、招待を切っても（`$invite=false`）**管理者全員にベルの通知**（「エンジニアが登録されました」、メールなし）と **audit_log（`engineer-create`、user_id は null、ダミーの氏名入り）**を書く。後者は purge では消えない（user_id が null のため）→ このコマンドの中で消す（下の仕様）。
- `W::saveSkills()` は、技術マスタに無い言葉を `skill_terms` に足す（purge では消えない）→ マスタにある言葉だけを渡す。
- `W::createUser()` は必ずパスワードを入れる（招待中にするときは、あとで `password=null` にする）。ふりがなは受け取らない（あとで `W::kanaCols()` で更新）。
- `SampleSeeder` は公開版では動かない作り。今回は別のコマンドにし、`SampleSeeder` は変えない。

## 受け入れ条件

- **AC1** `php artisan park:seed-dummy` に `--ses`（既定 8）・`--staff`（既定 15）・`--eg`（既定 80）・`--direct`（既定は eg の 10%を四捨五入）・`--seed`・`--yes` がある。
- **AC2** `--yes` が無いときは、作る件数の予定（種類 × 状態）・使う番号の範囲・seed を出すだけで、DB を一切変えない（直属の入れ物も作らない）。
- **AC3** `--yes` で、SES の会社・SES の担当者（role ses）・エンジニア（SES 所属と直属。role eg のアカウントつき）を、指定の件数だけ作る。全部を1つの transaction で入れ、途中で失敗したら何も残らない。
- **AC4** 状態が混ざる：SES は申込中・参加中・停止、担当者とエンジニアは 本登録済み・本登録の途中・招待中・仮登録のまま（リンク期限切れ）・停止中（下の対応表）。件数が足りるとき（状態の数以上）は、どの状態も1件以上ある。
- **AC5** メールアドレスはすべて `@park.example`（`ses-d01@park.example`・`ses-d01-2@park.example`・`eg-d001@park.example`）。
- **AC6** 氏名・ふりがなは姓・名に分かれて入り（`name_sei/name_mei/kana_sei/kana_mei`、つないだ `name/kana` も）、スキル・経験年数・単価・最寄り駅・生年などは、ありそうな値の組み合わせ。実在の人物の情報は使わない。
- **AC7** `--seed` が同じで、始まりの番号が同じなら、同じ名前・状態・値になる。`--seed` が無いときは乱数で決め、使った seed を出す。
- **AC8** メールを一切送らない。`mail_outbox` に1行も増えない。管理者のベル（`app_notifications`）も増えない。
- **AC9** 二重に実行したら、番号は続きから振る（既存のダミーと重ならない）。
- **AC10** 公開版（`APP_ENV=production`）でも動く。
- **AC11** `php artisan park:purge-samples --yes` で、作ったダミーが全部消える（守りに当たらない）。
- **AC12** 出力に名前・メールアドレスを出さない（件数・番号の範囲・seed だけ）。パスワード・招待のトークンも出さない。
- **AC13** README と管理者マニュアルに書き足す。

## 仕様

### コマンド

```
php artisan park:seed-dummy                         # 予定を見るだけ（既定の件数）
php artisan park:seed-dummy --ses=8 --staff=15 --eg=80 --seed=1        # 予定を見るだけ
php artisan park:seed-dummy --ses=8 --staff=15 --eg=80 --seed=1 --yes  # 入れる
```

- ファイル：`app/Console/Commands/SeedDummy.php`（入出力だけ）、`app/Services/DummySeed.php`（`plan(array $opt): array` と `run(array $opt): array`。名前の素・駅・スキルの組などの表もここに `const` で持つ）。
- signature：`park:seed-dummy {--ses=8} {--staff=15} {--eg=80} {--direct=} {--seed=} {--yes : 実際に入れる}`
- 入力の検査（当たったら何もせず、理由を出して終了コード 1）
  - `--ses` は 1〜50、`--staff` は 0〜200、`--eg` は 0〜500、`--direct` は 0〜eg。すべて整数。
  - `--staff` は「申込中でない SES の数」以上（各社に1人目の担当者が要る）。足りなければ「担当者は最低 N 人です」と出して止める。
  - これから使うメールアドレスが1つでも既にあれば止める（番号は続きからなので通常は起きない）。
- 出力（`--yes` の有無で同じ表。purge-samples と同じ作法）
  - 表：SES（申込中・参加中・停止）、担当者（状態ごと）、エンジニア SES 所属（状態ごと）、エンジニア 直属（状態ごと）の件数。
  - 番号の範囲（例 `ses-d01〜ses-d08`・`eg-d001〜eg-d080`。ドメインは書かない）、seed、`APP_ENV`。
  - `--yes` なし：「見るだけです（何も変えていません）。入れるときは --yes を付けます。」終了コード 0。
  - `--yes` あり：「入れました（SES n 社・担当者 n 人・エンジニア n 人）。消すときは php artisan park:purge-samples」。終了コード 0。

### 状態の対応表（v0.2.0 の言葉 ⇔ 今の列）

仮定：v0.2.0 の列ができるまで、次のように今の列で表す。画面の `user_state()` では B は「使用中」、C・D はどちらも「招待中」と出る（期限切れを区別する表示は今は無い）。

| 記号 | 状態（この計画の呼び名） | users | engineers（エンジニアだけ） | 割合 |
|---|---|---|---|---|
| A | 本登録済み | password あり・active=1・profile_completed_at あり・two_factor_confirmed_at あり・last_login（1〜30日前） | consent_at・self_confirmed_at あり、status は available（うち 15% は unavailable） | 50% |
| B | 本登録の途中（パスワードは設定、最初の情報登録が未了） | password あり・active=1・profile_completed_at なし・two_factor_confirmed_at なし | consent_at あり・self_confirmed_at なし | 10% |
| C | 招待中（仮登録・リンク有効） | password なし・`W::issueToken($id)`（7日） | consent_at・self_confirmed_at なし | 15% |
| D | 仮登録のまま（リンク期限切れ） | password なし・token あり・token_expires は 1〜20日前 | 同上 | 15% |
| E | 停止中 | password あり・profile_completed_at あり・active=0・token なし | suspended_at あり（consent・self_confirmed あり） | 10% |

SES の会社：

| 状態 | ses_companies | 担当者 | 件数 |
|---|---|---|---|
| 申込中 | status=pending、agreed_at・message あり、joined_at なし | 作らない（今の申し込みと同じ。承認まで users は無い） | ses≥3 のとき max(1, round(ses×0.15))、それ以外 0 |
| 停止 | status=suspended、agreed_at・self_confirmed_at・joined_at あり | 作る | ses≥4 のとき max(1, round(ses×0.10))、それ以外 0 |
| 参加中 | status=active、同上 | 作る | 残り（1社以上） |

- 退会済（left）は今回は作らない（要求の状態に無いため。要るなら足す。未決 2）。
- 既定（8社）なら 申込中1・停止1・参加中6。

### 件数の割り振り（seed で再現）

- 乱数は `new \Random\Randomizer(new \Random\Engine\Mt19937($seed))` を使う（PHP 全体の乱数に影響しない）。`--seed` が無ければ `random_int(1, 2147483647)` で決めて出力する。
- 状態ごとの件数：割合 × 人数を最大剰余法で整数にする。人数が状態の数（5）以上なら、各状態を最低1にする（多い状態から1ずつ回す）。並びは Randomizer の `shuffleArray`。
- 担当者：申込中でない各社に1人目（会社の email と同じアドレス）を置き、残りを各社にランダムに配る。**1人目は E（停止中）にしない**（会社に使える担当者が1人もいない形を避ける。今の画面の「最後の1人は停止できない」と合わせる）。E が1人目にしか置けないときは A に回す。
- エンジニア：`--direct` 人を直属（`W::directSesId()`。無ければ作る）、残りを申込中でない SES にランダムに配る（会社ごとの重み 1〜3）。状態は SES 所属と直属それぞれで上の割合。

### 番号とメールアドレス（AC5・AC9）

- SES：`ses-d%02d@park.example`（会社の email と1人目の担当者）。2人目以降の担当者：`ses-d%02d-%d@park.example`（`-2` から）。
- エンジニア（SES 所属・直属とも同じ通し番号）：`eg-d%03d@park.example`。users と engineers に同じアドレス。
- 始まりの番号：`users`・`ses_companies`・`engineers` の email から `ses-d(\d+)@park.example`・`eg-d(\d+)@park.example` に合う最大の番号を探し、その次から（無ければ 1）。桁があふれたら（100社目など）そのまま3桁になる。
- 二重実行の扱い：**続きから振る**（決定）。同じアドレスがあったら飛ばすのではなく、全体を止める（上の検査）。

### 値の作り方（AC6）

- 名前の素は `DummySeed` の `const` に持つ。姓（漢字・かな）40個前後、名（漢字・かな）男女それぞれ30個前後の、よくある組み合わせ。実在の人物のフルネームを並べない（姓と名をランダムに組み合わせるだけ）。
- SES の会社名：「さくら・あおば・みなも・こはる…」＋「システム・テック・ソリューションズ・ラボ…」を組み合わせ、末尾に「（ダミー）」を付ける（実在の会社と取り違えないため。仮定。未決 1）。代表者・担当者は姓・名（担当者はふりがなも）を `W::sesPeopleCols()` の形で入れる。従業員数 3〜80、強み（定型文から1つ）。電話・住所は入れない（実在の番号・住所になるのを避ける）。
- エンジニア（`W::createEngineer($sesId, $data, null, false)` に渡す）：`name_sei/name_mei/kana_sei/kana_mei`、`email`、`birth_year` 1965〜2002、`years_exp` は 1〜min(30, 年齢−22)、`desired_price` 40〜90（万円）、`min_price` は desired − 0〜10、`nearest_station`（首都圏のよくある駅 30個前後から）、`available_from` は今日〜3か月先の月初、`work_style` は onsite/hybrid/remote/any、`days_per_week` 5（1割は 3〜4）、`summary`・`strengths` は「型」ごとの定型文。initials は `engValues` が kana から作る。電話・住所・生年月日は入れない。
- スキル：「型」（業務系 Java・Web 系 PHP・フロント・インフラ・.NET・モバイル など6つ前後）を1つ選び、型ごとの候補から 3〜7 個（年数 1〜経験年数、レベル 2〜5）。候補は `SkillCatalog::CATALOG` にある正式な名前だけ。渡す前に `SkillCatalog::find()` で確かめ、見つからない言葉は渡さない（`skill_terms` を増やさない）。`skill_terms` が空のときだけ `SkillCatalog::sync()` を呼ぶ（マスタ。purge 後も残ってよい）。
- 担当者・エンジニアのアカウント：`W::createUser($email, $name, 'ses'|'eg', $pw, ['ses_id'=>…, 'engineer_id'=>…, 'sei'=>…, 'mei'=>…])` のあと、`W::kanaCols()` のふりがなと、対応表の状態の列を update。`$pw` は実行ごとに `Str::random(40)`（出力しない・どこにも書かない。ダミーでログインはできない。未決 3）。

### メールを出さない・跡を残さない（AC8）

- `W::invite()`・`W::applySes()`・`W::approveSes()`・`N::notify()` 系は呼ばない。`createEngineer` は `$invite=false`、`$actor=null`。
- transaction の中で、始める前の `max(id)` を覚えておき、終わりに次を消す：
  - `app_notifications`：id が始める前より大きく、title が「エンジニアが登録されました」で link が今回の `/engineers/{id}` のもの。
  - `audit_log`：id が始める前より大きく、action が `engineer-create` のもの（ダミーの氏名が残るため）。
- 最後に `N::audit(null, 'seed-dummy', 件数だけの JSON)` を1行（名前・アドレスは書かない）。
- 安全のため、transaction の最後で `mail_outbox` の件数が始める前と同じかを確かめ、違えば例外で全部戻す。

### purge-samples で消える根拠（AC11）

| 作るもの | purge の判定 | 消えるか |
|---|---|---|
| SES の会社（申込中・参加中・停止） | `kind='ses'` かつ email が `@park.example` | 消える（全社に email を入れる） |
| 担当者・エンジニアの users | email が `@park.example` | 消える |
| engineers（SES 所属・直属） | email が `@park.example` | 消える（直属も email で拾う） |
| engineer_skills | 消す engineers の id | 消える |
| 招待トークン・sessions・password_reset_tokens | 消す users | 消える |
| ベルの通知・engineer-create の記録 | （purge では消えない） | このコマンドの中で消しておく |
| 直属の入れ物（kind=direct） | 対象外 | 残る（システムの入れ物。今までどおり） |
| skill_terms（空のとき入れたマスタ） | 対象外 | 残る（マスタ） |
| audit_log の `seed-dummy` 1行 | 対象外 | 残る（件数だけ） |

守りの判定に当たらない理由：ダミーに管理者は作らない（TEZZO の管理者が残る）、ダミーの SES にダミー以外の人はいない、案件・応募・契約は作らない。
**注意（テスト中の運用）**：テストで、ダミーの SES に本物（@park.example 以外）の担当者・エンジニアを登録したり、ダミーのエンジニアで案件・応募を作ったりすると、purge は守りで止まる。そのときは `--move-kept-to-direct`（エンジニアだけ直属へ移す）を使うか、TEZZO に確かめる。README とマニュアルに書く。

### 公開版で動かすときの注意

- `park:status` と朝の点検の「見本データ（@park.example）」の数が増える（想定どおり）。
- 一覧の招待中・申込中のダミーに「招待を送り直す」「承認して招待を送る」を押すと、`@park.example` あてのメールが出る（届かない予約済みのドメイン。mail_outbox に残るが purge で消える）。押さないよう、マニュアルに書く。
- ダミーがいる間は、一斉メールを使わない。案件の配信でダミーのエンジニア・SES を選ばない（応募まで進むと purge が守りで止まる）。マニュアルと README に書く。

## 作業手順

1. `app/Services/DummySeed.php` を作る：表（姓・名・会社名の素・駅・型とスキル・定型文）、`plan()`（検査・番号・割り振り・件数の表を返す。DB は読むだけ）、`run()`（`plan()` の結果を1つの transaction で入れる。上の「跡を残さない」まで）。
2. `app/Console/Commands/SeedDummy.php` を作る（`PurgeSamples.php` と同じ形。予定の表 → `--yes` なしなら終わり → `run()`）。
3. 入れた直後に手元（GENESIS／FOCUS の手元の DB）で、既定の件数で `--yes` なし → `--yes` → 管理者の一覧・SES の一覧・エンジニアの一覧で状態の混ざり方を見る → `park:purge-samples` で消えることを確かめる。
4. テスト `tests/Feature/SeedDummyTest.php` を足し、`php artisan test` を通す。
5. README と管理者マニュアルを直す（下）。41_Park-Project の `04_Manuals` の写しも写し直す（JOE／Leo）。

## テスト（tests/Feature/SeedDummyTest.php）

`PurgeSamplesTest` の作り方に合わせる（RefreshDatabase。最初に @park.example でない管理者を1人、password つきで作る。`Mail::fake()`）。件数は小さめ（例 `--ses=5 --staff=8 --eg=12 --direct=5 --seed=1`）でよいが、T2 は既定の件数でも1回流す。

- **T1（AC2）** `--yes` なしで流す → 終了コード 0、出力に「見るだけ」。users・ses_companies・engineers・engineer_skills・app_notifications・audit_log・mail_outbox・skill_terms の件数が変わらない。kind=direct の行も増えない。
- **T2（AC1・AC3）** `--ses=8 --staff=15 --eg=80 --seed=1 --yes` → @park.example の ses_companies が 8、role ses の users が 15、engineers が 80（うち直属 8）、role eg の users が 80 で engineer_id がそれぞれの engineers を指す。どのエンジニアにも skills が 3件以上。
- **T3（AC4）** T2 のあと：SES に pending・active・suspended が各1以上、pending の SES に users が 0。担当者とエンジニアで A〜E（対応表の条件）が各1以上。1人目の担当者（会社の email と同じ）に active=0 がいない。E のエンジニアは suspended_at あり・users.active=0。
- **T4（AC5・AC6）** 作った users・engineers・ses_companies の email がすべて `@park.example` で終わり、形が `ses-dNN`・`ses-dNN-M`・`eg-dNNN`。name_sei・name_mei・kana_sei・kana_mei が空でなく、name がつないだもの。engineers.initials が入っている。skill_terms の件数が増えていない（実行前に sync 済みの状態で比べる）。
- **T5（AC8）** `--yes` のあと、mail_outbox が 0 件増、`Mail::assertNothingOutgoing()`。本物の管理者の app_notifications が増えていない。audit_log の増えは `seed-dummy` の1行だけで、detail に `@` が無い。
- **T6（AC11）** 入れたあと `park:purge-samples --yes` → 終了コード 0。@park.example の users・ses_companies・engineers が 0、それらの engineer_skills も 0。本物の管理者は残る。users・engineers・ses_companies（kind=ses）の件数が入れる前と同じ。
- **T7（AC10）** `app()['env'] = 'production'` にして `--yes` → 作られる。`--yes` なしなら変わらない。
- **T8（AC9）** 同じ指定で2回 `--yes` → 2回目は `ses-d06`・`eg-d013` から（上の小さい件数の例）。メールアドレスの重複なし、件数は2倍。
- **T9（AC7）** `--seed=42 --yes` → (email, name, kana, 状態) の一覧を取る → `park:purge-samples --yes` → もう一度 `--seed=42 --yes` → 同じ一覧になる。
- **T10（入力の検査）** `--ses=0`・`--eg=501`・`--staff` が申込中でない SES の数より少ない・`--direct` が eg より多い → どれも終了コード 1、DB は変わらない。
- **T11（AC12）** 出力に `@park.example` と、作った人の姓が1つも出ない。

## README・マニュアルに書き足すこと

- `README.md`：「見本データを消すコマンド」の段落の前に、`park:seed-dummy` の段落を足す。オプションと既定値、`--yes` なしは見るだけ、公開版でも動く（SampleSeeder とは別）、メールは出さない、状態の対応表の要約、番号は続きから、消すのは `park:purge-samples`、ダミーの SES に本物の人を入れると purge が止まること。
- `resources/manuals/admin.md`：「2. はじめに」の「メールについて」の後に「### テスト用のダミーデータ」を足す（3〜5行）：テストの間、一覧に `@park.example` の SES・担当者・エンジニアが並ぶ／ダミーには招待の送り直し・承認を押さない（届かないメールが出る）／ダミーの SES に本物の人を登録しない／片付けは管理者（TEZZO）がコマンドで行う。`admin-steps.md` は変えない。
- SES・エンジニアのマニュアルは変えない（ダミーを見るのは管理者の画面だけ。SES のダミーはログインできない）。

## TEZZO の了承が要る手順

- 公開版で `park:seed-dummy --yes` を流すこと（データを足す）。先に `--yes` なしの予定の表を TEZZO に見せる。TEZZO が入れると決めているが、件数と seed は流す前に確かめる。GENESIS にはサーバーの鍵が無いので、FOCUS の Leo（ssh）か TEZZO が流す。
- 公開版で `park:purge-samples --yes` を流すこと（データの削除）。先に `--yes` なしで件数を見せる。
- コードの反映（push → `park-deploy.sh`）は常時了承の範囲。マイグレーションは無い。

## 未決（返事待ちに載せる件）

TEZZO が 2026-10-30 に決定した（すべて仮定のとおり）。

1. 決定：SES の会社名の末尾に「（ダミー）」を付ける。人の氏名には付けない。
2. 決定：退会済は混ぜない。
3. 決定：ダミーのアカウントではログインしない（パスワードは乱数のまま）。
4. 決定：既定の件数は SES 8社・担当者15人・エンジニア80人、直属はエンジニアの1割。
5. 決定：v0.2.0 で仮登録・本登録の列ができたら、対応表を差し替える（今回は対象外。別の計画）。

---

# PLAN：エンジニアの氏名・ふりがな・電話を、アカウント（users）と技術登録（engineers）でそろえる（2026-10-28）

> 前の計画（一覧の停止・退会ボタン、SES の詳細、兼任ログイン、保留中の「テスト期間だけメールアドレスの重複を許す」）は、この上書きの直前のコミットの PLAN.md に残っている（`git log -p PLAN.md`）。保留中の計画は消えたわけではない。

## 背景（tezzo の報告）

エンジニアが最初のログインの「あなたの情報」（/account/welcome）でふりがなを入れたのに、技術登録（/engineers/{id}/edit）を開くと空のまま。

## 原因（コードで確かめたこと）

- `Workflow::saveProfile()`（welcome と /account の保存）は **users だけ**を書く。
- `EngineerController@update` → `Workflow::updateEngineer()` は **engineers だけ**を書く（users へは書かない）。`EngineerController@saveProfile`（詳しいプロフィール。電話がある）も同じ。
- エンジニアの招待（`createEngineer`・`EngineerController` の再招待）は `invite(['name' => $e->name, ...])` で、users に **ふりがなを渡していない**。
- `AccountController@welcomeForm` は、engineers から初期値を拾うのが `!$v->name_sei` のときだけ。招待で users.name が入っているので name_sei は split で埋まり、**engineers のふりがなは拾われない**。
- /account（`account/show.blade.php`）と /account/profile は users だけを出す。

## 受け入れ条件

- **AC1** EG が welcome で氏名・ふりがな・電話を入れて保存すると、engineers の name_sei/name_mei/name・kana_sei/kana_mei/kana・phone も同じ値になる。技術登録の編集画面に、そのふりがなが出る。
- **AC2** EG が /account で氏名・ふりがな・電話を変えると、engineers も同じになる。
- **AC3** EG 本人・SES・管理者が技術登録（/engineers/{id}）で氏名・ふりがなを変えると、その engineer の users（role='eg'）も同じになる。詳しいプロフィール（/engineers/{id}/profile）で電話を変えたときも users.phone が同じになる。
- **AC4** 兼任（users.role が 'ses' か 'admin' で engineer_id を持つ行）は同期の対象外。技術登録を直しても、その人の users 行は変わらない。welcome・/account で直しても engineers は変わらない（仮定A）。
- **AC5** 別の engineer の users、engineer_id が一致しない users は一切変わらない。
- **AC6** welcome の初期値：users の欄が空なら、欄ごとに engineers の値を出す（氏名・ふりがな・電話）。/account の表示も同じ（空欄のまま保存して engineers の値を消してしまわないため）。
- **AC7** 招待で users を作るとき、engineers の姓・名・ふりがな・電話を写す。
- **AC8** 既存の食い違いを一度だけそろえるコマンドがある。既定は「見るだけ」（件数と id だけ出す）。書き換えは `--apply` を付けたときだけで、tezzo の了承が要る。
- **AC9** 同期で engineers.self_confirmed_at を消さない（本人の操作で本人の行を写すだけなので）。管理者・SES が技術登録を直したときの今の動き（self_confirmed_at を消す）はそのまま。

## 仕様

### 同期の対象と向き

| 入口 | 保存先（今） | 足す同期 |
|---|---|---|
| /account/welcome・/account（`W::saveProfile`） | users | → engineers（DB の users 行が role='eg' かつ engineer_id あり のときだけ） |
| /engineers/{id}（`W::updateEngineer`） | engineers | → users（`engineer_id = id` かつ `role = 'eg'` の行だけ） |
| /engineers/{id}/profile（`EngineerController@saveProfile` → `updateEngineer`） | engineers | → users（同上。電話） |
| 招待（`W::invite` で eg の users を新しく作るとき） | users | engineers の値を写して作る |

同期する列：`name_sei`・`name_mei`・`name`・`kana_sei`・`kana_mei`・`kana`・`phone`。

### 同期先の決め方（別人を書き換えないため）

- users → engineers：`saveProfile` に来る `$user` の role は「いまの立場」に差し替わっていることがある（兼任ログイン）。**必ず `DB::table('users')->find($user->id)` で DB の行を読み直し**、その行の `role === 'eg'` かつ `engineer_id` があるときだけ、`engineers.id = その engineer_id` を更新する。
- engineers → users：`DB::table('users')->where('engineer_id', $id)->where('role', 'eg')` だけを更新する。role が 'ses'・'admin' の行（兼任）は対象にしない。`egShareTarget`・`dualIds` が扱う行は role が 'ses'/'admin' なので、これで外れる。
- **仮定A（兼任は対象外）**：兼任の人の users 行は SES 担当者・管理者としての情報（会社の電話など）を持つ。名前は同じ人のはずだが、電話は会社のものの可能性があり、片方だけ同期すると混乱する。今回は兼任を外し、必要なら tezzo が別に決める（返事待ちに載せる）。

### 電話（phone）を同期する理由

- エンジニアの users.phone は、エンジニア本人の電話としてしか使っていない（role='eg' は所属・役職も持たない）。
- engineers.phone は履歴書（`EngineerDocs`）に出る。welcome で入れた電話が履歴書に出ないのは、氏名・ふりがなと同じ不具合。
- 兼任の行を外すので、会社の電話が engineers に入ることはない。
→ 同期の対象にする。

### 値の決まり

- 「同じにする」が目的なので、空欄で保存したら相手側も空にする。誤って消さないよう、AC6 のとおり画面の初期値を欄ごとに engineers から補う。
- `saveProfile` の今の動き（氏名の姓・名がどちらも空なら氏名は変えない）は残す。その場合、engineers の氏名も変えない。
- users → engineers で kana が入り、engineers.initials が空なら、`W::initialsFromKana()` で initials を入れる（`engValues` と同じ動き）。
- 長さは今の決まり（姓・名とも50文字。`cut50`）。users 側・engineers 側どちらも今の検査を通ったあとの値を写す。
- 記録（`N::audit`）は今のまま。同期そのものには新しい記録を足さない（個人情報を記録に書かない）。

### 既存データをそろえるコマンド（AC8）

- `php artisan park:sync-eg-names`（`app/Console/Commands/SyncEgNames.php`）。**マイグレーションにはしない**（公開版への反映 `park-deploy.sh` が migrate を自動で流すため、了承なしにデータが書き換わってしまう）。
- 対象：users の role='eg' かつ engineer_id があり、engineers が存在する組。
- 項目ごとに比べ、どちらを正にするか：
  - a. 片方だけ値がある → 値のある方で、空の方を埋める。
  - b. 両方に値があって違う → users.profile_completed_at があれば users を正（本人が welcome で入れた値）、無ければ engineers を正（SES・管理者が入れた値しかない）。
- 既定（オプションなし）は見るだけ：a・b の件数と、対象の users.id・engineers.id だけを出す。**氏名・ふりがな・電話の中身は出さない**。
- `--apply` で書き換える。手元で確かめたあと、公開版で流すのは **tezzo の了承を取ってから**（データの書き換え）。

### 画面・マニュアル

- 画面の形は変えない（欄の追加なし）。welcome と /account の初期値の出し方だけ変える。
- `resources/manuals/engineer.md`・`engineer-steps.md` に1行足す：「氏名・ふりがな・電話は、アカウントの画面と技術登録のどちらで直しても、両方に反映されます」。SES・管理者向け（`ses.md`・`admin.md`）にも「技術登録で直した氏名・ふりがなは、エンジニアのアカウントにも反映されます」を1行。直したら 41_Park-Project の `04_Manuals` の写しも直す（JOE の作業）。

## 作業手順

1. `app/Services/Workflow.php` に2つの関数を足す。
   - `syncEngineerFromUser(int $userId): void`：DB の users 行を読み、role='eg' かつ engineer_id があれば、同期する列を engineers に写す（initials が空で kana があれば入れる）。`updated_at` も更新。
   - `syncUsersFromEngineer(int $engineerId): void`：engineers 行を読み、`users where engineer_id = ? and role = 'eg'` に同期する列を写す。
2. `W::saveProfile()` のトランザクションの中、users を更新したあとに `syncEngineerFromUser($user->id)` を呼ぶ。
3. `W::updateEngineer()` の更新のあとに `syncUsersFromEngineer($id)` を呼ぶ（技術登録・詳しいプロフィールの両方がここを通る）。`createEngineer()` の最後にも呼ぶ（兼任でない eg の users があれば）。
4. `W::invite()`：role='eg' で engineer_id があり users を新しく作るとき、engineers から name_sei/name_mei/kana_sei/kana_mei/phone を読み、`nameCols`・`kanaCols` と phone に使う（呼ぶ側を直さず invite の中で読む）。
5. `AccountController@welcomeForm`：EG で engineer_id があるとき、`name_sei/name_mei`・`kana_sei/kana_mei`・`phone` を、users 側が空なら欄ごとに engineers から補う（今の `if (!$v->name_sei && $e)` の条件をやめる）。
6. `AccountController@show` と `account/show.blade.php`：EG のとき同じ補い方で初期値を出す（コントローラで値を作って渡す）。`account/profile.blade.php`（見るだけ）も同じ値を出す。
7. engineers・users の氏名・ふりがな・電話を書く場所がほかにないか grep する（`DB::table('engineers')` と `DB::table('users')` の update・insert、AI の下書きの取り込み、見本データ）。あれば 1〜3 の関数を通す。
8. `app/Console/Commands/SyncEgNames.php` を作る（見るだけが既定、`--apply` で書き換え）。
9. マニュアル4つに1行ずつ足す。
10. テストを足し、`php artisan test` を通す。

## テスト（tests/Feature に `EgProfileSyncTest.php` を足す）

既存の `KanaFieldsTest`・`AccountProfileTest`・`DualRoleTest` のデータの作り方に合わせる。

- **T1（AC1）** EG（profile_completed_at なし、engineers に kana なし）でログイン → POST /account/welcome（姓・名・ふりがな・電話）→ engineers の kana_sei/kana_mei/kana・name・phone が同じ。GET /engineers/{id}/edit にふりがなが出る。
- **T2（AC2）** 登録済みの EG で POST /account でふりがな・電話を変える → engineers も同じ。
- **T3（AC3）** EG 本人が PUT/POST /engineers/{id} で kana を変える → users（role='eg'）の kana_sei/kana_mei/kana が同じ。SES・管理者が変えたときも同じ（あわせて self_confirmed_at が消えること＝今の動きを確認）。
- **T4（AC3）** /engineers/{id}/profile で電話を変える → users.phone が同じ。
- **T5（AC4）** 兼任（role='ses'、engineer_id あり）の users があるエンジニアの技術登録を直す → その users 行は変わらない。兼任の人が「エンジニア」で入って /account を保存 → engineers は変わらない。
- **T6（AC5）** 別のエンジニアの users、engineer_id が違う users が変わらない。
- **T7（AC6）** users の kana が空・engineers に kana がある EG で GET /account/welcome と GET /account → engineers のふりがな・電話が欄に出る。
- **T8（AC7）** SES がエンジニアを登録（ふりがな・電話つき）→ 作られた users に kana_sei/kana_mei/phone が入る。
- **T9（AC8）** 食い違いのデータを作り、`park:sync-eg-names` を引数なしで流す → DB は変わらず、出力に中身（氏名・ふりがな）が出ない。`--apply` → a（片方だけ）は埋まり、b は profile_completed_at の有無で正の側に合う。兼任の行は触らない。
- **T10（AC9）** EG 本人が welcome・/account を保存しても engineers.self_confirmed_at が消えない。

## 画面での確認（park-tester がブラウザで）

1. SES でエンジニアを登録（ふりがなは空のまま）→ 招待のリンクから EG でパスワード設定 → welcome でふりがな「やまだ／たろう」と電話を入れて登録 → 技術登録の編集（/engineers/{id}/edit）を開き、ふりがなが出ていること。詳しいプロフィールに電話が出ていること。
2. 逆向き：EG で技術登録のふりがなを変えて保存 → /account と /account/profile に新しいふりがなが出ること。
3. SES でそのエンジニアの技術登録の氏名を変える → EG で入り直し、/account に新しい氏名が出ること。
4. 兼任の SES 担当者（エンジニアを兼ねる人）で「エンジニア」として入り /account を保存 → SES 担当者として入り直したとき、アカウントの氏名・電話が勝手に変わっていないこと（AC4 の確認）。
5. SES で、ふりがなを入れてエンジニアを登録 → 招待のリンクから入った welcome の初期値にふりがなが出ていること。

## tezzo の了承が要る手順

- 公開版で `php artisan park:sync-eg-names --apply` を流すこと（データの書き換え）。先に引数なしで件数を見せる。
- コードの反映（push → `park-deploy.sh`）は常時了承の範囲。マイグレーションは無い。

## 返事待ちに載せる件

- 兼任の人（SES 担当者・管理者がエンジニアを兼ねる）の氏名・ふりがなも同期するか（今回は対象外。電話は会社のものの可能性があるため）。
- 既存データの正の決め方（両方に値があって違うとき、welcome を済ませた人は users、まだの人は engineers）でよいか。
