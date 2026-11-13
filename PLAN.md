# PLAN：連絡メール（v0.8.0。2026-11-13）

> **これが今の計画**。下の「v0.7.0」「v0.6.0」「v0.5.0」「v0.4.1」の節は前の計画で、消さずに残している。
> 依頼：TEZZO（Leo 経由。2026-11-13）「管理者・SES・担当者のEG一覧からEGへメールを出せるようにして欲しい。同様にEGからSES・担当者へもメールが出せるようにしたい」。
> 作る順：段階1 → 段階2 → 段階3 → 段階4（段階ごとに commit。`php artisan test` を通してから次へ）→ park-reviewer → park-tester。commit・push・公開版への反映は Leo。
> プレーヤー：管理者・SES・担当者・EG の4つ（担当者は今のアプリでは `role=ses`。SES の代表者と同じ扱い）。
> **案件の案内メール（`CaseMail`・`CaseSearch`）とは別の「連絡メール」**。案件に結び付かない、自由な件名・本文。今の案内メール・「やり取り」（管理者と EG のメッセージ。`/engineers/{id}/messages`）は変えない。
> 外部のライブラリは足さない。メール本文・取引先の名前はこのファイルにもテストにも書かない（テストの件名・本文は架空の文）。

## 0. コードで確かめた今の形

- **EG の一覧は2つ**：
  - 管理者：`GET /admin/engineers`（`AdminController::engineers` → `admin/engineers.blade.php`）。全社の EG。`q`・`status`・`ses=direct` で絞る（サーバで絞る）。行の右に「送り直し」「停止」「退会」「詳細」などの `<x-post>`（**行の中に form がある**）。
  - SES・担当者：`GET /ses/engineers`（`SesController::engineers` → `ses/engineers.blade.php`）。自社（`e.ses_id = user.ses_id`）の EG だけ。行の作りは管理者と同じ（行の中に form）。
- **EG の詳細**：`GET /engineers/{id}`（`EngineerController::show` → `engineers/show.blade.php`）。`load()` が `Perms::engineer`（管理者は全員、SES は自社、EG は本人）で 404／403。上のカード「エンジニア情報」の `x-slot:actions`（6〜15行）に「再開・停止・退会・送り直し・編集」。
- **EG のホーム**：`GET /eg`（`eg/home.blade.php`）。右の列（27〜31行）に「スキルシートを良くするには」のカード。19行に「管理者からのメッセージ」の未読カード。
- **ナビ**：`layouts/app.blade.php` の `$nav`（4〜7行。admin・ses・eg ごと）。
- **送る道**：`Notifier::queueMail($to, $name, $subject, $body, $kind, $replyTo, $fromName)`。件名の頭に `[PARK]`、本文の後ろに `$fromName`（無ければ運営の名前）・URL・「PARK から自動で送っています」の足を付ける。`mail_outbox` に残し、送れる設定なら `deliver`。`deliver` が **@park.example 宛てを `config('park.dummy_mailbox')` に差し替える**（v0.7.0 で実装済み）。差出人のアドレスは全メール共通で、名前だけ変わる（`fromOf`）。
- **今の案内メールの差出人・返信先**（`CaseMail::send` 109行）：管理者は `fromName = admin_sender($u)`（会社名。無ければ null）・返信先なし。SES は `fromName = null`・自社の人あてだけ返信先＝送った人のアドレス。
- `user_label($u)`：SES は「会社名 氏名」、管理者は「（会社名か運営の名前） 氏名」、直属の EG は氏名だけ。
- **使える人の条件**：EG は `Registration::usableEngineers`（本登録済み・停止中でない・退会でない）。EG のアカウントは `Notifier::egUsers($eid)`（その EG の `users` の行。兼任の SES・管理者の行も入る。active・本登録済み）。SES の人は `Registration::usable`（**private**。active・`left_at` なし・本登録済み・会社が `active`）。管理者は `Notifier::admins()`。
- 直属：`ses_companies.kind='direct'`（`Workflow::isDirect`）。直属には SES の人がいない（`Notifier::toSes` も管理者に回す）。
- 兼任：1つの `users` の行が `role=ses|admin` と `engineer_id` を持つ（`Workflow::isDual`）。ログインのときに立場を選ぶ。お知らせは `for_role` で立場ごとに分ける。
- お知らせ：`Notifier::notify($userId, $title, $body, $link, $mail, $kind, $forRole)`。`$mail=false` で画面のお知らせだけ。
- `SamplePurge` は見本の `users`・`engineers` を消す（新しい表に見本の人の行が残ると消せない）。
- 版は `config/park.php`（今 `v0.7.0`）。版の文字を見ているテスト：`RegistrationFoundationTest`・`SkillQuestionsTest`・`V030RegressionTest`・`V050CatalogTest`・`V060CaseAiTest`・`V070SearchTest`。

## 1. 受け入れ条件

| 番号 | 受け入れ条件 |
|---|---|
| **段階1** | **送れる範囲と送る仕組み（画面なし）** |
| R1 | 管理者は、どの会社の EG にも送れる（直属の EG も） |
| R2 | SES・担当者は、自社の EG にだけ送れる。他社の EG には送れない |
| R3 | EG は、自分の所属する会社の SES・担当者（`role=ses`・同じ `ses_id`）にだけ送れる。所属が直属の EG は、宛先が管理者になる |
| R4 | 宛先に出る・送れるのは使える人だけ：EG は本登録済み・停止中でない・退会でない・アカウントがある人。SES・担当者・管理者は active・退会でない・本登録済み（SES は会社が参加中）。自分自身は宛先に出ない（兼任の人も） |
| R5 | 今回は無し：SES に所属する EG から管理者へ、EG どうし、SES どうし、管理者から SES・担当者へ |
| S1 | 1通の連絡メールで、選んだ人それぞれに1通ずつメールが届く（ほかの宛先は見えない） |
| S2 | メールの件名・本文は送った人が書いたもの。本文の前に宛名、後ろに「誰から（表示名）」「返信すると誰に届くか」「PARK の画面でも読める」の数行が付く |
| S3 | 差出人の表示名：管理者は `admin_sender`（無ければ運営の名前）、SES・担当者・EG は `user_label`（会社名＋氏名。直属の EG は氏名） |
| S4 | 返信先（Reply-To）は送った人のメールアドレス。ただし、送った人と受け取る人が同じ会社か、どちらかが管理者のときだけ付ける（他社にアドレスを出さない） |
| S5 | 受け取った人に、画面のお知らせが届く（メールは重ねて送らない）。押すと連絡メールの画面が開く |
| S6 | 見本データ（@park.example）宛ては、今の `Notifier::deliver` の差し替えで届く（作り直さない） |
| S7 | 送り先の人数は 1〜50 人。件名 1〜100 字、本文 1〜5000 字。外れたら送らずに理由を出す |
| S8 | 同じ作成画面から2回押しても（同時の2回でも）、1回分しか送らない |
| K1 | 送った記録が残る（送った人・立場・件名・本文・日時・宛先ごとの `outbox_id`）。操作の記録（`audit_log`）にも残る（件名・本文は書かない） |
| **段階2** | **作成・一覧・詳細の画面** |
| G1 | 作成画面に、宛先（名前の一覧）・件名・本文・［送る］。押すとブラウザの確かめ「n名に送ります。よろしいですか」が出る |
| G2 | 送ったあと「n名に連絡メールを送りました」と出て、その連絡メールの画面へ移る |
| G3 | 「連絡メール」の一覧：「受け取った」「送った」の2つ。管理者には「すべて」もある。新しい順 |
| G4 | 連絡メールの画面：件名・本文・送った人・日時。送った人と管理者には宛先の一覧（送れたかの状態つき）。受け取った人には自分のことだけ |
| G5 | メールが「保存のみ」の設定（`N::canSend()` が偽）なら、作成画面に今の案内と同じ注意を出す |
| G6 | ナビに「連絡メール」（管理者・SES・EG） |
| **段階3** | **入口** |
| E1 | 管理者の EG 一覧（`/admin/engineers`）に、行ごとのチェックと［選んだ人にメールを送る］。送れない人（仮登録・停止中・退会）の行にはチェックを出さない |
| E2 | SES・担当者の EG 一覧（`/ses/engineers`）に、同じチェックとボタン |
| E3 | 一覧に「表示中の人を全部選ぶ／外す」がある |
| E4 | EG の詳細（`/engineers/{id}`）に［メールを送る］（管理者・その EG の会社の SES・担当者。送れる人のときだけ）。押すとその人だけが宛先の作成画面 |
| E5 | EG のホーム（`/eg`）に［担当者にメールを送る］のカード。作成画面で、宛先を自社の SES・担当者（直属なら管理者）からチェックで選ぶ。送れる相手がいなければ「今は送れる相手がいません」 |
| **共通** | **権限・境界** |
| P1 | SES・担当者が、他社の EG の id を作成画面の URL・送信に入れると 403。何も送られない |
| P2 | EG が、他社の SES の人・管理者（直属でないとき）・EG の user id を宛先に入れると 403。何も送られない |
| P3 | 自分の範囲の人でも、停止中・退会・仮登録になった人は送るときに外し、「送れない n 名を外しました」と出す。全員外れたら送らない |
| P4 | 連絡メールの画面は、送った人・受け取った人・管理者だけが開ける。ほかは 404（他社の SES・担当者・EG が URL を直打ちしても） |
| P5 | ログインしていなければログインへ。宛先が0人・選んでいないときは「送る相手を選んでください」 |
| **段階4** | **まわり** |
| V1 | 版を v0.8.0 にする。README・マニュアル（`resources/manuals` の admin・ses・engineer と各 -steps）を直す。見本データの削除（`SamplePurge`）で、見本の人の連絡メールの行も消える |

## 2. 決めたこと（Leo の仮の推奨をもとに planner が仮置き。違えば7章で直す）

| 番号 | 決めたこと | 理由 |
|---|---|---|
| D1 | 名前は「連絡メール」。サービス `app/Services/DirectMail.php`、画面 `app/Http/Controllers/DirectMailController.php`、画面のファイル `resources/views/mails/index.blade.php`・`new.blade.php`・`show.blade.php` | 今の作りに合わせる |
| D2 | 表を2つ足す（3-1）。1回の送信＝`direct_mails` 1行、宛先ごと＝`direct_mail_recipients` 1行 | 50 人に送っても本文を1回だけ持つ。宛先ごとの `outbox_id` と状態を持てる |
| D3 | 宛先の持ち方：管理者・SES が送るときは **EG（`engineers.id`）** を選び、届け先はその EG のアカウント（`Notifier::egUsers` の、`role=eg` を先にした1行）。EG が送るときは **人（`users.id`）** を選ぶ。どちらも `direct_mail_recipients.to_user_id` に入れ、EG あてなら `to_engineer_id` も入れる | 一覧は EG の行、EG の宛先は人。受け取った人の「受け取った」一覧は user id 1つで引ける |
| D4 | 送れる相手の判定は `DirectMail` の1か所（`engineerTargets($u, array $ids)`・`staffTargets($u)`・`check`）。一覧のチェックの出し分け・作成画面・送信のすべてがこれを使う。`Registration::usable` を public にして使う（中身は変えない） | 正本1か所。画面と送信で食い違わない |
| D5 | 範囲の外（他社の EG・存在しない id・選べない人の user id）が1つでも来たら **403 で何も送らない**。範囲の中だが使えなくなった人（停止・退会・仮登録）は外して送り、「送れない n 名を外しました」（P1〜P3） | URL の細工ははっきり拒み、開いている間に状態が変わっただけの人は普通に扱う |
| D6 | 返信先（S4）：送った人の `users.email`。`replyToAllowed($from, $to)`：どちらかが管理者、または送った人の会社（SES は `ses_id`、EG は `engineers.ses_id`）と受け取る人の会社が同じとき。今の範囲（R1〜R3）ではいつも当てはまるが、守りとして必ず通す | EG→自社の SES の返信先は EG のアドレスになるが、自社の SES・担当者は EG の一覧（アカウントの列）で既にそのアドレスを見ている。新しく見せる範囲は増えない（7章 Q2） |
| D7 | 本文の組み立て（`DirectMail::compose`）：`{宛名} 様` → 空行 → 送った人の本文 → 区切り → 「{送った人の表示名} から、PARK の連絡メールでお送りしています。」「このメールに返信すると、{送った人の表示名} に届きます。」（返信先があるときだけ）「PARK の画面でも読めます：{/mails/{id} の URL}」。宛名は `user_label`（EG あては EG の氏名）。そのあと `queueMail` の今の足が付く | 送った人がだれで、返信がどこへ行くかを受け取った人に示す。`queueMail` は変えない |
| D8 | 二重送信の守り（S8）：作成画面を開くたびに使い捨ての鍵（32 文字の乱数）を隠し欄 `send_key` に入れ、`direct_mails.send_key` に **unique**。送信は最初に `direct_mails` を入れ、unique で弾かれたら送らずに、前に送った連絡メールの画面へ「もう送っています」で移る。そのあとで宛先ごとに `queueMail` | 同時の2回押しも DB が止める。メールを送るのは行が入った1回だけ |
| D9 | 受け取った人のお知らせ（S5）：`N::notify(to_user_id, "{送った人の表示名}から連絡メールが届きました", 件名, "/mails/{id}", false, 'direct-mail', 立場)`。立場は EG あてなら `eg`、SES あてなら `ses`、管理者あてなら `admin` | メールは本体が届くので重ねない。兼任の人のお知らせを立場で分ける |
| D10 | 受け取る人の `mail_opt_in`（お知らせのメールの希望）は見ない（送る） | 人から人への連絡で、今の案内メールも見ていない（7章 Q4） |
| D11 | 見られる範囲（P4）：送った人（`from_user_id`）・受け取った人（`to_user_id`）・管理者（全部）。同じ会社のほかの SES・担当者は見られない | 個人どうしの連絡として扱う（7章 Q3） |
| D12 | 管理者が SES の会社の EG に送っても、その会社の SES には知らせない | 今の「やり取り」（管理者と EG）も知らせていない（7章 Q5） |
| D13 | 一覧のチェックは、行の中に `<x-post>` の form があるので、表の外に `<form id="dm" method="get" action="/mails/new">` を置き、チェックは `<input type="checkbox" name="eg[]" form="dm">` で結び付ける。［選んだ人にメールを送る］はその form の送信。「表示中の人を全部選ぶ／外す」は表の上のボタン＋小さな JavaScript（画面の中に書く） | form の入れ子を作らない。今の画面の作り（Blade＋少しの JS）に合わせる |
| D14 | 作成画面は `GET /mails/new?eg[]=…`（管理者・SES）／`GET /mails/new`（EG）。51 人以上を選んで来たら、作成画面に「一度に送れるのは 50 名までです。一覧で減らしてください」と出し、［送る］を出さない | 一覧から GET で渡せる。上限を画面の最初で知らせる |
| D15 | 操作の記録：`audit('direct-mail', "#{id} {立場} → n名")`（件名・本文・名前は書かない） | 記録に中身を残さない |
| D16 | 送信のルートに `throttle:10,1` | 連打・機械的な送信を抑える |
| D17 | 版は **v0.8.0**（表の追加と新しい機能） | |

## 3. 仕様の詳細

### 3-1. 表（マイグレーション `2026_11_13_000002_direct_mails.php`）

- `direct_mails`：`id`、`send_key`（string 40・unique）、`from_user_id`（users の id。null 可・`nullOnDelete`）、`from_role`（string 10：admin／ses／eg）、`from_ses_id`（null 可。送った人の会社。管理者は null、EG は `engineers.ses_id`）、`from_label`（string 200。送ったときの表示名）、`subject`（string 150）、`body`（text）、`to_count`（int）、`created_at`。index `from_user_id`。
- `direct_mail_recipients`：`id`、`direct_mail_id`（direct_mails・cascade）、`to_user_id`（users の id。null 可・`nullOnDelete`）、`to_engineer_id`（null 可・`nullOnDelete`）、`to_role`（string 10）、`to_label`（string 200。送ったときの表示名）、`outbox_id`（null 可）、`created_at`。unique `(direct_mail_id, to_user_id)`、index `to_user_id`。
- `down` は2つの表を消す。
- 表示名を写して持つのは、あとで人が消えても（見本の削除など）記録が読めるようにするため。送れたかの状態は `mail_outbox` の `status` を `outbox_id` で引く。

### 3-2. `DirectMail`（段階1）

- `const MAX_TO = 50`、`MAX_SUBJECT = 100`、`MAX_BODY = 5000`。
- `canCompose($u): bool`：admin・ses・eg（ログインした立場）。
- `engineerTargets($u, array $ids): array`（管理者・SES 用）：`['ok' => [eid => 届け先の users の行], 'unusable' => [eid…], 'outside' => [id…]]`。範囲：管理者は全 EG、SES は `engineers.ses_id = $u->ses_id`。範囲の中で `usableEngineers` と `egUsers` に当たらない人は `unusable`。自分自身（`$u->engineer_id`）は `outside`。**まとめて1〜2回の問い合わせ**（一覧の行の数で増やさない）。
- `staffTargets($u): array`（EG 用）：自分の EG の会社が `kind=ses` なら、その会社の `role=ses` の人で `Registration::usable` のもの。`kind=direct` なら `Notifier::admins()` で `left_at` なしのもの。自分の user id は除く。並びは代表者（`ses_companies.rep_user_id`）を先に、ほかは名前順。
- `replyToAllowed(object $from, object $to): bool`（D6）。
- `compose(...)`（D7）。
- `send($u, array $ids, string $subject, string $body, string $key): array`：
  1. 検査（S7。前後の空白は全角も取る）→ だめなら `['bad' => 文]`。
  2. 宛先：管理者・SES は `engineerTargets`、EG は `staffTargets` と照らす。`outside` が1つでもあれば `['forbidden' => true]`（コントローラが 403）。`ok` が0なら `['bad' => '送る相手を選んでください。']`（全員 unusable のときは「送れる相手がいません」）。
  3. `direct_mails` を入れる（D8。unique で弾かれたら `['dup' => 前の id]`）。
  4. 宛先ごと：`queueMail(届け先のメール, 表示名, 件名, compose(...), 'direct-mail', 返信先, 差出人の表示名)` → `direct_mail_recipients` → `notify`（D9）。
  5. `audit`（D15）。`['ok' => id, 'sent' => n, 'skipped' => unusable の数]`。
- `canView($u, object $m): bool`（D11）。
- `inbox($u)`・`outbox($u)`・`all()`：一覧（50 件ずつ。`paginate` は今の画面にあるものに合わせる。無ければ `?page=` と `limit/offset`）。

### 3-3. ルート（`Route::middleware('role')` のグループに足す）

| ルート | 動き |
|---|---|
| `GET /mails` | 一覧（G3）。`?box=in`（既定）・`out`・`all`（管理者だけ。ほかは `in` に戻す） |
| `GET /mails/new` | 作成画面（G1・G5・D14）。管理者・SES は `eg[]`、EG は宛先のチェック（`to[]`） |
| `POST /mails` | 送る（`throttle:10,1`）。立場が admin・ses なら `eg[]`、eg なら `to[]` だけを読む（もう一方は無視） |
| `GET /mails/{id}` | 連絡メールの画面（G4）。`canView` でなければ 404 |

### 3-4. 画面

- **作成画面**（`mails/new.blade.php`）：「宛先（n名）」に名前を並べる（管理者・SES：EG の氏名＋会社名、EG：SES・担当者の氏名と「代表」の印、直属なら「管理者」）。EG のときはチェックで選ぶ（はじめはどれもチェックなし。1人だけなら最初からチェック）。件名・本文（`textarea`）・隠し欄 `send_key`・［送る］（`onsubmit` で「n名に送ります。よろしいですか」。n は送る時点のチェックの数）。下に「返信は {あなた} のメールアドレスに届きます」「管理者は、送った連絡メールを見ることができます」の注意。入力のエラーで戻るときは件名・本文を残す（`old()`）。
- **一覧**（`mails/index.blade.php`）：タブ（受け取った／送った／すべて）。列：日時・件名・相手（受け取った＝送った人、送った＝宛先の先頭の名前＋「ほか n 名」）。
- **連絡メールの画面**（`mails/show.blade.php`）：件名・送った人・日時・本文（改行そのまま、`e()` で）。送った人と管理者に宛先の表（名前・立場・状態＝送った／保存のみ／失敗）。受け取った人が SES・担当者・管理者のとき［このエンジニアの詳細］（EG から届いたもの）。EG が送った人の画面に［もう一度送る］は付けない（作成画面へのリンクだけ）。
- **管理者の EG 一覧**（`admin/engineers.blade.php`）：表の先頭に列「選ぶ」（`$mailable[$e->id]` のときだけチェック、`form="dm"`）。表の上に［表示中の人を全部選ぶ］［全部外す］、表の下に［選んだ人にメールを送る］。`AdminController::engineers` で `DirectMail::engineerTargets($u, 一覧の id)` の `ok` を `$mailable` として渡す。
- **SES の EG 一覧**（`ses/engineers.blade.php`）：同じ。`SesController::engineers` で同じく `$mailable`。右上の［エンジニアを登録］の隣に［選んだ人にメールを送る］は置かず、表の下にだけ置く（押し間違いを減らす）。
- **EG の詳細**（`engineers/show.blade.php` の「エンジニア情報」の actions）：`!$mine` で、`DirectMail::engineerTargets($u, [$e->id])['ok']` にあれば `<a class="btn ghost small" href="/mails/new?eg[]={id}">メールを送る</a>`。
- **EG のホーム**（`eg/home.blade.php` の右の列、「スキルシートを良くするには」の下）：カード「担当者への連絡」。文「所属の会社の担当者に、メールで連絡できます。」（直属なら「管理者に、メールで連絡できます。」）＋［担当者にメールを送る］→ `/mails/new`。
- **ナビ**（`layouts/app.blade.php`）：admin は「メール」の前、ses は「請求」の後、eg は「やり取り」の後に `['/mails', '連絡メール']`。

## 4. プレーヤーごとに変わること

| プレーヤー | 送れる相手 | 入口 | 見られるもの |
|---|---|---|---|
| 管理者 | 全社の EG（使える人） | EG 一覧のチェック、EG の詳細、受け取ったメールの画面 | 全部の連絡メール（「すべて」）。直属の EG から届く |
| SES | 自社の EG（使える人） | 自社の EG 一覧のチェック、EG の詳細 | 自分が送った・受け取ったもの。自社の EG から届く |
| 担当者 | SES と同じ（`role=ses`） | SES と同じ | 自分が送った・受け取ったもの（同じ会社のほかの人の分は見えない） |
| EG | 所属の会社の SES・担当者（直属なら管理者） | ホームのカード、ナビの「連絡メール」 | 自分が送った・受け取ったもの。届いたメールに返信すると、送った人に届く |

- 境界：選んだ人が0人 → 「送る相手を選んでください」。51 人以上 → D14。EG の会社に使える SES・担当者がいない（全員停止など）→ E5 の文。会社が停止中の SES の人は宛先に出ない。兼任の人が自分を選ぶことは無い（R4）。期限切れ・取り消しは無い（送った記録は消さない）。

## 5. 作業の手順（implementer）

### 段階1：表と仕組み

1. マイグレーション（3-1）。`php artisan migrate`（手元）。
2. `Registration::usable` を `public static` にする（中身は変えない）。
3. `app/Services/DirectMail.php`（3-2）。`Notifier` は変えない。
4. `tests/Feature/V080DirectMailTest.php` の T1〜T9 を書き、`php artisan test`。

### 段階2：画面

1. `DirectMailController`（`index`・`create`・`store`・`show`）とルート（3-3）。`store` は `send` の結果で 403／入力に戻す（`withInput`）／前の画面へ／作った画面へ。
2. `mails/index`・`new`・`show` の3画面（3-4）。ナビ（G6）。
3. T10〜T16 を書き、`php artisan test`。

### 段階3：入口

1. `AdminController::engineers`・`SesController::engineers` で `$mailable` を渡す。2つの一覧にチェック・全部選ぶ・送るボタン（D13）。
2. `engineers/show.blade.php` に［メールを送る］、`eg/home.blade.php` にカード。`EgController::home` で直属かどうかを渡す（文の出し分け）。
3. T17〜T20 を書き、`php artisan test`。

### 段階4：まわり

1. `SamplePurge`：見本の人が送った・受け取った `direct_mails`・`direct_mail_recipients` を消す対象に足す（件数の表示・バックアップにも出す。今の表の並べ方に合わせる）。
2. `config/park.php` を `v0.8.0`。版を見ているテスト（0章の6つ）を直す。
3. README：「連絡メール」の節（送れる範囲 R1〜R5、返信先 D6、二重送信の守り D8、上限 S7、表2つ、案内メール・やり取りとの違い）。
4. マニュアル（`resources/manuals`）：
   - `admin.md`・`admin-steps.md`：EG 一覧で選んで送る手順、詳細からの1人あて、「連絡メール」の一覧（「すべて」で全体が見える）、直属の EG から届くこと、返信先。
   - `ses.md`・`ses-steps.md`：自社の EG に送る手順（担当者も同じ。他社の EG には送れない）、EG から届くこと、返信すると EG に届くこと、管理者は連絡メールを見られること。
   - `engineer.md`・`engineer-steps.md`：ホームの［担当者にメールを送る］の手順、送れる相手（所属の会社の担当者。直属なら管理者）、返信先が自分のアドレスになること、届いたメールへの返信は送った人に届くこと、管理者は連絡メールを見られること。
   - 04_Manuals の写しは Leo（または park-manager）が push のあとに写し直す。implementer は park の中だけ直す。
5. T21〜T23 を書き、`php artisan test` が全部通ることを確かめる（zip の既知の1件を除く）。

## 6. テスト（`tests/Feature/V080DirectMailTest.php`）

件名・本文はテストの中で作る**架空の文だけ**。立場は今のやり方（管理者・自社 SES の代表・同じ会社の担当者・他社の SES・自社の EG・他社の EG・直属の EG を作ってログイン）。メールは `mail_outbox` の行で確かめる。

| 番号 | 条件 | 手順 | 確かめること |
|---|---|---|---|
| T1 | R1・R2・R4 | 管理者・SES で `engineerTargets`（自社・他社・直属・仮登録・停止中・退会・自分自身の EG） | 管理者は全社の使える人が ok。SES は自社だけ ok、他社は outside。仮登録・停止・退会は unusable、自分は outside |
| T2 | R3・R4 | 自社 EG・直属 EG で `staffTargets`（代表・担当者・停止中の担当者・会社が停止中の別会社） | 自社 EG は代表と使える担当者だけ（代表が先）。直属 EG は管理者。停止中の人は出ない |
| T3 | S1・S2・S3・K1 | SES が自社の EG 2人に送る | `mail_outbox` 2通（宛先ごと）。件名・本文が入り、宛名・「…から、PARK の連絡メールで」の行。`from_name` が「会社名 氏名」。`direct_mails` 1行・`direct_mail_recipients` 2行（`outbox_id` あり）。`audit_log` に `direct-mail`（件名が入っていない） |
| T4 | S4 | SES→自社 EG／EG→自社 SES／管理者→他社の EG／直属 EG→管理者 | どれも `reply_to` が送った人のアドレス。`replyToAllowed` に別の会社どうしを渡すと false |
| T5 | S5 | T3 のあと | 受け取った2人に `app_notifications`（`for_role=eg`・リンク `/mails/{id}`）。お知らせのメールは増えない |
| T6 | S7 | 宛先 51 人／件名 101 字／本文が空 | 送られない（行が増えない）。理由の文 |
| T7 | S8 | 同じ `send_key` で2回送る | `direct_mails` 1行、`mail_outbox` は人数分だけ。2回目は前の画面へ |
| T8 | S6 | `@park.example` の EG に、送れる設定（`mail.default` を array 以外にし、`Mail::fake`）で送る | 届け先が `park.dummy_mailbox`（今の `deliver` のまま） |
| T9 | D3 | 兼任（`role=ses`・`engineer_id` あり）の EG に管理者が送る | 届け先はその人の行。お知らせは `for_role=eg` |
| T10 | G1・G5 | 管理者で `GET /mails/new?eg[]=…`／`mail.default=log` で開く | 宛先の名前・件名・本文・`send_key`・確かめの文／「保存のみ」の注意 |
| T11 | G2 | `POST /mails` | 「n名に連絡メールを送りました」で `/mails/{id}` へ |
| T12 | G3 | 送った人・受け取った人・管理者で `GET /mails`（`box` を変えて） | それぞれの一覧に出る。SES の `?box=all` は「受け取った」になる |
| T13 | G4 | 送った人・受け取った人・管理者で `GET /mails/{id}` | 本文が出る。宛先の表は送った人と管理者だけ（受け取った人にはほかの宛先の名前が出ない） |
| T14 | P1 | SES が他社の EG の id で `GET /mails/new?eg[]=`・`POST /mails` | 403。`direct_mails`・`mail_outbox` が増えない |
| T15 | P2 | EG が他社の SES の人／管理者（直属でない EG）／別の EG の user id を `to[]` に入れて送る | 403。増えない |
| T16 | P3・P5 | 選んだあとで1人を停止して送る／全員停止／何も選ばずに送る | 「送れない 1 名を外しました」で残りに送る／「送れる相手がいません」／「送る相手を選んでください」 |
| T17 | P4 | 他社の SES・同じ会社のほかの担当者・関係のない EG で `GET /mails/{id}` | 404 |
| T18 | E1・E3 | 管理者で `GET /admin/engineers`（使える人・仮登録・停止中がいる） | 使える人の行だけに `name="eg[]"` のチェック（`form="dm"`）。全部選ぶのボタン・［選んだ人にメールを送る］ |
| T19 | E2・E4 | 担当者で `GET /ses/engineers`／自社 EG の詳細／停止中の EG の詳細 | チェックとボタン／［メールを送る］が `/mails/new?eg[]=` を指す／停止中の人には出ない |
| T20 | E5 | 自社 EG・直属 EG で `GET /eg` と `GET /mails/new`／担当者が全員停止の会社の EG | カードと宛先の候補（直属は管理者）／「今は送れる相手がいません」 |
| T21 | V1 | 見本の EG に送ってから `SamplePurge` | 見本の人の連絡メールの行が消え、削除が止まらない |
| T22 | V1 | `/login` | `v0.8.0` |
| T23 | 既存 | 既存のテスト一式（案内メール・エンジニア検索・やり取りを含む） | 版の文字のほかは変えずに通る |

- 画面の見た目（チェックの列・全部選ぶ・確かめのダイアログ）は reviewer がコードを読み、Leo が手元の画面で確かめる。

## 7. 確認したいこと（Leo が決める。答えが来るまで推奨で作る）

- **Q1 管理者の返信先**：Leo の推奨どおり、管理者の `users.email` にする（D6）。**推奨：このまま**。ただし管理者のアドレスが個人のものなら、EG に見えることになる。避けたいときは、管理者だけ返信先を付けない（今の案内メールと同じ。返信は共通の差出人アドレスへ）に直す（`compose` の一文と `replyToAllowed` の2か所）。
- **Q2 EG→SES の返信先が EG のアドレスになること**：**推奨：付ける**（D6）。自社の SES・担当者は EG の一覧で既にそのアドレスを見ている。作成画面に「返信は あなたのメールアドレスに届きます」と出して、EG に分かるようにする。
- **Q3 同じ会社のほかの SES・担当者が見られるか**：**推奨：見られない（本人だけ。管理者は全部）**（D11）。会社で共有したいなら、次の版で「自社の分」を足す（`from_ses_id` と宛先の会社で引ける）。
- **Q4 お知らせのメールを断っている人（`mail_opt_in` が偽）に送るか**：**推奨：送る**（D10）。人が書いた連絡で、案内メールと同じ扱い。
- **Q5 管理者が SES の会社の EG に送ったとき、その SES に知らせるか**：**推奨：知らせない**（D12）。SES が気にするなら、次の版で「管理者から貴社の◯◯さんに連絡がありました」（件名だけ）を足す。
- **Q6 上限**：1回 50 人・件名 100 字・本文 5000 字・1分 10 回（S7・D16）。**推奨：このまま**。
- **Q7 「やり取り」（管理者と EG のメッセージ）との関係**：**推奨：今回は両方残す**。直属の EG は「やり取り」と連絡メールの両方で管理者に連絡できる。マニュアルに「やり取りは PARK の画面の中だけ、連絡メールはメールで届く」と違いを書く。

## 8. 了承が要ること

- 表を2つ足す（マイグレーション）：公開版への反映（`park-deploy.sh` が migrate する）は常時了承の範囲。`.env` の変更は無い。
- **メールの送信**：この機能は、使う人が押したときに相手へメールを送る。実装・テストでは `mail_outbox` に入るだけ（テストは送らない）。**公開版で実際の人あてに試しに送ることは、TEZZO の了承を取ってから**（試すなら見本のデータの人あて。`@park.example` は `park@jona.jp` に届く）。
- データの削除：`SamplePurge` に新しい表を足すだけ（見本の削除を実行するのは今までどおり TEZZO の了承を取ってから）。

---

> 以下は前の計画（v0.7.0。2026-11-13）。消さずに残す。

# PLAN：エンジニア検索 → メール送信（v0.7.0。2026-11-13）

> **これが今の計画**。下の「v0.6.0」の節は前の計画（実装・公開版への反映は済み）で、消さずに残している。v0.5.0 より前の計画は Git の履歴（`git log -p PLAN.md`）にある。
> 依頼：TEZZO（Leo 経由。2026-11-13）。「案件確認⇒エンジニア検索画面」「エンジニア一覧画面」「エンジニア詳細画面」の3つ。
> 作る順：段階1 → 段階2 → 段階3 → 段階4（段階ごとに commit。`php artisan test` を通してから次へ）→ park-reviewer → park-tester。commit・push・公開版への反映は Leo。
> プレーヤー：管理者・SES・担当者・EG の4つ（担当者は今のアプリでは `role=ses`）。**使えるのは今の案内メールと同じ人**（`CaseMail::canSend`＝管理者と、案件を持ち込んだ会社の SES・担当者）。**EG には画面ごと出さない**。
> 外部のライブラリは足さない。**案件メールの本文・取引先の名前はこのファイルにもテストにも書かない**（テストの本文は架空の文）。今の `/cases/{id}/mail` の流れは壊さず残す（ボタンの行き先だけ変える）。

## 0. コードで確かめた今の形

- 案件の詳細（`cases/show.blade.php`）：左に案件票・「AI の読み直し」・「案内メール」（`$canMail` の人。右上に［エンジニアに案内する］→ `/cases/{id}/mail`）。右は管理者に［エンジニアに案内する］のカード（94行）、SES に「貴社のエンジニアとの相性」（`$canMail` なら［エンジニアに案内する］、106行）。**元のメール（`raw_text`）は今どの画面にも出ていない**。
- 今の案内（`CaseMailController`）：form（候補の表＋件名・本文の雛形）→ preview（一人ずつの本文と提案。`N::canSend()` が偽なら「保存のみ」の注意）→ send。管理者は scope=all、SES は own／group を切り替える。
- `CaseMail::candidates`：`Matcher::forCase($id, true)`（使える人・有効な SES・応募済みを除く・稼働中も入る）から `unavailable` を除き、`own`（SES なら自社。**管理者は全員 own**）・`sent`（`case_mails` の返事）を付ける。
- `CaseMail::send($u, $c, $items, $mode)`：まとめて `skillIndexMany`・`expMany` を読み、すでに `case_mails` がある人・未登録の人は飛ばす。返事のリンク（トークン）を本文の後ろに付け、`N::queueMail`（own＋SES なら返信先は送った人）、`case_mails`（route own／group・mode・本文・点数・提案）、`case_offers`（無ければ）、group の人の SES に知らせ、`audit('case-mail')`。点数は **案件そのもの** で出す。`case_mails.mode` は `string(10)`。
- `Matcher::score`：○×を項目ごとには返さない（reasons・missing の文）。技術は `hasSkill($idx, 名前)`（同じ言葉、または両方3文字以上で `loose_match`）、業務は `$exp[項目名]`。経歴だけで当たる技術は `fromProject=true`（点数は 0.8）。
- 索引の1つ：`['name', 'years'（`SkillForm::label` の文か null）, 'rank'（`years_min`＝0・1・3・5、無ければ -1。経歴だけの行には無い）, 'fromProject', 'months'（経歴の月数。あるときだけ）]`。
- 名前の見え方：SES は `Perms::engineer` で自社の人だけ `/engineers/{id}` を開ける。今の案内では、他社の人はイニシャル＋会社名で出す。
- 自社・他社：`ses_companies.kind='direct'` が管理者直属（`Workflow::isDirect`）。`ses_companies.contact_name` に会社の担当者名がある。
- ルート：`/cases/{id}/mail` などは `role:admin,ses` のグループ（EG は 403）。
- 業務の項目名の一覧は `SkillForm::caseItems()`（[項目名 => ['section' => …]]）、案件の業務は `CaseExp::split`。技術の正式名は `SkillCatalog::find`（`ensure` はマスタに足すので使わない）。
- 版は `config/park.php`（今 `v0.6.0`）。版の文字を見ているテスト：`RegistrationFoundationTest`・`SkillQuestionsTest`・`V030RegressionTest`・`V050CatalogTest`・`V060RuleTest`・`V060CaseAiTest`・`V060ExtraTest`。

## 1. 受け入れ条件

| 番号 | 受け入れ条件 |
|---|---|
| **段階1** | **データと判定（画面なし）** |
| J1 | 必須（技術＋業務の合計 n 項目）のうち当たった数を h として、`h*4 >= n*3` なら合格（n=1→1、2→2、3→3、4→3、5→4、8→6）。必須が0項目なら全員合格。尚可は足切りしない（0でもよい） |
| J2 | 対象は、今の案内と同じ人（使える人・有効な SES・応募済みを除く・`unavailable` を除く・稼働中は入れる） |
| J3 | 並び：自社 → 他社。それぞれの中は、当たった必須の技術の経験年数の合計が多い順。同じなら h の多い順、次に点数（`Matcher::score`）の高い順、次に id |
| J4 | 「自社」：SES・担当者は自分の会社の人。管理者は直属（`kind=direct`）の会社の人。ほかは他社 |
| **段階2** | **検索画面と一覧画面** |
| E1 | 案件の詳細の［エンジニアに案内する］（左の「案内メール」・右の管理者のカード・右の SES のカードの3か所）の行き先が、エンジニア検索（`/cases/{id}/search`）になる。古い `/cases/{id}/mail` は残り、今までどおり動く |
| E2 | 検索画面の左に元のメール（`raw_text`）の全文。無ければ作業内容（`description`）と「元のメールはありません」 |
| E3 | 右に、メールから抽出された条件として「必須」「尚可」（案件の技術と業務を並べる）。項目ごとに「外す」チェック |
| E4 | 必須・尚可それぞれに手入力の欄があり、項目を足せる（「、」や改行で区切って複数可）。技術は正式な名前にそろえ、マスタに無い言葉はそのまま。業務の項目名と同じなら業務として扱う。同じ項目は重ねない |
| E5 | 右の項目の下に［エンジニアを検索］ボタン。押すと一覧画面へ |
| E6 | 足した・外した条件はその検索にだけ保存する。**案件の必須・尚可・業務の列と技術マスタは変わらない** |
| E7 | 同じ人が同じ案件の検索画面をもう一度開くと、前の条件（足した・外した）が入っている |
| L1 | 一覧画面の並び（上から）：①全員選択のチェック＋案件のタイトル ②全員へのメッセージ（入力欄）③エンジニアの行（チェック＋氏名（所属会社）＝詳細へのリンク＋「必須 h/n 尚可 k/m」）④［エンジニアへメールを送信］ |
| L2 | 行は J1 の合格者だけを J3 の順で出す。合格者には、はじめからチェックが入る |
| L3 | 他社の人は、SES・担当者にはイニシャル（会社名）で出す（名前を伏せる）。管理者には氏名（会社名） |
| L4 | 一覧にエンジニアを足せる：名前などで探し、［一覧に足す］。足した人は不合格でも出て「手で足した」の印。探せる範囲は L3 と同じ（SES・担当者は自社の人を名前・愛称・イニシャルで、他社の人はイニシャルで。結果も L3 の出し方） |
| L5 | すでにこの案件の案内を送った人は、チェックできず「送信済み」と出る |
| L6 | 合格者が0人なら「条件に合うエンジニアがいません。条件を外すか、下の［一覧に足す］で足してください」 |
| L7 | 一覧のチェック・全員へのメッセージは、詳細画面へ行って戻っても消えない |
| **段階3** | **詳細画面と送信** |
| P1 | 詳細画面：必須・尚可の条件ごとに ○／×（技術は年数も）、エンジニアへの個別メッセージの入力欄。保存すると一覧へ戻る |
| P2 | 必須に×がある人には、「[×の項目]に関しては○○様とご検討ください」を表示する（○○様は D7） |
| P3 | 詳細画面に、この人に送るメールの見本（件名・本文）が出る |
| P4 | 他社の人の詳細は、SES・担当者に名前・連絡先・単価を出さない（今の案内の候補の表に出ている範囲だけ）。自社の人は `/engineers/{id}` へのリンクも出す |
| M1 | ［エンジニアへメールを送信］で、チェックした人に送る（送信済みの人は飛ばす）。押すと「n名に送ります」の確かめが出る |
| M2 | 本文は雛形（D9）に、全員へのメッセージ・個別メッセージ・P2 の一文を入れたもの。空の欄は行ごと詰める |
| M3 | 送った記録・返事のリンク・案件の画面への表示・他社の SES への知らせは今の案内と同じ（`CaseMail::send` を通す。`case_mails.mode='search'`） |
| M4 | 同じ人に2回送らない（2回押し・2つの画面からでも） |
| M5 | 送ったあと案件の画面へ戻り「n名に案内メールを送りました」。「案内メール」の欄に出る（送り方は「エンジニア検索」） |
| M6 | メールが「保存のみ」の設定（`N::canSend()` が偽）なら、一覧に今の preview と同じ注意を出す |
| **共通** | **権限・境界** |
| K1 | 使えるのは `CaseMail::canSend` の人だけ。EG は 403（ルートの `role:admin,ses`）。グループのほかの会社の SES・担当者は 403 |
| K2 | 検索は、作った人と同じ会社の SES・担当者と管理者が開ける（管理者が作った検索は管理者だけ）。それ以外は 403 |
| K3 | 案件が募集中（`open`）でなければ、検索・送信とも「募集中の案件だけ案内できます」で案件の画面へ戻す |
| K4 | 他人の検索のメンバー・ほかの案件の検索のメンバーの id を送っても、何も変わらない（404・403 か無視） |
| **段階4** | **まわり** |
| V1 | 版を v0.7.0 にする。README・マニュアル（`resources/manuals` の運営・SES・エンジニア）を直す |

## 2. 決めたこと（Leo の仮の推奨をもとに planner が仮置き。違えば7章で直す）

| 番号 | 決めたこと | 理由 |
|---|---|---|
| D1 | 新しい流れの名前は「エンジニア検索」。サービスは `app/Services/CaseSearch.php`、画面は `app/Http/Controllers/CaseSearchController.php`、画面のファイルは `resources/views/cases/search*.blade.php` | 今の作りに合わせる |
| D2 | 表を2つ足す（3-1）。検索は **案件×作った人で1つ**（`unique(case_id, created_by)`）。もう一度検索すると同じ行を書き直す | E7・L7 を DB で守る。行が増えすぎない |
| D3 | 条件は `case_searches.conditions`（JSON）に `{"req": [...], "pref": [...]}`。1項目は `{"name", "kind": "skill"|"exp", "src": "case"|"added", "on": true|false}`。案件の列からの項目は検索画面を開くたびに案件の今の値から作り直し、`added` の項目と `on=false` の印だけを前の検索から引き継ぐ | 案件を直したあとも、条件が古くならない |
| D4 | 当たりの判定は項目ごと：技術は `Matcher::hasSkill`（経歴だけで当たっても○）、業務は `expMany` の項目名。`CaseSearch::judge($items, $idx, $exp): array`（項目ごとの `hit`・`months` と、`h`・`n`・`k`・`m`・`monthsSum`） | `Matcher::score` は○×を返さない。点数の仕組みは変えない |
| D5 | 年数（月）：当たった技術の索引の `months`（あれば）と `rank*12`（rank が0以上のとき）の大きいほう。無ければ0。業務は0。並びの「経験年数の合計」はこの月数の合計。画面には「約 x 年」 | 月数は経歴から、rank はスキルのチェックの年数の下限から。どちらかしか無い人もいる |
| D6 | 一覧のメンバーは `case_search_members` に入れる（検索した時点の合格者＋手で足した人）。再検索では、手で足した人と個別メッセージがある人を残し、ほかは作り直す（チェックは合格者に入れ直す。送信済みは外す）。h/n などは表示のたびに計算する（`skillIndexMany`・`expMany` でまとめて読む） | 条件を変えたら結果も変わるのが自然。書いたメッセージは消さない |
| D7 | P2 の ○○様：**自社の人には、送る人の氏名＋様**（ログインしている人。管理者も同じ）、**他社の人には「ご所属の会社のご担当者様」**（会社の担当者の名前は出さない）。この一文は詳細画面に出し、メールの個別メッセージの後ろにも入れる。詳細画面の「この一文を入れない」チェックで外せる（`case_search_members.omit_note`） | 「エンジニアが送り手（自社なら送る人、他社なら所属の SES）と相談して決める」と読むのが自然。他社の担当者の名前を SES に見せない（7章 Q1） |
| D8 | 送る前の確かめは、ブラウザの確かめ（「n名に送ります。よろしいですか」）だけにし、別の確認画面は挟まない。一人ずつの本文の見本は詳細画面（P3）で見られる | TEZZO の「押下で送信」に合わせつつ、押し間違いを防ぐ（7章 Q2） |
| D9 | メールの件名・本文は固定の雛形（件名は `CaseMail::defaultSubject`、本文は `CaseSearch::defaultBody()`）。今の `CaseMail::defaultBody` に次を足した形：あいさつの行の後に `{全員へのメッセージ}`、`■必須` の後に `■尚可：{尚可}`、`■あなたに合う点` の後に空行と `{個別メッセージ}`・`{検討のお願い}`。`{必須}`・`{尚可}` は検索の条件（`on` の項目）、`{合う点}` は当たった必須・尚可（年数つき）。一覧で本文の雛形は直せない | TEZZO の画面にある入力は2つ（全員・個別）だけ。本文まで直したいときは古い流れが残っている（7章 Q3） |
| D10 | 送信は `CaseMail::send($u, $c, $items, 'search')` を通す（件名・本文はこちらで組み立てて渡す）。点数・`case_offers` は今どおり案件そのもので出す。`case_mails.mode` に `search` を足すだけで表は変えない | 記録・返事・知らせを1か所に保つ。EG の案件画面の相性と食い違わない |
| D11 | 送るときに、チェックの人を「今この人が送ってよい人か」でもう一度しぼる（J2・L3 の範囲・送信済み）。管理者が送るときの route は今どおり全員 own（自社・他社は並びと名前の出し方だけに使う） | 開いている間に状態が変わっても安全。今の管理者の送り方を変えない |
| D12 | 「手で足す」の探し方は、一覧画面の `GET ?q=`（サーバで探して下に結果を出す。JavaScript なしで動く）。30件まで | 外部のライブラリ・API を足さない |
| D13 | 一覧の「詳細」は、リンクでなく保存ボタン（`name="go" value="{engineer_id}"`）にして、チェックとメッセージを保存してから詳細へ移る。「全員選択」は小さな JavaScript（画面の中に書く） | L7。今の画面の作り（Blade＋少しの JS）に合わせる |
| D14 | 版は **v0.7.0**（表の追加と新しい機能） | |

## 3. 仕様の詳細

### 3-1. 表（マイグレーション `2026_11_13_000001_case_searches.php`）

- `case_searches`：`id`、`case_id`（cases・cascade）、`created_by`（users の id）、`ses_id`（作った人の会社。管理者は null）、`conditions`（text。JSON）、`message_all`（text・null）、`last_sent_at`（null）、`created_at`・`updated_at`。`unique(case_id, created_by)`。
- `case_search_members`：`id`、`search_id`（case_searches・cascade）、`engineer_id`（engineers・cascade）、`checked`（bool）、`by_hand`（bool）、`message`（text・null）、`omit_note`（bool、既定 false）、`created_at`・`updated_at`。`unique(search_id, engineer_id)`。
- `down` は2つの表を消す。

### 3-2. `CaseSearch`（段階1・3）

- `canUse($u, $c)`＝`CaseMail::canSend`。`canOpen($u, $s)`：管理者は全部、SES は `$s->ses_id === $u->ses_id`。
- `conditions(object $c, ?object $s): array`（D3）。技術は `parse_list($c->required_skills)` を `find` で正式名に、業務は `CaseExp::split`。
- `addItems(array $cond, string $side, string $text): array`（E4）：`parse_list` で分け、業務の項目名なら `exp`、ほかは `find` の正式名か、そのまま（`ensure` は呼ばない）。`norm_skill` が同じものは足さない。1つ 60 字まで・1回 30 個まで。
- `pool($u, $c): array`：J2 の人に `own`（J4）・`sent` を付けたもの（`CaseMail::candidates` と同じ材料。管理者の own だけ J4 にする）。
- `judge(...)`（D4・D5）、`passes(int $h, int $n): bool`（J1）、`sortRows(array $rows)`（J3）。
- `run($u, $c, array $cond): int`：検索を保存し、メンバーを作り直す（D6）。検索の id を返す。
- `rows($u, $s): array`：一覧に出す行（メンバー＋判定＋表示名（L3）＋ `sent`）。
- `findEngineers($u, $c, string $q): array`（L4・D12）。
- `noteFor($u, object $row): ?string`（P2・D7）：×の必須を「、」でつなぎ「{項目}に関しては{○○様}とご検討ください」。
- `body($u, $c, $s, object $row): array`（件名・本文。D9。空の差し込みは行ごと消し、続く空行を1つに詰める）。
- `send($u, $c, $s, array $ids): int`（D10・D11）。送ったら `last_sent_at`、`audit('case-search-send', "{案件名} → n名")`。

### 3-3. 画面とルート（`role:admin,ses` のグループに足す）

| ルート | 動き |
|---|---|
| `GET /cases/{id}/search` | 検索画面（E2〜E5）。左右2段（`cols wideside` に合わせる）。左に元のメール（`<pre>` で全文、改行そのまま）。右に必須・尚可の表（項目・「メールから／足した」・外すチェック）と、それぞれの「足す」欄、［エンジニアを検索］ |
| `POST /cases/{id}/search` | 条件を保存して `run` → `/searches/{sid}` へ |
| `GET /searches/{sid}` | 一覧（L1〜L6・M6）。`?q=` があれば下に「一覧に足す」の結果 |
| `POST /searches/{sid}` | チェック・全員へのメッセージを保存。`go` があればその人の詳細へ、なければ一覧へ |
| `POST /searches/{sid}/add` | `engineer_id` を `by_hand=1`・チェックありで足す（`findEngineers` の範囲だけ） |
| `GET /searches/{sid}/members/{eid}` | 詳細（P1〜P4） |
| `POST /searches/{sid}/members/{eid}` | 個別メッセージ・`omit_note` を保存 → 一覧へ |
| `POST /searches/{sid}/send` | チェック・全員へのメッセージを保存してから送る（M1〜M5） |

- 一覧の行の右に「手で足した」「送信済み」の印。検索画面へ戻る［条件を変える］。
- 詳細の表：条件・○×・年数（「約 x 年」／経歴だけで当たったら「経歴から」）。下に P2 の一文と「この一文を入れない」、個別メッセージ、メールの見本、［保存して一覧に戻る］。
- 案件の画面（`cases/show.blade.php`）の3つのボタンを `/cases/{id}/search` に変える。「案内メール」の表の送り方の文を、`search` なら「エンジニア検索」に。

## 4. プレーヤーごとに変わること

| プレーヤー | 変わること |
|---|---|
| 管理者 | 全案件で［エンジニアに案内する］が新しい検索になる。直属の人が上、ほかの会社の人が下（名前は見える）。送ったメールの送り主・返信先は今と同じ |
| SES | 自社が持ち込んだ案件で同じことができる。自社の人が上（名前）、グループのほかの会社の人が下（イニシャル（会社名））。他社の人には PARK から届き、その人の SES に知らせが届くのは今と同じ |
| 担当者 | SES と同じ（`role=ses`）。同じ会社の人が作った検索も開ける（K2）。検索は人ごとに1つなので、2人が同じ案件で別々に検索できる。同じエンジニアに2人から送られることは無い（M4） |
| EG | 画面は見えない（403）。届くメールに、全員へのメッセージ・個別メッセージ・「…に関しては○○様とご検討ください」が入ることがある。返事のリンク・PARK の画面での応募は今と同じ |

- 境界：条件を全部外した（必須0）→ 全員合格（J1）。尚可0 → 表示は「尚可 0/0」。チェック0人で送信 → 「送る相手を選んでください」。送信済みだけ → 「送る相手がいません」。案件を募集終了にしてから送信 → K3。期限切れ・取り消しは無い（検索の行は消さない）。

## 5. 作業の手順（implementer）

### 段階1：表と判定

1. マイグレーション（3-1）。`php artisan migrate`（手元）。
2. `app/Services/CaseSearch.php`：`canUse`・`canOpen`・`conditions`・`addItems`・`pool`・`judge`・`passes`・`sortRows`・`run`・`rows`（3-2）。`CaseMail::candidates` は変えず、要るなら材料を読む private 関数を `CaseSearch` に書く。
3. `tests/Feature/V070SearchTest.php` の T1〜T4 を書き、`php artisan test`。

### 段階2：検索画面と一覧画面

1. `CaseSearchController`（3-3 の上5つ）とルート。読み込みの共通関数で K1〜K4（案件が無ければ 404、`canUse` でなければ 403、`open` でなければ戻す、検索は `canOpen` と `case_id` の一致）。
2. `cases/search.blade.php`（検索画面）・`cases/search-list.blade.php`（一覧）。全員選択の JS、詳細へは保存ボタン（D13）、`?q=` の結果と［一覧に足す］。
3. `cases/show.blade.php` の3つのボタンの行き先を変える（E1）。
4. T5〜T13 を書き、`php artisan test`。

### 段階3：詳細画面と送信

1. `findEngineers`・`noteFor`・`body`・`send`（3-2）。`CaseMail::send` の `$mode` に `search` を受ける（中は今のまま）。`show.blade.php` の送り方の文。
2. 詳細の2つのルートと `cases/search-member.blade.php`。送信のルート。一覧の［エンジニアへメールを送信］に確かめ（D8）。
3. T14〜T22 を書き、`php artisan test`。

### 段階4：まわり

1. `config/park.php` を `v0.7.0`。版を見ているテスト（0章の7つ）を直す。
2. README：「案件の案内」の節に、エンジニア検索（条件の足し方、合格の決まり J1、並び J3、自社・他社 J4、メールの組み立て D9、古い流れも残ること）と表2つ。
3. マニュアル（`resources/manuals`）：
   - `admin.md`・`admin-steps.md`：［エンジニアに案内する］の手順を、検索画面 → 一覧 → 詳細 → 送信に書き直す（条件を足す・外す、合格の決まり、手で足す、個別メッセージ、ご検討の一文）。
   - `ses.md`・`ses-steps.md`：同じ内容を SES・担当者向けに（自社が持ち込んだ案件だけ。他社の人はイニシャル。同じ会社の人の検索も開ける）。
   - `engineer.md`・`engineer-steps.md`：案内メールに、メッセージと「…に関しては○○様とご検討ください」が入ることがある、と1〜2文。
   - 04_Manuals の写しは Leo（または park-manager）が push のあとに写し直す。implementer は park の中だけ直す。
4. `php artisan test` が全部通ることを確かめる（zip の既知の1件を除く）。

## 6. テスト（`tests/Feature/V070SearchTest.php`）

本文・名前はテストの中で作る**架空のものだけ**。立場は今のやり方（管理者・SES・同じ会社の担当者・他社の SES・EG のユーザーを作ってログイン）。エンジニアは、スキルのチェック（年数つき）・経歴・業務のチェックで作る。メールは `mail_outbox` の行で確かめる。

| 番号 | 条件 | 手順 | 確かめること |
|---|---|---|---|
| T1 | J1 | `CaseSearch::passes` を n=0〜8 で | 0→常に真、1→1、2→2、3→3、4→3、5→4、8→6 で境目が合う |
| T2 | J2・J4 | 自社・他社・直属・`unavailable`・応募済み・停止中の SES の人を作り、SES と管理者で `pool` | 対象の人だけ。SES は自社が own、管理者は直属が own |
| T3 | J3・D5 | 年数（rank 3 と経歴 48 か月など）の違う合格者で `run` → `rows` | 自社が先。各グループで月数の合計の多い順。同じなら h、次に点数 |
| T4 | D4 | 経歴だけで当たる技術／業務のチェック | どちらも○ |
| T5 | E1 | 管理者・SES で `GET /cases/{id}` | 3つのボタンが `/cases/{id}/search` を指す。`GET /cases/{id}/mail` も 200 |
| T6 | E2・E3 | `raw_text` ありの案件／無い案件で `GET /cases/{id}/search` | 全文が出る／作業内容と「元のメールはありません」。必須・尚可（技術と業務）が出る |
| T7 | E4・E6 | 必須に「架空の技術名、マスタにある技術の別名、業務の項目名」を足して `POST /cases/{id}/search` | 条件の JSON：別名は正式名、無い言葉はそのまま、業務は `exp`。`cases` の4列と `skill_terms` の数が変わらない |
| T8 | E3・E5 | 必須を1つ外して検索 | 外した項目で判定されない（n が1減る） |
| T9 | E7・D3 | 同じ人でもう一度 `GET /cases/{id}/search` | 足した項目・外したチェックが残る。`case_searches` は1行 |
| T10 | L1・L2・L6 | 合格者2人・不合格1人で一覧 | 合格者だけ・チェックあり・「必須 h/n 尚可 k/m」・全員へのメッセージの欄・送信ボタン。合格0の案件では L6 の文 |
| T11 | L3・K3 | SES で他社の人を含む一覧／案件を募集終了にして開く | 他社の人は名前が出ずイニシャル（会社名）／案件の画面へ戻り「募集中の案件だけ」 |
| T12 | L4 | SES が `?q=` に他社の人の氏名 → 0件、イニシャル → 出る。不合格の自社の人を `add` | 足した人が「手で足した」で出る。他社の人の名前は応答に出ない |
| T13 | L5・L7 | 案内済みの人がいる案件／チェックを外しメッセージを書いて `go` で詳細へ → 一覧へ戻る | 「送信済み」でチェックなし／外したチェックと全員へのメッセージが残る |
| T14 | P1・P2・D7 | 必須に×がある自社の人・他社の人の詳細 | ○×と年数。自社は「…に関しては{送る人の氏名}様とご検討ください」、他社は「ご所属の会社のご担当者様」。×が無い人には出ない |
| T15 | P3・P4 | SES で他社の人の詳細 | メールの見本が出る。氏名・メール・単価が出ない。自社の人には `/engineers/{id}` のリンク |
| T16 | M1・M2・D9 | 全員へのメッセージ・個別メッセージありで2人に送信 | `mail_outbox` 2通。本文に両方のメッセージ・検索の条件の必須・尚可・ご検討の一文（×の人だけ）。空の差し込みの行が残らない |
| T17 | D7 | `omit_note` を入れて送る | ご検討の一文が入らない |
| T18 | M3・D10 | T16 のあと | `case_mails` 2行（`mode=search`・route・トークン）、`case_offers` 2行（点数は `Matcher::score` と同じ）、他社の人の SES に知らせ、`audit_log` に `case-search-send` |
| T19 | M4 | 同じ送信をもう一度／同じ会社の担当者の検索から同じ人に送る | 増えない。「送る相手がいません」 |
| T20 | M5・M6 | 送信のあと案件の画面／`mail.default=log` で一覧 | 「n名に案内メールを送りました」と「エンジニア検索」／「保存のみ」の注意 |
| T21 | K1・K2 | EG・他社の SES で `GET /cases/{id}/search`・`GET /searches/{sid}`・送信。同じ会社の担当者で `GET /searches/{sid}` | 403／担当者は 200 |
| T22 | K4 | 別の案件の検索の `sid` に、この案件のメンバーの `eid` で詳細・保存／範囲外の `engineer_id` を `add` | 404 か 403。行が増えない |
| T23 | V1 | `/login` | `v0.7.0` |
| T24 | 既存 | 既存のテスト一式（`/cases/{id}/mail` の流れを含む） | 版の文字のほかは変えずに通る |

- 画面の見た目（左右の配置・全員選択・確かめのダイアログ）は reviewer がコードを読み、Leo が手元の画面で確かめる。

## 7. 確認したいこと（Leo が決める。答えが来るまで推奨で作る）

- **Q1 「○○様」はだれか**：TEZZO の文は「詳細画面に表示する」。Leo の仮の推奨は「メールに、その人の呼び名＋様」だったが、エンジニア本人あてのメールで「ご自分とご検討ください」となり意味が通らない。**推奨：D7（自社＝送る人の氏名＋様、他社＝「ご所属の会社のご担当者様」。詳細画面に出し、メールにも入れる。外すこともできる）**。別の読み（送る人への注意で ○○＝エンジニアの名前、画面だけに出す）なら、`noteFor` の宛名と、`body` に入れるかどうかの2か所を直すだけ。
- **Q2 送る前の確認画面**：**推奨：D8（ブラウザの確かめだけ。一人ずつの見本は詳細画面）**。TEZZO の「押下で送信」に合わせる。別の確認画面を挟むなら、今の `mail-preview` と同じ形を足す。
- **Q3 本文の雛形を直せるか**：**推奨：直せない（D9）**。一覧は TEZZO の配置のとおり2つの入力だけにする。雛形ごと変えたいときは古い流れ（URL を直接開く）を使える。要るなら次の版で、一覧の下に「件名・本文の雛形」の折りたたみを足す。
- **Q4 年数の数え方**：**推奨：D5（経歴の月数とチェックの年数の下限の大きいほう）**。チェックの年数は「3年以上」などの下限なので少なめに出る。
- **Q5 不合格の人の見せ方**：一覧に出さず、手で足せるだけにする。**推奨：一覧の上に「条件に届かなかった人：n名」だけを出す**（だれかは出さない）。足したい人は［一覧に足す］で。
- **Q6 足した条件を案件に戻すか**：**推奨：戻さない（E6）**。案件を直すのは今の編集画面。AI の自動差し替えの「人が直していない」の判断にも触れない。

## 8. 了承が要ること

- 表を2つ足す（マイグレーション）：公開版への反映（`park-deploy.sh` が migrate する）は常時了承の範囲。データの削除・`.env` の変更は無い。
- **メールの送信**：この機能は、使う人（管理者・SES）が押したときにエンジニアへメールを送る。実装・テストでは `mail_outbox` に入るだけ（テストは送らない）。**公開版で実際のエンジニアに試しに送ることは、TEZZO の了承を取ってから**（試すなら見本のデータの人あてで）。
- 古い `/cases/{id}/mail` の流れを消すかどうかは、今回は決めない（消すときは TEZZO の了承）。

---

> 以下は前の計画（v0.6.0。2026-11-12）。実装・公開版への反映は済み。消さずに残す。

# PLAN：案件メールの必須・尚可の抜き出しに、手元の AI を組み込む（v0.6.0。2026-11-12）

> **これが今の計画**。下の「v0.5.0」「v0.4.1」の節は前の計画（実装・公開版への反映は済み）で、消さずに残している。
> 依頼：Leo（2026-11-12）。調査の記録は 41_Park-Project の `40_動作確認/41_マッチング精度`（`2026-11-12-03-v3と2回目のホールドアウト`・`2026-11-12-05-手元のAIでの抜き出し`）。規則は見ていないメールで 6 割前後、手元の AI（Ollama の qwen2.5:7b）は 94〜100%。ただし AI は 1 件 1 分ほどかかり、元の文を 1% ほど書き換える。
> 作る順：段階1 → 段階2 → 段階3 → 段階4（段階ごとに commit。`php artisan test` を通してから次へ）→ park-reviewer → park-tester。commit・push・公開版への反映は Leo。
> プレーヤー：管理者・SES・担当者・EG の4つ（担当者は今のアプリでは `role=ses`）。**案件を誰が直せ・誰が見られるかは今のまま**（`Perms::caseEdit`・`caseView`）。**EG には AI の途中経過・結果の比べ表を一切見せない**。
> 外部のライブラリは足さない。**メール本文・取引先の名前はこのファイルにもテストにも書かない**（テストの本文は架空の文を作る）。本文は Xserver（PARK）と手元の PC（FOCUS の worker）の間だけでやり取りし、ほかのサービスには渡さない（今の経歴書の AI と同じ道）。

## 0. コードで確かめた今の形

- **取り込み**：`CaseController::import` → `CaseParser::parse($raw)` → 案件票の画面（`cases.form`）に入れて見せる（まだ DB に無い）。人が直して `POST /cases`（`store` → `Workflow::saveCase`）で初めて案件ができる。`raw_text` は `CASE_FIELDS` に入っていて保存される。
- `CaseParser::parse`：`LABELS` で見出しの行を探し、次の見出しか空行までの行を `$buckets` に集める。必須・尚可は `required_skills`・`preferred_skills` に「、」でつなぐ。そのあと `CaseExp::fromLines`（v0.5.0 の業務の拾い出し）、`SkillCatalog::detect` で本文の技術を拾い、必須に無いものを尚可に足す（必須が空なら、拾った技術を必須にする）。
- `saveCase`：必須・尚可を `parse_list`（`、` `,` `/` `・` 改行などで分ける）で分け、1つずつ `SkillCatalog::ensure`（40字以下でマスタに無い言葉を確認待ちで足す）と `find`（正式な名前にそろえる）を通す。足したら管理者に知らせる（メールなし）。業務は `_exp` があるときだけ画面の選択から、新規で `_exp` が無ければ送られた文から。
- **AI の仕事の箱**（`app/Services/AiJobs.php`・表 `ai_jobs`：kind・ref_type・ref_id・input（JSON）・status・output・engine・error・created_by・created_at・picked_at・done_at）。種類は `script`（面談スクリプト）・`resume`（経歴書）。状態は queued → running → done／failed →（人が）applied／dropped。30 分たっても返らない running は queued に戻る。
- worker（`tools/ai-worker/park_ai_worker.py`、FOCUS で常駐）：`POST /ai/worker/next`（合言葉 `X-Park-Ai-Token`＝`.env` の `PARK_AI_TOKEN`）で `{id, kind, prompt}` を受け、Ollama の chat に **user の1通**・`format: json`・`temperature 0.2`・`num_ctx 8192` で渡し、`POST /ai/worker/{id}/result` で返す。調査（`lab_ai.php`）は **system に指示・user に本文・temperature 0** で測った。
- `AiJobs::finish`：`items()` で形を確かめて done／failed。done なら作った人に知らせる（メールなし。リンクは ref_type が interview なら面談、ほかは EG の書類）。`AiController::job` の権限も interview とそれ以外（EG）の2通りだけ。
- AI の仕事を見ている所：`admin/ai.blade.php`（リンクは interview か EG）、`Todo`（done の数）、`park:status`、`SamplePurge`（interview・engineer_file の仕事だけ消す）。
- 権限：`Perms::caseEdit` ＝管理者、または案件の `source_ses_id` と同じ会社の `role=ses`（SES・担当者）。EG は `caseView`（案内か応募があるときだけ）で見るだけ。
- テスト：`tests/Feature/ParkTest.php` の `test_ai_job_box` が worker の出入りを `config(['services.park_ai.token' => 'test-token'])` とヘッダーで試している。版は `config/park.php`（今 `v0.5.0`）で、`RegistrationFoundationTest`・`SkillQuestionsTest`・`V030RegressionTest`・`V050CatalogTest` が版の文字を見ている。

## 1. 受け入れ条件

| 番号 | 受け入れ条件 |
|---|---|
| **段階1** | **規則を強くする（v3 の読み方）** |
| R1 | 取り込みの画面は、今までどおり押してすぐ規則の結果を出す（AI を待たない） |
| R2 | 次の書き方の必須・尚可を読める：`<<必須>>`・`<必須>`・`⇒必須`・`スキル(必須)：`・`【必須】`・`■必須スキル■`・`◆必須`、見出しに「必須」の無い `◆スキル：`・`■スキル■`・`【スキル】`・`スキル要件：`（中身は必須）、`MUST`・`WANT`・`歓迎` |
| R3 | 箇条書きの印 `・` `-` `*` `●` `○` `①`〜`⑳` と、`(1)`・`（1）`・`1)` の番号を、項目の頭として読み、印は外す |
| R4 | 印の無い行：印の無い節では1行＝1項目。箇条書きの節では、印の無い行は前の項目の続き |
| R5 | 見出しと同じ行に本文がある（`■必　須：Java の開発経験`）ときは、見出しの文字を消して1項目にする。見出しの後ろが `(`・`（`・`※` で始まる注意書きなら項目にしない |
| R6 | 区切り線（`***`・`===`・`---`・`━━━` など3文字以上）、次の見出し（`■` `【` `◆` `<<` `▼` で始まる行、`4．` のような番号の見出し、`単価：` のような8字以内の項目名＋コロン）、空行のあとの印の無い行で、節を終える |
| R7 | 見出しのすぐ下の小見出し（`【テクニカルスキル】` のような15字以内の `【…】` だけの行）は読み飛ばす |
| R8 | 最初の箇条書きより深く下げた `-` の行は子項目として前の項目に付ける。`※` で始まる行も前の項目に付ける |
| R9 | 段階1の読み方で必須・尚可がどちらも空のときは、今の読み方（`LABELS` の `$buckets`）の結果を使う（今読めているメールを落とさない） |
| R10 | 必須・尚可より後の処理（業務の拾い出し・本文の技術の追加・必須が空なら技術を必須に）は今と同じ |
| **段階2** | **AI の仕事の箱に新しい種類** |
| A1 | 種類 `case_skills`「案件の必須・尚可の読み直し」を足す。`ref_type=case`・`ref_id=案件の id` |
| A2 | 本文（`raw_text`）のある案件を登録したとき、自動で仕事を1つ入れる。本文を変えて保存したときも入れ直す（前の、まだ終わっていない・選ばれていない仕事は `dropped` にする） |
| A3 | 案件の画面に「AI に読み直してもらう」のボタン（直せる人だけ）。順番待ち・作業中の仕事があるときは、押しても2つ目を入れない |
| A4 | 合言葉（`PARK_AI_TOKEN`）が無い、本文が空、本文が長すぎる（7章 Q1）ときは仕事を入れない。登録・保存は今までどおり成功する |
| A5 | worker は、仕事に `system`・`user`・`options` があれば、system と user の2通・その温度で Ollama に渡す。無い仕事（今の2種類）は今までどおり。古い worker（直す前）でも `prompt` だけで動く |
| A6 | AI の答えが `{"required": [...], "preferred": [...]}` の形でなければ failed（理由つき）。規則の結果はそのまま残る |
| **段階3** | **本文との突き合わせと差し替え** |
| S1 | AI の各項目を本文と突き合わせる。本文にそのままあれば「そのまま」、近い行があればその行の文に戻して「本文の文に戻した」、近い行も無ければ AI の文のまま「本文に無い言い方」の印を付ける |
| S2 | 「同じ」＝必須・尚可の行数が規則とどちらも同じで、「本文に無い言い方」が0件。このとき、次の全部を満たせば **AI の結果で自動で差し替える**：人が直していない（登録の前にも後にも）／案内・案内メール・応募がまだ無い／案件が募集中か下書き／本文が仕事を入れたときと同じ |
| S3 | 差し替えるのは `required_skills`・`preferred_skills`・`required_exp`・`preferred_exp` の4つだけ。中身は、AI の行（S1 で戻したもの）を規則の結果と同じ後処理（R10）と `saveCase` と同じそろえ方（`ensure`・`find`）に通したもの。ほかの列は変えない |
| S4 | 「同じ」でない、または S2 の条件を満たさないときは、勝手に変えない。案件の画面で、直せる人（管理者・その案件の SES と担当者）に違いを見せ、「AI の結果を使う」「今のままにする」を選んでもらう |
| S5 | AI の行が規則の行とまったく同じ（並びは問わない）で、自動の条件を満たさないときは、選ぶ必要が無いので仕事を閉じる（`dropped`）。画面には「AI も同じ読み方でした」とだけ出す |
| S6 | 選ぶ必要がある結果が出たとき、仕事を入れた人に知らせる（画面の知らせだけ。メールは送らない） |
| S7 | 「AI の結果を使う」は、本文が仕事を入れたときと同じときだけ使える。違えば「本文が変わったので使えません。もう一度頼んでください」 |
| S8 | AI が動いていない・失敗した・捨てた案件は、規則の結果のまま、今と同じに使える（点数・案内・配信） |
| **段階4** | **画面・表示・権限** |
| G1 | 案件の詳細に「AI の読み直し」の欄（`#ai`）。見えるのは直せる人だけ。状態ごとの文（3-6）。EG・ほかの会社の SES には欄ごと出ない |
| G2 | 違いの表：今の必須・尚可と AI の必須・尚可を並べ、「AI だけ」「今だけ」「本文の文に戻した」「本文に無い言い方」の印を付ける。AI の結果を使ったときの業務（必須の業務・尚可の業務）も見せる。選んだあとは表を出さない |
| G3 | 管理者の「AI の仕事」一覧で、新しい種類の名前が出て、案件の画面（`/cases/{id}#ai`）へ行ける |
| G4 | 取り込み・登録の知らせに、AI が読み直すこと（合言葉があるときだけ）を1文足す |
| G5 | 権限：仕事を頼む・使う・捨てるのは `caseEdit` の人だけ（他社の SES・EG は 403）。worker の出入りは今の合言葉のまま |
| G6 | 見本データの削除（`SamplePurge`）で、見本の案件の `case_skills` の仕事も消える |
| V1 | 版を v0.6.0 にする。README・マニュアル（`resources/manuals`。04_Manuals の写しは Leo）を直す。worker の説明（ファイル先頭の文）も直す |

## 2. 決めたこと（Leo の方針をもとに planner が仮置き。違えば7章で直す）

| 番号 | 決めたこと | 理由 |
|---|---|---|
| D1 | AI の仕事は **案件を登録したとき**（`store` の後）に入れる。取り込みの画面（まだ案件が無い）では入れない | 仕事は案件に結び付ける（ref_id）。取り込んでも登録しない人の分を AI に回さない |
| D2 | 規則の読み方は `CaseParser::sections(string $text): array`（`['required' => [...], 'preferred' => [...]]`）に置き、`parse` はそれを使う。後処理は `CaseParser::finishSkills(array $req, array $pref, string $text): array`（`required_skills`・`preferred_skills`・`required_exp`・`preferred_exp`・`detected`）に分け、規則と AI の両方が使う | 正本1か所。AI の結果にも、本文の技術の追加・業務の拾い出しが同じように効く |
| D3 | Leo の一覧に加えて、2回目のホールドアウトで外れた形のうち小さいものを規則に入れる：尚可の見出しに `推奨`・`BETTER`、見出しの `/経験`・`／経験`（`■必須スキル/経験`）、必須の節の中で行に `（尚可）`・`(尚可)`・`（歓迎）` がある行は尚可へ移して印を消す | 規則でも安く読めるもの。見出しの無いメール・1通に複数の募集は AI に任せる（7章 Q2） |
| D4 | 業務のロジックは新しい `app/Services/CaseAi.php`（`queue`・`snapshot`・`ruleSnapshot`・`restore`・`compare`・`settle`・`apply`）。`AiJobs` には種類・`items`・`finish` の分岐だけ足す | `AiJobs` を太らせない。今の2種類の動きを変えない |
| D5 | 仕事の input（JSON）：`actor_role`（先頭）・`prompt`（指示＋本文。古い worker 用）・`system`（指示）・`user`（本文）・`options`（`{"temperature": 0}`）・`text_hash`（`sha1(raw_text)`）・`rule`（`sections()` の結果）・`base`（入れたときの案件の4列を `snapshot` したもの）・`edited`（登録の前に人が直したか）。**表の列は足さない（マイグレーション無し）** | 比べるのに要るものを全部仕事に持たせる。今の表で足りる |
| D6 | 「人が直した」の判断：`snapshot($case)`＝4列を正規化した形（必須・尚可は `parse_list` で分け、`find` があれば正式な名前、無ければそのまま、重なりを除いて並べ替え。業務は `CaseExp::split`）。`ruleSnapshot($raw)`＝`CaseParser::parse($raw)` の4列を同じ形にしたもの（`ensure` は呼ばない）。登録の直後に `snapshot` と `ruleSnapshot` が違えば `edited=true`。結果が届いたとき、今の `snapshot` が `base` と違えば「登録の後に直した」 | 隠し欄を足さずに、登録の前の手直しも見分けられる |
| D7 | 本文との突き合わせ（S1）：正規化 `N(s)`＝全角英数・カナを半角／全角にそろえ（`mb_convert_kana($s, 'asKV')`）、空白・改行・箇条書きの印・`、。,.：:` を消し、英字を小文字に。`N(項目)` が `N(本文全体)` に含まれれば「そのまま」（※の補足を前の行につないだ項目も、改行を消すので含まれる）。含まれなければ、本文の各行と「隣り合う2行をつないだもの」から、2文字ずつの組（bigram）の Dice 係数が一番高いものを選び、**0.5 以上**ならその行（印を外したもの）に戻して「本文の文に戻した」（AI の文は `from` に残す）、未満なら「本文に無い言い方」 | 外部のライブラリなしで書ける。調査の書き換え（誤字1字・言い換え）は戻せる見込み。閾値は7章 Q3 |
| D8 | 自動で差し替えない条件（S2）の「案内・応募がまだ無い」は、`case_offers`・`case_mails`・`applications` にその案件の行が1つも無いこと | EG に届いた後で、知らないうちに必須が変わるのを防ぐ |
| D9 | 状態の使い方：自動で差し替えた＝`applied`、選ぶ必要あり＝`done` のまま、同じ読み方で閉じた・本文が変わった・新しい仕事に置き換わった・「今のまま」を選んだ＝`dropped`。どれになったかは `audit_log`（`ai-case-auto`・`ai-case-same`・`ai-case-stale`・`ai-case-apply`・`ai-case-keep`）に残す | 列を足さずに、画面と記録で区別できる |
| D10 | AI に渡す指示（3-2）は調査の `lab_ai.php` の文をそのまま使う（言い換えない）。本文は加工しない | 測った条件のまま入れる |
| D11 | worker：`next` の答えに、input に `system`・`user`・`options` があれば足す。worker は `system` と `user` があれば2通で送り、`options` を今の既定（`temperature 0.2`・`num_ctx 8192`）の上に重ねる | 古い worker・今の2種類を壊さない。調査と同じ温度 0 で動かす |
| D12 | 知らせ（S6）は `N::notify(created_by, …, "/cases/{id}#ai", false, 'ai', actor_role)`。自動で差し替えたとき・同じで閉じたときは知らせない | 選ぶ必要があるときだけ。メールは増やさない |
| D13 | 「AI の結果を使う」で差し替えたあとも、点数・案内メールの文は今の仕組みで次に開いたとき・次に送るときから変わる。もう送った案内（`case_offers` の点数・理由）は書き換えない | 送った記録は変えない |
| D14 | 版は **v0.6.0**（新しい機能。表の変更は無い） | |

## 3. 仕様の詳細

### 3-1. 規則の読み方（段階1。`CaseParser::sections`）

調査の `sections2`（41_Park-Project の記録にある v3 の考え方）を PARK に移す。要点：

- 1行ずつ：前後の空白は `preg_replace('/^[\s　]+|[\s　]+$/u', '', …)` で消す（`trim()` は全角空白で文字を壊す）。空行は「すき間あり」の印だけ付けて次へ。
- 区切り線 `/^[\*＊=＝━─\-－_＿]{3,}/u` で節を終える。
- 見出しの判定は、半角にそろえて空白を詰めた文（60字以内）で：前の飾り `<<` `<` `■` `【` `▼` `[` `⇒` `◆` `(` `（`、任意の `スキル`・`スキル要件`＋括弧、本体 `必須|尚可|歓迎|推奨|MUST|WANT|BETTER`、任意の `スキル|要件|条件`、任意の `・経験`・`/経験`・`／経験`、後ろの飾りとコロン、残りの本文。`必須|MUST` なら必須、ほかは尚可。`◆スキル`・`■スキル■`・`【スキル】`・`スキル要件：`（本体の無い見出しで、後ろに本文が無い）は必須。
- 箇条書きの印：`/^(?:[・\-\*●○◯〇－]|[①-⑳]|[\(（]?\d+[\)）])\s*/u`（`４．単価` のような「数字＋．」は印にしない）。見出しの行が印で始まるときは見出しにしない。
- 見出しと同じ行の本文：見出しの文字（`必　須` のように間に空白があっても）までを消し、頭の印も消して1項目。本文が印で始まれば、その節は箇条書きの節。
- 節の中：小見出し（R7）を飛ばす → 深い `-` は前の項目に付ける（R8） → `※` は前の項目に付ける → すき間のあとの印の無い行は節の終わり → 次の見出し・項目名（R6）は節の終わり → 印のある行は1項目 → 印の無い行は、箇条書きの節なら前の項目の続き、そうでなければ1項目。
- D3：必須の節の行に `（尚可）`・`(尚可)`・`（歓迎）` があれば、その印を消して尚可へ。
- `parse` では、`sections()` の必須・尚可のどちらかが空でなければそれを使い、どちらも空なら `$buckets` の結果（R9）。そのあと `finishSkills`（R10。今の 50〜53行と 81〜91行の処理を移したもの）。

### 3-2. AI への指示（段階2。`CaseAi::PROMPT`。調査と同じ文）

```
次の文章は、IT エンジニアの案件の募集メールです。応募する人に求める条件を、「必須」と「尚可」に分けて抜き出してください。

決まり：
- 「必須」は、必ず要るもの（必須・MUST・スキル要件・必要スキルなど）。見出しが無くても、「必須」「読み書き必須」のように書いてあるものは必須。
- 「尚可」は、あればよいもの（尚可・歓迎・推奨・あればなお良い・Want など）。
- 1つの条件を1つの項目にする。元の文の言葉をそのまま使い、言い換えない。箇条書きの印（・ ① (1) など）は外す。
- ※の補足は、直前の項目に含める。1行に「・」で2つ書いてあれば2つに分ける。
- 求める人物像・人柄の節、単価・場所・期間・面談・国籍・年齢などの契約の条件は入れない。
- 該当が無ければ空の配列にする。

次の JSON だけを返してください。
{"required": ["…", "…"], "preferred": ["…"]}
```

- `system`＝上の文、`user`＝本文そのまま、`prompt`＝上の文＋空行＋「募集メール：」＋本文（古い worker 用）。`options`＝`{"temperature": 0}`。
- `items()`：`AiJobs::json` で読み、`required`・`preferred` のどちらかが配列であること。文字でない要素は捨て、前後の空白を消し、空は捨てる。1つ 300 字まで・それぞれ 60 項目まで（超えた分は捨てる）。両方とも配列でなければ null（failed）。

### 3-3. 仕事の流れ（段階2・3）

1. **入れる**（`CaseAi::queue(int $caseId, $actor): ?int`）：合言葉なし・本文が空・本文が長すぎる（Q1）なら null。同じ案件の `case_skills` で queued・running のものがあれば null（A3）。同じ案件の queued と done（選ばれていない）は `dropped` にする（A2）。input を D5 のとおり作って `AiJobs::queue`（private なので、`CaseAi` から呼べるよう `AiJobs::queueCase(…)` を足す）。
2. **呼ぶ所**：`CaseController::store`（`saveCase` の後。`edited` は D6）／`update`（保存の前後で `raw_text` が変わり、空でないとき）／新しい `POST /cases/{id}/ai-parse`（ボタン）。
3. **worker**：今のまま取りに来る（ほかの種類と同じ順番待ち）。
4. **届いたら**（`AiJobs::finish` → done のとき、kind が `case_skills` なら作った人への今の知らせは出さず `CaseAi::settle($id)` を呼ぶ）：
   - 案件が無い、または `sha1(raw_text)` が `text_hash` と違う → `dropped`（`ai-case-stale`）。
   - `restore`（D7）で AI の行を本文に戻す → `compare`：行数（必須・尚可）と「本文に無い言い方」の数、規則の行とまったく同じか（`N()` で比べ、並びは問わない）。
   - 自動の条件（S2・D8）を全部満たす → `apply` して `applied`（`ai-case-auto`）。
   - まったく同じで、自動の条件を満たさない → `dropped`（`ai-case-same`、S5）。
   - それ以外 → `done` のまま。知らせる（D12）。
5. **選ぶ**：`POST /ai-jobs/{id}/apply-case`（「AI の結果を使う」）→ 状態が done・本文が同じ（S7）なら `apply` して `applied`（`ai-case-apply`）。「今のままにする」は今の `POST /ai-jobs/{id}/drop`（`dropped`、`ai-case-keep`。戻り先は `/cases/{id}#ai`）。
6. **`apply($case, $restored, $actor)`**：`finishSkills(AI の必須の行, AI の尚可の行, raw_text)` → 必須・尚可は `saveCase` から分けた `Workflow::normalizeCaseSkills(array $v, string $title): array`（`ensure`・`find`・管理者への知らせ）に通す → 4列と `updated_at` だけ更新。`audit`。

### 3-4. 画面（段階4）

**案件の詳細**（`cases/show.blade.php`。必須・尚可の業務の行の後ろ、`id="ai"`、`Perms::caseEdit` の人だけ。最新の `case_skills` の仕事 `AiJobs::latest('case', $id, 'case_skills')` で出し分け）：

| 状態 | 出す文・ボタン |
|---|---|
| 仕事なし（本文あり） | 「手元の AI に、必須・尚可を読み直してもらえます。」［AI に読み直してもらう］（合言葉が無ければボタンを出さず「AI はまだつながっていません」） |
| queued・running | 「手元の AI が必須・尚可を読み直しています（1件 1分ほど。手元の PC が止まっていると進みません）。今は規則で読んだ結果です。待たずに案内してもかまいません（案内したあとは、AI の結果は自動では入れません）。」 |
| applied（自動） | 「AI で読み直し、規則と同じ数だったので AI の結果にしました（日時）。」＋「本文の文に戻した」が n 件あれば件数 |
| applied（人が選んだ） | 「AI の結果を使いました（日時）。」 |
| done | 見出し「AI の読み直しの結果を確かめてください」。理由（どれか）：「規則と行数が違います」「本文に無い言い方があります」「登録の前か後に人が直した案件です」「もう案内・応募があります」「募集を終えた案件です」。違いの表（G2）。［AI の結果を使う］［今のままにする］。人が直した案件なら「使うと、直した必須・尚可・業務も置き換わります」 |
| dropped（同じ） | 「AI も同じ読み方でした。」 |
| dropped（そのほか）・failed | failed は「AI で読めませんでした（理由）。規則の結果のまま使えます。」。どちらも［もう一度頼む］ |

**違いの表**：左「今」（`required_skills`・`preferred_skills` を「、」で分けたもの）、右「AI」（`restore` 後）。必須・尚可ごとに、`N()` で一致しない行に「今だけ」「AI だけ」。AI の行に「本文の文に戻した」（AI の文を `title` で見せる）・「本文に無い言い方」（目立つ色）。下に「AI の結果を使うと、必須の業務：…／尚可の業務：…」。

**取り込みの知らせ**（`import`）と **登録の知らせ**（`store`）：合言葉があり本文があるとき「登録すると、手元の AI が必須・尚可を読み直します（1件 1分ほど）。結果は案件の画面に出ます。」／「手元の AI が必須・尚可を読み直しています。」を足す。

**管理者の「AI の仕事」**（`admin/ai.blade.php`）：`ref_type=case` のリンクは `/cases/{ref_id}#ai`。

## 4. プレーヤーごとに変わること

| プレーヤー | 変わること |
|---|---|
| 管理者 | 取り込みの規則が強くなる。全案件で「AI の読み直し」の欄が見え、頼む・使う・捨てるができる。自分が登録した案件で選ぶ必要が出たら知らせが届く。「AI の仕事」一覧に新しい種類が出る（「やること」の「AI の下書きができています」の数にも入る） |
| SES | 自社が持ち込んだ案件で同じことができる。自分が登録した案件の知らせが届く。グループのほかの会社の案件では欄が出ない |
| 担当者 | SES と同じ（`role=ses`）。同じ会社のほかの人が登録した案件でも、欄が見え、選べる（知らせは登録した人にだけ。7章 Q5） |
| EG | **見えるものは変わらない**（欄も知らせも出ない）。案内が届く前の案件は、AI の結果で必須・尚可・業務が変わっていることがある。案内のあとは、人が「AI の結果を使う」を押したときだけ、案件の必須・尚可・相性の点数が変わる |

- 0件・境界：本文の無い案件（手で入れた案件）は仕事を入れない。AI の答えが空（必須・尚可とも0）で規則に行があれば「行数が違う」で選んでもらう。規則が0で AI に行があっても同じ。2回押し・並んだ仕事は A3・D5 で1つ。worker が止まっていれば queued のまま（30 分の戻しは running だけ）で、案件は規則の結果で使える。

## 5. 作業の手順（implementer）

### 段階1：規則

1. `CaseParser::sections(string $text): array` を足す（3-1）。定数 `BULLET`、private の `headingKind`・`indentOf`・`endsSection`。
2. `CaseParser::finishSkills(array $req, array $pref, string $text): array` を足し、今の `parse` の業務の拾い出しと本文の技術の追加を移す（動きは変えない）。
3. `parse`：`$buckets` の必須・尚可の代わりに `sections()` を使う（R9 の戻し）→ `finishSkills`。ほかの項目（案件名・単価…）は今のまま。
4. `php artisan test`。既存のテストで、取り込みの必須・尚可の中身が変わって落ちるものがあれば、新しい読み方で正しいかを見て期待値を直し、どれをなぜ直したかを reviewer への報告に書く。

### 段階2：AI の仕事

1. `AiJobs::KINDS` に `'case_skills' => '案件の必須・尚可の読み直し'`。`queueCase(int $caseId, array $input, $actor): int`（中で `queue('case_skills', 'case', …)`）。
2. `AiJobs::items`：`case_skills` の分岐（3-2）。`finish`：失敗の文に `case_skills` 用（「AI の答えの形が違いました。もう一度頼むか、規則の結果のまま使ってください」）。done のとき `case_skills` は今の知らせを出さず `CaseAi::settle`。
3. `app/Services/CaseAi.php`：`PROMPT`・`MAX_TEXT`（Q1）・`queue`・`snapshot`・`ruleSnapshot`（D6）。
4. `AiController::next`：input に `system`・`user`・`options` があれば答えに足す。
5. `tools/ai-worker/park_ai_worker.py`：`ask_ai(job)` にして、`system` と `user` があれば2通、`options` を既定に重ねる（D11）。先頭の説明に「案件の必須・尚可の読み直し」を足す。標準ライブラリだけのまま。
6. `CaseController::store`・`update` から `CaseAi::queue`（3-3 の 2）。ルート `POST /cases/{id}/ai-parse`（`/cases/{id}/edit` などと同じグループ）→ `CaseController::aiParse`（`caseEdit` でなければ 403。入れられなければ理由の文で戻す）。
7. `php artisan test`。

### 段階3：突き合わせと差し替え

1. `CaseAi::restore(array $lines, string $text): array`（`[['text', 'mark' => 'ok'|'fixed'|'missing', 'from' => AI の文 or null], …]`。D7）。`N()` と bigram の Dice は `CaseAi` の private。
2. `CaseAi::compare(object $job, object $case): array`（`req`・`pref`（restore 後）・`sameCount`・`missing` 数・`sameAsRule`・`reasons`（3-4 の理由の鍵）・`auto`（bool））。
3. `Workflow::saveCase` から必須・尚可のそろえ方を `normalizeCaseSkills` に分ける（`saveCase` の動きは変えない）。
4. `CaseAi::apply`・`settle`（3-3 の 4・6。D8・D9・D12）。
5. `AiController::job`：`ref_type=case` の分岐（`role` が admin／ses で `Perms::caseEdit`。違えば 403）。`applyCase`（`POST /ai-jobs/{id}/apply-case`。S7）。`drop` の戻り先に case を足し、`case_skills` は `ai-case-keep` を記録。
6. `php artisan test`。

### 段階4：画面・まわり

1. `cases/show.blade.php` に 3-4 の欄。`CaseController::show` で `caseEdit` の人にだけ `aiJob`・`aiCompare`（done のとき）を渡す。EG・他社の SES には渡さない。
2. `import`・`store` の知らせ（G4）。
3. `admin/ai.blade.php` のリンク（G3）。`SamplePurge`：`ref_type=case` で見本の案件の id の仕事を足す（G6）。
4. `config/park.php` を `v0.6.0`。版を見ているテスト（`RegistrationFoundationTest`・`SkillQuestionsTest`・`V030RegressionTest`・`V050CatalogTest`）を直す。
5. README：取り込みの節に「規則の読み方（v3）」の段落、AI の仕事の箱の節に `case_skills`（入れる時・自動で差し替える条件・選ぶ画面・本文との突き合わせ・input の中身）、worker の `system`・`user`・`options`、FOCUS の worker を新しくする手順（park を pull して worker を起動し直す）。
6. マニュアル（`resources/manuals`）：
   - `admin.md`（取り込みの節、168行あたり）・`admin-steps.md`：取り込みのあと AI が読み直すこと、案件の「AI の読み直し」の欄の見方と選び方、自動で差し替える条件、案内のあとは自動で変えないこと、「AI の仕事」一覧。
   - `ses.md`・`ses-steps.md`（76行あたり）：同じ内容を SES・担当者向けに（自社が持ち込んだ案件だけ。知らせは登録した人に届く）。
   - `engineer.md`・`engineer-steps.md`：変えない（EG に見えるものは変わらない）。
   - 04_Manuals の写しは Leo（または park-manager）が park の push のあとに写し直す。implementer は park の中だけ直す。
7. `php artisan test` が全部通ることを確かめる（zip の既知の1件を除く）。

## 6. テスト（新しく `tests/Feature/V060RuleTest.php`・`V060CaseAiTest.php`）

本文はテストの中で作る**架空の文だけ**（実在の会社名・人名を使わない）。立場は今のやり方（管理者・SES・担当者・他社の SES・EG のユーザーを作ってログイン）。worker は `config(['services.park_ai.token' => 'test-token'])` とヘッダー `X-Park-Ai-Token` で `POST /ai/worker/next`・`/ai/worker/{id}/result`（`output` に JSON の文）。

| 番号 | 条件 | 手順 | 確かめること |
|---|---|---|---|
| T1 | R2 | `<<必須>>`・`⇒必須`・`スキル(必須)：`・`◆スキル：`・`【必須】`・`MUST`/`WANT` の架空の本文それぞれで `CaseParser::sections` | 必須・尚可の行が期待どおりの数と文 |
| T2 | R3・R4 | `(1)`〜`(3)` の番号の行／印の無い3行の節／箇条書きの節の中の印の無い行 | 番号の行が3項目（印なし）。印の無い節は3項目。続きの行は前の項目につながる |
| T3 | R5 | `■必　須：Java の開発経験` の1行／`■必須（いずれか）` の行 | 前は1項目「Java の開発経験」。後は項目にならない |
| T4 | R6・R7・R8 | 区切り線／`単価：` の行／`【テクニカルスキル】` の小見出し／深く下げた `-` の子項目／`※` の補足 | 区切り・項目名で節が終わる。小見出しは項目にならない。子項目と ※ は前の項目に付く |
| T5 | D3 | `推奨：`・`■必須スキル/経験`・必須の節の「Go の経験（尚可）」 | 推奨は尚可。`/経験` が項目にならない。「Go の経験」が尚可に移る |
| T6 | R9・R10 | 今のテストにある取り込みの本文（v3 で読めない形）／技術だけが本文にある本文 | 今と同じ結果。必須が空なら技術が必須に入る。業務の拾い出しが今と同じ |
| T7 | R1 | 管理者で `POST /cases/import` | 200。`ai_jobs` が増えない（D1） |
| T8 | A1・A2・D5 | 合言葉あり、管理者が本文つきで `POST /cases` | `case_skills` の仕事が1つ（`ref_type=case`、input に `system`・`user`・`options.temperature=0`・`text_hash`・`rule`・`base`・`edited=false`）。知らせに AI の文 |
| T9 | D6 | 取り込みの結果から必須を1行消して登録 | `edited=true` |
| T10 | A4 | 合言葉なし／本文なし／本文が長すぎる で登録 | 登録は成功、仕事は0 |
| T11 | A2 | 登録後、本文を変えて `POST /cases/{id}`／本文を変えずに保存 | 前は古い仕事が `dropped`、新しい仕事が1つ。後は増えない |
| T12 | A3・G5 | 順番待ちがある案件で `POST /cases/{id}/ai-parse`／他社の SES・EG が押す／担当者が押す（順番待ちなし） | 増えない／403／増える |
| T13 | A5 | worker で `next` | `case_skills` は `system`・`user`・`options` がある。`script` の仕事には無い（今の形のまま） |
| T14 | A6・S8 | 結果に `{"items": []}`・JSON でない文・`error` を返す | `failed`。案件の4列は変わらない。画面に「規則の結果のまま使えます」と［もう一度頼む］ |
| T15 | S1・D7 | `CaseAi::restore`：本文にそのままある行／※の補足を前につないだ行／1字違う行／言い換えた行（本文に近い行が無い） | ok／ok／fixed（本文の行に戻り、from に AI の文）／missing |
| T16 | S2・S3 | 規則と行数が同じで1行だけ言い方が違う結果（本文にある文）を返す | `applied`。4列が AI の行から作られ、本文の技術の追加・業務の拾い出しが効いている。`audit_log` に `ai-case-auto`。知らせは無い。ほかの列（単価など）は変わらない |
| T17 | S4・S6 | 規則より1行多い結果を返す | `done`、4列は変わらない。登録した人に知らせ（`mail` なし・リンク `/cases/{id}#ai`）。管理者・SES・担当者の画面に違いの表と2つのボタン、「AI だけ」の印 |
| T18 | S2・D8 | 行数が同じ結果だが、①登録の後に人が必須を直した ②案内（`case_offers`）がある ③状態が募集終了 ④`edited=true` | どれも `done`（自動で変えない）。理由の文が出る |
| T19 | S5 | 規則とまったく同じ結果で、案内がある案件 | `dropped`・`ai-case-same`。画面に「AI も同じ読み方でした」。知らせは無い |
| T20 | S2 | 「本文に無い言い方」が1つある、行数は同じ結果 | `done`。画面に「本文に無い言い方」の印 |
| T21 | S7 | done のあと本文を変えて保存してから「AI の結果を使う」 | 前の仕事は `dropped`（T11）。古い仕事の id で押すと使えず、4列は変わらない |
| T22 | 選ぶ | T17 の案件で、担当者が［AI の結果を使う］／別の案件で SES が［今のままにする］ | 前は `applied`・4列が差し替わり・`ai-case-apply`。後は `dropped`・4列はそのまま・`ai-case-keep`、戻り先が `/cases/{id}#ai` |
| T23 | G1・G5 | done の案件を、案内を受けた EG・グループのほかの SES で `GET /cases/{id}`。EG・他社 SES で `POST /ai-jobs/{id}/apply-case` | 「AI の読み直し」の欄が出ない（AI の行の文も出ない）。403 |
| T24 | D12 | 本文が変わった後に結果が届く（`text_hash` 違い） | `dropped`・`ai-case-stale`、4列は変わらない |
| T25 | G3・G6 | 管理者で `/admin/ai`／見本の案件に仕事を入れて `SamplePurge` | 「案件の必須・尚可の読み直し」と `/cases/{id}#ai` のリンク／仕事が消える |
| T26 | V1 | `/login` | `v0.6.0` |
| T27 | 既存 | 既存のテスト一式（`test_ai_job_box` を含む） | 版の文字と、段階1で理由を書いて直した期待値のほかは、変えずに通る |

- worker（Python）の自動テストは無い。reviewer がコードを読み、Leo が FOCUS で `python park_ai_worker.py --once` を手元の PARK に向けて1件流して確かめる（架空の本文の案件で）。
- 画面の見た目（違いの表）は reviewer がコードを読み、Leo が画面を開いて確かめる。

## 7. 確認したいこと（Leo が決める。答えが来るまで推奨で作る）
2026-11-12 Leo：Q1〜Q8 はすべて推奨どおりに決めた。

- **Q1 本文の長さの上限**：**推奨：6000 字**。超えたら AI に回さず、欄に「本文が長いので AI に回していません」。調査のメールはこの範囲で、worker の `num_ctx 8192` に収まる。長いメール（1通に複数の募集など）は規則の結果と人の手直しで。
- **Q2 規則に足す D3 の形**（`推奨`・`BETTER`・`/経験`・行の中の `（尚可）`）：**推奨：入れる**（安く読め、外れた形のうち確かなもの）。見出しの無いメール（「英語　読み書き必須」）・1通に複数の募集は規則に入れず AI に任せる。
- **Q3 「同じ」の決め方と戻しの閾値**：行数が同じ＋「本文に無い言い方」0件で「同じ」、本文の行に戻すのは Dice 0.5 以上。**推奨：このまま**。調査の外れ（「望ましい」を尚可に／1行を2つに分ける）は行数が変わるので、人に選んでもらう形になる。公開版で 2〜3 週間の「自動／選ぶ」の割合を `audit_log` で見て直す。
- **Q4 案内のあとの扱い**：案内・案内メール・応募がある案件は自動で変えない（D8）。**推奨：このまま**。EG に届いた内容が知らないうちに変わるのを防ぐ。
- **Q5 知らせる相手**：登録した人だけ（メールなし）。**推奨：このまま**。同じ会社の担当者は案件の画面で見られる。足りなければ、次の版で `N::toSes`（その会社の全員）にする。
- **Q6 既存の案件**：保存済みの案件を一斉に AI に回すコマンドを作るか。**推奨：作らない**（手元の PC で1件1分、案内済みの案件は自動で変わらない）。要る案件は［AI に読み直してもらう］で1件ずつ。
- **Q7 worker の起動場所**：**推奨：FOCUS の1台だけ**。`AiJobs::next` は取り合いの守り（ロック）が無いので、GENESIS でも同時に動かすと同じ仕事を2台が取ることがある。GENESIS の Ollama は調べ物用に残す。
- **Q8 事務職などエンジニア以外の案件**：今回は特別扱いしない（AI は同じ指示で抜く）。**推奨：このまま**。

## 8. 了承が要ること

- **無し（TEZZO の了承が要る操作は今回の手順に入っていない）**。表の変更（マイグレーション）・データの削除・メールの送信・`.env` の変更は無い。公開版への反映（`park-deploy.sh`）は常時了承の範囲。
- ただし、次の場合だけ TEZZO の了承を取る：
  - 公開版の `.env` に `PARK_AI_TOKEN` がまだ入っていなかった場合（入れるのは `.env` の変更）。入っていなければ、AI の仕事は入らず、今と同じ動き（A4）なので、反映自体は止めなくてよい。
  - 古い `ai_jobs` の行を消したくなった場合（今回の仕組みは `dropped` にするだけで、消さない）。
- FOCUS の worker を新しいコードで起動し直すのは Leo が行う（park を pull して起動し直すだけ。設定ファイル `~/.park-ai/config.json` は変えない）。

---

# PLAN：技術マスタの追加と、業務経験・業務領域のマスタ（v0.5.0。2026-11-12）

> **これが今の計画**。下の「v0.4.1」の節は前の計画（実装・公開版への反映は済み）で、消さずに残している。
> 依頼：Leo（2026-11-12）。もとは TEZZO の方針の了承（2026-11-12「その感じで進めて」）。調査の記録は 41_Park-Project の `40_動作確認/41_マッチング精度`（`2026-11-12-03`・`2026-11-12-04`）。
> 作る順：段階1 → 段階2 → 段階3（段階ごとに commit。`php artisan test` を通してから次へ）→ park-reviewer → park-tester。commit・push・公開版への反映は Leo。
> プレーヤー：管理者・SES・担当者・EG の4つ（担当者は今のアプリでは `role=ses`）。**誰が編集でき・誰が見られるかは今のまま変えない**。
> 外部のライブラリは足さない。案件メールの本文・取引先の名前はこのファイルに書かない。
> 範囲外：抜き出し（`CaseParser` の必須・尚可の読み方）への手元の AI の組み込み（手元の AI の測定のあとで別に計画する）。

## 0. コードで確かめた今の形

- **技術マスタ**：辞書は `app/Support/SkillCatalog.php` の `CATALOG`（分類 => [正式な名前 => 別名]）。`park:skills-sync`（`SkillCatalog::sync`）が `skill_terms` に入れる（同じ名前があれば別名だけ足す。名前の変更・分類の直しはしない）。`sync` は `DatabaseSeeder`・`SampleSeeder`・`DummySeed`・`SeedDummyTest` からも呼ばれる（戻り値は新しく入れた件数）。
- `SkillCatalog::find`/`map`：名前・別名 → [id, 正式な名前, 分類]。同じ言葉が2つの行にあれば、先に読んだ行が勝つ。`norm_skill` はこれで正式な名前にそろえてから比べる。
- `SkillCatalog::detect`：案件の本文から lang〜tool の分類だけを拾う（英数字の言葉は前後が英数字でないときだけ、大文字小文字は区別しない）。`domain`（業種・業務）・`method`（工程）・`role`（役割）は拾わない。この3つは `find` で正式な名前にそろえるのにだけ使われ、工程・役割は EG のスキルの `words`（役割・工程の節の項目名）と突き合わさる。`domain` は EG 側に相手が無い（業界の節は `match:false`）。
- **「Director」の誤検出**：tool の `Director` が、本文の「Director クラス」（役職）に当たる。
- 経歴の索引（`engineer_project_skills`）は `ProjectSkills::rebuildAll` が `find` で作り直す。管理者の技術マスタ画面は、追加・直し・統合のあとにこれを呼んでいる。`park-deploy.sh` は `migrate`・キャッシュだけで、`park:skills-sync` は流さない（README の手での更新手順には書いてある）。
- `loose_match`（helpers）は「片方がもう片方を含めば一致」（Java/JavaScript だけ例外）。両方3文字以上で使う（`Matcher::sameSkill`、スキルシートの `$isHit`）。**新しく足す短い名前は、ほかの名前の中に含まれて誤って当たる**（例：`word` は `wordpress` に、`erp` は `redhatenterpriselinux` に含まれる）。
- **EG のスキル**：v0.4.1 の形（`resources/data/skill_form.json` の節 → `SkillForm` → `engineer_skill_checks`。`match:false` の節は `words` が空で、照合・一覧の列・技術の「使っている人」・完成度から外れる。詳細・スキルシート・Excel には出る）。業界の節（`industry`）と同じ形で、節を足すだけで保存・表示・「今の表に無い項目」が効く。**マイグレーションは要らない**。
- **案件**：必須・尚可は `cases.required_skills`・`preferred_skills`（「、」などで区切った文。`parse_list` は `・` と `/` でも分ける）。`Workflow::saveCase` が1つずつ `SkillCatalog::ensure`（無い言葉はマスタに確認待ちで足す）と `find` を通す。だから **「進捗・課題管理」のような名前は、この欄に入れると壊れる**（`・` で分かれ、マスタにも足される）。
- 取り込み：`CaseController::import` → `CaseParser::parse`（必須・尚可の見出しの下の行を `$buckets` に1行ずつ集め、最後に「、」でつなぐ）→ 案件票の画面に入れて見せる（人が直してから登録）。
- 点数：`Matcher::score($c, $e, $idx)`。必須 55 点 × 合った数 ÷ 必須の数（経歴だけで合えば 0.8）、尚可 15 点。まとめて呼ぶところ：`Matcher::forCase`・`Workflow::distribute`・`CaseMail::send`・`SesController`（73行）・`CaseController::show`（SES の表）。1人だけ：`CaseController::show`（EG）・`recommend`・`Qa`（面談の想定問答。合う点・足りない点をそのまま使う）。
- EG の「案件チェック」：`SkillChecks::caseMarks($state, $c)`（項目の `words` と案件の必須・尚可を突き合わせて「必須」「尚可」の印、`missing`＝本人のチェックのどれにも合わない必須）。案件は `EngineerController::pickableCases`（取る列を絞っている）。
- スキルシートの `?case=`：`EngineerController::sheet` の `$reqRows`（必須ごとに ○／—）。

## 1. 受け入れ条件

| 番号 | 受け入れ条件 |
|---|---|
| **段階1** | **技術マスタ** |
| C1 | 技術マスタに、3章の表の技術が分類・別名つきで入る |
| C2 | 本文に「Director クラス」「Director」とあっても、`detect` は何も拾わない。「Adobe Director」「Macromedia Director」は拾う。案件の必須の欄・経歴に「Director」と単独で書いた言葉は、今までどおり技術（Adobe Director）としてそろう |
| C3 | 新しく足す短い名前（Word・ERP・SSO・dbt）は、ほかの名前の中に含まれていても一致にしない（Word の案件が WordPress の人に当たらない） |
| C4 | 既存の DB に `park:skills-sync` を流すと：足りない言葉が入る／`Director` の行が `Adobe Director` に名前が変わる（id は同じ）／案件から自動で足された確認待ちの行で、名前が辞書と同じものは、辞書の分類になり確認済みになる／辞書の別名が、ほかの行の名前・別名とぶつかるものは画面に一覧で出す（勝手に統合・削除しない）／経歴の索引を作り直す／何度流しても結果は同じ |
| C5 | 「振り分けの前に消す言い方」（3章）を、`detect` と段階3の拾い出しの両方で、言葉を探す前に消す |
| **段階2** | **EG のスキル画面** |
| W1 | スキル画面の開発・運用ツールの節の後ろに節「業務経験」（15項目）、業界の節の後ろに節「業務領域」（8項目）が出る。チェックだけ（年数なし・その他なし）。名前と並びは3章のとおり |
| W2 | チェックは `engineer_skill_checks` に保存され、詳細・スキルシート（名前を伏せた版も）・Excel に節として出る。一覧の「スキル」列・技術の「使っている人」・技術の照合（words）・完成度の「5つ以上」には入らない |
| W3 | 作りは v0.4.1 の業界と同じ（JSON に節を足す。表の追加・マイグレーション無し）。既存の節・データの保存は変わらない |
| **段階3** | **案件側と突き合わせ** |
| K1 | 案件メールの取り込みで、必須・尚可の見出しの下の各行から、辞書（3章の「案件での言い方」）で業務経験・業務領域を拾い、案件票の画面に「必須」「尚可」が選ばれた状態で出る。同じ項目が両方にあれば必須。取り込みの知らせに、拾った名前が出る |
| K2 | 英字の言い方（AI・BI・ITIL など）は、前後が英数字でないときだけ当たる（「AIX」で AI 活用にならない）。消す言い方は先に消す（「5営業日」で営業・見積にならない） |
| K3 | 案件の登録・編集の画面で、業務経験・業務領域の項目ごとに「—／必須／尚可」を選べ、保存される（`cases.required_exp`・`preferred_exp`）。今までの必須・尚可スキルの欄は変わらない |
| K4 | 案件の詳細に「必須の業務」「尚可の業務」が出る（どちらかがあるときだけ）。案件を見られる全員（管理者・SES・担当者・EG） |
| K5 | 点数：案件の必須の業務は必須の数に、尚可の業務は尚可の数に入る。EG がその項目にチェックしていれば合う点「必須 ◯◯」、無ければ足りない点「必須 ◯◯」。**業務の指定が無い案件の点数は今と同じ** |
| K6 | EG の案件チェック：業務経験・業務領域の項目にも「必須」「尚可」の印が付き、本人がチェックしていない必須の業務は「あなたのスキルに無い必須」に出る |
| K7 | 案件に合わせたスキルシート（`?case=`）の必須の表に、必須の業務が ○ 経験あり／— で出る |
| K8 | まとめて点数を出すところで、問い合わせの数が人数で増えない |
| K9 | JSON から業務の項目が消えたら、案件に保存した名前は無視される（点数・表示・印に出ない） |
| K10 | 権限は今のまま：案件を直せるのは管理者・自社の案件の SES と担当者。EG は見るだけ。通知・メールは足さない |
| V1 | バージョンを v0.5.0 にする。README・マニュアル（`resources/manuals` と 04_Manuals の写し）を直す |

## 2. 決めたこと（Leo の決定として仮置き。違えば7章で直す）

| 番号 | 決めたこと | 理由 |
|---|---|---|
| D1 | Director：正式な名前を `Adobe Director`、別名 `Director`・`Macromedia Director`。別名 `Director` は **`detect`（文章から探す）では使わない**（`SkillCatalog::NO_DETECT`）。`find`（言葉そのもの）では今までどおり当たる | 経歴・必須の欄に単独で書いた「Director」は技術のことなので、そろえる働きは残す。誤りは文章から探すときだけ |
| D2 | 名前の変更は `SkillCatalog::RENAMES = ['Director' => 'Adobe Director']` を `sync` の最初に行う（古い名前の行があり、新しい名前の行が無いときだけ `name` を変える。両方あれば何もせず、ぶつかりとして知らせる） | id が変わらないので、経歴の索引の付け替えが要らない |
| D3 | 案件から自動で足された行（`source=auto`・`reviewed=false`）の名前が辞書の名前と同じなら、`sync` が分類を辞書のものにし、`reviewed=true`・`source=master` にする | 確認待ちのまま「その他」の分類で残るのを防ぐ。削除はしない |
| D4 | 辞書の別名が、別の行の名前・別名として DB にもうあるときは、`sync` は足すだけでぶつかりを報告する（コマンドの画面に出す）。統合は管理者が技術マスタの画面で行う（統合は行を消すので、TEZZO の了承を取ってから） | データの削除を自動でしない |
| D5 | 「完全に同じときだけ一致」の言葉 `PARK_EXACT_ONLY = ['word', 'erp', 'sso', 'dbt']`（`norm_skill_raw` の形）を helpers に置き、`loose_match` で片方がこれなら `===` だけにする | C3。今ある言葉の動きは変えない |
| D6 | 技術マスタの「そのほか」の LLM・RAG・生成 AI・ChatGPT は、技術マスタに足さず、業務経験「AI 活用」の言い方に寄せる（AI の分類は作らない） | EG のスキル画面に相手の項目が無く、技術として拾っても誰にも当たらない。案件側の要件は「AI を使った経験」の意味がほとんど |
| D7 | 消す言い方は `SkillCatalog::STRIP`（PHP の定数。1か所）に置き、`SkillCatalog::strip($text)` で、全角英数を半角にした文から1つずつ空白に置き換える。`detect` と段階3の拾い出しが使う | C5。正本1か所 |
| D8 | EG 側の項目と、案件で拾う言い方は `skill_form.json` に置く。節の新しい鍵 `caseMatch: true`（案件の業務と名前で突き合わせる節）、項目の新しい鍵 `caseWords: [...]`（案件での言い方）。`caseMatch` の節は `match: false`・`items` だけ・年数なし・その他なし。`caseWords` は `caseMatch` の節だけで、どの項目にも1つ以上 | 「項目の正本は JSON」を保つ。名前を2か所に書かない |
| D9 | 節の key は `work`（業務経験）・`area`（業務領域）。並びは `… dev, work, industry, area, pmpl`。短い名前 `SHORT`：`work => 業務経験`・`area => 業務領域` | 業務経験は役割・工程・技術の続き、業務領域は業界の隣。PM/PL の3問（出たり消えたりする節）は一番下のまま |
| D10 | 案件の保存先は `cases` に列を2つ足す：`required_exp`・`preferred_exp`（text・null 可。項目の名前を改行でつなぐ）。`required_skills` には入れない | `parse_list` が `・` で名前を分けてしまう。`ensure` で技術マスタに混ざるのも防ぐ |
| D11 | `work` と `area` の項目名は、2つの節を通して重ならない（JSON の検査）。案件の列は名前だけで持つ | どちらの節の項目かは JSON から引ける |
| D12 | 点数は、必須の業務を必須の数に、尚可の業務を尚可の数に足す（1項目＝技術1つと同じ重み）。EG の行があれば 1（経歴からは数えない）。`WEIGHTS` は変えない | K5。業務の無い案件は今と同じ点になる |
| D13 | 取り込みでは、`required_skills`・`preferred_skills` の中身は今のまま（業務だけの行も残す）。業務は別に拾って `required_exp`・`preferred_exp` に入れる | 今の抜き出しの動きを変えない。業務だけの行の扱いは7章 Q3 |
| D14 | 案件の画面の入力は `exp[項目の id]` = `''`／`required`／`preferred`（セレクト）＋隠し欄 `_exp=1`。`_exp` が無い保存では2列を触らない（新しい案件は null）。知らない id・知らない値は黙って無視 | 案件の画面を通らない保存（見本など）で消さない |
| D15 | 案件の業務を読むときは、今の JSON にある名前だけを使う（`CaseExp::split`）。消えた項目は黙って外す | K9 |
| D16 | EG の業務のチェックは `SkillChecks::expMany($ids)`（[engineer_id => [名前 => true]]。500人ごとに1回）で読む。`Matcher::score` に5つ目でなく **4つ目の引数 `?array $exp = null`** を足し、null なら1人分を読む。まとめて呼ぶところは全部まとめて渡す | K8。`skillIndexMany` の形（技術の索引）に混ぜると、`hasSkill` のゆるい一致で技術と業務が当たってしまう |
| D17 | 案内メールの本文（`{必須}`）・面談の想定問答の「必須ごとの質問」・案件の一覧の「必須」列には業務を足さない（面談の「合う点・足りない点」には点数から自動で入る） | 今回は突き合わせまで。文面を変えるのは別の依頼で（7章 Q8） |
| D18 | バージョンは **v0.5.0**（`config/park.php`） | 表の列の追加（マイグレーション）と新しい機能のため |
| D19 | 業務経験・業務領域を `DummySeed`・`SampleSeeder` に入れない | v0.4.1 の業界と同じ |

## 3. 足すもの

### 3-1. 技術マスタ（段階1。`SkillCatalog::CATALOG`）

| 分類 | 正式な名前 => 別名 |
|---|---|
| db | `Redshift` => `Amazon Redshift`、`dbt` => '' |
| cloud | `Amazon Athena` => `Athena`、`Google Workspace` => '' 、`Microsoft 365` => `M365`、`ServiceNow` => ''、`Dify` => ''、`LINE Messaging API` => `LIFF` |
| tool | `Tableau`、`Power BI` => `PowerBI`、`Looker Studio`、`QlikView`、`PowerPoint`、`Word`、`Jest`、`Genesys Cloud`、`JUST.DB`、`Dataiku`、`Streamlit`、`ERP`（別名なし） |
| lang | `Google Apps Script` => `GAS` |
| fw | `Doctrine ORM` => `Doctrine` |
| os | `メインフレーム` => `汎用機` |
| infra | `SSO` => `シングルサインオン` |
| tool（直す） | `Director` => '' を `Adobe Director` => `Director、Macromedia Director` に（D1・D2） |

- EG のスキル画面には、Redshift・Streamlit・Doctrine・メインフレーム・Google Workspace・Microsoft 365・Jest がもうある（その人に当たるようになる）。Tableau などは EG の「その他」か経歴で当たる。スキル画面に項目を足すのは今回の範囲外。
- 消す言い方 `STRIP`（D7）：`電気小売`、`製造業`、`PMワークベンチ`、`プロジェクトマネージャ試験`、`可視化`、`営業日`（調査の v3 の5つ＋「営業日」。`Director（D）` は D1 で要らなくなる）。

### 3-2. 業務経験（段階2。`skill_form.json` の節 `work`）

```json
{ "key": "work", "title": "システム開発プロジェクトで経験のある業務は？", "match": false, "caseMatch": true,
  "items": [ {"name": "進捗・課題管理", "years": false, "other": false, "caseWords": ["進捗", "課題管理", "WBS", "タスク管理", "リスク", "バックログ"]}, … ] }
```

| # | 名前（この並び・この文字） | caseWords |
|---|---|---|
| 1 | 進捗・課題管理 | 進捗、課題管理、WBS、タスク管理、リスク、バックログ |
| 2 | 折衝・調整 | 折衝、調整、合意形成、ステークホルダー、ヒアリング |
| 3 | ベンダー管理 | ベンダーコントロール、ベンダー管理 |
| 4 | 資料作成 | 資料、ドキュメント、設計書、報告書、議事録、マニュアル |
| 5 | 移行 | 移行、データ移行 |
| 6 | 導入・刷新 | 導入、刷新、更改、バージョンアップ、リプレース |
| 7 | 業務設計・運用設計 | 業務フロー、運用設計、業務整理、標準化、業務改善 |
| 8 | 品質管理 | 品質、品質指標 |
| 9 | データ分析・BI | 分析、BI、データアーキテクチャ |
| 10 | 障害・問い合わせ対応（ITIL） | 障害、不具合、問い合わせ、インシデント、ITIL、変更管理 |
| 11 | 英語・海外 | 英語、通訳、海外、グローバル |
| 12 | AI 活用 | AI、生成AI、AX、LLM、RAG、ChatGPT（後ろ3つは D6） |
| 13 | 難しいプロジェクト（大規模・複数・立て直し） | 大規模、複数案件、複数プロジェクト、横断、炎上、遅延、立て直し |
| 14 | 教育・ナレッジ | 教育、指導、ナレッジ、属人化解消 |
| 15 | 提案・事業化 | 提案、プリセールス、外販、新規事業 |

### 3-3. 業務領域（段階2。節 `area`。業界の節の後ろ）

`{ "key": "area", "title": "システム開発で関わった業務の領域は？", "match": false, "caseMatch": true, "items": [...] }`

| # | 名前 | caseWords（Leo の案。調査の辞書を土台に、ほかの言葉と取り違えやすいものは外した） |
|---|---|---|
| 1 | 営業・見積 | 営業、見積、SFA、販売管理 |
| 2 | 人事・給与 | 人事、給与、勤怠、労務 |
| 3 | 経理・財務 | 経理、会計、財務、債権、債務、固定資産、決算 |
| 4 | 総務・バックオフィス | 総務、バックオフィス、庶務、稟議 |
| 5 | 受発注・在庫 | 受発注、受注、発注、在庫、購買、調達 |
| 6 | 生産管理 | 生産管理、生産計画、MES |
| 7 | 規制対応（GxP など） | GxP、GMP、薬事、規制対応 |
| 8 | 顧客管理（CRM・コンタクトセンター） | CRM、顧客管理、コンタクトセンター、コールセンター |

- 外したもの：`CSV`（ファイルの形式と取り違える）、`GCP`（Google Cloud と取り違える）、`工程管理`（技術マスタで「マネジメント」の別名）。

### 3-4. 案件（段階3。マイグレーション `2026_11_12_000001_case_exp_columns.php`）

- `cases` に `required_exp` text null・`preferred_exp` text null を足す（`after('preferred_skills')`）。down は2列を消す。既存の行は null のまま（後から埋めない。7章 Q4）。

## 4. プレーヤーごとに変わること

| プレーヤー | 段階1（技術マスタ） | 段階2（EG のスキル） | 段階3（案件） |
|---|---|---|---|
| 管理者 | 技術マスタの画面に新しい言葉が出る。ぶつかりがあれば統合の判断（了承を取ってから） | 全 EG の業務経験・業務領域を直せる（仮登録中は今どおり直せない） | 全案件で業務を選べる。取り込みで拾われる。点数・案内メールの候補の順・配信の点数に入る |
| SES | 案件の取り込みで新しい技術が拾われる | 自社の EG の分を直せる | 自社が持ち込んだ案件で業務を選べる。「貴社のエンジニアとの相性」の点数・足りない点に入る |
| 担当者 | SES と同じ（`role=ses`） | SES と同じ | SES と同じ |
| EG | — | 本人の分を直せる。詳細・スキルシートに出る | 案件の詳細で業務が見える。「あなたとの相性」・案件チェックの印・「あなたのスキルに無い必須」に入る |

- 通知・メールは足さない。上位が EG のスキルを直すと、今どおり `W::editedBy` で本人の「確認しました」がやり直しになる。
- 0件：業務を選んでいない案件は、詳細に行を出さず、点数も今と同じ。業務にチェックの無い EG は、業務のある案件で足りない点が増える（意図どおり）。

## 5. 作業の手順（implementer）

### 段階1：技術マスタ

1. `SkillCatalog::CATALOG` に3-1の表を足す（Director は直す）。
2. 定数を足す：`NO_DETECT = ['Director']`、`RENAMES = ['Director' => 'Adobe Director']`、`STRIP`（3-1）。関数 `strip(string $text): string`（全角英数を半角にした文から、STRIP の言葉（同じく半角にしたもの）を空白に置き換える）。
3. `detect`：最初に `strip` を通す。`NO_DETECT` にある言葉は飛ばす。
4. `sync` を `syncDetail(): array`（`['added' => n, 'renamed' => [旧 => 新], 'fixed' => [名前…], 'conflicts' => ['言葉 → 既にある行の名前'…]]`）に分け、`sync(): int` は `syncDetail()['added']` を返す（今の呼び出し元はそのまま）。順番：RENAMES → 各言葉（あれば別名を足す。D3 の auto 行は分類・確認済みを直す。無ければ入れる）→ 別名ごとに、同じ `norm_skill_raw` の言葉がほかの行にあれば conflicts に足す → `forget`。
5. `SkillsSync` コマンド：`syncDetail` の結果を出す（足した数・名前を変えた行・直した行・ぶつかり）。最後に `ProjectSkills::rebuildAll()`。ぶつかりがあれば「技術マスタの画面で統合してください（統合は行を消します）」と出す。
6. helpers：`PARK_EXACT_ONLY` を足し、`loose_match` の最初で、どちらかがこれに入っていれば `$a === $b` を返す（D5）。
7. `php artisan test`。手元の DB で `park:skills-sync` を2回流し、2回目は added 0・renamed 空になることを確かめる。

### 段階2：EG のスキル画面

1. `skill_form.json`：3-2 の `work` を `dev` の後ろに、3-3 の `area` を `industry` の後ろに足す（D9）。
2. `SkillForm::build`：節の `caseMatch`（既定 false）を `tree` の節に持たせ、項目の node に `caseWords`（無ければ `[]`）を持たせる。検査（`\RuntimeException`）：`caseMatch` の節が `match:false` でない／`groups` がある／`single` である／項目に `years:true`・`other:true` がある／`caseWords` が空・文字の配列でない、`caseMatch` でない節に `caseWords` がある、`work`・`area` を通して名前が重なる。
3. `SkillForm::SHORT` に `work`・`area` を足す。関数 `caseItems(): array`（`caseMatch` の節の項目を JSON の並びで `[名前 => ['id', 'section', 'words' => caseWords]]`）。
4. 画面 `engineers/skills.blade.php`：今の描き方（業界と同じ items の節）で出ることを確かめる。説明文の「業界は当てはまるものすべて」の文を「業務経験・業界・業務領域は当てはまるものすべて」に直す。
5. `SkillChecks`：変えない（`match:false` で一覧・照合・使っている人・完成度から外れる）。確かめるだけ。
6. `php artisan test`（`SkillFormJsonTest`・`SkillQuestionsTest` の節の一覧を直す）。

### 段階3：案件

1. マイグレーション（3-4）。
2. `app/Support/CaseExp.php`（新しく）：
   - `detectLine(string $line): array`（名前の配列）：`SkillCatalog::strip` を通した行に、`caseItems()` の各 caseWords を当てる。英数字だけの言葉は `detect` と同じ前後の決まり（大文字小文字を区別しない）、それ以外は含むかどうか。
   - `fromLines(array $req, array $pref): array`（`['required' => [...], 'preferred' => [...]]`。JSON の並び。両方にあれば required だけ）。
   - `split(?string $v): array`（改行で分け、`caseItems()` にある名前だけ。D15）、`join(array $names): ?string`（JSON の並びに直して改行でつなぐ。空なら null）。
3. `CaseParser::parse`：`$buckets` を「、」でつなぐ前に `CaseExp::fromLines($buckets['required_skills'] ?? [], $buckets['preferred_skills'] ?? [])` を呼び、`$out['required_exp']`・`$out['preferred_exp']` を `join` した文で入れる。ほかの出力は変えない（D13）。
4. `CaseController::import`：知らせの文に「本文の必須・尚可から見つかった業務：◯◯（必須）、◯◯（尚可）」を足す（無ければ出さない）。
5. `cases/form.blade.php`：「尚可スキル」の欄の下に、`<details open>`「業務経験・業務領域（必須・尚可）」。節ごと（業務経験・業務領域）に、項目ごとの行：名前＋`<select name="exp[項目の id]">`（`—`／`必須`／`尚可`。`$c->required_exp`・`preferred_exp` を `CaseExp::split` して選ぶ）。隠し欄 `_exp=1`。説明「取り込みでは、必須・尚可の文から拾って選んでおきます。確かめて直してください。」
6. `Workflow::saveCase`：`CASE_FIELDS` には足さない。`array_key_exists('_exp', $d)` のとき、`exp` の配列から名前の一覧を作り（`caseItems` にある id・`required`／`preferred` の値だけ）、`required_exp`・`preferred_exp` を `CaseExp::join` で入れる。`_exp` が無いときは、新規なら `$d['required_exp']`・`$d['preferred_exp']`（文）を `split`→`join` して入れ（見本・テスト用）、更新なら2列を触らない（D14）。`ensure` は通さない。
7. `cases/show.blade.php`：「尚可」の行の後ろに、`dt`「必須の業務」「尚可の業務」（中身があるときだけ。`x-chips`）。
8. `SkillChecks::expMany(array $ids): array`（`caseMatch` の節の行で、今の JSON でチェックできるもの。500人ごとに1回）。
9. `Matcher::score`：4つ目の引数 `?array $exp = null`（null なら `SkillChecks::expMany([$e->id])[$e->id]`）。必須：`$req` の技術に加え、`CaseExp::split($c->required_exp ?? null)` の名前ごとに、`isset($exp[$name])` なら hit +1・合う点「必須 名前」、無ければ足りない点「必須 名前」。分母は技術と業務の合計。尚可も同じ。両方が空のときの 0.5 の扱いは今と同じ（技術も業務も無いとき）。
10. まとめて呼ぶところで `expMany` を1回読んで渡す：`Matcher::forCase`、`Workflow::distribute`、`CaseMail::send`、`SesController`（73行）、`CaseController::show`（SES の表）。`Qa`・EG の表示・`recommend` は1人分なので引数なしでよい。
11. `SkillChecks::caseMarks`：`caseItems()` の項目ごとに、案件の required_exp にあれば `required`、preferred_exp にあれば `preferred` の印。必須の業務で本人が `isOn` でないものを `missing` に足す（名前のまま）。`empty` は技術・業務の4つとも空のとき。`EngineerController::pickableCases` の取る列に `required_exp`・`preferred_exp` を足す。
12. `EngineerController::sheet`：`$reqRows` の後ろに、必須の業務ごとに `[名前, ○ 経験あり／—]`（`expMany` で本人の分）。
13. `config/park.php` を `v0.5.0`。版を見ているテスト（`RegistrationFoundationTest`・`SkillQuestionsTest`・`V030RegressionTest`）の `v0.4.1` を直す。
14. README：「## スキルのチェック式」に v0.5.0 の段落（`work`・`area`、`caseMatch`・`caseWords`、案件の2列、点数への入り方、`CaseExp`）。技術マスタの段落に `NO_DETECT`・`RENAMES`・`STRIP`・`PARK_EXACT_ONLY`、`park:skills-sync` が名前の変更・確認待ちの直し・ぶつかりの報告・経歴の索引の作り直しまですること。
15. マニュアル（`resources/manuals`）：
    - `engineer.md`「### スキル」：業務経験・業務領域の節（当てはまるものすべて。年数なし）。業界と違い、**案件の点数と「案件に合わせた印」に使う**こと。「案件を見る」（97行あたり）：案件票に「必須の業務」「尚可の業務」が出ること。
    - `engineer-steps.md` 3-3 の2：手順に「業務経験・業務領域にチェック」を足す。
    - `ses.md` 82行・`ses-steps.md` 59行・`admin.md` 120行：節の説明に業務経験・業務領域を足す（点数に使う）。
    - `admin.md` 168〜183行・`ses-steps.md` 76行：取り込みで業務も拾うこと、案件票の「業務経験・業務領域」の選び方、点数の説明に「必須・尚可の業務」を足す。
    - 04_Manuals の写しは、park を push したあと Leo（または park-manager）が写し直す。implementer は park の中だけ直す。
16. `php artisan test` が全部通ることを確かめる（zip の既知の1件を除く）。

## 6. テスト（新しく `tests/Feature/V050CatalogTest.php`・`V050WorkExpTest.php`）

立場は今のテストのやり方（管理者・SES・担当者・EG のユーザーを作ってログイン）に合わせる。取り込みは `POST /cases/import`（`raw_text`）、登録は `POST /cases`、編集は `POST /cases/{id}`、詳細は `GET /cases/{id}`、スキルは `GET/POST /engineers/{id}/skills`（`_sf=1`・`_sf_end=1`）、シートは `GET /engineers/{id}/sheet?case=`。本文はテストの中で作った架空の文だけを使う。

| 番号 | 条件 | 手順 | 確かめること |
|---|---|---|---|
| T1 | C1 | `SkillCatalog::sync()` のあと `find` | 3-1 の名前・別名（`Athena`・`M365`・`GAS`・`Doctrine`・`LIFF`・`汎用機`）が正しい正式な名前と分類を返す |
| T2 | C2 | `detect("Director クラスの方")`／`detect("Director")`／`detect("Adobe Director の経験")` | 前の2つは空、3つ目は `Adobe Director`。`find('Director')[1]` は `Adobe Director` |
| T3 | C3 | 「Word」が必須の案件と、WordPress だけにチェックした EG で `Matcher::score`／`loose_match('word','wordpress')`／`loose_match('erp','redhatenterpriselinux')` | 必須 Word は足りない点。loose_match は false。`loose_match('laravel','laravel5')` など今のものは true のまま |
| T4 | C4 | `skill_terms` に `Director`（tool）と、auto・未確認の `Tableau`（other）と、auto の `M365` を入れてから `syncDetail()` を2回 | 1回目：`Director` の行が同じ id で `Adobe Director`、`Tableau` は tool・確認済み、conflicts に `M365` が出る（行は消えない）。2回目：added 0・renamed 空 |
| T5 | C4 | `php artisan park:skills-sync`（`$this->artisan`） | 終了 0。経歴に「Tableau」と書いた EG の `engineer_project_skills` に Tableau の行ができる |
| T6 | C5 | `SkillCatalog::strip('5営業日以内')`／`CaseExp::detectLine('5営業日以内に報告書を作成')` | 「営業日」が消える。拾うのは `資料作成` だけ（`営業・見積` は無い） |
| T7 | W1 | `SkillForm::tree()`／EG で画面を開く | 節の key が `roles, phases, lang, db, os, pm, dev, work, industry, area, pmpl`。work 15件・area 8件が3章の名前と並びで、`years=false`・`other=false`。画面に「進捗・課題管理」「経理・財務」が出て、その行に年数のプルダウンが無い |
| T8 | W2 | EG が work 2つ・area 1つを `chk` で保存 → 詳細・シート（`?anon=1` も）・`SkillChecks::lines` | `engineer_skill_checks` に `section=work` 2行・`area` 1行（`years_min` null）。「業務経験」「業務領域」の節が出る |
| T9 | W2 | 同じ EG で `indexMany`・`summaryMany`・`usedPeople`・`normIndex`・`Qa::sheetCheck`（技術3つ＋業務5つ） | 業務の行が索引・一覧の列・使っている人・normIndex に無い。「5つ以上」の指摘が出る |
| T10 | W3 | JSON の検査（`SkillForm::validate` に一時の配列を渡す）：caseMatch に `years:true`／`match` が無い caseMatch／caseWords が空／caseMatch でない節に caseWords／work と area に同じ名前 | どれも `\RuntimeException` |
| T11 | K1 | 管理者で、必須の見出しの下に「進捗管理の経験」「AIX の運用」、尚可の下に「英語での会話」「進捗の報告」の行がある本文を `POST /cases/import` | 画面の `exp[...]` で `進捗・課題管理` が必須、`英語・海外` が尚可に選ばれている。`AI 活用` は選ばれていない（K2）。知らせに「見つかった業務」が出る。`required_skills` の欄の中身は今と同じ |
| T12 | K3 | 管理者・SES・担当者がそれぞれ `exp` を付けて `POST /cases`（`_exp=1`）→ 編集で `_exp=1` と空の `exp` で更新／`_exp` なしで更新 | 列に名前が改行で入る（JSON の並び）。空で更新すると null。`_exp` なしの更新では列が変わらない。知らない id・値は無視。技術マスタに業務の名前が足されない |
| T13 | K10 | EG が `POST /cases/{id}`／他社の SES が自社でない案件を編集 | 今どおり拒否（403） |
| T14 | K4 | 業務のある案件を、管理者・SES・担当者・EG で `GET /cases/{id}`／業務の無い案件 | 前は「必須の業務」と名前が出る。後は「必須の業務」の行が出ない |
| T15 | K5 | 必須 `Java` と必須の業務 `進捗・課題管理`、尚可の業務 `英語・海外` の案件。EG-A（Java と進捗・課題管理にチェック）・EG-B（Java だけ）で `Matcher::score` | A は必須2つ合い、合う点に「必須 進捗・課題管理」。B は足りない点に「必須 進捗・課題管理」で、A より低い |
| T16 | K5 | 業務の列が null の案件で、`V030RegressionTest` と同じ人・案件 | 点数が今と同じ（82 点） |
| T17 | K6 | EG-B で、その案件を選んでスキル画面を開く（`?case=`） | `進捗・課題管理` の行に「必須」、`英語・海外` に「尚可」の印。「あなたのスキルに無い必須」に `進捗・課題管理` |
| T18 | K7 | 管理者で EG-A のシートを `?case=` で開く | 必須の表に「進捗・課題管理」「○ 経験あり」 |
| T19 | K8 | エンジニア 3人と 30人で `Matcher::forCase` の問い合わせ数を数える（`V030RegressionTest` と同じやり方） | 同じ回数 |
| T20 | K9 | 案件の `required_exp` に JSON に無い名前を直接入れる | 点数・詳細・印に出ない |
| T21 | V1 | `/login` | `v0.5.0` が出る |
| T22 | 既存 | 既存のテスト一式 | 版の文字と節の一覧を直したほかは、変えずに通る |

- 画面の見た目（案件票のセレクトの並び、スキル画面の新しい2節）は自動テストが無いので、reviewer がコードを読み、Leo が画面を開いて確かめる。

## 7. 確認したいこと（Leo が決める。答えが来るまで推奨で作る）

> **2026-11-12 Leo：Q1〜Q10 はすべて推奨どおり。** Q1 まとめて v0.5.0 で1回反映／Q2 業務1項目＝技術1つの重み／Q3 業務だけの行も必須の欄から外さない／Q4 既存案件の拾い直しコマンドは作らない／Q5 AI 系は業務経験「AI 活用」に寄せる／Q6 業界の突き合わせは今回しない／Q7 `park-deploy.sh` への `park:skills-sync` の追加は計画に書くだけ（スクリプトは 41_Park-Project 側にあるので Leo が直す）／Q8 案内メールには出さない／Q9 並びは計画どおり／Q10 業務領域の辞書は計画のまま（外れが出たら JSON を直し、必要なら `STRIP` に「教育業界」を足す）。

- **Q1 反映の単位**：段階ごとに公開版へ出すか、3段階をまとめて v0.5.0 で出すか。**推奨：まとめて1回**（段階2だけだと、EG にチェックさせても点数に効かず、入れる理由が伝わらない）。
- **Q2 業務の重み**：業務1項目を技術1つと同じ重みで必須・尚可の数に入れる（D12）。**推奨：このまま**。案件によって業務の数が多いと、技術の比重が下がる。気になれば、次の版で必須の中を技術と業務で分ける。
- **Q3 業務だけの行**：取り込みで、業務だけに当たって技術を含まない行（例「進捗管理の経験」）を必須スキルの欄から外すか。**推奨：今回は外さない**（D13）。外すと、同じ行の役割・工程（PMO など）も消える。手元の AI の抜き出しを入れるときに見直す。それまでは、登録の前に人が欄を直す。
- **Q4 既存の案件**：保存済みの案件の `raw_text` から業務を拾い直すコマンドを作るか。**推奨：作らない**（点数が一斉に変わる。必要な案件は編集で選ぶ）。
- **Q5 AI の分類**：LLM・RAG・生成 AI・ChatGPT を「AI 活用」に寄せた（D6）。**推奨：このまま**。
- **Q6 業界との突き合わせ**：v0.4.1 の業界（26件）と、技術マスタの `domain` を案件と突き合わせるか。**推奨：今回はしない**（業界の名前と `domain` の名前がそろっておらず、別の整理が要る）。
- **Q7 `park-deploy.sh` に `park:skills-sync` を足すか**（41_Park-Project の `.claude/scripts`）。**推奨：足す**（何度流しても同じで、データを消さない。今は README の手での手順にしかなく、公開版の辞書が古いままになる）。足さない場合は、今回の反映のあとに Leo が公開版で1回流す。ぶつかりが出たら、統合（行を消す）は TEZZO の了承を取ってから。
- **Q8 案内メールの本文**：`{必須}` に業務も並べるか（D17）。**推奨：今回はしない**。
- **Q9 節の並び**：`dev → work → industry → area → pmpl`（D9）。**推奨：このまま**。項目名と並びは画面で TEZZO に見てもらう（見た目の確認は TEZZO）。
- **Q10 業務領域の言い方**：3-3 の caseWords は Leo の案（調査の辞書が無かったので足した）。**推奨：このまま入れ、外れを見て JSON を直す**。また「教育」は業界の「教育」と取り違えるおそれがある（例：教育業界向けの案件）。**推奨：今回は消す言い方に足さず、外れが出たら `STRIP` に「教育業界」を足す**。

## 8. 了承が要ること

- 公開版への反映（`park-deploy.sh`。マイグレーション1本＝列の追加だけ）は常時了承の範囲。データの削除・メールの送信は無い。
- `park:skills-sync` を公開版で流すと、`Director` の行の名前が変わり、確認待ちの行の一部が確認済みになる（行は消さない）。README の更新手順にあるコマンドなので、Leo が流してよい（Q7）。
- ぶつかり（D4）の統合は行を消すので、**TEZZO の了承を取ってから**管理者の画面で行う。

---

> 以下は前の計画（v0.4.1。2026-11-11）。実装・公開版への反映は済み。消さずに残す。

# PLAN：スキル画面に「業界」と「PM/PL の経験」の設問を足す（v0.4.1。2026-11-11）

> **これが今の計画**。前の計画（スキル登録画面の全面変更 v0.4.0）は上書きした。見るときは park で `git log --oneline -- PLAN.md` を出し、`git show <commit>:PLAN.md` で開く。
> 依頼：TEZZO（2026-11-11）「今の設問の最後に、【1】業界（複数選択）と、【2】役割で PM か PL を選んだ人にだけ出す『PM/PLご経験者の方へ』の3問（それぞれ単一選択）を足す」。
> 作る順：park-implementer → park-reviewer → park-tester。commit・push・公開版への反映は Leo（反映は常時了承済み。今回はマイグレーションなし）。
> プレーヤー：管理者・SES・担当者・EG の4つ（担当者は今のアプリでは `role=ses`）。**誰が編集でき・誰が見られるかは今のまま変えない**。
> 外部のライブラリは足さない。

## 0. コードで確かめた今の形

- 項目の正本は `resources/data/skill_form.json`（`yearOptions` と `sections` 7つ：roles・phases・lang・db・os・pm・dev）。`SkillForm::load` が読み、節の `items`（直の項目）と `groups`（`years` が false なら見出しだけ＝チェックなしの親）を `nodes`・`tree` にする。項目ごとに `words`（`SkillForm::words`）を作る。
- 保存は `SkillChecks::save`（`chk[id]`・`yr[id]`・`oth[id][]`・`othyr[id][]`・`keep[]`）。今の JSON でチェックできる項目の行は毎回入れ替える（＝送られなかった項目の行は消える）。
- `words` を使うところ：`SkillChecks::indexMany`（マッチング。`Matcher::skillIndexMany` → 点数・案内・スキルシートの必須の○）、`usedPeople`（技術マスタの「使っている人」。`SkillTable::usedCounts`）、`caseMarks`（EG 本人の画面の「必須」「尚可」の印）、`SkillForm::normIndex` → `place`（移行 `migrateFromOld`・`DummySeed`・`SampleSeeder` の置き場所）、`sheet.blade.php` の `$hit`（合う技術の強調）。
- `Matcher::sameSkill` は「同じ、または両方3文字以上で `loose_match`」。業界の名前が `words` に入ると、案件の必須の「インフラ」「サービス」「金融系」などと合ってしまう（例：「インフラ（電力・ガス・水道など）」は words に「インフラ」「電力」「ガス」「水道など」が入る）。**だから新しい2つの節は words を空にする（D3）**。
- 表示：`forDisplay` → `linesOf`（詳細・Excel・`EngineerDocs`）、`sheet.blade.php`（スキルシート）、`summaryMany`（管理者・SES の一覧の「スキル」列と、管理者一覧の `q` 検索）。
- 完成度：`Qa::sheetCheck` は `engineer_skill_checks` の行数（5以上で加点）を数える。
- 画面：`engineers/skills.blade.php`（節ごとの `<details class="sf-sec" data-sf-sec="key">`、件数「（n 件チェック）」）、JS は `public/js/park.js`。
- 役割の PM・PL の項目名は `プロジェクトマネージャー（PM）`・`プロジェクトリーダー（PL）`（000004 で全角括弧にそろえ済み）。どちらも `years: true`。

## 1. 受け入れ条件

| 番号 | 受け入れ条件 |
|---|---|
| A1 | スキル画面の一番下（dev の節の後ろ）に、節「システム開発経験のある業界は？」が出る。26 の業界をチェック（複数可）で選べる。年数のプルダウンは無い。「その他」は無い |
| A2 | 業界の名前と並びは依頼の原文どおり（3章）。誤記の疑いのものも直さない（確認事項 Q1・Q2） |
| A3 | 業界の節の後ろに、見出し「PM/PLご経験者の方へ」の節が出る。中は3問で、それぞれ単一選択（v0.4.1 の修正でラジオからプルダウンに変更。TEZZO の指示）。選択肢は原文どおり |
| A4 | A3 の節は、役割で PM か PL にチェック（年数だけ選んだときも含む）があるときだけ出る。画面で PM・PL を付け外しすると、その場で出たり隠れたりする |
| A5 | PM・PL の両方を外して保存すると、3問の保存済みの回答は消える。画面で隠れている間の回答は送られない |
| A6 | サーバー側でも、送られた役割に PM・PL が無ければ、3問の回答を保存しない（JS が無い・細工した送信でも） |
| A7 | 1つの設問に保存される答えは最大1つ。選択肢に無い値・別の設問の選択肢・配列の値が来たら、何も保存せず「選択肢から選んでください。」で戻す |
| A8 | 「選択しない」（一日最大利用者数）は、未回答と同じ扱い（行を作らない）。未回答のときは「選択しない」に印が付いた状態で出る |
| A9 | 業界・PM/PL の回答は、案件とのマッチング（点数・案内・スキルシートの○・案件の印・技術マスタの「使っている人」・移行とダミーの置き場所）に一切影響しない |
| A10 | 業界・PM/PL の回答は、詳細・スキルシート（名前を伏せた版も）・Excel に節として出る。一覧の「スキル」列には出さない |
| A11 | 完成度の「スキル5つ以上」と「年数の未選択」の数に、業界・PM/PL の行を数えない |
| A12 | 管理者・SES・担当者・EG の、見る・直す範囲は今のまま（4章） |
| A13 | 表の追加・マイグレーションは無し。既存のデータ・既存の7節の保存は変わらない（既存テストが通る） |
| A14 | バージョンを v0.4.1 にする。README・マニュアル（resources/manuals と 04_Manuals の写し）を直す |

## 2. 決めたこと（Leo の決定として仮置き）

| 番号 | 決めたこと | 理由 |
|---|---|---|
| D1 | **保存先は今の `engineer_skill_checks`**（別の表は作らない）。業界：`section=industry`・`parent=''`・`item=業界名`。PM/PL：`section=pmpl`・`parent=設問の文（原文のまま）`・`item=選んだ選択肢`。`other_text=''`・`years_min=null`・`source=form` | 比べた結果は下の表。マイグレーション不要・「今の表に無い項目」（`gone`）・`state`・`forDisplay`・`SamplePurge`・バックアップがそのまま効く。単一の制約は DB でなく保存の検査（D5）で守る |
| D2 | JSON に2節を足す（3章）。節に新しい鍵 `match`（false＝マッチングに使わない）・`single`（true＝グループごとに1つ選ぶ）・`showIf`（出す条件）、グループに `short`（表示の短い名前）、項目に `none`（true＝選んでも行を作らない）を足す。鍵が無い今の7節は今までどおり | 「正本は JSON」の形を保つ。コードに節名を書き込まない |
| D3 | `match:false` の節の項目は `words` を空にする（`SkillForm::make`）。加えて `indexMany`・`usedPeople`・`summaryMany` は、`SkillForm::matchable($section)` が false の行を**節名で**飛ばす（JSON から消えた行＝`gone` の行でも混ざらないように） | A9。words が空なら `normIndex`・`place`・`caseMarks`・スキルシートの `$hit` は自然に外れる |
| D4 | 一覧の「スキル」列（`summaryMany`）には業界・PM/PL を出さない。したがって管理者の一覧の検索（`q`）でも業界では探せない | 列が技術の一覧のため。業界で探す機能は今回の範囲外（要れば次の依頼） |
| D5 | 単一選択の入力は新しい欄 `one[グループの id]=選択肢の id`（例 `one[pmpl-1]=pmpl-1-3`）。`pmpl` の項目に `chk[]`・`yr[]` が来ても無視する | ラジオボタンは同じ name で1つしか送れないので、構造上1つ。値の検査は A7 |
| D6 | 出す条件 `showIf`：`{"section":"roles","items":["プロジェクトマネージャー（PM）","プロジェクトリーダー（PL）"]}`。どれか1つが「チェックあり」（`SkillChecks::isOn`。年数だけでも可）なら出す。PMO・チームリーダーなどは対象外 | 依頼どおり PM・PL だけ |
| D7 | 条件を満たさないときの `one[]` は、エラーにせず**黙って捨てる**（行を作らない）。保存は入れ替えなので、前の回答の行は消える | A5・A6。JS が無い人がPMを外して保存したときに、エラーで戻すと困るため |
| D8 | 画面で隠すときは、節に `hidden` を付け、中のラジオを `disabled` にする（送らない）。ラジオの選択は画面の上では残すので、同じ編集の中で PM を付け直せば戻る | 誤って外したときに選び直さなくて済む。保存すれば D7 で消える |
| D9 | 「選択しない」（`none:true`）は行を作らない。画面では、未回答の設問に「選択しない」があればそこに印を付けて出す。「選択しない」が無い設問（期間・人数規模）は、未回答なら何も選ばれていない | 未回答と「答えない」を区別する意味が薄いため。一度選ぶと未回答に戻せないが、PM/PL を外せば消せる（今回は許容） |
| D10 | 節の件数「（n 件チェック）」：業界はチェック数、PM/PL は答えた設問の数（「（n 件回答）」の文にする）。PM/PL の節は、出ているときは常に開いて出す。業界は今の決まり（チェックがあれば開く） | 今の見た目に合わせる |
| D11 | 表示の名前：節の短い名前は `SkillForm::SHORT` に `industry => 業界`・`pmpl => PM/PL の経験`。設問は `short`（期間・一日最大利用者数・人数規模）で出す。例：`【PM/PL の経験】期間：1年〜3年 ／ 一日最大利用者数：1万人〜10万人 ／ 人数規模：5人〜29人` | 設問の文は長いので、詳細・シートでは短く |
| D12 | バージョンは **v0.4.1**（`config/park.php`） | 表の変更なし・設問の追加のため小さい番号 |
| D13 | 業界・PM/PL を `DummySeed`・`SampleSeeder` に入れない | 今回の範囲外。無くても画面・テストは困らない |
| D14 | 全角の「／」を項目の区切り、半角の「/」を名前の中の文字として読む（3章の 26 件） | 依頼文の書き方から。違えば Q2 で直す |

### D1 の比較

| | 案1：今の表に載せる（採用） | 案2：別の表（例 `engineer_pm_answers`：engineer_id・question・answer） |
|---|---|---|
| マイグレーション | 要らない | 要る（作成・down） |
| 1問1つの保証 | 保存の検査で守る（D5・A7） | 一意キー `(engineer_id, question)` で DB が守る |
| 既存の仕組み | `state`・`gone`・`forDisplay`・`lines`・Excel・`SamplePurge`・バックアップがそのまま効く | 保存・表示・消す処理・バックアップを全部足す |
| マッチングを汚す心配 | ある → D3 で節ごとに外す | 無い |
| 設問・選択肢を JSON で変えられるか | 変えられる（消えた選択肢は `gone` に出る） | 別の正本が要る |

→ 足すコードが少なく、正本が JSON 1つのまま。汚す心配は D3 とテストで止める。

## 3. JSON に足すもの（`resources/data/skill_form.json` の `sections` の最後に、この順で）

```json
{
  "key": "industry",
  "title": "システム開発経験のある業界は？",
  "match": false,
  "items": [ {"name": "IT・インターネットサービス", "years": false, "other": false}, … ]
},
{
  "key": "pmpl",
  "title": "PM/PLご経験者の方へ",
  "match": false,
  "single": true,
  "showIf": {"section": "roles", "items": ["プロジェクトマネージャー（PM）", "プロジェクトリーダー（PL）"]},
  "groups": [
    {"name": "PM/PLを担当したシステム開発プロジェクトで一番長期のものは？", "short": "期間", "years": false,
     "items": [{"name": "〜半年"}, {"name": "半年〜1年"}, {"name": "1年〜3年"}, {"name": "3年以上"}]},
    {"name": "PM/PLを担当したシステム開発プロジェクトでの一日最大利用者数は？", "short": "一日最大利用者数", "years": false,
     "items": [{"name": "選択しない", "none": true}, {"name": "〜1万人"}, {"name": "1万人〜10万人"}, {"name": "10万人〜50万人"}, {"name": "50万人〜100万人"}, {"name": "100万人以上"}]},
    {"name": "PM/PLを担当したシステム開発プロジェクトでの人数規模は？", "short": "人数規模", "years": false,
     "items": [{"name": "5人未満"}, {"name": "5人〜29人"}, {"name": "30人〜99人"}, {"name": "100人以上"}]}
  ]
}
```

- pmpl の項目も、既存の形にそろえて `"years": false, "other": false` を書く（上は省略）。
- 業界 26 件（この順。全角「／」で区切った原文どおり）：IT・インターネットサービス、業界横断、化学/素材、原料/金属/石油、日用品、インテリア、アパレル、繊維/電気、自動車/輸送機器、食品/飲料/資料、医薬品/福祉、人材、教育、金融/保険、建設、不動産、インフラ（電力・ガス・水道など）、コンサルティング、音楽、サービス、小売、趣味、機械/物流、マスコミ/メディア、エンターテインメント、公共/官公庁
- 「〜」は依頼文の文字（U+301C）のまま。

## 4. プレーヤーごとに見えるもの・できること

| プレーヤー | 業界・PM/PL を直せる相手 | 見える場所 |
|---|---|---|
| 管理者 | 全 EG（仮登録中は今どおり直せない） | 詳細・スキルシート・Excel。一覧の列には出ない |
| SES | 自社の EG（参加中・停止中） | 同上（自社の EG だけ） |
| 担当者 | SES と同じ（`role=ses`） | 同上 |
| EG | 本人だけ | 同上（本人だけ）。「案件に合わせて印」は新しい2節に印を付けない |

- 上位が直すと、今どおり `W::editedBy` で本人の「確認しました」がやり直しになる。通知・メールは足さない（今のスキル保存と同じ。記録 `skill-save` の件数に含まれる）。
- 0件：業界も PM/PL も未回答なら、詳細・シートにその節は出ない（今の「空の節は出さない」）。

## 5. 作業の手順（implementer）

1. **JSON**：3章の2節を足す。
2. **`SkillForm`**：
   - `load`：節の `match`（既定 true）・`single`（既定 false）・`showIf` を `tree` の節に持たせる。項目の node に `match`・`single`・`none`・`short`（グループの親だけ）を持たせる。`match:false` の項目は `words=[]`（`make` に引数を足す）。
   - 検査（例外）：`single` の節は `groups` だけで、グループの `years` は false、項目に `other:true` が無いこと。`showIf` の各項目が `byPath(section,'',item)` でチェックできる項目として見つかること。`none:true` は single の節だけ。
   - `SHORT` に `industry`・`pmpl` を足す。
   - 足す関数：`matchable(string $section): bool`、`showIfIds(string $sectionKey): array`（条件の項目の id。条件が無ければ空）、`singleGroups(): array`（[グループの id => [選択肢の id…]]）。
3. **`SkillChecks::save`**：
   - 検査（DB に書く前）：`one` は配列でなければ無視でなく bad。各値は、知らないグループの id なら無視、値が文字でない・そのグループの選択肢の id でなければ `['bad' => '選択肢から選んでください。']`。空・null は未回答。
   - 行づくり：`single` の節の項目は今のループで飛ばし（`chk`・`yr` を見ない）、別に `one` から作る。条件（`showIf` の id のどれかが `isOn` 相当＝`!empty($chk[id]) || $yr[id] !== ''`）を満たさなければ作らない。`none` の項目は作らない。
   - 入れ替えは今のまま（pmpl・industry の行も「今の JSON でチェックできる項目の行」なので、毎回消して入れ直す → A5）。
4. **`SkillChecks::state`・`stateFromInput`**：状態に `one`（[グループの id => 選択肢の id]）を足す。`state` は single の行で `one` と `chk` の両方を立てる（件数の数え方を共通にするため）。同じ設問に行が2つあれば、id の小さいほう。`stateFromInput` は `one` を受け、選択肢として正しいものだけ `one` と `chk` に入れる。`EngineerController::skills` の `old()` に `'one' => old('one')` を足す。
5. **`SkillChecks` の読み出し**：`indexMany`・`usedPeople`・`summaryMany` で `!SkillForm::matchable($r->section)` の行を飛ばす（D3・D4）。`forDisplay` のグループの名前は `short ?? item`。`caseMarks` は words が空なので変えなくてよい（テストで確かめる）。
6. **`Qa::sheetCheck`**：`$skills` を `matchable` の節の行だけに絞ってから数える（A11）。
7. **画面 `skills.blade.php`**：
   - 節の繰り返しで、`single` の節は別の描き方：設問の文（太字）＋選択肢のラジオ `<input type="radio" name="one[gid]" value="cid">`（`$state['one'][gid]` と同じなら checked。未回答で `none` の選択肢があればそれを checked）。
   - `showIf` がある節は `data-sf-showif="roles-0,roles-2"`（実際の id は `showIfIds`）を付け、条件を満たさなければ `hidden` を付け、中のラジオに `disabled`。
   - 節の中に「PM・PL のチェックを外して保存すると、ここの回答は消えます。」を muted で出す。
   - 件数の文：single の節は「（n 件回答）」。PM/PL の節は出ていれば `open`。
   - 説明文（70行）の「年数を選んでください」の後ろに「業界は当てはまるものすべて、PM/PL の設問は1つずつ選んでください。」を足す。
8. **`public/js/park.js`**：役割の節の `data-sf` のチェック・年数が変わったら、`[data-sf-showif]` の節ごとに、書かれた id のどれかが「チェックあり」（チェックが付いている、または同じ行の年数が空でない）かを見て、`hidden` と中のラジオの `disabled` を切り替える。読み込み時にも1回。「すべて開く／閉じる」は隠れた節にも効いてよい（隠れたまま）。
9. **詳細・シート・Excel**：コードの変更は無い想定（`forDisplay`・`linesOf` が出す）。見た目を確かめる。シートの `$hit` は words が空なので強調されない。
10. **バージョン**：`config/park.php` を `v0.4.1`。`V030RegressionTest` の `v0.4.0` を直す。
11. **README**：「## スキルのチェック式」に v0.4.1 の段落（2節・`match`／`single`／`showIf`／`short`／`none` の意味、`one[]` の入力、条件を満たさない回答は捨てる、マッチング・一覧の列・完成度から外す）。
12. **マニュアル**（resources/manuals）：
    - `engineer.md`「### スキル」：業界（当てはまるものすべて）と、PM・PL を選んだときだけ出る3問、PM・PL を外して保存すると回答が消えること、案件の点数には使わないこと。
    - `engineer-steps.md` 3-3 の2：手順に「業界にチェック」「PM・PL なら3問を選ぶ」を足す。
    - `ses.md` 82行・`ses-steps.md` 59行・`admin.md` 120行：同じ趣旨を1〜2文（SES・担当者・管理者が直すときも同じ）。
    - 04_Manuals の写し（`41_Park-Project/04_Manuals` の 管理者・SES・エンジニア の `マニュアル.md`・`操作手順書.md`）は、park を push したあと Leo（または park-manager）が写し直す。implementer は park の中だけ直す。
13. `php artisan test` が全部通ることを確かめる。

## 6. テスト（`tests/Feature/SkillQuestionsTest.php` を新しく作る。既存の直し：`SkillFormJsonTest` の節の一覧を9つに）

立場は今のテストのやり方（EG 本人・SES・担当者・管理者のユーザーを作ってログイン）に合わせる。画面は `GET /engineers/{id}/skills`、保存は `POST /engineers/{id}/skills`（`_sf=1`・`_sf_end=1` を付ける）。

| 番号 | 条件 | 手順 | 確かめること |
|---|---|---|---|
| T1 | A1・A2 | `SkillForm::tree()` | 節の key が `roles…dev, industry, pmpl` の順。industry の項目が3章の26件と同じ並び、どれも `years=false`・`other=false` |
| T2 | A1 | EG で画面を開く | 「システム開発経験のある業界は？」と「IT・インターネットサービス」が出る。業界の行に年数のプルダウンが無い |
| T3 | A1 | EG で業界を2つ `chk` して保存 | `engineer_skill_checks` に `section=industry` の行が2つ（`years_min` null） |
| T4 | A3・A4 | PM 無しで画面を開く／PM（年数だけ `yr`）を保存してから開く | 前は pmpl の節に `hidden`、後は `hidden` が無く「PM/PLご経験者の方へ」と3問・ラジオが出る |
| T5 | A3 | PL を chk、`one` に3問を1つずつ送って保存 | `section=pmpl` の行が3つ、`parent` が設問の文、`item` が選んだ文 |
| T6 | A5・A6 | T5 のあと、PM・PL を外し `one` を付けたまま保存 | pmpl の行が0（エラーにならない） |
| T7 | A6 | PM・PL 無しで `one` だけ送る（JS 無しの想定） | pmpl の行が0、保存は成功 |
| T8 | A7 | `one[pmpl-0]` に別の設問の選択肢の id／知らない文字／配列 を送る（それぞれ） | 「選択肢から選んでください。」で戻り、DB は前のまま（他の節の行も変わらない） |
| T9 | A7 | `chk[pmpl-0-1]`・`chk[pmpl-0-2]` を両方送る（PM あり、`one` 無し） | pmpl の行が0（`chk` は無視） |
| T10 | A8 | `one[pmpl-1]` に「選択しない」の id | pmpl の行が増えない。画面を開き直すと「選択しない」に checked |
| T11 | A9 | 業界「インフラ（電力・ガス・水道など）」「サービス」「金融/保険」と PM/PL の回答だけを持つ EG と、必須「インフラ、サービス、金融」の案件 | `SkillChecks::indexMany` が空、`Matcher::score` の必須の一致が0、`caseMarks` で industry・pmpl の id に印が無い、`usedPeople` にその人が無い |
| T12 | A9 | `SkillForm::normIndex()`／`SkillForm::place('インフラ', 'infra', 2)` | normIndex に industry・pmpl の id が無い。place の置き場所が industry でない |
| T13 | A10 | 業界と PM/PL を保存した EG の詳細・スキルシート（`?anon=1` も）・`SkillChecks::lines` | 「業界」「PM/PL の経験」「期間：1年〜3年」が出る |
| T14 | A10・D4 | 管理者 `/admin/engineers`・SES `/ses/engineers` | スキル列に業界名が出ない。`?q=アパレル` で、その EG が出ない |
| T15 | A11 | 技術3つ＋業界5つの EG の `Qa::sheetCheck` | 「5つ以上チェック」の指摘が出る |
| T16 | A12 | SES・担当者が自社 EG の業界を保存／他社 EG に POST／管理者が保存 | 自社は成功、他社は今どおり拒否（403/404）、管理者は成功。上位が保存すると本人の確認がやり直し |
| T17 | A13 | 既存のスキルテスト一式 | 変えずに通る（`SkillFormJsonTest` の節の一覧だけ直す） |
| T18 | D2 | JSON の検査 | `showIf` に無い名前を書いた JSON・single の節に `years:true` のグループ、で例外になる（テスト用に一時ファイルを読ませる形が難しければ、`SkillForm` に検査用の関数を分けてそれを呼ぶ） |
| T19 | 境界 | JSON から pmpl の選択肢を1つ消した想定（保存済みの行の `item` を存在しない文に書き換える） | 画面の「今の表に無い項目」に出る。その行はマッチング・一覧に出ない（D3 の節名での除外） |
| T20 | A14 | `/login` | `v0.4.1` が出る |

- JS（A4 の「その場で出たり隠れたり」）は自動テストが無いので、reviewer がコードを読み、Leo が画面を開いて確かめる（PM を付ける→出る、外す→隠れる、付け直す→選んでいたものが残る）。

## 7. 確認事項（TEZZO に聞く。答えが来るまで原文どおりで作る）

- **Q1**：業界の「食品/飲料/資料」は「食品/飲料/飼料」の誤りではないか（よくある業界の分け方では「飼料」）。
- **Q2**：区切りの読み方。「アパレル」「繊維/電気」、「インテリア」「アパレル」などが別々の項目でよいか（「アパレル/繊維」「電気/…」のつもりではないか）。「趣味」「音楽」は単独の業界でよいか。
- **Q3**（仮置き D4）：管理者の一覧で、業界で EG を探せなくてよいか。

## 8. 了承が要ること

- 今回はマイグレーション・データの削除・メールの送信は無い。公開版への反映は常時了承の範囲（`park-deploy.sh`）。
- 本番で、PM・PL を外して保存した人の回答が消えるのは仕様（A5）。過去のデータを一括で消す処理は無い。
