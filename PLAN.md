# PLAN：EG検索の結果をプロパー／パートナーに分け、エンジニア・担当者へのメール作成画面を作る（v0.16.0。2026-11-23）

> **これが今の計画**。前の計画（v0.15.0 以前）は、このファイルを書き直す前の版にある：`git show b062dfc:PLAN.md`（計画担当は書き足す道具を持たないため、末尾に残せなかった。Leo が `git show b062dfc:PLAN.md >> PLAN.md` で末尾に足して commit する）。
> 要求：TEZZO さん（2026-11-23）EG検索の結果を「プロパー（自社の EG）」「パートナー（他社の EG）」に分ける。プロパーには直接案件メール、パートナーはその会社の担当者に PARK の通知＋メール。パートナーの表示は「イニシャル（企業名[担当者名]）」。「全員へのメッセージ」を消す。「エンジニアを追加」→「エンジニア・担当者を追加」。送信ボタン →「エンジニア・担当者へメールを作成」。作成画面は、先にプロパーへ（左＝本文を直せる、右＝宛先とボタン、金額の確認）、次に担当者へ1人ずつ（チェックで同じ条件の担当者にまとめて送れる。未送信が残れば次の担当者の画面）。
> プレーヤー：管理者・SES・担当者・EG の4つ。アプリでは SES と担当者はどちらも `users.role=ses`（権限は同じ）。この版でも SES と担当者は同じ扱い。
> 外部のライブラリは足さない。名前・メール・合言葉はこのファイルにもテストにも書かない（テストは架空の名前、`@park.example`）。
> 作る順：段階1 → 6（段階ごとに `php artisan test` を通して commit）→ park-reviewer → park-tester。commit・push・公開版への反映は Leo。**本物のアドレスへの送信の試しは TEZZO さんの了承を取ってから**。

## 0. コードで確かめた今の形

- 一覧：`CaseSearchController::index` → `cases/search-list.blade.php`。1つの表に自社 → 他社の順（`CaseSearch::sortRows`）。名前は `CaseSearch::displayName`（管理者・自社＝氏名、他社＝イニシャル。後ろに「（所属の会社名）」）。他社の行の年齢・価格・最寄り駅は「—」。下に「全員へのメッセージ」（`case_searches.message_all`）、「エンジニアを追加」（`findEngineers`：自社は氏名・愛称・イニシャル、他社はイニシャルで探す）、「メールアドレスを追加」（`case_search_extras`）、［エンジニアへメールを送信］（`POST /searches/{sid}/send`）。
- 送信：`CaseSearch::send` → `CaseMail::send`。**他社の EG にも PARK から直接メールを送っている**（`case_mails.route='group'`、本文はイニシャルで呼ぶ、所属の SES にはお知らせ `offer-ses`）。本文は1人ずつ `CaseSearch::body`（`{全員へのメッセージ}`・`{合う点}`・`{個別メッセージ}`・確認事項の節）。
- **プロパー／パートナーの判定の根拠**：EG が「SES の直属か」を表す列は**無い**（`ses_companies.kind='direct'` は v0.11.0 で廃止した管理者の直属で、別のもの）。今の「自社」は `engineers.ses_id === users.ses_id`（`CaseSearch::pool` の `own`、`CaseMail::candidates` も同じ）。この版では **プロパー＝`engineers.ses_id` が操作している人の `users.ses_id` と同じ EG、パートナー＝それ以外** とする。列は足さない。
- **EG ごとの担当者を表す列も無い**。会社の担当者の集まりは `Notifier::sesUsers`（role が ses か、その会社を兼ねる admin。参加中・本登録済み）。会社の SES（1社1人）は `ses_companies.rep_user_id`。EG のアカウントを登録した人は `users.registered_by`。
- 権限：検索を使える＝`CaseMail::canSend`（受注 SES の SES・担当者だけ）。開ける＝作った人と同じ会社（`CaseSearch::canOpen`、管理者は不可）。案件の画面は SES ならグループの全案件を見られる（`Perms::caseView`）。受注 SES ではない SES には「エンジニアとの相性」と［推薦する］（`CaseController::recommend`）が出る。
- 受信：`inbound_mails.ses_id` は案内メールの `case_mails.ses_id`。お知らせは `toSes(inbound.ses_id)`。ただし SES が `/inbound` で見られるのは「案件の今の受注 SES が自社」の行だけ（`Inbound::query` の `c.source_ses_id`）。
- やり取り：相手の会社は `CaseThread::partnerFor`（受注 SES が所属と違えば受注 SES）。
- プロバイダー宛の下書き：`ProviderReply::draftFromInbound` が `inbound.ses_id` の会社で作る。直せるのは受注 SES だけ（`canEdit`）。
- 金額：案件の金額（`cases.price`＝プロバイダーからの金額）が、EG の画面にもそのまま出ている（`cases/show` の応募の確認「金額（…）で問題ない」、`_card_case` の金額、`eg/offers`・`eg/home` の一覧）。案内メールの本文の「■単価：」も案件の金額。
- 版：`config/park.php` の `version` は `v0.15.0`。

## 1. 受け入れ条件

| 番号 | 立場 | 条件 |
|---|---|---|
| A1 | 受注 SES の SES・担当者 | 「エンジニアを選択」の一覧が「プロパー（自社のエンジニア）」と「パートナー（他社のエンジニア）」の2つの表に分かれる。並びは今の決まり（当たった必須の月数 → h → 点数 → id） |
| A2 | 同上 | プロパーの行は今どおり氏名・EG-・年齢・価格・最寄り駅が出る。パートナーの行は「イニシャル（企業名[担当者名]）」で、年齢・価格・最寄り駅は「—」、EG- は出ない |
| A3 | 同上 | パートナーの行の担当者は 3.2 の決め方で1人に決まる。担当者が決まらない会社の行は「担当者がいません」と出てチェックできない |
| A4 | 同上 | 「全員へのメッセージ」の欄が無い（列 `message_all` は残すが、画面にもメールにも使わない） |
| A5 | 同上 | カード名が「エンジニア・担当者を追加」。名前で探すと、自社のエンジニア（氏名・愛称・イニシャル）と、他社の担当者（企業名・担当者の氏名）が出る。自社のエンジニアは「一覧に足す」でプロパーの表に、他社の担当者は「一覧に足す」でパートナーの表に「（エンジニアの指定なし）企業名[担当者名]」の行で入る。他社のエンジニアはここでは探せない |
| A6 | 同上 | 送信ボタンが「エンジニア・担当者へメールを作成」。押すとチェックを保存して作成画面へ。何も選んでいないと一覧に戻り「送る相手を選んでください。」 |
| A7 | 同上 | 「すべてを選択」はプロパー・パートナー・追加の宛先の全部のチェックに効く（送信済み・依頼済み・担当者がいない行は除く） |
| B1 | 同上 | 作成画面（プロパー）：左に件名と本文（直せる）、右に送るプロパーの一覧（と追加の宛先）、その下に［エンジニアへメールを送信］。本文の「■単価：」ははじめプロバイダーからの金額 |
| B2 | 同上 | ［エンジニアへメールを送信］で確認ダイアログが出る（6.3 の文）。金額の行を変えていないときは、変えていないことをはっきり書く。OK で送る。キャンセルで何も送らない |
| B3 | 同上 | 送ると、チェックしたプロパー全員と追加の宛先に、直した本文で1通ずつ届く（`{呼び名}`・`{合う点}`・`{個別メッセージ}` は1人ずつ差し替わる）。`case_mails.price_text` に送った単価の文が残る |
| B4 | 同上 | 送ったあと、未送信の担当者がいれば担当者の作成画面へ、いなければ案件の画面（#mails）へ戻り、何名に送ったかが出る |
| B5 | 同上 | プロパーを選んでいないときは、作成画面は担当者から始まる |
| C1 | 同上 | 作成画面（担当者）：左に件名と本文（直せる。宛名と依頼するエンジニアは差し込み）、右に未送信の担当者の一覧。各行の左にチェック、一番上の1件だけにチェックが入っていて外せない。一覧の上に「すべてを選択」、一覧の下（ボタンの上）に「同じ条件で送信する際はチェックを入れてください」 |
| C2 | 同上 | ［担当者へメールを送信］で確認ダイアログ（6.3）。OK でチェックした担当者全員に、同じ本文（宛名と依頼するエンジニアだけ差し替え）で送る。`case_search_staff.price_text` に単価の文が残る |
| C3 | 同上 | 送ったあと、一覧に未送信の担当者が残れば、次の担当者（残りの一番上）の作成画面が出る。残らなければ案件の画面（#mails）へ |
| C4 | 同上 | ［この担当者には今回は送らない］で、その担当者を外して次へ進める |
| C5 | 同上 | 途中で画面を離れても、「エンジニアを選択」の上に「まだ送っていない担当者が n 名います」と［続きを作成する］が出る |
| D1 | パートナーの担当者 | PARK のお知らせ（ベル）に「エンジニアの紹介の依頼：案件名［CA-…］」が出て、押すと案件の画面の「紹介の依頼」（#referral）が開く。メールも1通届く（6.4 の見え方） |
| D2 | 同上 | 「紹介の依頼」に、依頼元（受注 SES の企業名・担当者名）、伝えられた単価（`price_text`）、届いた本文、依頼されたエンジニア（自社の人なので氏名。詳細へのリンク・案内済みかどうか）が出る。プロバイダーからの金額はここには出さない |
| D3 | 同上 | ［自社のエンジニアを検索する］で、依頼元の検索の条件をもとに検索し、「エンジニアを選択」が開く。**対象は自社のエンジニア（その担当者のプロパー）だけ**。依頼されたエンジニアにはチェックが入っている。パートナーの表・他社の担当者の追加・メールアドレスの追加は出ない |
| D4 | 同上 | そこから作成画面（プロパーだけ）へ進み、送れる。本文の「■単価：」は、はじめから伝えられた単価の文。送った案内メールは `case_mails.mode='partner'`、`ses_id` は自社 |
| D5 | 同上 | ［今回は紹介できる人がいません］（ひとこと任意）で辞退を記録し、依頼元の担当者にお知らせが届く |
| D6 | 同上 | 自社から送った案内メールへの EG の返信を、`/inbound` で見られ、お知らせが届く。案件のやり取りの相手は自社 |
| D7 | 受注 SES の SES・担当者 | パートナー経由で送られた案内メールへの返信の本文は、受注 SES には見えない。案件の画面の「案内済メール」に「担当者への依頼」の表（企業名[担当者名]・送った日・状態＝依頼中／辞退・その会社から案内した人数）が出る |
| E1 | EG | 案件の画面・届いた案件の一覧・ホームの金額は、自分に届いた案内メールの単価の文（`case_mails.price_text`）。プロバイダーからの金額は出ない。応募の確認も「金額（伝えられた単価）で問題ない」 |
| E2 | EG（パートナーのプロパー） | 自社の担当者から届いた案内メールは、今の自社あての案内と同じ形（氏名・愛称で呼ぶ、返信は自社の担当者と PARK に届く） |
| E3 | EG（パートナー） | 受注 SES から PARK 経由で直接メールが届くことは無くなる（検索から送るとき） |
| F1 | 管理者 | 案件の画面の「担当者への依頼」を読むだけで見られる。検索・作成画面は今どおり開けない（403） |
| F2 | ほかの SES（依頼を受けていない会社） | 「紹介の依頼」は出ない。`/searches/...` を開けない |
| G1 | システム | 同時に2回押しても、同じ EG・同じ追加の宛先・同じ担当者（同じ案件）に2通出ない。送った行は「送信済み」で選べない |
| G2 | システム | メールを「保存のみ」にしているときは記録だけ残る（画面に「保存のみ」） |
| G3 | システム | 送信・依頼・辞退は監査に残る（アドレスは書かない） |
| G4 | 全員 | 今のテストが通る（「全員へのメッセージ」・他社 EG への直接送信・`/searches/{sid}/send` を見ていたテストは新しい形に直す。8章） |

## 2. データ（マイグレーション1本、足すだけ）

`database/migrations/2026_11_23_000001_partner_requests.php`。外部キーは付けない（今までどおり）。`down()` は足したものを消すだけ。

- 新しい表 `case_search_staff`（他社の担当者への紹介の依頼。1行＝検索×担当者）
  - `id`、`search_id`（index）、`case_id`（index）、`ses_id`（依頼する側の会社）、`created_by`
  - `partner_ses_id`（index。依頼先の会社）、`staff_user_id`（index。依頼先の担当者 users.id）
  - `engineer_ids`（text null。依頼するエンジニアの id の JSON 配列。手で足した担当者だけなら空）
  - `by_hand`（boolean 既定 false。「エンジニア・担当者を追加」で足した）、`checked`（boolean 既定 true。一覧のチェック）
  - `in_batch`（boolean 既定 false。作成画面の送る順番に入っている）
  - `subject`・`body`（text null。下書き／送った本文）、`price_text`（string 100 null。送った単価の文）
  - `sent_at`・`sent_by`・`outbox_id`（null 可）
  - `declined_at`（null 可）、`declined_note`（text null）、`declined_by`（null 可）
  - `created_at`・`updated_at`。`unique(search_id, staff_user_id)`
- `case_searches` に足す：`proper_subject`（text null）、`proper_body`（text null）＝プロパーの下書き。`request_id`（unsigned big int null）＝パートナーが紹介の依頼から始めた検索なら、その `case_search_staff.id`
- `case_mails` に足す：`price_text`（string 100 null）。マイグレーションで、今ある行の `body` から「■単価：」の行を読んで埋める（見つからなければ空のまま）

## 3. 決めたこと（細部。理由つき）

### 3.1 プロパー／パートナー
- プロパー＝`engineers.ses_id === 操作している人の users.ses_id`。パートナー＝それ以外。理由：直属を表す列が無く、今の「自社」の判定（`own`）がこれ。新しい列を足すと、所属変更（Affiliation）と二重管理になる。
- `CaseSearch::pool` の `own` をそのまま使い、行に `kind`（`proper`／`partner`）を足すだけにする。

### 3.2 パートナーの担当者の決め方（`CaseSearch::staffFor(object $e): ?object`）
上から順に、最初に当たった1人。どれも `Registration::usable` で使える人だけ、role が ses か（その会社を兼ねる）admin、`ses_id` が EG の会社と同じ人。
1. その EG のアカウント（role=eg）を登録した人（`users.registered_by`）
2. その会社の SES（`ses_companies.rep_user_id`）
3. その会社の担当者のうち id が一番小さい人
- 理由：EG ごとの担当者の列が無い。登録した人が一番その EG を知っている。SES は1社に必ず1人いる。
- 誰もいなければ null（A3）。担当者を EG ごとに決める欄は、この版では作らない（11章の仮置き 1）。

### 3.3 表示
- パートナーの行：`イニシャル（企業名[担当者名]）`。イニシャルが空なら「—」。担当者名は `users.name`（姓・名をつないだもの。人名の入力欄はこの版で増えない）。
- 手で足した担当者の行：`（エンジニアの指定なし）企業名[担当者名]`。点数・マッチの列は「—」。
- 管理者はこの画面を開けない（今どおり）ので、管理者向けの出し分けは要らない。
- 詳細画面（`/searches/{sid}/members/{eid}`）でパートナーの人を開いたとき：○×と確認メモは今どおり。個別メッセージとメールの見本は出さず、「この方には直接メールを送りません。企業名[担当者名] に紹介を依頼します。」と出す。

### 3.4 一覧のチェックと送る相手
- プロパー：今の `case_search_members.checked`。
- パートナー：同じく `case_search_members.checked`。作成画面へ進むとき、チェックしたパートナーの EG を担当者ごとにまとめ、`case_search_staff` の行（`engineer_ids`）にする。
- 手で足した担当者：`case_search_staff.checked`（`spick[]`）。
- 「依頼済み」：この案件で、その EG を含む送信済みの `case_search_staff` がある、またはその担当者に送信済み。チェックできない。
- 同じ担当者に、別の検索（同じ会社の別の人）から送信済みなら、その担当者の行は「依頼済み（日付）」でチェックできない（G1）。

### 3.5 作成画面の流れ（`/searches/{sid}/compose`）
- `POST /searches/{sid}/compose`（一覧の新しいボタン）：チェックを保存（`persist`）→ 「メールアドレスを追加」に書いたまま足していなければ今どおり止める → 担当者の束を作り直す（`CaseSearch::buildBatch`：未送信の `case_search_staff` の `in_batch` を全部 false にしてから、チェックした分を作る／更新して true。並びは `id` 順）→ プロパー・追加の宛先・束のどれも無ければ一覧へ戻す（A6）→ `GET /searches/{sid}/compose` へ。
- `GET /searches/{sid}/compose`：
  1. チェックした未送信のプロパーか追加の宛先があれば「プロパー」の画面。
  2. 無ければ、束（`in_batch` かつ未送信、辞退でない）の一番上の担当者の画面。
  3. どちらも無ければ `/cases/{id}#mails`（「送る相手は残っていません。」）。
- `POST /searches/{sid}/compose/proper`：件名・本文を受け、下書き（`proper_subject`・`proper_body`）に保存してから送る → `GET compose` へ（B4）。
- `POST /searches/{sid}/compose/staff/{stid}`：`stid`（一番上）と、チェックした `others[]` に送る → `GET compose` へ（C3）。
- `POST /searches/{sid}/compose/staff/{stid}/skip`：`in_batch=false` にして `GET compose` へ（C4）。
- ［下書きを保存］（どちらの画面にも）：送らずに保存して同じ画面へ。
- 古い `POST /searches/{sid}/send` は、`compose` と同じ処理にして作成画面へ移す（ブックマーク・古い画面からでも直接送らない）。
- 画面の上に「1. プロパーへ → 2. 担当者へ（残り n 名）」の段の表示。

### 3.6 本文の作り方
- 本文は1つの共通の文（作成画面の左で直す）。案件の項目（案件名・ID・単価・開始・場所・必須・尚可・確認事項の節）は、はじめから文字にして入れる（直せるように）。1人ずつ変わる所だけ差し込みのまま残し、欄の下に説明を出す。
  - プロパー：`{呼び名}`・`{合う点}`・`{個別メッセージ}`（空なら、その行ごと消す。今の `fill` と同じ）
  - 担当者：`{宛名}`（「企業名 氏名」）・`{依頼するエンジニア}`（イニシャルを「・K.A」の形で1行ずつ。指定なしなら「（指定なし。貴社のエンジニアからお探しください）」）
- 雛形：`CaseSearch::properTemplate($u, $c, $s)`（今の `defaultBody` から `{全員へのメッセージ}` を外し、案件の項目を文字にしたもの）、`CaseSearch::staffTemplate($u, $c, $s)`（6.4）。
- 単価の文：本文の最初の `^■単価：(.*)$` の行の中身（前後の空白を取る、100字まで）。無ければ null。プロパーの雛形の単価は、プロパー（受注 SES）なら案件の金額（`man($c->price)`、無ければ「相談」）、パートナーの検索（`request_id` あり）なら依頼の `price_text`（無ければ「相談」）。担当者の雛形の単価は案件の金額。
- 確認事項の節：プロパー（自社）あてなので、返信で答えてもらう形（`confirmBlock($names)`）。
- 送るときに本文の後ろに付けるもの（返事のリンク・案件の画面・システム伝達項目）は、今の `CaseMail::send` のまま。
- 追加の宛先には、プロパーと同じ本文を使う（`{呼び名}`＝名前か「ご担当者」、`{合う点}` と `{個別メッセージ}` の行は消す。後ろに付けるものは今の `sendExtras` のまま）。
- 件名：プロパーは今の `【案件のご案内】案件名`、担当者は `【紹介のお願い】案件名`。どちらも直せる（100字まで）。

### 3.7 担当者への送信（`CaseSearch::sendStaff($u, $c, $s, array $staffIds, string $subject, string $body): array`）
- 案件の行を `lockForUpdate` → 1行ずつ：使えない担当者・辞退済み・送信済み・同じ案件で同じ担当者に送信済み（ほかの検索）は飛ばす → `update ... where sent_at is null` で先に印を付ける（0行なら飛ばす）→ メール（`N::queueMail`、kind `case-referral`、Reply-To なし、差出人名は付けない）→ お知らせ（`N::notify(staff_user_id, …, mail=false, kind 'case-referral', forRole 'ses')`）→ 行に `subject`・`body`・`price_text`・`outbox_id`・`in_batch=false`。
- Reply-To を付けない理由：他社の人に個人のアドレスを出さない（連絡メールの `DirectMail::replyToAllowed` と同じ決まり）。返事は PARK の画面で受ける。
- 戻り：`['sent' => n, 'skipped' => n]`。監査 `case-referral-send`（「案件コード → 担当者 n 名」。名前・アドレスは書かない）。
- 依頼されたエンジニア本人と、その会社のほかの担当者には知らせない（依頼は担当者にだけ。担当者が選んで案内する）。

### 3.8 パートナーの担当者の側
- 「紹介の依頼」カード（`resources/views/cases/_card_referral.blade.php`、id="referral"）：受注 SES ではない SES・担当者の案件画面（今の配置）の右の一番上。出す条件＝この案件で、`partner_ses_id` が自社で送信済みの `case_search_staff` がある。**会社の誰が開いても見える**（依頼先が1人でも、休みなどで止まらないように）。お知らせとメールは依頼先の1人だけ。
- 依頼が2件以上（別の担当者・別の受注 SES の人から）あれば、新しい順に並べる。
- ［自社のエンジニアを検索する］：`POST /cases/{id}/referral/{stid}/search` → 依頼元の検索の `conditions` を写して、自分の検索（`case_searches`、案件×作った人で1つ）を作る／書き直す（`request_id` を入れる）→ 依頼されたエンジニア（自社で今も対象の人）を `by_hand` でメンバーに足しチェック → `/searches/{sid}`。
- パートナーの検索での決まり（`case_searches.request_id` があり、作った人が受注 SES ではない）：`pool` は自社のエンジニアだけ。「エンジニア・担当者を追加」は自社のエンジニアだけ。「メールアドレスを追加」と担当者の束は出さない／作らない。送る `mode` は `partner`。
- `CaseSearch::canUse($u, $c)`：今の `CaseMail::canSend` に加えて「自社あての送信済みで辞退していない依頼がある」SES・担当者も true。
- ［今回は紹介できる人がいません］：`POST /cases/{id}/referral/{stid}/decline`（ひとこと任意、500字まで）。`declined_*` を入れ、依頼した人（`sent_by`）にお知らせ（メールも。kind `case-referral-decline`）。辞退のあとはカードに「辞退しました（日付）」、検索のボタンは出さない。辞退の取り消しはこの版では作らない。
- 今の［推薦する］はそのまま残す（紹介の依頼が無い案件でも使えるため）。

### 3.9 返信・やり取り・下書き（パートナーから送った案内）
- `Inbound::query`（SES）：「受注 SES が自社 かつ 案内メールが `mode='partner'` ではない」行、または「`i.ses_id = 自社` かつ `mode='partner'`」の行（実装どおり。`Inbound.php` 355〜356行）。理由：パートナーのプロパーの返信は、その会社の中の話。受注 SES にはパートナーの担当者が推薦で伝える。v0.12.0 の W4（受注 SES が変わると過去の返信も新しい会社に見える）は、パートナー経由でない行では今のまま。
- お知らせ：今どおり `inbound.ses_id`（＝パートナーの会社）に出る。今の `notify` の「自社の EG」の分かれ道に入るので、氏名つきで出る。
- `CaseThread::partnerFor`：その EG あての `case_mails` が `mode='partner'` なら、所属の会社。
- `ProviderReply::draftFromInbound`：案内メールが `mode='partner'` なら下書きを作らない（プロバイダーとやり取りするのは受注 SES で、パートナーの会社は直せないため）。
- 受注 SES の「案内済メール」の表（`CaseController::show` の `mails`）：`mode='partner'` の行は出さない。代わりに「担当者への依頼」の表（D7）。管理者は全部を読むだけ。

### 3.10 金額の見せ方（EG）
- 理由：本文で金額を変えても、PARK の画面にプロバイダーからの金額が出ていると意味がないため。
- 関数 `CaseMail::priceFor(object $c, int $engineerId): string`：その EG あての `case_mails.price_text`（新しい行）→ 無ければ「担当者にご確認ください」。
- 使う所：`cases/show`（応募の確認の文）、`cases/_card_case`（EG のときの金額の行。金額のメモ `price_note` も EG には出さない）、`eg/offers`、`eg/home`。
- SES（受注 SES ではない会社も含む）・管理者の画面の金額は今のまま（11章の仮置き 2）。

## 4. 画面（ボタン・文言）

### 4.1 エンジニアを選択（`cases/search-list.blade.php`）
- 上の行：今どおり（案件 ID・金額・開始・最寄り駅）。パートナーの検索では金額を `price_text` にする。
- 束が残っているとき：「まだ送っていない担当者が n 名います。」［続きを作成する］（`GET /searches/{sid}/compose`）。
- 「すべてを選択」→ 表「プロパー（自社のエンジニア）」→ 表「パートナー（他社のエンジニア）」（パートナーの検索では出さない）。列は今と同じ。パートナーの表のチェックの欄：送信済み＝「案内済み」、依頼済み＝「依頼済み」、担当者なし＝「担当者がいません」。
- 「全員へのメッセージ」の欄は消す。
- カード「エンジニア・担当者を追加」：説明「名前で探して一覧に足せます。自社のエンジニアは氏名・愛称・イニシャル、他社は企業名か担当者の氏名で探します。」結果の表は「種類（エンジニア／担当者）・表示名・［一覧に足す］」。
- 「メールアドレスを追加」は今どおり（パートナーの検索では出さない）。
- ボタン：［エンジニア・担当者へメールを作成］（`formaction=/searches/{sid}/compose`）、［チェックを保存］（今の「チェックとメッセージを保存」から改名）。確認ダイアログは出さない（作成画面で出す）。

### 4.2 作成画面（`cases/search-compose.blade.php`、2列 `cols`）
- プロパー：左＝件名・本文（textarea 24行くらい）・差し込みの説明・［下書きを保存］。右＝「送るエンジニア（n 名）」の表（名前・EG-）、追加の宛先（名前・アドレス）、［エンジニアへメールを送信］、［エンジニアを選択に戻る］。
- 担当者：左＝同じ形。右＝「担当者（残り n 名）」。上に「すべてを選択」、各行にチェック＋「企業名[担当者名]」＋依頼するエンジニアのイニシャル。一番上はチェック済みで外せない（`disabled` と hidden）。下に「同じ条件で送信する際はチェックを入れてください」、［担当者へメールを送信］、［この担当者には今回は送らない］、［エンジニアを選択に戻る］。
- 本文の textarea に `data-price`（雛形の単価の文）を持たせ、確認ダイアログで今の「■単価：」の行と比べる。
- 送信のボタンは押したら無効にする（二度押し防止。サーバー側でも G1）。

### 4.3 確認ダイアログの文（JavaScript の `confirm`）
- 単価の行が雛形のまま：
  `金額がプロバイダーからの金額（{元}）のままです。\nエンジニア向けの金額に変えましたか？\n\n［OK］このまま {n} 名に送信する\n［キャンセル］戻って直す`
  （担当者の画面は「担当者向けの金額」「{n} 名の担当者に送信する」）
  （パートナーの検索では「金額が伝えられた単価（{元}）のままです。エンジニア向けの金額に変えましたか？」）
- 変えた：`金額を「{元}」から「{今}」に変えました。\n{n} 名に送信します。よろしいですか？`
- 単価の行が無い：`本文に「■単価：」の行がありません。金額を書かずに {n} 名に送信します。よろしいですか？`

### 4.4 担当者に届くメールの見え方（雛形。実際の文字はテストの架空の名前）
```
件名：[PARK] 【紹介のお願い】{案件名} [PARK-xxxxxxxxxx は付けない]
{宛名} 様

お疲れさまです。{送り主}です。
PARK のグループの案件について、貴社のエンジニアのご紹介をお願いします。

■案件：…
■案件 ID：CA-…
■単価：（担当者向けに直した金額）
■開始：…
■場所・働き方：…／…
■必須：…
■尚可：…

■ご紹介をお願いしたい方（貴社のエンジニア）
・K.A
・T.S

上の方への案内や、ほかの方の検索は、PARK の案件の画面からできます。
ここに書いた単価が、貴社から案内するときの単価になります。

▼案件の画面（紹介の依頼）
https://…/cases/{id}#referral
※このメールに返信しても届きません。ご返事（案内・推薦・辞退）は上の画面からお願いします。
（PARK の署名：今の queueMail のとおり）
```
- `{送り主}` は今の `fromName`（会社名 氏名）。

### 4.5 案件の画面
- パートナーの担当者：「紹介の依頼」カード（3.8）。
- 受注 SES：「案内済メール」の中に「担当者への依頼」の表（D7）。
- 管理者：同じ表を読むだけ。
- EG：金額（3.10）。

## 5. ルート（`routes/web.php`。今の `/searches` の並びに足す）

```
GET  /searches/{sid}/compose                     CSearch@compose
POST /searches/{sid}/compose                     CSearch@startCompose
POST /searches/{sid}/compose/proper              CSearch@sendProper
POST /searches/{sid}/compose/draft               CSearch@saveDraft        （kind=proper|staff, stid）
POST /searches/{sid}/compose/staff/{stid}        CSearch@sendStaff
POST /searches/{sid}/compose/staff/{stid}/skip   CSearch@skipStaff
POST /searches/{sid}/staff                       CSearch@addStaff         （手で担当者を足す）
POST /searches/{sid}/staff/{stid}/remove         CSearch@removeStaff      （未送信の手で足した行だけ）
POST /searches/{sid}/send                        → startCompose と同じ
POST /cases/{id}/referral/{stid}/search          CSearch@referralSearch
POST /cases/{id}/referral/{stid}/decline         CSearch@referralDecline
```
- どれも `loadSearch`（同じ会社）・`closed`（募集中だけ）を通す。`stid` は、その検索の行か（送る側）、自社あての行か（受ける側）を必ず確かめ、違えば 404。
- エンジニア（role=eg）は今どおりルートで止める。管理者は `canOpen` で 403。

## 6. 作業の手順（段階ごとに `php artisan test` を通して commit）

### 段階1：データと判定、一覧の分け方
1. マイグレーション（2章）。`case_mails.price_text` の埋め戻しを含む。
2. `CaseSearch`：`pool` の行に `kind`、`staffFor`、`staffLabel`（企業名[担当者名]）、`displayName` をパートナーは「イニシャル（企業名[担当者名]）」に。
3. `rows` を `['proper' => [...], 'partner' => [...], 'staff' => [...]（手で足した担当者）, 'failed' => n]` に。依頼済み・担当者なしの印。
4. `findEngineers` を `findTargets` に（自社のエンジニア＋他社の担当者。パートナーの検索では自社のエンジニアだけ）。`addStaff`・`removeStaff`。
5. `search-list.blade.php`：2つの表、全員へのメッセージを消す、カード名、ボタン名、`spick[]`。`persist` から `message_all` を外し、`spick[]` を足す。
6. `CaseSearch::body` から `{全員へのメッセージ}` を外す。
7. テスト（8章 T1〜T6）。

### 段階2：作成画面（プロパー）
1. `properTemplate`・`priceTextOf(string $body): ?string`・1人ずつの差し込み（`renderProper`）。
2. `startCompose`（＝`buildBatch` も呼ぶ）・`compose`（プロパーの分岐）・`sendProper`・`saveDraft`。`send` の古いルートを `startCompose` に。
3. `CaseSearch::send` を、共通の本文を受ける形に（`sendProper($u, $c, $s, string $subject, string $body)`）。自社のエンジニアだけに絞る（パートナーは送らない）。`CaseMail::send` に `price_text` を渡して保存。
4. `sendExtras` も同じ本文を受ける形に。
5. `search-compose.blade.php`（プロパー）と確認ダイアログ。
6. テスト（T7〜T12）。

### 段階3：作成画面（担当者）
1. `staffTemplate`・`renderStaff`・`sendStaff`（3.7）・`skipStaff`。
2. `compose` の担当者の分岐、一覧の「すべてを選択」・一番上の固定、「同じ条件で…」、残りの数、次への遷移。
3. 一覧の上の「まだ送っていない担当者」。
4. テスト（T13〜T19）。

### 段階4：パートナーの担当者の側
1. `canUse` の拡張、パートナーの検索の決まり（3.8）、`referralSearch`・`referralDecline`。
2. `_card_referral.blade.php` と `CaseController::show` の読み出し（受注 SES ではない SES・担当者だけ）。
3. `CaseMail::MODES` に `'partner' => '担当者経由'`。パートナーの送信は `mode='partner'`。
4. `Inbound::query`・`CaseThread::partnerFor`・`ProviderReply::draftFromInbound`・`CaseController::show` の `mails`（3.9）。
5. テスト（T20〜T27）。

### 段階5：受注 SES・管理者の「担当者への依頼」と、EG の金額
1. 「担当者への依頼」の表（`_card_mails` の中）。
2. `CaseMail::priceFor` と、EG の画面4か所（3.10）。
3. テスト（T28〜T32）。

### 段階6：マニュアル・版
1. `resources/manuals/ses.md`・`ses-steps.md`：「エンジニアを選択」（プロパー／パートナー、エンジニア・担当者を追加）、作成画面（金額を直す・確認・担当者を順に）、「紹介の依頼」を受けたとき（検索・案内・辞退）、案内済メールの「担当者への依頼」。全員へのメッセージの記述を消す。
2. `resources/manuals/engineer.md`・`engineer-steps.md`：金額は担当者から伝えられた単価が出ること。
3. `resources/manuals/admin.md`：「担当者への依頼」を読むだけで見られること。
4. 写し：41_Park-Project の `04_Manuals` の `管理者`・`SES`・`エンジニア` の `マニュアル.md`・`操作手順書.md`（写しのテストがある）。
5. `config/park.php` の `version` を `v0.16.0`、README の版と変更点。版を見るテストを直す。
6. 全テスト。

## 7. 送信・公開の扱い

- 公開版への反映は常時了承の範囲（Leo）。migrate は表1つと列4つの追加と、`case_mails.price_text` の埋め戻し（今の本文を読むだけ）。
- 公開版で本物の担当者にメールを送る試しは、TEZZO さんの了承を取ってから（デモ用の会社・アドレスで）。
- データの削除は無い。

## 8. テスト（`tests/Feature/V0160PartnerComposeTest.php` ほか）

架空の会社 A（受注 SES）・B（パートナー）・C（関係ない会社）、それぞれ SES と担当者、EG。メールは `@park.example`。`Notifier` は保存のみ。

| 番号 | 手順 | 確かめること |
|---|---|---|
| T1 | A の担当者で案件の検索を実行 → `GET /searches/{sid}` | 「プロパー」「パートナー」の2つの表。A の EG は氏名、B の EG は「イニシャル（B社[担当者名]）」で年齢・価格・最寄り駅が「—」（A1・A2） |
| T2 | B の EG のアカウントを B の担当者 X が登録 → 一覧 | 担当者名が X。登録者が使えない（停止）なら B の SES、SES もいなければ B の id の小さい担当者（A3） |
| T3 | B に使える担当者がいない | その行は「担当者がいません」でチェックが無い（A3） |
| T4 | 一覧の HTML | 「全員へのメッセージ」も `message_all` の欄も無い。カード名「エンジニア・担当者を追加」、ボタン「エンジニア・担当者へメールを作成」（A4〜A6） |
| T5 | 「エンジニア・担当者を追加」で B の会社名・担当者名、A の EG 名、B の EG の氏名で探す | B の担当者と A の EG は出る。B の EG（氏名・イニシャル）は出ない。担当者を足すと `case_search_staff`（by_hand=1）ができ、パートナーの表に「（エンジニアの指定なし）」（A5） |
| T6 | 何もチェックせず `POST /searches/{sid}/compose` | 一覧へ戻り「送る相手を選んでください。」（A6） |
| T7 | A の EG 2名と B の EG 1名をチェック → compose | プロパーの画面。右に A の2名、本文の「■単価：」が案件の金額、`{呼び名}` が残る（B1） |
| T8 | 本文の単価を「60万円」に直して `compose/proper` | `case_mails` が A の2名分、`price_text='60万円'`、本文は1人ずつ呼び名が入る。B の EG あての `case_mails` は無い（B3・E3） |
| T9 | 送ったあとの遷移 | B の担当者の画面へ（B4）。束が無ければ `/cases/{id}#mails` とメッセージ |
| T10 | プロパーを選ばず B の EG だけ → compose | 担当者の画面から始まる（B5） |
| T11 | `compose/proper` を2回続けて送る | 同じ EG に2通出ない（G1） |
| T12 | 古い `POST /searches/{sid}/send` | 何も送らず作成画面へ移る |
| T13 | B の担当者の画面の HTML | 一番上のチェックが checked・disabled、「すべてを選択」、「同じ条件で送信する際はチェックを入れてください」、［担当者へメールを送信］（C1） |
| T14 | 単価を直して `compose/staff/{stid}` | B の担当者 X に `mail_outbox`（kind=case-referral、Reply-To なし、本文に K.A などのイニシャル、EG の氏名は入らない）とお知らせ（link `/cases/{id}#referral`）。`price_text` が残る（C2・D1） |
| T15 | B と C の担当者（どちらも束）で、一番上＋ others[] にもう1人 | 2人とも同じ本文で送られ、宛名だけ違う（C2） |
| T16 | 束が3人、一番上だけ送る | 次の担当者の画面になる（C3） |
| T17 | skip | その担当者は送られず、次へ（C4） |
| T18 | 途中で一覧を開く | 「まだ送っていない担当者が n 名います」（C5） |
| T19 | A の別の担当者の検索から、同じ案件で同じ B の担当者に送る | 送られない（skipped）。一覧では「依頼済み」（G1） |
| T20 | B の担当者 X で案件の画面 | 「紹介の依頼」に依頼元・`price_text`・依頼されたエンジニアの氏名。案件の金額（プロバイダーからの金額）の数字は、そのカードに出ない（D2） |
| T21 | B の別の担当者 Y で案件の画面 | 同じカードが出る。お知らせは X にだけ（3.8） |
| T22 | X で［自社のエンジニアを検索する］ | `/searches/{sid}` に B の EG だけ、依頼された人にチェック。パートナーの表・メールアドレスの追加が無い。「エンジニア・担当者を追加」で A の担当者を探しても出ない（D3） |
| T23 | そのまま compose → proper | 単価の初めの文が `price_text`。送ると `case_mails.mode='partner'`、`ses_id`＝B（D4・E2） |
| T24 | 辞退 | `declined_at` が入り、依頼した A の担当者にお知らせ。カードに「辞退しました」、検索のボタンが無い（D5） |
| T25 | T23 の EG の返信を受信（`Inbound` のテストの形） | B の担当者が `/inbound` で見られ、お知らせが届く。A の担当者は `/inbound` に出ず、`/inbound/{id}` は 403。プロバイダー宛の下書きができない（D6・D7） |
| T26 | C の担当者 | 「紹介の依頼」が出ない。`/searches/{X の sid}` は 403（F2） |
| T27 | T23 の EG でやり取りを書く | 相手の会社が B（3.9） |
| T28 | A の担当者の案件の画面 | 「担当者への依頼」に B社[X]・送った日・依頼中／辞退・案内した人数。`mode='partner'` の案内メールの行は無い（D7） |
| T29 | 管理者の案件の画面 | 「担当者への依頼」が読める。`/searches/{sid}` と compose は 403（F1） |
| T30 | T8 の EG で案件の画面・`/eg/offers`・`/eg` | 金額が「60万円」。案件の金額は出ない。応募の確認の文も「60万円」（E1） |
| T31 | 案内メールの無い EG（応募から入った人など） | 金額が「担当者にご確認ください」 |
| T32 | マイグレーションの埋め戻し | 本文に「■単価：80万円」がある既存の行の `price_text` が「80万円」 |

直すテスト：`V070SearchTest`（全員へのメッセージ、他社の EG への直接送信、`/send` で送る前提）、`SearchPriceColumnsTest`（表が2つになる）、v0.15.0 の追加の宛先のテスト（送り方が作成画面経由）、版を見るテスト、マニュアルの写しのテスト。

## 9. 4つのプレーヤーでの見え方（まとめ）

| 立場 | 変わること |
|---|---|
| 管理者 | 案件の画面に「担当者への依頼」（読むだけ）。検索・作成画面は今どおり開けない |
| SES・担当者（受注 SES） | 一覧が2つ、全員へのメッセージが無い、担当者を足せる、作成画面でプロパー → 担当者の順に送る、案内済メールに「担当者への依頼」 |
| SES・担当者（パートナー） | お知らせとメールで依頼が届く（依頼先の1人）。案件の画面の「紹介の依頼」（会社の全員）から、自社のエンジニアだけで検索して案内、または辞退。自社から送った案内の返信は自社で見る |
| EG | 他社の受注 SES から直接は届かず、自社の担当者から届く。PARK の画面の金額は、届いた案内の単価 |

## 10. 警告（グループ単位の変更。完了報告で TEZZO さんへ）

- W1 他社の担当者の氏名・企業名が、受注 SES の担当者の一覧と追加の検索に出る（担当者の氏名は、今もやり取りで EG に見えているが、会社をまたいで担当者どうしに見えるのは新しい）。
- W2 パートナーの会社の全員が、依頼の本文・伝えられた単価・依頼元の担当者名を見られる。
- W3 パートナーのプロパーの返信は、受注 SES には見えなくなる（パートナー経由の分だけ）。受注 SES はパートナーの推薦を待つ。
- W4 EG の画面からプロバイダーからの金額が消える（今まで見えていた）。

## 11. 仮置き（TEZZO さんの答えで変わり得る。答えが来るまでは、この形で作る）

1. **パートナーの担当者の決め方**：「EG を登録した人 → その会社の SES → id の小さい担当者」にした。EG ごとに担当者を決める欄（エンジニアの編集画面）を作るかは、次の版で決める。
2. **SES どうしの金額**：受注 SES ではない SES（パートナーの担当者を含む）の案件一覧・案件票には、今どおりプロバイダーからの金額が出る。担当者向けの金額に変える意味が薄れるので、隠すべきか確認したい（隠すと、グループ全体の見え方が変わる）。
3. **パートナーが応募したい人を見つけたあと**：今は［推薦する］で受注 SES に知らせるだけ。受注 SES がそのままプロバイダー宛の下書きを作れる流れ（推薦 → 提案）は、この版では作らない。
4. **古い `/cases/{id}/mail`（グループのほかの人へ直接送る画面）**：この版では触らない（検索からは他社の EG に直接送らなくなったが、古い画面は残る）。閉じるかを確認したい。
5. **辞退の取り消し**：作らない。辞退したあとに紹介したくなったら［推薦する］を使う。
6. **担当者へのメールの返信先**：付けない（PARK の画面で返事）。受信で紐付けて、依頼元に届ける形にするかは、使ってみてから。
7. **兼任の管理者（`role=admin`）が担当者になる場合**：担当者の立場（`role=ses`）に切り替えて使う前提。管理者の立場のままでは、検索・作成画面・紹介の依頼は使えない（4つのプレーヤーそれぞれで考える）。

### 残した宿題（v0.16.0。次の版で決める）
- パートナー（B社）の画面に、自社のエンジニアが返した返事を追う欄が無い。返事のお知らせ（`ReplyController`）は、B社の担当者が見られる案件の「紹介の依頼」（#referral）に向けている。返事を一覧で追える欄を作るかは、次の版で決める。
# PLAN：案件詳細とエンジニア選択の作り直し（v0.15.0。2026-11-21）

> **これが今の計画**。前の計画（v0.14.0 以前）は、このファイルの末尾に残してある。
> 要求：TEZZO さん（2026-11-21）案件詳細を「左＝メール本文 → 案内メール → プロバイダーへの返信 → この案件への応募」「右＝AI の読み直し → 検索のキーワード（必須・尚可・EGに確認。左にチェック、既定でオン、各節の最後に追加）→ エンジニアに提案」に。エンジニア選択画面は「エンジニアとの相性」を元にし、タイトル「エンジニアを選択」、すべてを選択、マッチした点・マッチしなかった点・件数、EGを追加、任意のメール配信先、送信。メールに「EGに確認」を必ず出し、返事が必須と書く。返信を受注 SES の SES 担当者が確かめられる。
> 方針は Leo（2026-11-21、12項目）。細部は planner が決め、4章に理由を書いた。「仮置き」と書いた所は、TEZZO さんの答えで変わり得る。
> プレーヤー：管理者・SES・担当者・EG の4つ（担当者＝`users.role=ses`。今回の権限は SES と担当者で同じ）。
> 外部のライブラリは足さない。名前・メール・合言葉はこのファイルにもテストにも書かない（テストは架空の名前、`@park.example`）。
> 作る順：段階1 → 6（段階ごとに `php artisan test` を通して commit）→ park-reviewer → park-tester。commit・push・公開版への反映は Leo。**本物のアドレスへの送信の試し（任意の配信先）は TEZZO さんの了承を取ってから**。

## 0. コードで確かめた今の形

- 案件詳細：`CaseController::show` → `cases/show.blade.php`。左に「案件票」（dl）・「AI の読み直し」（`$ai`、`Perms::caseEdit` の人だけ）・「案内メール」と「プロバイダーへの返信」（`$canSeeMails`＝`canMail` か管理者）・「エンジニアとのやり取り」・「この案件への応募」。右は役割で分かれる（管理者＝案内・配信済み・状態の変更、SES＝「エンジニアとの相性」（自社の人の点数と `Matcher` の reasons）、EG＝あなたとの相性・応募）。**元のメール（`raw_text`）は今の案件詳細のどこにも出ていない**（検索画面にだけ出る）。
- 「案内できる人」＝`CaseMail::canSend`（role=ses で `source_ses_id` が自社）。`Perms::caseEdit` は、これに管理者を足したもの。
- 検索：`/cases/{id}/search`（GET＝`cases/search`：左に元のメール、右に必須・尚可の表と「外す」チェック `off[side][]`、「必須に足す」「尚可に足す」）→ POST で `CaseSearch::run`（`case_searches` を案件×作った人で1つ、`case_search_members` を作り直す）→ `/searches/{sid}`（`cases/search-list`：全員選択・全員へのメッセージ・氏名（押すと詳細）・「必須 h/n　尚可 k/m」・一覧に足す・送信・保存）。
- 条件は `case_searches.conditions`（JSON `{req: [...], pref: [...]}`、1項目は `name・kind（skill/exp）・src（case/added）・on`）。`conditions()` は案件の今の値から作り直し、前の「外す」と足した項目だけを引き継ぐ。
- 送信：`CaseSearch::send` → 案件の行を `lockForUpdate` → `CaseMail::send`（`case_mails` に1行、返事のリンク `/r/{token}`、返信先トークン `reply_tokens`（kind=case-mail）、件名に `[PARK-xxxxxxxxxx]`、本文の最後に「案内番号」、`case_offers`、EG へのお知らせ、他社の EG なら所属 SES に知らせ、監査）。
- 「EGに確認」：`cases.confirm_items`（改行区切り）。`CaseMail::render` が `{確認事項}` を「、」でつなぎ、空なら `■ご確認ください：` の行ごと消す。検索の本文（`CaseSearch::defaultBody`）にも同じ行がある。
- 受信：`Inbound::match` が ①宛先 `park+トークン@…` ②In-Reply-To ③件名 `[PARK-…]`（差出人が EG の登録アドレス）④本文の案内番号・`/r/` リンク・案件 ID の順で探す。`linkFields` で `inbound_mails` に `case_mail_id・case_id・ses_id・engineer_id・sender_kind（eg/provider）`。`notify` が受注 SES の担当者全員に知らせる。SES・担当者の見える範囲は「紐付け済みで、案件の今の受注 SES が自社」（`Inbound::query`）。`/inbound?case=&eg=` で絞れる。
- 返事のリンクの画面：`ReplyController`（`public/reply`）。ひとことは任意。
- 版の表示：`config/park.php` の `version` は今 `v0.13.0`（v0.14.0 で上げていない）。版を見るテストが9か所ある（8章）。

## 1. 受け入れ条件

| 番号 | 立場 | 条件 |
|---|---|---|
| A1 | 受注 SES の SES・担当者 | 案件詳細の左の一番上が「メール本文」。`raw_text` がそのまま出る（空なら「元のメールはありません。」と作業内容）。カードの右上に「編集」と、ID・状態・金額の1行 |
| A2 | 同上 | メール本文の下に、折りたたみ「案件票（読み取った項目）」。今の dl（必須・尚可・業務・EGに確認・元の文・経験年数…締め切り）と作業内容・その他の条件が全部入っている |
| A3 | 同上 | 左はその下に「案内メール」→「プロバイダーへの返信」→「この案件への応募」→「エンジニアとのやり取り」（ある時だけ）の順 |
| A4 | 同上 | 右の一番上が「AI の読み直し」（今のカードの中身そのまま。id="ai"） |
| A5 | 同上 | その下に「検索のキーワード」（id="keywords"）。「必須」「尚可」「EGに確認」の順に項目が並び、各項目の左にチェック。はじめは全部オン。前に外した項目は外したまま |
| A6 | 同上 | 必須・尚可・EGに確認のそれぞれの最後に「追加」の欄とボタン。押すと条件に足され、案件詳細（#keywords）に戻る。案件の必須・尚可・EGに確認・技術マスタは変わらない |
| A7 | 同上 | キーワード欄の最後に「エンジニアに提案」ボタン。押すとチェックの状態（と追加の欄）を保存して検索し、エンジニア選択画面（`/searches/{sid}`）へ |
| A8 | 同上 | 「エンジニアとの相性」カードは、この人たちの画面には出ない（選択画面に移った）。案内メールの見出しの「エンジニアに提案する」は #keywords へのリンク |
| A9 | 管理者・ほかの SES・EG | 案件詳細の配置・中身は今のまま（元のメールは出ない。キーワード欄・「エンジニアに提案」は出ない） |
| A10 | 受注 SES の SES・担当者 | `GET /cases/{id}/search` は `/cases/{id}#keywords` へ移る（募集を終えた案件は今どおり案件へ戻して知らせる）。管理者・ほかの SES・EG は今どおり 403 |
| B1 | 同上 | 選択画面のタイトルは「エンジニアを選択」。一番上の行に「すべてを選択」のチェック |
| B2 | 同上 | 一覧の列：チェック／エンジニア（押すと詳細）／点数／マッチした点／マッチしなかった点／「必須 h/n　尚可 k/m」。マッチした点・しなかった点は「必須 …」「尚可 …」を改行で分けて出す |
| B3 | 同上 | 一覧の下に「EGを追加」（今の「一覧に足す」）と「メールアドレスを追加」。アドレス（任意で名前）を足すと「追加の宛先」の行になり、チェックが入る |
| B4 | 同上 | 「エンジニアへメールを送信」で、チェックしたエンジニアと追加の宛先の両方に送る。送った追加の宛先は「送信済み」になり選べない |
| B5 | 同上 | 全員へのメッセージ・詳細（個別メッセージとメールの見本）・送信済み・保存は今どおり |
| C1 | EG・追加の宛先 | 送るメールに、チェックした「EGに確認」の項目が「■ご確認ください（必ずご返信ください）」の見出しで全部出て、返事が必須である旨の一文がある。チェックした項目が0なら、その節は出ない |
| C2 | EG（返事のリンクの画面） | その案内に確認事項があるとき、画面に項目と「お答えが必須です」が出て、ひとことが空だと記録されない（エラーの文で戻る）。確認事項が無い案内は今どおり任意 |
| C3 | 受注 SES の SES・担当者 | 追加の宛先からの返信（宛先トークン／In-Reply-To／件名の印＋差出人が同じアドレス）が、その追加の宛先の行に紐付き、担当者全員にお知らせが出る。案件詳細の「案内メール」の「追加の宛先」に、送った日と「返信メール n 件」（`/inbound?case=…&extra=…` へのリンク）が出る |
| C4 | 管理者 | 「追加の宛先」の表を読むだけで見られる（アドレス・送った日・返信の件数）。操作は無い |
| C5 | ほかの SES・EG | 追加の宛先のアドレスは見えない（案件詳細にも、受信メールにも） |
| D1 | システム | アドレスの形が正しくない・同じ検索に同じアドレス・この案件で送ったアドレス・登録しているエンジニアのアドレス・PARK 自身のアドレスは足さない（理由を出す）。1回に足せるのは 10 件、1つの検索に 30 件まで |
| D2 | システム | 同時に2回送っても、同じ追加の宛先に2通出ない。送信は監査に残る |
| D3 | システム | メールを「保存のみ」にしているときは、今の案内と同じく記録だけ残る（画面に「保存のみ」） |
| D4 | 全員 | 今のテストが通る（検索画面の `off[...]` と `GET /cases/{id}/search` を見ていたテストは新しい形に直す。8章） |

## 2. データ（マイグレーション1本、足すだけ）

`database/migrations/2026_11_21_000002_case_search_extras.php`。外部キーは付けない（今までどおり）。`down()` は足したものを消すだけ。

- 新しい表 `case_search_extras`（追加の宛先）
  - `id`、`search_id`（index）、`case_id`（index）、`ses_id`（null 可。作った会社）、`created_by`
  - `email`（string 254。小文字にそろえて入れる）、`name`（string 100、null 可）
  - `checked`（boolean、既定 true）
  - `sent_at`（null 可）、`sent_by`（null 可）、`reply_token_id`（null 可）、`outbox_id`（null 可）、`subject`（text null）、`body`（text null）、`confirm_items`（text null。送ったときの確認事項。改行つなぎ）
  - `created_at`、`updated_at`
  - unique（`search_id`, `email`）
- `reply_tokens`：`search_extra_id`（null 可、index）。kind に `search-extra` を使う。
- `inbound_mails`：`search_extra_id`（null 可、index）。`sender_kind` に `extra` を使う（今は eg／provider。長さ 10 で足りる）。
- `case_mails`：`confirm_items`（text、null 可。送ったときの確認事項。改行つなぎ。検索から送ったときだけ入る）。

`case_searches.conditions` の JSON に `confirm`（配列）を足す（列は変えない。古い JSON に `confirm` が無くても動く）。1項目は `{name, kind: 'text', src: 'case'|'added', on}`。

## 3. 仕様

### 3-1. `CaseSearch`（条件）

- `key()`：`kind=text` は `text:`＋名前（前後の空白を取る）。
- `conditions($c, $s)`：`confirm` を足す。中身は `cases.confirm_items` の行（空行は捨て、同じ文は1つ）を `src=case`、前の検索の `src=added` を後ろに。前に外した印（`on=false`）の引き継ぎは req・pref と同じ。
- `addItems($cond, $side, $text)`：`$side=confirm` のときは改行だけで区切り（文の中の「、」で割らない）、1項目 200 字まで（`MAX_CONFIRM_LEN`）、技術マスタには通さず `kind=text`。数の上限は `MAX_ADD`（30）のまま。req・pref は今のまま。
- `activeItems()`：`confirm` も返す。`judge`・`passes`・点数は `confirm` を見ない（今の req・pref だけ）。
- 新：`applyForm(array $cond, Request の値): array`（コントローラーから呼ぶ。下の 3-3）。

### 3-2. `CaseSearch`（メールの本文）

- `defaultBody()`：`■ご確認ください：{確認事項}` の行をやめ、`■尚可：{尚可}` の次の行に `{確認事項の節}` を置く。
- `confirmBlock(array $names): string`（新）：0件なら `''`。あれば次の形（`・` は全角）。
  ```
  ■ご確認ください（必ずご返信ください）
  ・項目1
  ・項目2
  上の項目について、このメールへの返信で必ずお答えください（お答えがないと、ご提案を進められません）。
  ```
- `body()`：`$vals` に `'{確認事項の節}' => confirmBlock(activeItems の confirm の名前)` を足す（今の目印の仕組みで、空なら行ごと消える）。`CaseMail::render` に渡す `$c` は `confirm_items` を空にした写し（render の一行の確認事項を出さない）。戻り値に `'confirm' => [名前…]` を足す。
- `send()`：`CaseMail::send` に渡す `$items[$eid]` に `'confirm' => […]` を入れる。
- `CaseMail::send`：`$it['confirm']` があれば `case_mails.confirm_items` に改行つなぎで入れる（無ければ null。案件の画面の案内 `/cases/{id}/mail` の動きは変えない）。

### 3-3. コントローラー（`CaseSearchController`）

- `form`（GET `/cases/{id}/search`）：`loadCase`（権限は今のまま）→ `closed` なら今どおり → それ以外は `redirect("/cases/{$id}#keywords")`。`cases/search.blade.php` は消す（使わない）。
- 条件の読み方（`run` と新しい `keywords` で共通の private `formCond`）：
  - リクエストに `kw=1` がある（新しいフォーム）：`on[req][]`・`on[pref][]`・`on[confirm][]` にある鍵だけオン、無い鍵はオフ。
  - `kw` が無い（古い形・空の POST）：今の `off[...]` の読み方のまま（`off` が無ければ前回の状態のまま）。空の POST で全部オフにならないための互換。
  - そのあと `add_req`・`add_pref`・`add_confirm` を `addItems`。
- `run`（POST `/cases/{id}/search`＝「エンジニアに提案」）：`formCond` → `CaseSearch::run` → `/searches/{sid}`（今どおり）。
- 新 `keywords`（POST `/cases/{id}/keywords`＝各「追加」ボタン）：`loadCase`・`closed` → `formCond` → `CaseSearch::saveConditions($u, $c, $cond)`（新。`run` の前半＝`case_searches` の行を作る／書き直すだけ。メンバーは作らない）→ `/cases/{id}#keywords`「キーワードを保存しました。」。
- `index`：`extras`（この検索の追加の宛先）を渡す。
- `persist`：今に足して `xpick[]`（追加の宛先の id）で `case_search_extras.checked` を入れ直す（送信済みは触らない）。
- 新 `addExtras`（POST `/searches/{sid}/extras`）：`persist` → `CaseSearch::addExtras($u, $s, $c, $text)` → `/searches/{sid}#extras`、結果の文（足した数・足さなかった理由）。
- 新 `removeExtra`（POST `/searches/{sid}/extras/{xid}/remove`）：`persist` → 送っていない行だけ消す（その検索の行でなければ 404）。消すのは送る前の入力の取り消しで、記録の削除ではない。
- `send`：`persist` → チェックしたエンジニアの id と、チェックした未送信の追加の宛先。両方0なら「送る相手を選んでください。」。`$n = CS::send(...)`（エンジニアが0なら呼ばない）＋`$x = CS::sendExtras(...)`。両方0なら「送る相手がいません。」。知らせの文：`$n>0` は今と同じ「{n}名に案内メールを送りました。返事が来たら、下の「案内メール」に記録してください。」、`$x>0` なら後ろに「追加の宛先 {x}件にも送りました。」（`$n=0` なら「追加の宛先 {x}件に案内メールを送りました。」）。行き先は今どおり `/cases/{id}#mails`。

ルート（`routes/web.php` の `role:admin,ses` の中、検索の並び）：`POST /cases/{id}/keywords`、`POST /searches/{sid}/extras`、`POST /searches/{sid}/extras/{xid}/remove`。

### 3-4. 追加の宛先（`CaseSearch`）

- 定数：`MAX_EXTRA_ADD = 10`（1回）、`MAX_EXTRAS = 30`（1つの検索）、`EXTRA_KIND = 'search-extra'`。
- `addExtras($u, $s, $c, ?string $text): array` → `['added' => n, 'skipped' => [理由の文…]]`
  - 1行に1件。形は「アドレス」か「名前 <アドレス>」（`<>` の外を名前）。前後の空白を取り、アドレスは小文字。
  - 断る：`filter_var(FILTER_VALIDATE_EMAIL)` が偽・254 字超／同じ検索にある／この案件で送ったことがある（`case_search_extras` の `case_id` が同じで `sent_at` あり）／登録しているエンジニアのアドレス（`Inbound::engineerEmails` と同じ見方。`engineers.email` と、`engineer_id` のある `users.email`）→「登録しているエンジニアです。『EGを追加』から足してください。」／PARK の送信元・返信先（`mail.from.address`・`park.inbound.reply_base`、同じドメインの `park+…` も）／1回 10 件・合計 30 件を超えた分。
  - 名前は 100 字まで（`str_in`）。足した行は `checked=true`。
  - 監査 `N::audit($u->id, 'case-search-extra-add', "{案件のコード} 検索#{sid} {n}件")`（アドレスは監査の文に書かない）。
- `extraBody($u, $c, $s, $x): array`（件名・本文）
  - 件名：`CaseMail::defaultSubject($c)`（送るときに `[PARK-…]` を付けるのは送信側）。
  - 本文の型（`extraTemplate()`）：
    ```
    {呼び名} 様

    お疲れさまです。{送り主}です。
    案件のご案内です。

    {全員へのメッセージ}

    ■案件：{案件名}
    ■案件 ID：{案件ID}
    ■単価：{単価}
    ■開始：{開始}
    ■場所・働き方：{場所}／{働き方}
    ■必須：{必須}
    ■尚可：{尚可}
    {確認事項の節}

    ご興味があれば、このメールに返信してください（条件で気になる点があれば、それも一緒に）。
    ```
  - 呼び名は `name`、無ければ「ご担当者」。必須・尚可・確認事項は検索の条件（`activeItems`）。合う点・個別メッセージ・返事のリンク・PARK の画面のリンクは出さない。空の差し込みは行ごと詰める（`body()` と同じ目印の仕組みを共通の private 関数にして使う）。
- `sendExtras($u, $c, $s): int`
  - 案件の行を `lockForUpdate` するトランザクションの中で、この検索の `checked=true` かつ `sent_at` が null の行を読み直す（`lockForUpdate`）。
  - 1行ごとに：`ReplyTokens::issue(EXTRA_KIND, case_id, 自社 ses_id, null, Inbound::enabled())` → 本文の最後に「※このメールに返信すると、担当者に届きます。」と `――――――――――\n［システム伝達項目・消さないでください］\n案内番号：PARK-{tag}\n案件 ID：{code}`。
  - 返信先：受信が使えるとき `park+トークン@…, 送った担当者のアドレス`、使えないとき送った担当者のアドレスだけ（自社の EG あての `CaseMail::send` と同じ考え）。件名に `ReplyTokens::subjectMark`。
  - `N::queueMail($x->email, $x->name ?: '', 件名, 本文, 'case-search-extra', 返信先, null)`。
  - 行を `update(['sent_at', 'sent_by', 'checked' => false, 'reply_token_id', 'outbox_id', 'subject', 'body', 'confirm_items'])`（`where('sent_at', null)` を付け、0行なら送らない＝二重送信を防ぐ。更新を先にしてから `queueMail`）。`ReplyTokens::attach($tokenId, ['search_extra_id' => …, 'outbox_id' => …, 'message_id' => …])`（`attach` の許す項目に `search_extra_id` を足す）。
  - `case_mails`・`case_offers`・EG へのお知らせは作らない。
  - 最後に `N::audit($u->id, 'case-search-extra-send', "{$c->title} → {$n}件")`。`case_searches.last_sent_at` も更新。

### 3-5. 受信の紐付け（`Inbound`・`ReplyTokens`）

- `ReplyTokens::KIND_EXTRA = 'search-extra'`。`attach` に `search_extra_id`。
- `match`：①（宛先トークン）と②（In-Reply-To）は今のままで `search-extra` のトークンも当たる。③④の件名・本文の案内番号では、今の `tokenByTag`（case-mail だけ）で当たらなかったら、新 `extraTokenByTag($tag, $from)`（kind=search-extra が1件だけ、かつ差出人がその行の `email`）を試す。`/r/`・案件 ID の探し方は対象外。
- `linkFields`：トークンが `search-extra` なら `['status' => 'linked', 'match_by' => $by, 'reply_token_id', 'case_mail_id' => null, 'case_id' => トークンの case_id, 'ses_id' => トークンの ses_id ?: 案件の source_ses_id, 'engineer_id' => null, 'sender_kind' => 'extra', 'search_extra_id', 'from_mismatch' => 差出人が行の email と違う, 'linked_at']`。
- `groupWithoutPark`：`case_mail_id` が無いので今のまま false（自動で紐付けてよい）。
- `notify`：`sender_kind=extra` の分かれを provider の次に足す。`N::toSes(ses_id, '追加の宛先から案内メールへの返信：'.案件名.タグ, 本文の頭 200 字, "/inbound/$id", false, 'inbound')`（メールは出さない。返信は送った担当者のアドレスにも届いているため）。
- `query`：`leftJoin('case_search_extras as x', 'x.id', '=', 'i.search_extra_id')`、`x.name as extra_name`・`x.email as extra_email` を足す。見える範囲は今のまま（受注 SES と管理者だけ）。
- `senderLabel`：`sender_kind=extra` → `extra_name` があれば「名前（アドレス）」、無ければアドレス（行が無ければ「追加の宛先」）。
- `masked`：`sender_kind=extra` は伏せない（自社が入れた宛先。エンジニアではない）。
- `unlink`：`search_extra_id => null` も戻す。手での紐付け（`link`）は今のまま（案内メールだけ）。
- `ProviderReply::draftFromInbound`・`ProviderMailController::fromInbound` は `sender_kind=eg` だけなので、追加の宛先からは下書きを作らない（変えない）。`inbound/show` の「プロバイダーへの返信を作る」系のボタンが `extra` で出ないように、条件を `sender_kind === 'eg'` に絞る。
- `InboundController::index`：`?extra=` で `i.search_extra_id` を絞る。

### 3-6. 返事のリンクの画面（`ReplyController`・`public/reply`）

- `load` の select に `m.confirm_items` を足す（`m.*` に入っている）。行に分ける（空行は捨てる）。
- 画面：確認事項があれば、ご返事の上に「ご確認ください（お答えが必須です）」と項目の箇条書き。ひとことの見出しを「ひとこと（必須。上の項目へのお答えを書いてください）」に、`required` を付ける。無ければ今のまま（任意）。
- `save`：確認事項があり、`note` が空なら記録しない。`redirect("/r/{$token}/{$answer}")` に戻して「上の項目へのお答えを、ひとことの欄に書いてください。」（`withErrors` か flash。入力は `withInput`）。返事の種類（応募したい・質問・見送る）を問わず必須（仮置き。見送りでもお答えをもらう）。
- 自社の EG・他社の EG のどちらの返事の画面も同じ（同じ画面のため）。

### 3-7. 画面：案件詳細（`cases/show.blade.php`・`CaseController::show`）

`$newLayout = $canMail`（＝`CaseMail::canSend`）。`$newLayout` のときだけ下の配置。偽のときは今のファイルの形をそのまま出す（管理者・ほかの SES・EG）。ファイルが長くなるので、今の各カードを部分テンプレートに切り出して両方の配置で使う：`cases/_card_case`（案件票の dl と作業内容・その他の条件）、`cases/_card_ai`、`cases/_card_mails`（案内メール＋追加の宛先）、`cases/_card_provider`、`cases/_card_apps`、`cases/_card_threads`、`cases/_card_keywords`（新）。

左（`$newLayout`）：
1. 「メール本文」カード：actions に「編集」（今の条件）と `<span class="muted">ID <x-code> ・状態 badge ・金額 man()</span>`。中身は `<pre class="mailbody">raw_text</pre>`、空なら「元のメールはありません。」と作業内容。その下に `<details><summary>案件票（読み取った項目）</summary>` で `_card_case` の中身（dl・作業内容・その他の条件）。
2. 案内メール（`#mails`）。見出しの「エンジニアに提案する」は `href="#keywords"`（募集中だけ）。表の下に「追加の宛先」（3-8）。
3. プロバイダーへの返信。
4. この案件への応募。
5. エンジニアとのやり取り（ある時だけ）。

右（`$newLayout`）：
1. AI の読み直し（`isset($ai)` のとき。今のカードのまま）。
2. 「検索のキーワード」（`id="keywords"`）。募集中でなければ「募集を終えた案件です。」だけ。
   - `<form method="post" action="/cases/{id}/search">`、`@csrf`、`<input type="hidden" name="kw" value="1">`。
   - 節を「必須」「尚可」「EGに確認」の順に。各項目 1 行：`<label><input type="checkbox" name="on[{side}][]" value="{key}" @checked(on)> 名前</label>`、業務は「（業務）」、足した項目は「足した」の印。項目が無い節は「項目はありません。」。
   - 各節の最後：`<textarea name="add_{side}" rows="2">` と `<button class="btn ghost small" formaction="/cases/{id}/keywords">追加</button>`。説明：必須・尚可は「「、」や改行で区切ると、いくつでも足せます。」、EGに確認は「1行に1つ。チェックした項目は、メールの「ご確認ください」に入り、返事が必須になります。点数には使いません。」。
   - 最後に `<button class="btn">エンジニアに提案</button>` と説明「この検索にだけ使います。案件の必須・尚可は変わりません。」。
3. 「エンジニアとの相性」カードは出さない。

`CaseController::show`：`$newLayout` で募集中なら `cond = CaseSearch::conditions($c, 自分の検索)` を渡す。追加の宛先の表のデータ（3-8）を `canSeeMails` のとき渡す。

### 3-8. 案件詳細の「追加の宛先」（案内メールのカードの中）

- データ：`case_search_extras` の `case_id` が同じで `sent_at` あり、新しい順。返信の件数は `inbound_mails` の `status=linked` を `search_extra_id` ごとに数える。
- 見せる人：`$canSeeMails`（受注 SES の SES・担当者と管理者）だけ。
- 列：宛先（名前、アドレス）／送った日／返信。返信は、`canMail` なら「返信メール n 件」（`/inbound?case={id}&extra={xid}` へのリンク）、管理者は件数の文だけ（管理者の受信の一覧は `/admin/inbound` のため）。0件なら「まだありません」。
- 1件も無ければ、この小見出しごと出さない。

### 3-9. 画面：エンジニア選択（`cases/search-list.blade.php`）

- ページのタイトル「エンジニアを選択：{案件名}」、カードの見出し「エンジニアを選択」。actions：「キーワードを変える」（`/cases/{id}#keywords`）・「案件に戻る」。
- 一番上の行：`<label class="check"><input type="checkbox" id="pickall"> すべてを選択</label>`。押すと、送っていないエンジニア（`pick[]`）と追加の宛先（`xpick[]`）の全部に入れる／外す（仮置き：追加の宛先も含める）。
- 一覧の列（`x-table` の head）：`''`・`エンジニア`・`点数`・`マッチした点`・`マッチしなかった点`・`件数`。
  - チェック：今どおり（送信済みは「送信済み」）。`name="pick[]" value="{id}" checked` の並びは変えない（テストが見ている）。
  - エンジニア：今どおり（名前のボタンで詳細へ、コード、「手で足した」、届いていなければ「条件に届いていません」）。
  - 点数：`<x-score :n="$row['score']" />`（`Matcher` の点）。
  - マッチした点：`$j['req']` の hit を `<b>必須</b> 名前、名前<br>`、`$j['pref']` の hit を `<b>尚可</b> …`。業務は名前のあとに「（業務）」。無ければ「—」。
  - マッチしなかった点：同じく hit でないもの。
  - 件数：`必須 {h}/{n}　尚可 {k}/{m}`（今の文字のまま）。
- 一覧の下：「全員へのメッセージ」（今の欄）。
- 「EGを追加」カード（今の「一覧に足す」。ボタンの文字「一覧に足す」は残す）。
- 「メールアドレスを追加」カード（`id="extras"`）：説明「PARK に登録していない方にも、同じ案件のご案内を送れます。1行に1件、「アドレス」か「名前 <アドレス>」で書いてください（1回に 10 件まで）。返信は、このメールを送った担当者と PARK に届きます。」。`<textarea name="extra_text">` と `<button formaction="/searches/{sid}/extras">宛先に足す</button>`。その下に、この検索の追加の宛先の表：チェック（送信済みは「送信済み」と送った日）`name="xpick[]"`・名前・アドレス・「外す」（未送信だけ。`formaction="/searches/{sid}/extras/{xid}/remove"`）。
- 「エンジニアへメールを送信」（今の文字）。確認の JS は `pick[]` と `xpick[]` の合計で数える（「n件に送ります。よろしいですか？」）。「チェックとメッセージを保存」は今どおり。
- 保存のみの注意（`$canSend`）は今どおり。

### 3-10. マニュアルと README

- `resources/manuals/ses.md`（138〜143 行あたり）・`ses-steps.md`（87〜88 行あたり）：案件詳細の新しい配置、キーワードのチェック（既定でオン、外すと検索から外れる）、追加、「EGに確認」のチェックの意味（メールの必須の確認事項になる）、「エンジニアに提案」、選択画面（すべてを選択・列・EGを追加・メールアドレスを追加・送信）、追加の宛先の返信の見方（案件詳細の「追加の宛先」→「返信メール n 件」）。
- `admin.md`（230〜231・237 行）・`admin-steps.md`（115 行）：担当者の流れの文を新しい名前に（「エンジニアに案内する → 条件 → エンジニアを検索」→「検索のキーワード → エンジニアに提案 → エンジニアを選択」）。管理者は「追加の宛先」を読むだけ見られること。
- `engineer.md`・`engineer-steps.md`（返事のリンクの所）：案内に「ご確認ください（必ずご返信ください）」があるときは、ひとことに答えを書かないと返事が記録されないこと。
- `README.md`：v0.15.0 の節（表・列、ルート、追加の宛先の受信の紐付け、上限）。
- 直したら Leo が 41_Park-Project の `04_Manuals` の写し（`SES`・`管理者`・`エンジニア` の `マニュアル.md`・`操作手順書.md`、README）を直す。

## 4. 決めたこと（planner。違えば Leo が直す）

| 番号 | 決めたこと | 理由 |
|---|---|---|
| P1 | 新しい配置は `CaseMail::canSend` の人だけ。ほかは今のファイルの形をそのまま出す（カードは部分テンプレートで共有） | Leo 決め1。管理者・ほかの SES・EG のテストと「元のメールを出さない」決まりを壊さない |
| P2 | 「メール本文」は `raw_text` をそのまま出す（`pre`）。案件票の dl は折りたたみで全部残す | Leo 決め2。情報を失わない。テストの `assertSee` も `<details>` の中で通る |
| P3 | 「エンジニアとの相性」カードは、案内できる人の画面から外す | 選択画面が「相性」を元にした作りになり、同じ表が2か所に出る。推薦する人（ほかの SES）には今どおり出る |
| P4 | 「追加」は検索を走らせず条件だけ保存（`/cases/{id}/keywords`）、「エンジニアに提案」は保存＋検索 | 追加のたびに一覧（メンバー）を作り直すと、選択画面のチェックが入れ直される |
| P5 | 新しいフォームは `kw=1` の目印つきで「入れる」チェック。目印が無い POST は今の `off` の読み方 | チェックの外れた項目はブラウザが送らないので、目印が無いと「全部外した」と区別できない。空の POST（今のテスト）で全部オフにならない |
| P6 | 「EGに確認」は条件の `confirm` に持ち、点数・合否に使わない。チェックは「メールに入れるか」 | Leo 決め5 |
| P7 | 確認事項の節は検索から送るメールだけ（エンジニア・追加の宛先）。案件の画面の案内（`/cases/{id}/mail`）は今の一行のまま | 要求は選択画面からの送信。今の案内の画面の文面（担当者が保存した型）を変えない |
| P8 | 送ったときの確認事項を `case_mails.confirm_items` と `case_search_extras.confirm_items` に残す。返事の画面はこれを見る | 案件の確認事項があとで変わっても、送ったメールと返事の画面がずれない |
| P9 | 返事の画面で、確認事項があればひとことを必須（返事の種類を問わず）。**仮置き** | Leo 決め5。見送りでもお答えをもらうかは TEZZO さんの意図が読み切れないので、要求の「返事が必須」に寄せた |
| P10 | 追加の宛先の返信は、新しいトークンの種類 `search-extra` と `inbound_mails.search_extra_id`・`sender_kind=extra` で紐付ける。`case_mails` は作らない | Leo 決め8。`case_mails.engineer_id` を null にすると結合とテストが壊れる。トークンの仕組みはそのまま使え、①②の探し方は変えなくて済む |
| P11 | 追加の宛先の返信先は「PARK（受信が使えるとき）＋送った担当者」。お知らせだけでメールは出さない | 自社の EG あての案内と同じ考え。担当者には返信そのものが届いている |
| P12 | 登録しているエンジニアのアドレスは追加の宛先にできない | 他社の EG に名前を伏せて案内する決まり・案内の記録（`case_mails`）を迂回させない |
| P13 | 上限は 1回 10 件・1つの検索 30 件。**仮置き** | 送りすぎと、PARK の送信元の評判（迷惑メール扱い）を守る。数は TEZZO さんの答えで変える |
| P14 | 追加の宛先の「外す」は、送る前の行だけ消す | 打ち間違いを直せるように。送った行は記録なので消さない |
| P15 | 「すべてを選択」は追加の宛先も含める。**仮置き** | 一覧の最後尾に並ぶので、一覧の一部と読める |
| P16 | `/cases/{id}/search` の GET は案件詳細へ移すだけ。`cases/search.blade.php` は消す | Leo 決め6。古いリンク・ブックマークも動く |
| P17 | 版は `v0.15.0`（`config/park.php`）。版を見るテストも直す | 画面と流れが変わる |

## 5. 画面と操作（プレーヤーごと）

| 立場 | 画面 | 変わること |
|---|---|---|
| 管理者 | 案件詳細 | 配置は今のまま。「案内メール」の中に「追加の宛先」（アドレス・送った日・返信の件数。読むだけ） |
| 管理者 | 受信メール（`/admin/inbound`） | 追加の宛先からの返信が「紐付け済み」で並ぶ。差出人は「名前（アドレス）」 |
| 管理者 | `/cases/{id}/search`・選択画面 | 今どおり入れない（403） |
| SES・担当者（受注 SES） | 案件詳細 | 新しい配置（3-7）。メール本文・キーワード・「エンジニアに提案」。「追加の宛先」と返信の件数 |
| SES・担当者（受注 SES） | エンジニア選択 | 3-9。メールアドレスを追加して送れる |
| SES・担当者（受注 SES） | 受信メール（`/inbound`）・今やること・お知らせ | 追加の宛先からの返信が並ぶ。差出人は伏せない |
| SES・担当者（ほかの会社） | 案件詳細 | 今のまま（相性と推薦）。追加の宛先は見えない |
| SES・担当者（ほかの会社） | 自社の EG に届いた案内 | 確認事項の節が入る（自社の EG がグループの案件で返事を求められる） |
| EG | 案内メール | 「■ご確認ください（必ずご返信ください）」と項目、返事が必須の文 |
| EG | 返事のリンクの画面 | 確認事項があれば項目が出て、ひとことが必須 |
| EG | 案件詳細 | 今のまま |
| 追加の宛先（PARK の外の人） | メール | 案件の案内（合う点・PARK のリンクは無い）。返信すると担当者と PARK に届く |

## 6. 境界の挙動

| 場面 | 挙動 |
|---|---|
| 権限が無い人が `/cases/{id}/keywords`・`/searches/{sid}/extras` | `loadCase`・`loadSearch` で 403（今と同じ見方）。EG はルートで止まる |
| 募集を終えた案件 | キーワード欄は「募集を終えた案件です。」だけ。POST は今どおり案件へ戻して知らせる。追加の宛先の表は残る |
| `raw_text` が空 | 「元のメールはありません。」と作業内容 |
| 案件の「EGに確認」が空 | キーワード欄の節は「項目はありません。」。追加で足せる。チェックが0ならメールに節は出ない |
| 案件の必須・尚可・EGに確認があとで変わった | `conditions()` が案件の今の値から作り直す（足した項目と外した印だけ引き継ぐ）。送ったメールの確認事項は送ったときのまま |
| キーワードを全部外して「エンジニアに提案」 | 必須0項目なので全員合格（今の `passes`）。一覧に全員が並ぶ |
| 「追加」を空で押す | 何も足さず、チェックの状態だけ保存して戻る |
| 同じ検索に同じアドレス／大文字小文字だけ違う | 足さない（小文字にそろえ、unique でも止まる。unique に当たった例外は「すでにあります」に変える） |
| この案件で別の担当者がもう送ったアドレス | 足さない（「この案件ではもう送っています」） |
| 11 件以上を一度に／合計 31 件目 | 上限までだけ足し、残りは理由を出す |
| 2回同時に送信 | 案件の行を押さえ、`sent_at` が null の行だけ更新して送る。2通目は出ない（エンジニアは今どおり） |
| エンジニア0・追加の宛先だけチェック | 追加の宛先だけ送る |
| 受信が使えない（`PARK_IMAP_*` が空） | 返信先は送った担当者だけ。PARK には紐付かない（今の案内と同じ） |
| メールが「保存のみ」 | `queueMail` が記録だけ（今と同じ）。画面に「保存のみ」 |
| 追加の宛先の返信が別のアドレスから | ①②で当たれば紐付き `from_mismatch=true`。③④（件名・本文の印）は差出人が同じときだけ |
| 案件の受注 SES が変わった | 返信は今の受注 SES に見える（受信の今の決まり。案内メールと同じ） |
| 返事の画面で確認事項があり、ひとことが空 | 記録しない。同じ画面に戻ってエラーの文 |
| 古い案内（`case_mails.confirm_items` が null） | 返事の画面は今どおり（任意） |
| 古い `conditions`（`confirm` が無い） | 案件の確認事項から作り、全部オン |

## 7. 了承・警告（Leo が TEZZO さんに）

- W1（警告・グループ単位）：他社の SES に所属する EG に届く案内（グループの案件）にも、「必ずご返信ください」の節が入り、返事の画面でひとことが必須になる。他社の EG の動きが変わる。
- W2（警告・グループ単位）：PARK の送信元（公開版のメールの設定）から、PARK に登録していない人へメールが出せるようになる。送り先は各社の担当者が決める。迷惑メール扱いで PARK の送信元の評判が下がると、全社の案内メールが届きにくくなる。上限（1回 10 件・1検索 30 件）は仮置き。
- W3（警告）：追加の宛先のアドレスと、その人からの返信の中身が PARK の DB に残る（受注 SES と管理者に見える）。PARK の外の人の個人情報を持つことになる。
- W4（了承が要る）：公開版で、本物の外のアドレスへ試しに送ること。手元・公開版とも、試しは `@park.example` か、メールを「保存のみ」にした状態で行う。
- W5（報告）：受注 SES の担当者の案件詳細から「エンジニアとの相性」の表が消え、選択画面に移る。
- 仮置き（TEZZO さんの答えで変える）：P9（見送りでもひとこと必須）、P13（上限の数）、P15（すべてを選択に追加の宛先を含める）。

## 8. 作業の手順（implementer 向け）

段階1（データ）
- マイグレーション（2章）。`ReplyTokens::KIND_EXTRA`、`attach` の許す項目。

段階2（条件とメールの本文）
- `CaseSearch`：`key`・`conditions`・`addItems`・`activeItems` に `confirm`（3-1）。`saveConditions`。`defaultBody`・`confirmBlock`・`body`・`send` の `confirm`（3-2）。目印で差し込む部分を private 関数に分ける。
- `CaseMail::send` に `confirm_items`（3-2）。

段階3（コントローラーとルート）
- `CaseSearchController`：`form` のリダイレクト、`formCond`（`kw`／`off` の両方）、`run`、`keywords`、`persist` に `xpick`、`addExtras`・`removeExtra`、`send` の両方送り（3-3）。ルート3本。
- `cases/search.blade.php` を消す。

段階4（追加の宛先と受信）
- `CaseSearch::addExtras`・`extraBody`・`sendExtras`（3-4）。
- `Inbound`：`match`・`extraTokenByTag`・`linkFields`・`notify`・`query`・`senderLabel`・`masked`・`unlink`（3-5）。`InboundController::index` の `extra`。`inbound/show` のボタンの条件。
- `ReplyController`・`public/reply`（3-6）。

段階5（画面）
- `cases/show` を部分テンプレートに分け、`$newLayout` の配置を足す（3-7）。**まず分けるだけで今のテストが全部通ることを確かめてから**、新しい配置を足す。
- `CaseController::show`：`cond`・追加の宛先のデータ（3-7・3-8）。
- `cases/search-list`（3-9）。`cases/search-member` は見本の本文が変わるだけ（触らない）。

段階6（版・マニュアル・既存テストの直し）
- `config/park.php` を `v0.15.0`。版を見るテストを直す：`RegistrationFoundationTest:52`、`SkillQuestionsTest:650-651`、`V0100CaseThreadTest:520`、`V0101CaseThreadPartnerTest:657`、`V030RegressionTest:177-178`、`V050CatalogTest:158`、`V060CaseAiTest:528`、`V070SearchTest:424-425`、`V080DirectMailTest:449-450`、`V081AffiliationTest:369-370`、`V090DisplayIdTest:609-610`。
- `V070SearchTest` を新しい形に（目的は変えない）：
  - 167〜170 行：`GET /cases/$cid/search` は `/cases/$cid#keywords` へのリダイレクトを確かめ、`GET /cases/$cid` で元のメールの全文・`Java`・`AWS`・「エンジニアに提案」、本文の無い案件で「元のメールはありません」「架空の作業内容」。
  - 195・204・205 行：`off[...]` を `kw=1`＋`on[...]`（外す項目以外の鍵を全部入れる）に。テストの中に「今の条件の鍵からある鍵を除いた `on`」を作る小さな関数を足す。
  - 211 行：`GET /cases/$cid` で `ZZ架空の技術` が出る。
  - 243 行：募集を終えた案件は今どおり `/cases/$cid` へ（変えない）。
  - 154・159 行：`/cases/$cid/search` の文字（フォームの action）は案内できる人の画面にだけ出る、で通るはず。通らなければ理由を見て直す。
- `V0110NoDirectTest:366・369`：「エンジニアに提案する」（案内メールの見出しのリンク）が担当者だけに出る、で通るはず。
- 空の POST（`V0110AdminStaffDualTest`・`V0110NoDirectTest`・`V090DisplayIdTest`）は P5 の互換で今のまま通る。
- マニュアルと README（3-10）。

## 9. テスト（`tests/Feature/V0150CaseDetailSelectTest.php`）

人・会社は見本データと、テストの中の架空のもの。アドレスは `@park.example`。メールは `mail_outbox` で確かめる。

案件詳細（A1〜A10）
- 受注 SES の担当者で `GET /cases/{id}`：`メール本文` のカードに `raw_text` の固有の文（架空）、`案件票（読み取った項目）` の `<details>`、`id="ai"`（AI のカード。編集できる人）、`id="keywords"`、「必須」「尚可」「EGに確認」、`name="on[req][]"` の項目が `checked`、`name="add_confirm"`、「エンジニアに提案」。「エンジニアとの相性」が出ない。HTML の中で「メール本文」→「案内メール」→「プロバイダーへの返信」→「この案件への応募」の順（`strpos` で比べる）、右の列で `id="ai"` が `id="keywords"` より前。
- 管理者・ほかの SES・EG で `GET /cases/{id}`：`raw_text` の固有の文が出ない、`id="keywords"` が出ない。ほかの SES には「エンジニアとの相性」が出る。
- 前に `confirm` の項目を外して保存 → 案件詳細でその項目に `checked` が無い。
- 「追加」：`POST /cases/{id}/keywords`（`kw=1`、今の鍵を全部 `on`、`add_confirm`＝「架空の確認その1」）→ `/cases/{id}#keywords` へ。案件詳細に「架空の確認その1」が `checked` で出る。`cases.confirm_items`・`required_skills`・`skill_terms` の件数は変わらない。
- 「エンジニアに提案」：`POST /cases/{id}/search`（`kw=1`、`on[req]` から1つ抜く）→ `/searches/{sid}` へ。`case_searches.conditions` の抜いた項目が `on=false`。
- `GET /cases/{id}/search` → `/cases/{id}#keywords`。管理者・EG・ほかの SES は 403。
- `kw` 無しの空の POST → 前回の状態のまま（全部オフにならない）。

選択画面（B1〜B5）
- `GET /searches/{sid}`：「エンジニアを選択」「すべてを選択」「マッチした点」「マッチしなかった点」、当たった項目が「マッチした点」の列に、当たらない項目が「マッチしなかった点」の列に（行の HTML で確かめる）、「必須 2/2　尚可 1/1」、「EGを追加」「一覧に足す」「メールアドレスを追加」。
- 追加の宛先：`POST /searches/{sid}/extras`（`extra_text`＝「架空 一郎 <x1@park.example>\nx2@park.example\nnot-an-address\nX1@park.example」）→ 2件入る（小文字、名前）、2件は理由つきで断る。登録しているエンジニアのアドレス → 断る。11 件を一度に → 10 件だけ。
- 「外す」：未送信の行が消える。送信済みの行は 404 か何もしない。

送信（B4・C1・D2・D3）
- `POST /searches/{sid}/send`（`pick` にエンジニア1人、`xpick` に追加の宛先1件）→ `case_mails` 1行（`confirm_items` にチェックした確認事項）、`case_search_extras.sent_at` が入る、`mail_outbox` 2通。どちらの本文にも「■ご確認ください（必ずご返信ください）」と項目と「必ずお答えください」の文。外した確認事項は入らない。追加の宛先の本文に「あなたに合う点」「/r/」「/eg/offers」が無く、呼び名が「架空 一郎 様」。名前の無い宛先は「ご担当者 様」。件名に `[PARK-`。知らせの文に「追加の宛先 1件にも送りました」。監査 `case-search-extra-send`。
- 確認事項を全部外して送る → 本文に「ご確認ください」が無い。
- 同じ送信をもう一度 → `mail_outbox` が増えない。
- 追加の宛先だけチェック → 追加の宛先だけ送る。
- 保存のみ（`N::canSend` が偽）→ 記録は残り、画面に「保存のみ」。

返事の画面（C2）
- 確認事項のある `case_mails` の `/r/{token}/interested`：「お答えが必須です」と項目。`POST /r/{token}`（note 空）→ `case_mails.reply` が変わらず、エラーの文。note あり → 記録される。
- 確認事項の無い案内：note 空で記録される（今どおり）。

受信（C3〜C5）
- 受信を使える設定にして（`config(['park.inbound.host' => …])`）送り、宛先 `park+<トークン>@…`・差出人＝追加の宛先のアドレスの .eml を `Inbound::ingest` → `status=linked`・`sender_kind=extra`・`search_extra_id`・`case_id`・`engineer_id` null・`from_mismatch=false`。受注 SES の担当者全員にお知らせ（「追加の宛先から案内メールへの返信」）、メールは出ない。`provider_mails` の下書きはできない。
- In-Reply-To（送ったメールの `message_id`）だけの返信も紐付く。件名の `[PARK-…]` だけで差出人が違う → 紐付かない。
- 受注 SES の担当者で `GET /cases/{id}`：「追加の宛先」に「返信メール 1 件」と `/inbound?case={id}&extra={xid}`。`GET /inbound?case=…&extra=…` で1件、差出人が「架空 一郎（x1@park.example）」で伏せない。
- 管理者で `GET /cases/{id}`：「追加の宛先」とアドレスが出る、リンクは無い。
- ほかの SES で `GET /cases/{id}`・`GET /inbound/{id}`：アドレスが出ない・404。EG：`/inbound` に入れない。
- `Inbound::unlink` → `search_extra_id` が null に戻る。

全体（D4）：`php artisan test` が全部通る。

## 10. 戻し方

- コード：v0.15.0 の commit を `git revert`。
- DB：`php artisan migrate:rollback --step=1`（足した表・列を消すだけ。`case_searches.conditions` の `confirm` は古いコードが無視するので残ってよい）。公開版での rollback はデータ（追加の宛先・その返信の紐付け）の削除になるので、TEZZO さんの了承を取ってから。

## 11. 次の版（今回は作らない）

- 「EGに確認」へのお答えを、項目ごとに画面で記録する（今はひとことの欄にまとめて書いてもらう）。
- 追加の宛先の返信から、プロバイダーへの返信の下書きを作る。
- 追加の宛先を、あとで PARK に招待する（仮登録の案内）。
- 案件の画面の案内（`/cases/{id}/mail`）にも、確認事項の節と返事の必須をそろえる。
- 宿題（L5）：EG が案件の画面（応募・見送り）から返事をするときは、確認事項への答えを必須にしていない（返事のリンクの画面だけ必須）。そろえるか決める。


# PLAN：案件の必須・尚可をキーワードに分け、合わない文は「EGに確認」へ（v0.14.0。2026-11-21）

> **これが今の計画**。前の計画（v0.13.0 以前）は、この節の下に続く（Leo が末尾に戻し済み。2026-11-21）。
> 要求：TEZZO さん（2026-11-21）「案件を登録する際、全文を DB に格納」「必須・尚可のキーワード抽出は全文登録のタイミングで」「『アジャイルスクラムマスター』→アジャイル・スクラムマスター、『AI駆動開発経験』→AI、『PM機能を対応できる人材』→PM のように、技術マスタに合致させる」「分類は必須・尚可・EGに確認の3つ。マスタに合わない文（『技術的知見を有している人材』『社員相当としての活躍』）は EGに確認 として DB に入れる」「単価などは今までどおり」。
> 方針は Leo（2026-11-21）。細部は planner が決め、4章に理由を書いた。
> プレーヤー：管理者・SES・担当者・EG の4つ（担当者＝`users.role=ses`。今回の権限は SES と担当者で同じ）。
> 外部のライブラリは足さない。手元の AI のプロンプトは変えない（AI の結果も同じ関数を通すだけ）。名前・メール・合言葉はこのファイルにもテストにも書かない（テストは架空の文、`@park.example`）。
> 作る順：段階1 → 2 → 3 → 4 → 5（段階ごとに `php artisan test` を通して commit）→ park-reviewer → park-tester。commit・push・公開版への反映は Leo。**既存の案件の書き換え（段階5のコマンドの `--apply`）は、公開版では TEZZO さんの了承を取ってから**。

## 0. コードで確かめた今の形

- 全文：`cases.raw_text`（`Workflow::CASE_FIELDS`）。取り込み（`CaseController::import` → `CaseParser::parse`）→ 案件票のフォーム → 登録（`store` → `Workflow::saveCase`）で保存される。**全文の格納はもうできている**（今回は変えない。受け入れ条件 K1 で確かめるだけ）。
- 必須・尚可の読み方：`CaseParser::ruleLines($text)` が必須・尚可の「行（文）」を返す → `CaseParser::finishSkills($req, $pref, $text)` が行を「、」でつないで `required_skills`・`preferred_skills` にし、`CaseExp::fromLines` で業務（`required_exp`・`preferred_exp`）を拾い、`SkillCatalog::detect`（本文全体、tech 分類だけ）で見つけた技術のうち必須に無いものを尚可に足す（必須が空なら必須に入れる）。
- 保存：`Workflow::normalizeCaseSkills` が `parse_list` の各項目を `SkillCatalog::ensure`（マスタに無ければ **文ごと**「確認待ち」で足す）と `find`（正式名へ）に通す。→ 「AI駆動開発経験」のような文がマスタの新語になり、必須の欄にも文が残る。
- AI：`CaseAi::queue`（登録・本文の変更で）→ 結果が届くと `compare` → `apply`（`finishSkills` → `normalizeCaseSkills`）。違いの表（`CaseController::aiView`）は「今」を `parse_list($c->required_skills)`、「AI」を AI の行で並べている。
- 採点：`Matcher::score` は `parse_list(required_skills)` の各項目を `hasSkill`（`norm_skill` → 同じか、3文字以上でゆるく一致）で数える。業務は `CaseExp::split(required_exp)`。単価・経験年数・開始・働き方は別の列。
- `parse_list` は `、 , / ・ ; |` と改行で分ける。マスタには「・」「/」を含む名前がある（`運用・保守`・`教育・育成`・`介護・福祉`・`CI/CD` など）。今は「教育・育成」が「教育」（業界）と「育成」に割れる。
- マスタ：`SkillCatalogData`（809語。分類 lang・fw・db・os・cloud・infra・tool・method・domain・role・qual）。`アジャイル`（method。別名にスクラム）、`スクラムマスター`（role）、`PM`（role）はある。`AI` 単独は無い（`生成AI`・`LLM`・`AIエンジニア`（別名 AI開発）はある）。業務の `AI 活用`（skill_form.json の caseWords に `AI`）はある。
- 案件の必須を使っている所：`Matcher`、`cases/show`（チップ）、`CaseMail::render`（`{必須}`）、`CaseSearch::conditions`・`body`、`CaseAi::skillList`、`Qa`（面談の想定質問）、`SkillChecks`（EG の「案件に合わせる」）、`EngineerController`（案件ごとのスキルシート）、`admin/cases`・`ses/cases` の一覧。
- 公開版の反映（`park-deploy.sh`）は `park:skills-sync` も動かす（マスタに「AI」が1行入る）。

## 1. 受け入れ条件

| 番号 | 立場 | 条件 |
|---|---|---|
| K1 | 管理者・SES・担当者 | 案件を取り込み → 登録すると、貼り付けた全文が `cases.raw_text` にそのまま入る（今の動きを壊さない） |
| K2 | システム | 必須・尚可の各行を、技術マスタの全分類（業務の項目も）に対して**最長一致**で分けて拾う。「アジャイルスクラムマスター」→ `アジャイル`・`スクラムマスター`、「AI駆動開発経験」→ `AI`、「PM機能を対応できる人材」→ `PM`、「Java経験3年以上」→ `Java` |
| K3 | システム | マスタの語にも業務の項目にも当たらない行は「EGに確認」になる。「技術的知見を有している人材」「社員相当としての活躍」は `cases.confirm_items` に入り、必須・尚可には入らない |
| K4 | システム | `required_skills`・`preferred_skills` にはマスタの正式名だけが入る（文は入らない）。必須にある名前は尚可に重ねない |
| K5 | システム | 拾い出しは、取り込み（フォームに結果を出し、人が確かめる）と、保存（`saveCase`。画面を通らない保存も）と、AI の結果の差し替え（`CaseAi::apply`）で、同じ関数（`App\Support\CaseKeywords`）を通る |
| K6 | システム | 文がマスタに新語として足されない。足すのは、項目そのものが空白の無い英数字1語（2〜30字。例：`Hotwire`）でマスタに無いときだけ（今のテストの動き）。そのときは今までどおり管理者に知らせる |
| K7 | システム | 元の必須・尚可の行（文）は `cases.required_lines`・`preferred_lines` に残る（新しい案件と、本文を変えた保存と、AI の差し替えで入る） |
| K8 | 管理者・SES・担当者 | 案件票のフォームに「EGに確認（1行に1つ）」の欄があり、取り込みの結果が入る。直して保存できる。必須・尚可の欄に文を書いて保存すると、拾える語は名前に、拾えない文は「EGに確認」に移る |
| K9 | 全員（案件を見られる人） | 案件の画面に「EGに確認」が出る（空なら出ない）。元の行は、直せる人（管理者・持ち込み元の SES・担当者）にだけ「元の必須・尚可の文」として出る。EG には元の行も `raw_text` も出さない |
| K10 | EG | 案内メール（案件の画面からの案内・検索からの案内）に「■ご確認ください：…」の行が出る（空なら行ごと出ない） |
| K11 | システム | 「EGに確認」は採点しない。単価・経験年数・開始・働き方の採点は今のまま |
| K12 | 管理者・SES・担当者 | AI の違いの表は「今の行」と「AI の行」を比べる（行が無い古い案件は今までどおり必須・尚可の欄で比べる）。AI の結果を使うと、必須・尚可・業務・EGに確認・元の行がまとめて置き換わる |
| K13 | システム | マスタの名前に「・」「/」があっても（`運用・保守`・`教育・育成`・`CI/CD`）、必須・尚可を読む所で割れない |
| K14 | システム | 既存の案件は migration で書き換わらない（列を足すだけ）。`php artisan park:case-keywords` は、何も書かずに変わる内容を出す。`--apply` を付けたときだけ書き換える |
| K15 | 全員 | 今のテストが通る（今の動きを確かめていたテストのうち、文が必須に入ることを確かめていたもの＝8章の一覧は、新しい動きに書き直す） |

## 2. データ（マイグレーション1本、足すだけ）

`database/migrations/2026_11_21_000001_case_keywords.php`（`cases` に3列。`after('preferred_exp')`。`down()` は3列を消すだけ）。

- `required_lines`（text、null 可）：必須の元の行。改行つなぎ。
- `preferred_lines`（text、null 可）：尚可の元の行。改行つなぎ。
- `confirm_items`（text、null 可）：EGに確認。改行つなぎ（1行1項目）。

マスタ：`SkillCatalogData::CATALOG['method']` の「データ・AI」に `'AI' => 'AI駆動開発、AI活用、人工知能'` を足す（`AI開発` は `AIエンジニア` の別名なので入れない。sync の「ぶつかり」が出ないこと）。

## 3. 仕様

### 3-1. `App\Support\CaseKeywords`（新。拾い出しの正本）

```php
// 1行（文）から拾う。['skills' => [正式名…], 'exp' => [業務の項目名…], 'new' => ?string（足してよい英数字1語）]
public static function line(string $line): array
// 必須・尚可の行から、案件の列の形にする
// ['required_skills' => [..], 'preferred_skills' => [..], 'required_exp' => [..], 'preferred_exp' => [..],
//  'confirm' => [行…], 'new' => [語…], 'required_lines' => [..], 'preferred_lines' => [..]]
public static function split(array $reqLines, array $prefLines): array
// 「、」区切りなどの欄の文字列を項目に分ける（「・」「/」はマスタの名前なら割らない）。
public static function items(?string $s): array
```

`line` の手順：

1. 行の前後の空白・箇条書きの印を外す。空なら何も返さない。
2. **行そのものがマスタの語**（`SkillCatalog::find`）なら、その正式名だけ（1文字の `C`・`R` や NO_DETECT の語も、ここで当たる）。
3. 文章から拾う：比べる文字は `mb_convert_kana($s, 'as')`。マスタの全分類の名前・別名を、**左から最長一致**で拾う（ある位置で当たる語のうち一番長いものを取り、その長さだけ進む。当たらなければ1字進む）。
   - 英数字だけの語は、前後が英数字でないときだけ当てる（`SkillCatalog::detect` と同じ前後の条件。大文字小文字は区別しない）。
   - 使わない語：1字の語（英字1字も、かな・漢字1字も）、`SkillCatalog::NO_DETECT`・`SkillCatalogData::NO_DETECT`、`CaseKeywords::NO_PICK`（下）。
   - 先に消す言い方：`CaseKeywords::SKIP = ['電気小売', 'PMワークベンチ', '可視化', '営業日']`（`SkillCatalog::STRIP` のうちマスタの語でないもの。`製造業`・`プロジェクトマネージャ試験` はマスタの語なので消さない。最長一致で `製造`・`PM` には当たらない）。
4. 業務：`CaseExp::detectLine($line)`（今のまま）。
5. `skills` も `exp` も空で、行が「空白の無い英数字1語・2〜30字・英字を含む」なら `new` にその語を入れ、`skills` にも入れる（保存のときに `ensure` する）。

`NO_PICK`（文章からは拾わない。行そのものが語なら 2. で当たる）：普通の言葉と区別がつかない短い別名。最初は次の語にし、テストで足りなければ足す。
`IT`（結合テスト）・`ST`・`EM`・`PO`・`カード`・`キャリア`・`株式`・`ガス`・`電力`・`予約`・`製造`・`運用`・`保守`・`監視`・`レビュー`・`リーダー`・`リーダ`・`パッケージ`・`公共`・`行政`・`交通`・`広告`・`宿泊`・`観光`・`賃貸`・`受託`・`基幹`・`流通`・`折衝`・`配送`・`倉庫`。
（例：「IT業界の経験」で `結合テスト` を拾わない。「運用保守」は別名 `運用保守` が長いので `運用・保守` に当たる。）

`split` の手順：

- 必須の各行・尚可の各行を `line` に通す。名前は出た順で重なりを消す。必須にある名前は尚可から外す。
- `skills` も `exp` も空の行 → `confirm`（同じ文は1つ。必須・尚可の順）。
- 業務は `CaseExp::fromLines($req, $pref)` の結果を使う（今と同じ。両方にあれば必須だけ）。
- `required_lines`・`preferred_lines` は、空でない元の行（印を外したもの）。

`items` の手順：`[,、，;；|]` と改行で分け、前後の空白を取る。分けた1つが `find` で当たればそのまま。当たらず「・」「/」「／」を含むなら、さらにそこで分ける（今の `parse_list` と同じ結果になる）。空は捨てる。

### 3-2. 読む所を `items` にそろえる（K13）

`app/helpers.php` に `function skill_items($s): array { return \App\Support\CaseKeywords::items($s); }` を足し、案件の `required_skills`・`preferred_skills` を `parse_list` で読んでいる所を `skill_items` に替える：`Matcher::score`（74・88行）、`cases/show`（14・15行）、`CaseMail::render`、`CaseSearch::conditions`、`CaseAi::skillList`、`Qa`（58・70行）、`SkillChecks`（509・510行）、`EngineerController`（368・382行）、`admin/cases`・`ses/cases` の一覧。エンジニアのスキルなど、ほかの欄の `parse_list` は変えない。

### 3-3. `CaseParser::finishSkills`（中身を差し替え。引数と戻り値の形は保つ）

```php
public static function finishSkills(array $req, array $pref, string $text): array
// 戻り値：'required_skills'（「、」つなぎ）, 'preferred_skills', 'required_exp', 'preferred_exp'（改行つなぎ。今と同じ）,
//         'detected'（今と同じ）, 'confirm_items'（改行つなぎ）, 'required_lines', 'preferred_lines'（改行つなぎ）, 'new_terms'（配列）
```

- `CaseKeywords::split($req, $pref)` の結果を使う。
- 本文全体の技術（`SkillCatalog::detect($text)`）の扱いは今のまま：必須に無いものを尚可の後ろに足す。必須が空なら必須に入れる。
- `parse` は今までどおり `finishSkills` を呼ぶので、取り込み（`import`）・`CaseAi::ruleSnapshot` にも効く。`parse` は DB に書かない（`ensure` しない）。

### 3-4. `Workflow::saveCase`・`normalizeCaseSkills`

- `CASE_FIELDS` に `confirm_items` を足す（`str_in`）。
- `normalizeCaseSkills(array $v, string $title): array` を書き直す：
  - `required_skills`・`preferred_skills` を `CaseKeywords::items` で項目にし、項目ごとに `CaseKeywords::line`。拾えた名前を欄に入れ、拾えない項目は `confirm_items` の後ろに足す（重ねない）。必須にある名前は尚可から外す。
  - `new` の語だけ `SkillCatalog::ensure`（分類 `other`、確認待ち）し、今と同じ文で管理者に知らせる（`/admin/skills`）。**文ごとの `ensure` はしない**。
  - 戻り値の `confirm_items` は改行つなぎ、空なら null。
- `saveCase`：新しい案件、または `raw_text` が保存前と違うとき、`CaseParser::ruleLines(raw_text)` で `required_lines`・`preferred_lines` を入れ直す（本文が空なら null）。それ以外の保存では触らない（AI が入れた行を消さない）。
- 業務（`_exp`）の扱いは今のまま。ただし `_exp` が無い新しい案件（見本・テスト）で `required_exp` が送られていないときは、今のまま空。

### 3-5. AI（`CaseAi`）

- `apply`：`finishSkills`（AI の行）→ `normalizeCaseSkills` のあと、`required_skills`・`preferred_skills`・`required_exp`・`preferred_exp` に加えて `confirm_items`・`required_lines`・`preferred_lines` も置き換える（AI の読み直しは必須・尚可の読み直しなので、EGに確認も読み直したものにする）。
- `snapshot`・`ruleSnapshot`・`compare` は変えない（名前どうしで比べる。EGに確認は比べない）。プロンプトも変えない。
- `CaseController::aiView`：`$now` を、`required_lines`・`preferred_lines` があればその行（改行で分ける）、無ければ今までどおり `skill_items(required_skills)`。表の下の「AI の結果を使うと」に「EGに確認：…」も出す（`$fin['confirm_items']`）。

### 3-6. 画面

- `cases/form`（登録・取り込み・編集）：
  - 必須・尚可の欄の説明（hint）を「技術マスタの名前が入ります。文を書いて保存すると、マスタの語を拾い、拾えない文は『EGに確認』に移します。」に。
  - その下に `<x-field label="EGに確認（1行に1つ）" name="confirm_items" type="textarea" rows="3" …>`。hint「マスタに無い条件です。点数には使いません。エンジニアの案件の画面と案内メールに、この文のまま出ます。会社名などは消してください。」
  - 取り込みの後と編集で、`required_lines`・`preferred_lines` があれば「元の必須・尚可の文」（`<details>`、読むだけ。送らない）。
- `CaseController::import` の知らせに「マスタに当たらない文 n 件は『EGに確認』に入れました。」を足す（n＞0 のとき）。
- `cases/show`：「尚可の業務」の下に `<dt>EGに確認</dt>`（`x-chips` ではなく、1行ずつの箇条書き。空なら出さない）。元の行は `Perms::caseEdit` の人にだけ `<details>元の必須・尚可の文</details>`。
- EG：`cases/show` の EG の表示にも「EGに確認」が出る（上の行は全員に出る）。`raw_text`・元の行は出さない（今も出していない。テストで確かめる）。

### 3-7. 案内メール

- `CaseMail`：`PLACEHOLDERS` に `{確認事項}`。`defaultBody` の `■必須：{必須}` の次の行に `■ご確認ください：{確認事項}`。`render` は `confirm_items` を「、」でつないで入れ、空なら `■ご確認ください：…` の行ごと消す（`CaseSearch::body` の尚可と同じやり方）。担当者が保存した文面（`{確認事項}` の無いもの）はそのまま。
- `CaseSearch::defaultBody`・`body`：同じく `■尚可：{尚可}` の次に `■ご確認ください：{確認事項}`、空なら行ごと消す。
- 入れるのは `confirm_items` だけ（`raw_text`・元の行は入れない）。

### 3-8. 再抽出のコマンド（`app/Console/Commands/CaseKeywords.php`、`park:case-keywords`）

- `php artisan park:case-keywords [--id=案件のid] [--apply]`。
- 対象：`required_lines` が null の案件（今までの案件）。`--id` があればその1件（行があっても対象）。
- 1件ごとに：今の `required_skills`・`preferred_skills` を元の行とみなして（`CaseKeywords::items` で分けたもの）`normalizeCaseSkills` と同じ手順で、必須・尚可・EGに確認を作る。`required_lines`・`preferred_lines` には今の項目を改行でつないで入れる。`raw_text` からは読み直さない（人が直した欄・AI の結果を壊さない）。業務の列は変えない。
- `--apply` が無いとき：DB に書かず、案件ごとに「今 → 新しい」（必須・尚可・EGに確認）を出す。最後に件数。
- `--apply`：書き換え、`audit_log` に `case-keywords`（案件のコード）を残す。マスタへの `ensure` は `new` の語だけ。メール・お知らせは出さない（管理者へのマスタのお知らせも、このコマンドではまとめて1件）。
- 手元では Leo が試してよい。**公開版での `--apply` は TEZZO さんの了承が要る**（データの書き換え）。

## 4. 決めたこと（planner。違えば Leo が直す）

| 番号 | 決めたこと | 理由 |
|---|---|---|
| D1 | 全文の格納は今の `raw_text` のまま。新しい列は作らない | もう入っている（0章）。K1 で壊れていないことだけ確かめる |
| D2 | 拾い出しの正本は `CaseKeywords` の1か所。取り込み・保存・AI・再抽出の全部が通る | 方針1・3。同じ文がどこから入っても同じ結果になる |
| D3 | マスタの全分類を対象にする（tech だけでなく method・role・domain・qual も） | 「アジャイル」「スクラムマスター」「PM」は工程・役割にある。tech だけでは要求の例が拾えない |
| D4 | 文章からは短い曖昧な別名を拾わない（`NO_PICK`）。行そのものが語なら拾う | 全分類にすると「IT」「運用」「カード」が普通の文に当たる。手で入れた語は今までどおり使える |
| D5 | 「EGに確認」は行の単位。1語でも拾えた行は「EGに確認」にしない（拾い残しの部分は捨てる。元の行は `required_lines` に残る） | 要求の例（「PM機能を対応できる人材」→PM）どおり。行の残りを全部確認事項にすると、ほぼ全行が確認事項になる |
| D6 | 「EGに確認」は1つの列（必須・尚可を分けない） | 要求は3分類（必須・尚可・EGに確認）。どちらの節の文だったかは元の行の列でわかる |
| D7 | 新語の自動追加は「空白の無い英数字1語」だけ残す | 新しい技術名（例：Hotwire）は今までどおり拾いたい。文・日本語・複数語は追加しない（今のテスト EngineerDetailTest の動きを保つ） |
| D8 | 元の行は保存する（`required_lines`・`preferred_lines`） | 文を必須の欄から外すと、条件の文が消える。AI の違いの表も行どうしで比べないと、毎回全部違って見える |
| D9 | 「EGに確認」は EG の案件の画面と案内メールに出す。出すのは人が確かめた `confirm_items` だけで、`raw_text`・元の行は出さない。フォームに「会社名などは消して」と書く | 「EGに確認」は担当者が EG に確かめる項目なので、EG が見えないと意味が無い。SH の名前の漏れは、元の文を出さないことと、担当者の目で防ぐ |
| D10 | 「その他」の分類は作らない | 単価・経験年数・開始・働き方・期間・場所はもう別の列にあり、「今までどおり」の要求どおり。マスタに当たらない条件は「EGに確認」で受けられる |
| D11 | 「EGに確認」は採点しない。Matcher は読む所を `skill_items` に替えるだけ | 要求どおり。「AI」（2字）は今の `sameSkill` では完全一致だけになるので、`生成AI` の人には当たらない（今の「AI駆動開発経験」も誰にも当たっていなかったので、悪くはならない） |
| D12 | マスタの「AI」は分類 `method`（データ・AI の並び）。別名は `AI駆動開発、AI活用、人工知能` | 生成AI・機械学習と同じ並び。tech 分類にしないので、本文全体の `detect` には入らない（本文の「OpenAI」などで尚可が増えない） |
| D13 | 業務（`AI 活用` など）は今のまま並べて拾う。マスタの語と業務の両方に入ってもよい | 業務は別の重みで数えている（v0.5.0 の決定）。重ねないようにすると、業務の点数が変わる |
| D14 | 読む所で「・」「/」を含むマスタの名前を割らない（`skill_items`） | 全分類にすると `運用・保守`・`教育・育成` が必須に入る。今の `parse_list` では「教育」（業界）と「育成」に割れる |
| D15 | 既存の案件はコマンドで直す。`raw_text` からではなく、今の欄から作り直す | 人が直した欄・AI の結果を壊さない。公開版の書き換えは了承の後 |
| D16 | 版は `v0.14.0` | 案件の項目と画面が変わる |

## 5. 画面と操作（プレーヤーごと）

| 立場 | 画面 | 見える・使う所 |
|---|---|---|
| 管理者 | 案件の登録（取り込み → 案件票）・編集 | 必須・尚可は名前、「EGに確認」の欄、「元の必須・尚可の文」。直して登録・保存 |
| 管理者 | 案件の画面 | 必須・尚可・業務・EGに確認・元の文。AI の違いの表は行どうし |
| 管理者 | 技術マスタ（`/admin/skills`） | 案件の文が「確認待ち」に増えなくなる。新しく入るのは英数字1語だけ（お知らせは今と同じ）。「AI」が増える（`park:skills-sync`） |
| 管理者 | コマンド | `park:case-keywords`（手元で確かめる。公開版の `--apply` は了承の後） |
| SES・担当者 | 案件の登録・編集（自社が持ち込み元の案件。`Perms::caseEdit`） | 管理者と同じ |
| SES・担当者 | 案件の画面 | 管理者と同じ（他社の案件で編集できないときは、元の文は出ない） |
| SES・担当者 | 案内メール（案件の画面・検索から） | 本文に「■ご確認ください：…」（空なら無し）。点数・「合う点」は必須・尚可・業務だけ |
| EG | 案件の画面（`cases/show` の EG の表示） | 必須・尚可（名前）・業務・「EGに確認」。元の文・本文は出ない。相性の点数に「EGに確認」は入らない |
| EG | 案内メール | 「■ご確認ください：…」。返信で答える（受信の紐付けは v0.12.0 のまま） |
| EG | スキルの編集の「案件に合わせる」 | 必須・尚可が名前になるので、印が付きやすくなる（画面は変えない） |

## 6. 境界の挙動

| 場面 | 挙動 |
|---|---|
| 必須・尚可の節が無い本文 | 今と同じ（必須が空なら本文の技術を必須に）。行は空、EGに確認も空 |
| 1行に複数の語（「Java、Spring、SQL」） | 全部拾う。重なりは1つ |
| 同じ語が必須と尚可に | 必須だけ |
| 「生成AIの活用経験」 | 最長一致で `生成AI`（`AI` は出ない） |
| 「OpenAI API」 | `OpenAI API`（英数字の前後の条件で `AI` は当たらない） |
| 「IT業界の経験3年」 | 何も拾わない → EGに確認（`IT` は NO_PICK） |
| 手で「C」「R」「Julia」を必須に入れる | 行そのものが語なので、そのまま名前になる |
| 手で「コミュニケーション能力」を必須に入れて保存 | 必須から外れて EGに確認へ |
| 手で「Hotwire」 | マスタに確認待ちで足し、必須に残る。管理者にお知らせ（今と同じ） |
| 「Good communication skills」 | 複数語なので足さない → EGに確認 |
| EGに確認が空 | 画面に出ない。メールの行も無い |
| 古い案件（行が無い・必須に文） | 画面はそのまま（文がチップで出る）。採点も今と同じ。コマンドで直すまで変わらない |
| 本文を変えずに編集を保存 | 元の行は変えない（AI の行を残す） |
| 本文を変えて保存 | 元の行を規則で読み直す。必須・尚可の欄は人の入力のまま（今と同じ）。AI の読み直しが入る（今と同じ） |
| 権限が無い人が案件の画面 | 今と同じ（`Perms::caseView`）。元の文は `caseEdit` の人だけ |
| AI の結果が何も読めない | 今と同じ（自動では入れない） |
| 二重の送信・二重の保存 | 結果は同じ（拾い出しは何度通しても同じ。EGに確認は重ねない） |

## 7. 了承・警告（Leo が TEZZO さんに）

- W1（了承が要る）：公開版での `php artisan park:case-keywords --apply`（既存の案件の必須・尚可・EGに確認を書き換える）。先に `--apply` 無しの出力を見せる。
- W2（報告）：公開版の反映（常時了承の範囲）で、`park:skills-sync` が技術マスタに「AI」を1行足す。全社のマスタが変わる（グループ単位の変更）。
- W3（報告）：反映の後に登録・保存した案件は、必須・尚可が名前だけになり、文は「EGに確認」に移る。他社の担当者が案内する案件の点数・案内メールの文面も変わる（グループ単位の変更）。
- W4（報告）：反映の時点で AI が作業中の案件は、結果の置き換えが新しい形になる（行・EGに確認も置き換わる）。

## 8. 作業の手順（implementer 向け）

段階1（データとマスタ）
- マイグレーション `2026_11_21_000001_case_keywords.php`（2章）。
- `SkillCatalogData` に `AI`（2章）。`php artisan park:skills-sync` のぶつかりが出ないことを確かめる。

段階2（拾い出し）
- `app/Support/CaseKeywords.php`（3-1）。`NO_PICK`・`SKIP` は定数。語の一覧は `SkillCatalog::map()` の元（`skill_terms` の名前・別名）から、長い順に並べて1回だけ作る（`SkillCatalog::forget` で一緒に消す）。
- `app/helpers.php` に `skill_items`（3-2）。
- `tests/Feature/V0140CaseKeywordsTest.php` の「拾い出し」のテストを先に書いて通す。

段階3（取り込み・保存・AI）
- `CaseParser::finishSkills`（3-3）。`Workflow::CASE_FIELDS`・`normalizeCaseSkills`・`saveCase`（3-4）。`CaseAi::apply`、`CaseController::aiView`・`import`（3-5・3-6）。
- 今のテストのうち、文が必須に入ることを確かめていたものを新しい動きに書き直す（下の一覧）。テストの目的（R9・R10・業務の拾い出し・AI の自動差し替え）は変えない。
  - `V050WorkExpTest`（207行）：`required_skills` は名前（例 `マネジメント、AIX` など、実際の結果で）、`required_lines` 相当（`finishSkills` の `required_lines`）に「進捗管理の経験」「AIX の運用」。
  - `V060RuleTest`（171行）：同じ。158・163・167行は今のままで通るはず。
  - `V060CaseAiTest`（220〜222行）：`required_lines` に「AWS の利用経験」、`preferred_lines` に「SQL の基本的な知識」、`required_skills` に `AWS`、`preferred_skills` に `SQL`、`required_skills` に `SQL` が無い。
  - `V060ExtraTest`・`EngineerDetailTest`：今のままで通るか確かめ、通らなければ理由を見て、目的を変えずに直す。

段階4（読む所・画面・メール）
- `parse_list` → `skill_items` の置き換え（3-2 の一覧）。
- `cases/form`・`cases/show`（3-6）。`CaseMail`・`CaseSearch`（3-7）。

段階5（コマンドとマニュアル）
- `park:case-keywords`（3-8）。
- マニュアル（`resources/manuals/`。直したら Leo が 41_Park-Project の `04_Manuals` の写しを直す）：
  - `admin.md`：156〜157行（マスタへの自動追加は英数字1語だけ、文は「EGに確認」）、201〜209行（取り込み・案件票の表に「EGに確認」「元の必須・尚可の文」）、212〜217行（AI の結果は EGに確認・元の文も置き換える）。
  - `admin-steps.md`（111行あたり）・`ses.md`・`ses-steps.md`：同じ内容（案件の登録・編集の手順に「EGに確認を確かめ、会社名などを消す」）。
  - `engineer.md`（110行）・`engineer-steps.md`（95行）：案件票と案内メールに「ご確認ください」があり、返信で答えること。点数には入らないこと。
- `README.md`：コマンド `park:case-keywords` の説明（`--apply` は了承の後）。

## 9. テスト（`tests/Feature/V0140CaseKeywordsTest.php`）

拾い出し（`CaseKeywords::line`・`split`。`park:skills-sync` 済みの DB で）
- K2：「アジャイルスクラムマスター」→ skills が `['アジャイル', 'スクラムマスター']`。「AI駆動開発経験」→ `AI` を含む（業務 `AI 活用` も出てよい）。「PM機能を対応できる人材」→ `PM`。「Java経験3年以上」→ `Java`。
- K3：「技術的知見を有している人材」「社員相当としての活躍」→ skills・exp とも空。`split([...], [])` の `confirm` に2つとも入る。
- 境界：「生成AIの活用経験」→ `生成AI` を含み `AI` を含まない。「OpenAI API を使う」→ `AI` を含まない。「IT業界の経験」→ skills 空。「運用保守の経験」→ `運用・保守`。行「C」→ `C`。
- K13：`skill_items('教育・育成、CI/CD、Java/Spring')` → `['教育・育成', 'CI/CD', 'Java', 'Spring']`。

取り込み（管理者で `/cases/import` に POST）
- 本文「【案件名】テスト案件\n【必須】\n・アジャイルスクラムマスター\n・技術的知見を有している人材\n【尚可】\n・AI駆動開発経験\n・社員相当としての活躍」→ 画面の必須の欄に `アジャイル、スクラムマスター`、尚可に `AI`、「EGに確認」の欄に2行、知らせに「EGに確認」。DB は変わらない（`cases`・`skill_terms` の件数が同じ）。

保存（K1・K4〜K8）
- 管理者で `/cases` に POST（受注 SES・プロバイダー・`raw_text`＝上の本文・`required_skills`＝`アジャイル、スクラムマスター、PM機能を対応できる人材`・`confirm_items`＝`技術的知見を有している人材`）→ `cases.raw_text` が本文と同じ。`required_skills` が `アジャイル、スクラムマスター、PM`。`confirm_items` に「技術的知見を有している人材」。`required_lines` に「アジャイルスクラムマスター」。`skill_terms` に「PM機能を対応できる人材」「技術的知見を有している人材」が無い（`source=auto` が増えない）。
- SES（担当者）で同じ POST → 同じ結果（受注 SES は自社）。
- `required_skills`＝`PHP、Hotwire、コミュニケーション能力` → `skill_terms` に `Hotwire`（auto）、必須は `PHP、Hotwire`、`confirm_items` に「コミュニケーション能力」、管理者にお知らせ（Hotwire だけ）。
- 本文を変えずに編集を保存 → `required_lines` が変わらない。本文を変えて保存 → 新しい本文の行になる。

AI（K5・K12）
- `CaseAi::apply` に AI の行「AI駆動開発経験」「社員相当としての活躍」を渡す → `preferred_skills`（または必須）に `AI`、`confirm_items` に「社員相当としての活躍」、行の列が AI の行。
- 結果が `done` の案件の画面（作った担当者）で、違いの表の「今」が `required_lines` の行。

画面と権限（K9・K10）
- EG（案内を受けた人）で `/cases/{id}` → 「EGに確認」と項目が出る。`required_lines` の文・`raw_text` の固有の文（本文にだけ書いた架空の会社名）が出ない。
- 他社の SES で開ける場合（`caseView`）でも「元の必須・尚可の文」が出ない。
- 担当者が案内メールを送る（`CaseMail`）→ `outbox` の本文に「■ご確認ください：技術的知見を有している人材」。`confirm_items` が空の案件では「ご確認ください」の行が無い。検索からの案内（`CaseSearch::body`）も同じ。

採点（K11）
- 同じエンジニアで、`confirm_items` だけ違う2つの案件の `Matcher::score` が同じ点。単価・経験年数・開始・働き方の点が今と同じ（今のテストが通る）。

コマンド（K14）
- 必須に文が入った古い形の案件を作り、`park:case-keywords` → DB が変わらず、出力に「今 → 新しい」。`--apply` → 必須が名前、EGに確認に文、`required_lines` に今の項目、`audit_log` に `case-keywords`。`required_lines` がある案件は対象外（`--id` を除く）。
- migration の後、既存の案件の `required_skills` が変わっていない。

全体（K15）：`php artisan test` が全部通る。

## 10. 次の版（今回は作らない）

- 「EGに確認」への EG の答えを画面で記録する（今は返信メールで受ける）。
- 面談の想定質問（`Qa`）に「EGに確認」を入れる。
- 「AI」2字と `生成AI` などをゆるく突き合わせる（`sameSkill` の見直し）。
- 手元の AI のプロンプトで、必須・尚可・EGに確認を直接分けてもらう。

# PLAN：プロバイダーへの返信（R16）と、合否の登録（R17 の最小）（v0.13.0。2026-11-20）

> **これが今の計画**。前の計画（v0.12.0 以前）は、この節の下に続く。**Leo が `git show HEAD:PLAN.md >> PLAN.md` で、このファイルの末尾に戻す**（planner は編集の道具が無く、全体を書き直さなかったため）。戻したあと `git diff --stat PLAN.md` が「追加だけ（削除 0）」になることを確かめる。
> 要件の正本：41_Park-Project の `33_要件定義/2026-11-16-01-メールの紐付け方式.md` と `2026-11-16-02-要件定義書（草案）.md` の R16・R17。v0.12.0 の計画（この下）の 10 章の下書きを引き継ぐ。
> 背景：TEZZO さんが来週火曜に阿部さんへデモ。通したい流れは「メール登録 → EG 登録 → 案件登録 → EG への案内 → EG の返信を受信 → プロバイダーへ返送」（10 章）。
> TEZZO さんの決定（2026-11-20）：「R16 まで作り切る。宛先・まとめ方は Leo の推奨で仮置き」。4 章の D は、その推奨で決めたもの（TEZZO さんに上げない）。
> 作る順：段階1 → 2 → 3 → 4 → 5（段階ごとに commit。`php artisan test` を通してから次へ）→ park-reviewer → park-tester。commit・push は Leo。**デモに要るのは段階1〜4**。
> プレーヤー：管理者・SES・担当者・EG の4つ（担当者＝`users.role=ses`。SES も担当者の1人。今回の権限は SES と担当者で同じ。管理者は案件を発信しない（v0.11.0）ので、プロバイダーへの送信・合否の登録もしない。担当者を兼ねた管理者は、担当者の立場でだけできる）。
> 外部のライブラリは足さない。手元の AI も使わない（文面はルールで作る）。名前・メール・合言葉はこのファイルにもテストにも書かない（テストは架空の会社・人、`@park.example`）。
> **公開版への反映は、7章の警告 W1〜W8 を TEZZO さんに報告してから**（グループ単位の変更のため）。

## 0. コードで確かめた今の形（v0.12.0）

- 案件は持ち込み元の会社が1つ（`cases.source_ses_id`）。プロバイダーが同じ案件を A 社・B 社に出したら、PARK では別々の案件（別の行）になる。だから「案件ごと」に送れば、自然に「SES ごとに別メール」になる。
- `cases.provider_id`（2026_11_15_000003。画面では必須、見本・古い案件は空のことがある）。`providers` は全社共通の登録（企業名・姓・名・`email` 必須。SES は見る・登録だけ、編集・削除は管理者。`ProviderController`）。
- 案内メール `case_mails`：案件×EG で1行（`CaseMail::send` が同じ案件・同じ EG には2通目を送らない）。`reply`（none／interested／question／declined）、`route`（own／group）、`ses_id`＝送った会社。案件の画面の「案内メール」（`cases/show` 64–85）。操作できるのは `CaseMail::canSend`（持ち込み元の担当者）、管理者は読むだけ。
- 受信 `inbound_mails`（v0.12.0）：`Inbound::ingest` → `match`（宛先のトークン → In-Reply-To → 件名 `[PARK-…]` → 本文）→ `notify`。紐付けの単位は `reply_tokens`（`kind`＝`case-mail`。R16 で `provider` を足す前提で作ってある）。PARK 自身（From が `MAIL_FROM_ADDRESS`）は記録しない。他社の EG の返信は、見る人が SES なら `Inbound::masked`（`secrets`・`scrub`）で氏名・ふりがな・メール・電話を伏せる。
- `Notifier::queueMail($to, $name, $subject, $body, $kind, $replyTo, $fromName)`：件名の頭に `[PARK]`、本文の後ろに PARK の署名（「PARK から自動で送っています」「park@jona.jp まで」）を必ず付ける。`message_id` を全メールに付ける。見本宛て（`@park.example`）は `deliver` で `park@jona.jp` に差し替える。
- 応募 `applications`（案件×EG で一意、`ses_id`＝EG の所属）と `Workflow::setStatus`（submitted・doc_passed・rejected…）。見えるのは `Perms::app`（管理者・EG の所属の会社・本人）。持ち込み元と EG の所属が違う（他社の EG）とき、持ち込み元は応募の画面を見られない。応募を作る道は「書類を返した（応募した）」（自社の EG だけ）。
- 公開版は受信の設定のうち `PARK_IMAP_PASSWORD` だけが空（92_FromJoe の返事待ち B）。入るまで受信は動かず、案内メールの返信先も今のまま。

## 1. 受け入れ条件

| 番号 | 立場 | 条件 |
|---|---|---|
| AC1 | システム | EG の返信（受信メール）が案内メールに紐付くと、その案件のプロバイダー宛の「下書き」が自動で1つできる（`provider_mails`、状態 `draft`）。同じ受信メールから2つは作らない。不達・自動返信・担当者自身からの返信・未紐付けでは作らない |
| AC2 | SES・担当者 | 下書きの宛先は、案件に付けたプロバイダーの企業名・姓名・メール。下書きの単位は「案件×EG（受信メール1通）」。複数の EG が返信したら、EG ごとに別の下書き |
| AC3 | SES・担当者 | 下書きの本文は、ルールで作った定型文に、EG の返信本文の要点（引用・署名・案内メールの写し・返事のリンク・システム伝達項目・URL を取り除いたもの）を字下げして入れたもの。本人の意向（応募したい・質問あり・見送り）で文面が変わる |
| AC4 | SES・担当者 | プロバイダーに出る EG の呼び名はイニシャルだけ（自社の EG でも）。本文の中の EG の氏名はイニシャルに、ふりがな・メール・電話は「（伏せています）」に置き換わっている |
| AC5 | SES・担当者 | 持ち込み元の担当者（SES・担当者のだれでも）が、下書きの意向・件名・本文を直して保存できる。［作り直す］で元から作り直せる（直した分は消えると確認を出す）。［取りやめる］で送らずに閉じられる |
| AC6 | SES・担当者 | ［送信］を押すと、確認のあと、プロバイダーのメールアドレスへ1通送る。勝手には送らない（送るのは担当者が押したときだけ） |
| AC7 | SES・担当者 | 送る前に、本文に EG の登録の氏名・ふりがな・メール・電話が残っていたら止め、どれが残っているかを出す。「このまま送る」に印を付けたときだけ送れる |
| AC8 | システム | 送るメール：件名の頭に `[PARK]` を付けない、PARK の署名を付けない。差出人の名前は「{会社名} {担当者の氏名}」、本文の最後に会社名と担当者の氏名、件名の最後に `[No.<トークンの頭10字>]`、本文の最後に「お問い合わせ番号：No.<同じ>」。返信先は、受信が使えるとき `park+<トークン>@…, <送った担当者のアドレス>`、使えないとき送った担当者のアドレスだけ |
| AC9 | システム | 送ると `reply_tokens` に `kind=provider` の行（案件・会社・EG・`provider_mail_id`・`outbox_id`・`message_id`）ができる。`provider_mails` は `sent`、送った人・日時・`outbox_id` が残る。`audit_log` に残る（アドレスは書かない） |
| AC10 | システム | 送ると、その案内メールの返事が「返事なし」なら下書きの意向で記録し、意向が「応募したい」で結果が空なら、結果を「結果待ち」にする |
| AC11 | EG | 送ると、EG 本人に「担当者が先方へご返事を伝えました」のお知らせ（メールなし）。他社の EG なら、EG の所属の会社の担当者全員にもお知らせ（メールなし） |
| AC12 | システム | プロバイダーの返事（宛先のトークン／In-Reply-To／件名の `[No.…]`＋差出人がプロバイダーのアドレス）を、v0.12.0 と同じ受信の仕組みで受け、`sender_kind=provider` で案件・会社・EG・`provider_mail_id` に紐付ける |
| AC13 | SES・担当者 | プロバイダーの返事は、持ち込み元の担当者全員にお知らせ（メールなし。返信先に担当者のアドレスもあるので、本人のメールは直接届いている）。「返信メール」の一覧・詳細では差出人が「{企業名}（プロバイダー）」。未読は「今やること」に出る |
| AC14 | SES・担当者 | 案件×EG（案内メールの行）に、結果を「結果待ち／合格／不合格」と、メモ（任意。1000 字まで）で登録できる。登録できる場所は、案件の画面の「案内メール」の行・プロバイダーへの返信の画面・プロバイダーの返事の詳細 |
| AC15 | EG | 合格・不合格を登録すると、EG 本人にお知らせ（メールなし。メモは出さない）。他社の EG なら、EG の所属の会社にもお知らせ（メールなし。メモは出さない）。「結果待ち」はお知らせなし。同じ値をもう一度押しても何もしない |
| AC16 | システム | その案件×EG に応募（`applications`）があれば、応募の記録に「先方の結果：合格／不合格（担当者が登録）」を1行足す（メモは入れない。応募の状態は変えない） |
| AC17 | 管理者 | 全社の下書き・送ったメール・結果・メモを読める。送る・直す・結果を登録するはできない（403）。担当者を兼ねた管理者は、兼ねた会社の案件で担当者としてできる |
| AC18 | 担当者 | AC1〜AC16 が SES と同じに働く（同じ会社の担当者全員。下書きのお知らせ・今やることも全員） |
| AC19 | 全員 | 他社の SES・担当者は、下書き・送ったメール・プロバイダーの返事を開けない（404）。EG は開けない（403） |
| AC20 | SES・担当者 | 案件にプロバイダーが付いていないと、下書きはできるが送れない（「案件の編集でプロバイダーを選んでから送ってください」）。プロバイダーが消されていても同じ |

## 2. データ（マイグレーション1本、足すだけ）

`database/migrations/2026_11_20_000001_provider_replies.php`。`down()` は足したものを消すだけ。外部キーは付けない（v0.12.0 と同じ。案件・EG・プロバイダーを消しても送った記録は残す）。

- `provider_mails`（新）：`id`、`case_id`（index）、`ses_id`（index。送る会社＝作ったときの案件の持ち込み元）、`provider_id`（null 可）、`engineer_id`（null 可、index）、`case_mail_id`（null 可、index）、`inbound_mail_id`（null 可、**unique**）、`reply`（string 12。interested／question／declined）、`to_email`（string 190、null 可）・`to_name`（string 150、null 可）（送ったときの写し）、`subject`（string 255）、`body`（text）、`status`（string 10。`draft`／`sent`／`discarded`、index）、`note`（text、null 可。取りやめの理由など）、`created_by`（null 可。自動で作ったときは空）、`updated_by`（null 可）、`sent_by`・`sent_at`・`outbox_id`・`reply_token_id`（null 可）、`created_at`・`updated_at`。
- `case_mails`：`result`（string 10、null 可。pending／passed／failed）、`result_note`（text、null 可）、`result_by`（null 可）、`result_at`（null 可）。
- `inbound_mails`：`sender_kind`（string 10、既定 `eg`。eg／provider）、`provider_mail_id`（null 可、index）。
- `reply_tokens`：`provider_mail_id`（null 可、index）。

## 3. 仕様

### 3-1. 下書きを作る（`app/Services/ProviderReply.php`。新）

`ProviderReply::draftFromInbound(int $inboundId): ?int`
- 作る条件（全部）：`status=linked`、`sender_kind=eg`、`auto` が空、差出人がその会社の担当者でない（`Inbound::fromStaff` を public に）、案件がある、`ses_id` がある、同じ `inbound_mail_id` の行が無い。
- 呼ぶ所：`Inbound::ingest` の紐付けのあと（`notify` の前）、`Inbound::link`（手で紐付けたとき）の `notify` の前。
- 中身：`provider_id`＝今の `cases.provider_id`、`case_mail_id`・`engineer_id`＝受信メールの値、`reply`＝案内メールの `reply`（none なら interested）、件名・本文＝3-2、`status=draft`、`created_by=null`。insert が unique に当たったら何もしない（同時の取り込み）。

`ProviderReply::draftFromCaseMail(int $caseMailId, $u): int`（受信メールが無いとき。返事のリンクで「応募したい」が来た・電話で聞いた、など）
- 案件の画面の「案内メール」の行の［プロバイダーへの返信を作る］から。`inbound_mail_id=null`、引用は空（「（本人のコメントはありません）」）。同じ案内メールに `draft` の行があれば、新しく作らずにそれを開く。

`ProviderReply::redraft($u, $id)`：`draft`・`discarded` の行を、元（受信メールか案内メール）から作り直す（件名・本文・意向を上書き、`status=draft`）。取りやめた下書きを戻すのもこれ。

### 3-2. 文面（ルール。`ProviderReply::compose($case, $provider, $sesName, $e, $reply, $quote)`）

件名：`【ご返事】{案件名}`（プロバイダーが分からないときも同じ）。

本文（`{…}` は差し込み。プロバイダーが空なら頭の2行は「ご担当者 様」）：

```
{プロバイダーの企業名}
{姓}{名} 様

いつもお世話になっております。{送る会社の名前}です。
ご紹介いただいた案件「{案件名}」について、エンジニアの意向を確認しましたので、ご連絡いたします。

■エンジニア：{イニシャル}
■本人の意向：{意向の文}
■本人からのコメント：
{引用。各行の頭に全角空白。空なら「（本人のコメントはありません）」}

{締めの文}
```

| 意向 | 意向の文 | 締めの文 |
|---|---|---|
| interested | 参画を希望しています | スキルシート（業務経歴書）は、別途お送りいたします。ご検討のほど、よろしくお願いいたします。 |
| question | 条件について、確認したいことがあります | 上記について、ご回答いただけますと幸いです。 |
| declined | 今回は見送りたいとのことです | せっかくご紹介いただいたのに申し訳ありません。またの機会によろしくお願いいたします。 |

- イニシャル：`engineers.initials`、無ければ「エンジニア」。会社名（EG の所属）は書かない（自社・他社とも）。
- 意向を画面で変えて［保存］すると、本文は変えない（直した文を消さない）。意向の文を変えたいときは［作り直す］。
- 送るときに足すもの（下書きには入れない）：本文の最後に `\n\n{送る会社の名前}\n{送った担当者の氏名}`、その下に `\n\n――――――――――\nお問い合わせ番号：No.{トークンの頭10字}\n（ご返信の際は、件名の番号を消さないでください）`。件名の最後に ` [No.{同じ}]`。

### 3-3. 引用の取り出しと伏せ方

`ProviderReply::extract(string $body, object $e): string`
1. 上から読み、次の行が来たら、そこから下を捨てる：`>` で始まる行、`-----Original Message-----`、`On … wrote:`、`…年…月…日…(<…>)? :?$`／`…のメッセージ:`／`…書きました`、`差出人:`／`From:`、`――――――――――`（PARK の区切り）、`-- `（署名の区切り）、`［システム伝達項目`、`▼ご返事はこちらから`。
2. 残りから、URL（`http://`・`https://` で始まる語）と `park+…@…` の形を消す（返事のリンクや返信先のトークンをプロバイダーに出さない）。
3. 前後の空行を詰め、2000 字で切る（切ったら「…（以下略）」）。
4. EG の伏せ：`Inbound::secrets($e, $initials)` を使って置き換える（氏名→イニシャル、ふりがな・メール・電話→「（伏せています）」）。`secrets`・`scrub` は `Inbound::scrubEngineer(object $e, string $text, string $label): string` として public にまとめる（中の決まりは変えない）。

`ProviderReply::leaks(object $e, string $text): array`：本文に残っている EG の登録の値の種類（「氏名」「ふりがな」「メール」「電話」）。判定は `secrets` と同じ値で、大文字小文字・電話の数字だけの比較も同じ。1字の姓は `scrub` と同じく敬称つきだけ。

### 3-4. 送る（`ProviderReply::send($u, int $id, bool $force): array`。戻り `['type' => 'ok'|'bad', 'msg' => 文]`）

1. `canEdit($u, $pm)` でなければ 403（コントローラー）。行をロック（トランザクション＋`lockForUpdate`）。`status` が `sent` なら「もう送っています」、`discarded` なら「取りやめた下書きです。［作り直す］で戻してください」。
2. プロバイダー：今の `cases.provider_id` を読み直して `provider_mails.provider_id` に入れる。空・行が無い・メールの形が違う → AC20 の文で止める。
3. 件名（1〜200 字）・本文（1〜20000 字）が空なら止める。
4. `leaks` があって `$force` が無い → 「本文に {種類} が残っています。直すか、『このまま送る』に印を付けてください。」で止める。
5. `ReplyTokens::issue(ReplyTokens::KIND_PROVIDER, case, ses, engineer, Inbound::enabled())`。
6. 返信先：受信が使えるとき `Inbound::replyAddress($token).', '.$u->email`、使えないとき `$u->email`。
7. `N::queueMail($to, $toName, $件名, $本文, 'provider-mail', $返信先, "{会社名} {担当者の氏名}", plain: true)`。`queueMail` に引数 `bool $plain = false` を足し、`true` のときは件名の `[PARK]` と PARK の署名を付けない（ほかは今のまま。見本宛ての差し替えも効く）。
8. `provider_mails`：`status=sent`、`to_email`・`to_name`（「{企業名} {姓}{名} 様」）、送った件名・本文、`sent_by`・`sent_at`・`outbox_id`・`reply_token_id`。`ReplyTokens::attach` の許す項目に `provider_mail_id` を足して、`provider_mail_id`・`outbox_id`・`message_id` を入れる。
9. 案内メール（`case_mail_id` があれば）：`reply=none` なら `CaseMail::reply(…, $pm->reply, null, 'ses')`。`reply=interested` で `result` が空なら `result=pending`（お知らせなし）。
10. お知らせ（AC11）：EG 本人へ `N::toEg($eid, "先方へご返事を伝えました：{案件名}[CA-]", "案件「{案件名}」について、担当者が先方へあなたのご返事を伝えました。", "/cases/{id}", false, 'provider-mail')`。他社の EG なら `N::toSes(EG の会社, "グループの案件で、貴社のエンジニアのご返事を先方へ伝えました：{案件名}[CA-]", "{氏名} さんのご返事を、{送る会社の名前}が先方へ伝えました。", "/cases/{id}", false, 'provider-mail')`。
11. `N::audit($u->id, 'provider-mail-send', "#{id} 案件 {CA-} → {PV-}（{意向}）")`。
12. 戻り：「{企業名} {姓} 様へ送りました。」（`N::canSend()` が偽なら「送信の設定が無いため、保存だけしました」）。

### 3-5. プロバイダーの返事を受ける（`Inbound` を直す）

- `ReplyTokens::KIND_PROVIDER = 'provider'`。
- `match`：
  - 1（宛先のトークン）・2（In-Reply-To）は種類を問わない。`linkFields` で、トークンが `provider` なら `sender_kind=provider`・`provider_mail_id`、`case_mail_id` は `provider_mails.case_mail_id`、`from_mismatch` は差出人が `provider_mails.to_email`（と今の `providers.email`）と違うとき。
  - 3・4（返信の形のときだけ。今の決まり）：件名の `[No.xxxxxxxxxx]`／本文の「お問い合わせ番号：No.xxxxxxxxxx」→ 頭 10 字が合う `kind=provider` のトークンが1件だけで、差出人がそのプロバイダーのアドレスのときだけ。今の `[PARK-…]`・「案内番号」は `kind=case-mail` のトークンだけに当てる。
- `notify`：`sender_kind=provider` → `N::toSes(ses, "プロバイダーから返信：{案件名}[CA-]（{イニシャル}）", 本文の頭 200 字, "/inbound/{id}", false, 'inbound')`。
- `notify`（EG の返信）：下書きができていれば、お知らせの本文の最後に「プロバイダーへの返信の下書きを作りました。確かめて送ってください。」（他社の EG のメールの本文も同じ文を足す。返信の中身は今どおり入れない）。
- `senderLabel`：`sender_kind=provider` → 「{企業名}（プロバイダー）」（管理者・SES とも。行が消えていれば「プロバイダー」）。
- `masked`：`sender_kind=provider` のときは差出人（プロバイダーの名前・アドレス）を伏せない。本文の中の他社の EG の氏名などは今どおり伏せる。
- `Todo::ses` の返信メールの行：プロバイダーなら「{企業名} から返信：{案件名}」。
- `unlink`：その受信メールから作った `draft` の下書きは `discarded`（`note`＝「受信の紐付けを外したため」）。`sent` は残す。紐付け直したら、`redraft` で戻す（自動では作り直さない）。

### 3-6. 合否の登録（R17 の最小。`app/Services/CaseResult.php`。新）

`CaseResult::set($u, int $caseMailId, string $result, ?string $note): array`
- `RESULTS = ['pending' => '結果待ち', 'passed' => '合格', 'failed' => '不合格']`。それ以外は断る。メモは 1000 字まで（任意）。
- 権限：案件の `CaseMail::canSend($u, $case)`。管理者は 403。
- 同じ結果・同じメモ → 「変わっていません」（何もしない）。
- `case_mails` の `result`・`result_note`・`result_by`・`result_at` を入れる。`N::audit($u->id, 'case-result', "{CA-} {EG-} → {結果}")`（メモは書かない）。
- 応募があれば（`applications` の案件×EG）：`Workflow::addEvent($appId, 'note', '先方の結果：{結果}（担当者が登録）', $u)`（合格・不合格のときだけ。メモは入れない。状態は変えない）。
- お知らせ（合格・不合格のときだけ。メールなし。メモは入れない）：
  - EG 本人：合格「先方から合格の連絡がありました：{案件名}[CA-]」本文「担当者から、次のご連絡をお待ちください。」／不合格「今回は見送りとなりました：{案件名}[CA-]」本文「次の案件に活かします。」。
  - 他社の EG なら EG の所属の会社に「グループの案件の結果：{案件名}[CA-]」本文「{氏名} さん：{結果}」。
- 応募の画面（`/applications/{id}`）を持ち込み元が見られない件（0 章）は今回変えない（11 章）。

### 3-7. 見える範囲と権限（`ProviderReply::canView`／`canEdit`）

| 立場 | プロバイダーへの返信（下書き・送ったもの） | プロバイダーの返事（受信） | 結果（合否・メモ） |
|---|---|---|---|
| 管理者 | 全社を読む。直す・送る・取りやめるは 403 | 全部読む（伏せない） | 読む（メモも）。登録は 403 |
| SES・担当者（持ち込み元の会社） | 読む・直す・送る・取りやめる・作り直す | 読む（他社の EG の氏名などは伏せる） | 読む・登録する |
| SES・担当者（EG の所属の会社が別） | 開けない（404）。お知らせだけ届く | 開けない（404） | 合否のお知らせだけ（メモなし） |
| SES・担当者（関係ない会社） | 404 | 404 | なし |
| EG | 403 | 403 | 合否のお知らせだけ（メモなし） |

- 見えるかは「今の持ち込み元」で決める（`cases.source_ses_id = users.ses_id`。v0.12.0 の受信と同じ）。下書きを作ったあと持ち込み元が変わったら、新しい持ち込み元が見る・送る。
- 他社の EG の表示（画面）：持ち込み元の担当者には `CaseThread::egName`（「イニシャル（会社名）」）。管理者には氏名。プロバイダーへは 3-2 のとおりイニシャルだけ。

## 4. 決めたこと（Leo の推奨で仮置き。違えば直す）

| 番号 | 決めたこと | 理由 |
|---|---|---|
| D1 | 宛先は、送るときの `cases.provider_id` のプロバイダーのメール（送った値は `provider_mails` に写して残す） | TEZZO さんの方針。案件の登録で必須。あとで消されても記録が残る |
| D2 | **1通＝案件×EG（受信メール1通）**。複数の EG をまとめて1通にしない | プロバイダーの合否は EG ごと。返事も EG ごとに紐付けられる（トークンが1人に1つ）。案件は会社ごとの行なので、SES ごとにも分かれる |
| D3 | 受信したら下書きを自動で作り、送るのは担当者の［送信］だけ | TEZZO さんの方針（勝手に送らない）。デモでも手数が少ない |
| D4 | 文面はルールで作る。手元の AI は使わない | 個人・案件の情報を外に出さない。デモまでに確実に動く。AI は次の版 |
| D5 | プロバイダーに出す EG はイニシャルだけ（自社の EG も、所属の会社名も出さない）。本文の氏名・連絡先は自動で伏せ、残っていれば送る前に止める（「このまま送る」で越えられる） | 「他社には EG はイニシャル」の決まりに合わせる。プロバイダーは PARK の外。所属の会社名を出すと、SES 同士の商流が見える |
| D6 | プロバイダーへのメールは件名の `[PARK]` と PARK の署名を外し、差出人の名前を送る会社と担当者にする。件名の印は `[No.…]`（`[PARK-…]` にしない） | 「SH には当面知られないように進める」（41_Park-Project の CLAUDE.md）。ただし送信元アドレスは隠せない（7章 T1） |
| D7 | 返信先は PARK（トークン）と送った担当者の2つ | v0.12.0 の D3 と同じ考え。PARK に記録を残しつつ、担当者にも直接届く。受信が使えない間も、担当者には届く |
| D8 | プロバイダーの返事のお知らせはメールなし | 返信先に担当者がいるので、本人のメールは直接届いている（v0.12.0 の D6 と同じ） |
| D9 | 合否は案件×EG（`case_mails`）に持つ。応募（`applications`）の状態は変えず、記録を1行足すだけ | 他社の EG には応募の行が無いことがある（0 章）。最小で全員に効く形。応募の状態と合わせるのは次の版 |
| D10 | 合否を登録できるのは持ち込み元の担当者だけ。管理者は読むだけ | プロバイダーとやり取りするのは持ち込み元（v0.11.0 の「管理者は発信しない」と同じ線） |
| D11 | 合否のメモは、持ち込み元と管理者だけが見る。EG と EG の所属の会社には結果だけ | プロバイダーの率直な評価を、そのまま本人・他社に出さない |
| D12 | 合否・送信のお知らせは、全部メールなし（ベルだけ） | 押し間違えて直したときに、メールが何通も行かないように。メールは次の版で見直す |
| D13 | 一覧の画面は作らない。入口は、受信メールの詳細・案件の画面・今やること・お知らせ | 最小。デモの流れはこれで通る |
| D14 | 版は `v0.13.0` | プロバイダーへの送信という新しい機能 |

## 5. 画面と操作（プレーヤーごと）

| 立場 | 画面 | 操作 |
|---|---|---|
| SES・担当者 | 受信メールの詳細（`inbound/show`）：EG の返信なら、枠「プロバイダーへの返信」に下書きの状態（下書き・送信済み・取りやめ）と［開く］。無ければ［下書きを作る］ | — |
| SES・担当者 | 受信メールの詳細：プロバイダーの返事なら「プロバイダーからの返信です（{企業名}）」、送ったメールへのリンク、結果の登録（3-6） | 結果を選ぶ・メモ・［登録］ |
| SES・担当者 | **プロバイダーへの返信**（`/provider-mails/{id}`。新）：上に案件（CA-）・宛先（企業名・姓名 様・メール。プロバイダーが無ければ赤字で AC20 の文）・エンジニア（自社は氏名、他社は「イニシャル（会社名）」）・元の返信へのリンク。注意書き「プロバイダーには、エンジニアはイニシャル『{イニシャル}』で伝わります」。残っている個人情報（`leaks`）があれば赤字で種類 | 意向（選ぶ）・件名・本文を直して［保存］。［送信］（押すと「{企業名} {姓} 様（{メール}）へ送ります。よろしいですか」の確認）。`leaks` があるときだけ「このまま送る」の印。［作り直す］（「直した文は消えます」の確認）。［取りやめる］（理由を書ける） |
| SES・担当者 | 同じ画面（送ったあと）：読むだけ。送った日時・送った人・メールの状態（送信済み・保存のみ・失敗）、「プロバイダーからの返信 n 件」（`/inbound?pm={id}`）、結果の登録 | 結果の登録 |
| SES・担当者 | 案件の画面：「案内メール」の表に列「先方の結果」（結果とメモ、登録のフォーム）と、行に［プロバイダーへの返信を作る］（下書きがあれば［下書きを開く］）。新しい枠「プロバイダーへの返信」（この案件の下書き・送ったもの：エンジニア・意向・状態・日時・［開く］） | — |
| SES・担当者 | 今やること：「プロバイダーへの返信の下書き：{案件名}（{呼び名}）」→ `/provider-mails/{id}`（`draft` の間ずっと） | — |
| 管理者 | 上の画面はすべて読むだけ（ボタン・フォームを出さない）。案件の画面の「先方の結果」はメモも見える | — |
| EG | 変わらない（お知らせが届くだけ） | — |

ルート：`role:admin,ses` に `GET /provider-mails/{id}`・`POST /provider-mails/{id}`（保存）・`POST /provider-mails/{id}/send`（throttle 20,1）・`/redraft`・`/discard`・`POST /inbound/{id}/provider-draft`・`POST /case-mails/{id}/provider-draft`・`POST /case-mails/{id}/result`。POST は管理者に 403（中で `canEdit`）。`GET /inbound` に `pm` の絞り込み。コントローラーは `ProviderMailController`（新）と `CaseMailController::result`。

## 6. 境界の挙動

| 場面 | 挙動 |
|---|---|
| 権限がない | 他社の SES が `/provider-mails/{id}` → 404。管理者が POST → 403。EG → 403。他社の SES が `/case-mails/{id}/result` → 403（今の `CaseMailController::load` と同じ） |
| 案件にプロバイダーが無い・消された | 下書きはできる（宛先の欄に赤字）。送れない（AC20） |
| プロバイダーのメールが見本（`@park.example`） | 送れる。`deliver` で `park@jona.jp` に差し替わる（件名に［ダミー宛］）。From が PARK なので、その写しは受信しても記録されない（v0.12.0 の決まり） |
| 二重の操作 | ［送信］の2回押し → 2回目は「もう送っています」（ロック）。同じ受信メールの下書きは1つ（unique）。合否の同じ値 → 「変わっていません」 |
| 送ったあとに直したい | 直せない（読むだけ）。続きはメールソフトで（返信先に担当者がいる）。次の版で「続きを送る」 |
| 取りやめた下書き | ［作り直す］で `draft` に戻る |
| 同じ EG が2回返信した | 受信メールごとに下書きが1つずつ（2つ）。今やることに2つ出る。要らない方は［取りやめる］ |
| 受信の紐付けを外した | その下書き（`draft`）は取りやめに。送ったものは残る |
| 案件の持ち込み元が変わった | 新しい持ち込み元が見る・送る。送ったものの `ses_id` は送った会社のまま |
| EG が退会・停止 | 下書き・送信・合否の登録はできる（記録のため）。お知らせは届かない（`egUsers` の今の決まり） |
| 受信が使えない（`.env` のパスワードが空） | EG の返信が取り込まれないので、自動の下書きはできない。案件の画面の［プロバイダーへの返信を作る］で作れる。送るメールの返信先は担当者だけ |
| 0件 | 枠「プロバイダーへの返信」は「まだありません。エンジニアの返信が届くと、下書きが自動でできます」 |
| 本文の引用が全部消えた | 「（本人のコメントはありません）」 |
| メールの送信が失敗 | `provider_mails` は `sent`（押した記録）。画面にメールの状態「失敗」と、管理者の「メール」画面で再送できる旨 |

## 7. TEZZO さんへの確認

### 警告：グループ単位の変更（公開版に反映する前に報告する）

- **W1** 他社の EG（B 社の F）の返信の要点が、A 社の担当者の［送信］で、A 社のプロバイダー（PARK の外）に送られる。氏名・ふりがな・メール・電話は登録の値で伏せ、残れば止めるが、別の書き方（ローマ字の名前・署名の会社名など）は伏せきれない。担当者は本文を自由に直せる（伏せた所を戻すこともできる）。
- **W2** B 社の担当者全員に「グループの案件で、貴社のエンジニアのご返事を先方へ伝えました（{F の氏名}）」のお知らせが届く（新しく届くもの）。
- **W3** A 社の担当者が合格・不合格を登録すると、F 本人と B 社の担当者全員にお知らせが届く（メモは届かない）。A 社が押し間違えると、B 社にも間違った結果が届く。
- **W4** プロバイダーの返事（受信）は A 社と管理者だけが見る。F の所属の B 社には見えない（結果のお知らせだけ）。
- **W5** 管理者は、全社のプロバイダーへのメール・下書き・合否のメモを読める。公平性のルール・誓約書の対象。
- **W6** プロバイダーの登録は全社共通。A 社が、B 社の登録したプロバイダーを案件に選んで、そこへメールを送れる（今も選べる。送信が加わる）。
- **W7** 同じ EG が、別の SES の案件を通して、同じプロバイダーに二重に提案されうる（PARK は別の案件として扱い、検知しない）。
- **W8** 案件の持ち込み元が変わると、前の会社が作った下書きも新しい会社が見る・送る。

### Leo が推奨で決めた件（4章の D1〜D14。TEZZO さんに上げない）

- 特に D2（1通＝案件×EG）、D5（イニシャルだけ）、D6（[PARK] と署名を外す）、D9（合否は案内メールの行に）。違えば言ってもらい、直す。

### TEZZO さんに上げる件

- **T1（外の人に見せる内容）** プロバイダーに届くメールの送信元は `park@jona.jp`、返信先は `park+…@jona.jp`（と担当者）。件名の [PARK] と PARK の署名は外したが、アドレスの「park」と jona.jp は見える。「SH には当面知られないように進める」と食い違う。阿部さんへのデモでは問題ないが、本物のプロバイダーに送り始める前に決めてほしい（推奨：当面は自社の EG の案件で、阿部さんの了解のうえで使う。隠す必要があれば、次の版で送信元を各 SES のアドレスにする方式を検討）。
- **T2（了承）** W1〜W8 を承知のうえで公開版に反映してよいか。
- **T3（送信）** デモで実際にプロバイダー宛てのメールを送ること（10 章）。

## 8. 作業の手順（implementer 向け）

### 段階1：データと送る土台

1. マイグレーション（2章）。`migrate` と `migrate:rollback` を確かめる。
2. `ReplyTokens::KIND_PROVIDER`、`attach` の許す項目に `provider_mail_id`。
3. `Notifier::queueMail` に `bool $plain = false`（3-4 の 7）。今の呼び出しは変えない。
4. `Inbound`：`secrets`・`scrub` を `scrubEngineer` として public に、`fromStaff` を public に（中身は変えない）。
5. テスト（T-1〜T-3）。

### 段階2：下書きと送信（R16）

1. `app/Services/ProviderReply.php`：`compose`・`extract`・`leaks`・`draftFromInbound`・`draftFromCaseMail`・`redraft`・`save`・`discard`・`send`・`canView`・`canEdit`（3-1〜3-4、3-7）。
2. `Inbound::ingest`・`link` から `draftFromInbound`、`notify` の文の追加、`unlink` で取りやめ（3-5）。
3. テスト（T-4〜T-20）。

### 段階3：プロバイダーの返事の受信と画面

1. `Inbound::match`・`linkFields`・`notify`・`senderLabel`・`masked`（3-5）、`Todo::ses`（下書き・プロバイダーの返事）。
2. `ProviderMailController` とルート（5章）、`resources/views/provider-mails/show.blade.php`（SES と管理者で共通。管理者はボタンを出さない）。
3. `inbound/show` の枠、`cases/show` の枠と列（`CaseController::show` で、案件の `provider_mails` と案内メールの `result` を1回の問い合わせで）。`/inbound?pm=` の絞り込み。
4. テスト（T-21〜T-30）。

### 段階4：合否の登録（R17 の最小）

1. `app/Services/CaseResult.php`（3-6）、`CaseMailController::result`。
2. 結果のフォーム（部分テンプレート `resources/views/cases/_result.blade.php` を、案件の画面・プロバイダーへの返信の画面・受信メールの詳細で使う）。
3. テスト（T-31〜T-38）。

### 段階5：マニュアル・README・版

1. `config/park.php` の `version` を `v0.13.0`。
2. マニュアル（`resources/manuals/`）：
   - `ses.md`・`ses-steps.md`：「プロバイダーへの返信」（下書きが自動でできる、確かめて送る、イニシャルで伝わる、残った個人情報で止まる、取りやめ・作り直し）、プロバイダーの返事の届き方、「先方の結果」の登録とだれに届くか。担当者も同じことができると書く。
   - `engineer.md`・`engineer-steps.md`：返信の要点が、名前を伏せて案件の先方に伝わること。先方の結果がお知らせで届くこと。
   - `admin.md`・`admin-steps.md`：全社のプロバイダーへのメール・結果を読めること（操作はしない）、公平性。
3. README：v0.13.0 の機能、`provider-mail` のメールの種類、受信が使えないときの動き。
4. 直したら Leo が 41_Park-Project の `04_Manuals` の写しを写し直す（写しのテストも通す）。

## 9. テスト（`tests/Feature/V0130ProviderReplyTest.php` ほか）

受信が使えるかは v0.12.0 と同じく `config(['park.inbound.host' => …])` で切り替える。メールは `mail_outbox` を見る。受信は `.eml` の文字列を `Inbound::ingest` に渡す（`tests/Fixtures/mail/` に架空のものを足す）。会社は A 社（案件の持ち込み元）・B 社（他社の EG の所属）・C 社（関係ない）。プロバイダーは `@park.example`。

| 番号 | 立場・手順 | 確かめること |
|---|---|---|
| T-1 | `queueMail(…, plain: true)` | 件名に `[PARK]` が無い。本文に PARK の署名が無い。`message_id` はある |
| T-2 | `queueMail` を今までどおり | 件名・署名は今のまま |
| T-3 | `migrate:rollback` | 足した表・列が消える |
| T-4 | A 社の担当者が自社の EG に案内 → EG の返信（宛先トークン）を取り込む | `provider_mails` が1行（`draft`・宛先のプロバイダー・`case_mail_id`・`inbound_mail_id`）。お知らせの本文に「下書きを作りました」 |
| T-5 | 他社（B 社）の EG の返信 | 下書きの本文の EG はイニシャルだけ。B 社の名前が無い |
| T-6 | 返信本文に引用（`>`）・「On … wrote:」・署名 `-- `・返事のリンク・システム伝達項目・`park+…@` | 下書きにどれも入らない。本人の文だけが字下げで入る |
| T-7 | 返信本文に EG の氏名・メール・電話（ハイフン違い） | 氏名はイニシャル、メール・電話は「（伏せています）」 |
| T-8 | 意向が interested／question／declined | 意向の文・締めの文が 3-2 の表どおり |
| T-9 | 同じ受信メールで `draftFromInbound` を2回 | 1行だけ |
| T-10 | 不達・自動返信・担当者からの返信・未紐付け | 下書きができない |
| T-11 | 管理者が未紐付けを手で紐付ける | 下書きができる |
| T-12 | 受信の紐付けを外す | 下書きが `discarded`。送ったものは変わらない |
| T-13 | A 社の担当者（SES でない）が下書きを直して保存 → 送信 | `mail_outbox` に1行：宛先＝プロバイダー、件名の最後が `[No.<頭10字>]`、`[PARK]` なし、本文の最後に会社名・担当者の氏名・お問い合わせ番号、`from_name`＝「{会社名} {氏名}」、`reply_to`＝`park+<token>@…, <担当者>`。`reply_tokens` に `kind=provider`・`provider_mail_id`。`provider_mails` が `sent`。`audit_log` |
| T-14 | 受信が使えないときに送信 | `reply_to` が担当者だけ |
| T-15 | 送信の2回目 | 「もう送っています」。`mail_outbox` は1行のまま |
| T-16 | 本文に EG の氏名を書き戻して送信 | 止まる（種類「氏名」）。「このまま送る」を付けると送れる |
| T-17 | 案件のプロバイダーが空・消された | 送れない（AC20 の文）。`mail_outbox` は増えない |
| T-18 | 送信のあと | 案内メールの `reply` が記録され、interested なら `result=pending`。EG 本人にお知らせ（`mail_outbox` に EG あての行が増えない）。他社の EG なら B 社の担当者全員にお知らせ |
| T-19 | ［作り直す］・［取りやめる］→［作り直す］ | 本文が元に戻る。`discarded` から `draft` に戻る |
| T-20 | 案件の画面から［プロバイダーへの返信を作る］（受信なし） | 引用なしの下書き。2回押しても同じ下書きが開く |
| T-21 | プロバイダーの返事（宛先 `park+<provider のトークン>@…`）を取り込む | `sender_kind=provider`・`provider_mail_id`・案件・会社・EG。A 社の担当者全員にお知らせ（メールなし） |
| T-22 | In-Reply-To が送ったメールの Message-ID | 同じく紐付く |
| T-23 | 件名に `[No.…]`、差出人がプロバイダーのアドレス／別のアドレス | 紐付く／未紐付け |
| T-24 | 件名に `[No.…]` で差出人が EG | `[PARK-…]` 側では当たらない（種類を分けている） |
| T-25 | A 社の担当者が「返信メール」を開く | 差出人が「{企業名}（プロバイダー）」。今やることに出て、開くと消える |
| T-26 | B 社・C 社の担当者が、下書き・プロバイダーの返事を開く | 404 |
| T-27 | EG で `/provider-mails/{id}` | 403 |
| T-28 | 管理者で下書きを開く・送信を押す | 読める（ボタンなし）／403 |
| T-29 | 担当者を兼ねた管理者（A 社）が、担当者の立場で送る | 送れる。`audit_log` に兼任の印 |
| T-30 | A 社の今やること | 下書きが出る。送ると消える |
| T-31 | A 社の担当者が案件の画面で「合格」（メモあり） | `case_mails.result=passed`・メモ・`result_by`。EG にお知らせ（メモなし・メールなし） |
| T-32 | 他社の EG で「不合格」 | B 社の担当者全員にお知らせ（メモなし） |
| T-33 | 「結果待ち」 | お知らせなし |
| T-34 | 同じ値をもう一度 | 「変わっていません」、お知らせは増えない |
| T-35 | 応募がある案件×EG で合格 | `application_events` に「先方の結果：合格（担当者が登録）」。応募の状態は変わらない |
| T-36 | 管理者・B 社・EG が `/case-mails/{id}/result` | 403 |
| T-37 | プロバイダーの返事の詳細から結果を登録 | T-31 と同じ |
| T-38 | 既存のテスト（受信・案内メール・写し） | 落ちない。落ちたら原因を直す（`notify` の文が増えたことによる完全一致など） |

## 10. デモの通し方（火曜。Leo が TEZZO さんに渡す）

前もって（Leo）：
- 公開版に v0.13.0 を反映（T2 の了承のあと）。
- 受信を動かす：返事待ち B（`PARK_IMAP_PASSWORD`）が入っていること。入っていなければ、受信の場面は「手元の FOCUS で `park:mail-ingest` に用意した .eml を渡す」で代える（画面は同じに動く）。
- **EG とプロバイダーのメールアドレスは、TEZZO さんが受け取れて返信もできる実在のアドレスにする**（`@park.example` や `park@jona.jp` だと、PARK 自身が送ったメールとして受信で読み飛ばされ、返信の場面が通らない）。アドレスはこのファイルに書かない。
- 管理者は、デモ用の SES の担当者を兼ねておく（1つのログインで「登録 → 案件 → 案内 → 送信」まで見せられる）。

流れ：
1. メール登録：SES（デモ用の会社）の担当者として入る（仮登録のメール → 本登録）。
2. EG 登録：EG を登録（実在のアドレス）。
3. 案件登録：プロバイダーを選んで（実在のアドレス）案件を登録。
4. EG への案内：「エンジニアに案内する」→ 送信。
5. EG の受信箱で、案内メールに「返信」（ひとこと書く）。
6. 管理者の「受信メール」で［今すぐ取り込む］（cron なら5分以内）。
7. 担当者のお知らせ →「返信メール」→ 枠「プロバイダーへの返信」→［開く］。下書き（EG はイニシャル、引用が入っている）を見せ、ひとこと直して［送信］。
8. プロバイダーの受信箱で届いたメールを見せ（件名に [PARK] が無い、差出人は会社と担当者の名前）、「返信」で合否を書く。
9. ［今すぐ取り込む］→ 担当者にお知らせ →「プロバイダーから返信」→ その画面で「合格」を登録 → EG のお知らせに届く。

## 11. 次の版（今回は作らない）

- 手元の AI で、返信の要点をまとめる・言い換える（FOCUS のつなぎ役。外のサービスには渡さない）。
- スキルシート（名前を伏せた業務経歴書）をメールに添付して送る。
- 送ったあとの「続きを送る」（同じトークンで2通目）。
- プロバイダーの返事から合否を自動で読む（R17 の a）。
- 合否と応募（`applications`）の状態を合わせる。持ち込み元が他社の EG の応募の画面を見られない件。
- 合否・送信のお知らせをメールでも送るか。
- 送信元を各 SES のアドレスにする方式（T1 の答えしだい）。
- 同じ EG の二重提案（W7）の検知。
- プロバイダーへの返信の一覧の画面。

## 12. 了承が要ること（Leo が TEZZO さんに取る）

- 公開版への反映（W1〜W8 の報告のあと。T2）。
- デモ・確認で、実際にプロバイダー宛て・EG 宛てのメールを送ること（T3）。宛先は TEZZO さんのアドレス。
- `provider_mails`・`inbound_mails` の行、受信箱のメールを消すこと（今回の仕組みには、消す操作を作らない）。
- T1（送信元のアドレスが見えること）の判断。本物のプロバイダーへ送り始める前に。

---

（ここから下に、前の計画（v0.12.0 以前）を Leo が `git show HEAD:PLAN.md >> PLAN.md` で戻す）

# PLAN：案内メールの返信先トークンと、受信の紐付け（v0.12.0。2026-11-16）

> **これが今の計画**。前の計画（v0.11.0 以前、2219 行）は、この節の下に続く。**Leo が `git show HEAD:PLAN.md >> PLAN.md` で、このファイルの末尾に戻す**（planner は編集の道具が無く、全体を書き直さなかったため）。
> 要件の正本：41_Park-Project の `33_要件定義/2026-11-16-01-メールの紐付け方式.md` と `2026-11-16-02-要件定義書（草案）.md` の R13〜R17。
> 今回の範囲：R13（トークン・Reply-To・件名・本文末尾の一文）、R14（受信の取り込みと紐付け）、R15（記録と SES への通知）、未紐付けの画面。**R16・R17 は次の版**（10章）。
> 作る順：段階1 → 2 → 3 → 4 → 5（段階ごとに commit。`php artisan test` を通してから次へ）→ park-reviewer → park-tester。commit・push は Leo。
> プレーヤー：管理者・SES・担当者・EG の4つ（担当者＝`users.role=ses`。SES も担当者の1人。今回の権限は SES と担当者で同じ。**知らせが届くのは会社の担当者全員**なので、担当者で必ず確かめる）。
> 外部のライブラリは足さない（IMAP・MIME の読み取りも自前。`ext-imap` に頼らない）。名前・メール・合言葉はこのファイルにもテストにも書かない（テストは架空の会社・人、`@park.example`）。
> **公開版への反映は、7章の警告 W1〜W5 を TEZZO さんに報告してから**（グループ単位の変更のため。v0.11.0 と同じ扱い）。IMAP の `.env` と cron は TEZZO さんの承認のあと（9章）。

## 0. コードで確かめた今の形

- 案内メールの出口は1つ：`CaseMail::send`（`CaseSearch::send` 387 もここを通す）。1人1通で `case_mails` に1行（`case_id`・`engineer_id`・`ses_id`＝送った会社・`sent_by`・`route` own/group・`subject`・`body`・`outbox_id`・`sent_at`・`token`（32 桁 16 進。返事のリンク `/r/{token}/…` 用、2026_10_16_000006））。
- 今の返信先：`route=own`（自社の EG）は送った担当者のアドレス（`queueMail` の `$replyTo`）。`route=group`（他社の EG）は返信先なしで、本文に「このメールへの返信は担当者に届かないので、上のリンクから」。
- `Notifier::queueMail` は `mail_outbox` に入れ、送れるときは `deliver`（`Mail::raw`）。`reply_to` は1つの文字列。Message-ID は保存していない（Symfony が自動で付ける）。件名の頭に `[PARK]`（`setting('app_name')`）、本文の後ろに共通の署名（「ご不明な点は park@jona.jp まで」）。
- 見本データ宛て（`@park.example`）は `deliver` で `park@jona.jp`（`config('park.dummy_mailbox')`）に差し替えて届く。**From も park@jona.jp なので、受信箱には PARK 自身が送ったメールが混ざる**。テストメール（管理者の設定画面）も同じ。
- 案件の画面の「案内メール」（`cases/show` 64–85、`CaseController::show` 124–140）：見えるのは `CaseMail::canSend`（持ち込み元の担当者）と管理者。他社の EG の行は「イニシャル（会社名・PARK から）」。
- 名前の伏せ方：`CaseThread::egName`／`maskName`（他社の担当者には「イニシャル（所属の会社名）」）。
- 今やること：`Todo::ses`（案内メールの返事・やり取りの未読など）、`Todo::admin`。ナビは `layouts/app.blade.php` 4–7。
- 定期実行の仕組みは無い（`routes/console.php` は空。AI は FOCUS のつなぎ役が取りに来る形）。
- `config/park.php`：`version`・`dummy_mailbox` だけ。

## 1. 受け入れ条件

| 番号 | 立場 | 条件 |
|---|---|---|
| AC1 | SES・担当者 | 案内メールを送ると、1通ごとに推測できないトークン（英小文字と数字 20 字）が発行され、`reply_tokens` に「トークン・案件・送った会社・EG・案内メール（`case_mails`）・`mail_outbox`・送信日時・Message-ID」が残る |
| AC2 | SES・担当者 | 受信が使えるとき（4章 D2）、案内メールの `Reply-To` は `park+<トークン>@jona.jp`（自社の EG あては、その後ろに送った担当者のアドレスも）。From は `MAIL_FROM_ADDRESS` のまま |
| AC3 | SES・担当者 | 件名の最後に `[PARK-<トークンの頭 10 字>]`、本文の最後（共通の署名の前）に「［システム伝達項目・消さないでください］案内番号：PARK-…／案件 ID：CA-…」が入る。受信が使えないときも入る |
| AC4 | SES・担当者 | 受信が使えないとき（`.env` が空）は、返信先と EG への説明の文は今までどおり（自社＝担当者のアドレス、他社＝なし） |
| AC5 | システム | 1通のメール（.eml の生の文）を渡すと、宛先のトークン → In-Reply-To/References → 件名の `[PARK-…]` → 本文（案内番号・返事のリンク・案件 ID）の順に案内メールを探し、見つかれば「紐付け済み」、なければ「未紐付け」で記録する（`park:mail-ingest`） |
| AC6 | システム | 同じメールを2回渡しても1件（Message-ID か中身のハッシュで重複を見る）。PARK 自身が送ったメール（From が `MAIL_FROM_ADDRESS`）は記録しない。不達の通知・自動返信は記録するが、SES には知らせない |
| AC7 | システム | 元のメール（.eml）を `storage` の非公開の場所に保存し、記録（`inbound_mails`）から引ける |
| AC8 | SES・担当者 | 紐付けた返信は、案件を持ち込んだ会社の担当者全員にお知らせが出る。自社の EG からはお知らせだけ（メールは本人から直接届いているため）、他社の EG からはお知らせ＋メール（本文は入れず、画面へのリンクだけ） |
| AC9 | SES・担当者 | 「返信メール」（`/inbound`）で、自社が持ち込んだ案件への返信だけが見える（他社の案件への返信は 404）。他社の EG は「イニシャル（会社名）」で、本文・件名の中の氏名・ふりがな・メール・電話も伏せる。開くと既読。案件の画面の「案内メール」の行に「返信メール n 件」 |
| AC10 | SES・担当者 | 読んでいない返信は「今やること」に出る |
| AC11 | 管理者 | 「受信メール」（`/admin/inbound`）で全件（未紐付け・紐付け済み・対象外）を読める（氏名は伏せない）。元の .eml をダウンロードできる |
| AC12 | 管理者 | 未紐付けの1件を、案件 ID（CA-）を入れて出る案内メールの一覧から選んで紐付けられる（確認の画面に「見えるようになる会社」を出す）。紐付けると AC8 と同じ知らせが出る。紐付けを外す・対象外にする・対象外から戻すもできる。操作は `audit_log` に残る |
| AC13 | 管理者 | 未紐付けがあると、お知らせ（メールなし）と「今やること」に出る |
| AC14 | 管理者 | ［今すぐ取り込む］：受信の設定があれば取り込み、件数を出す。無ければ「受信の設定がまだです（.env）」と出て何もしない |
| AC15 | システム | `park:mail-fetch`：`.env` の IMAP の設定が空なら何もせず「受信の設定がありません」と出して終わる（エラーにしない）。あれば、前回の続き（UID）から最大 50 通を読み、受信箱のメールは消さない・既読にしない |
| AC16 | EG | 画面は変わらない。受信が使えるとき、案内メールの説明が「このメールに返信すると、PARK を通して案件の担当者に届きます」になる。自動の返信は送らない |
| AC17 | 担当者 | AC8〜AC10 が SES と同じに働く（同じ会社の担当者全員） |
| AC18 | 全員 | `park:status` に「受信：設定あり／なし・最後に取り込んだ日時・未紐付けの件数・読まれていない返信の件数」が出る |

## 2. データ（マイグレーション1本、足すだけ）

`database/migrations/2026_11_16_000001_inbound_mails.php`。`down()` は足したものを消すだけ。

- `mail_outbox` に `message_id`（string 255、null 可、index）。
- `reply_tokens`（新）：`id`、`token`（string 40、unique）、`kind`（string 20。今回は `case-mail`。R16 の送信でも使う）、`case_mail_id`（null 可、index）、`case_id`、`ses_id`（null 可。送った会社）、`engineer_id`（null 可）、`outbox_id`（null 可）、`message_id`（string 255、null 可、index）、`sent_at`、`created_at`。外部キーは付けない（案件・EG を消しても記録が残るように。`case_mails` は cascade なので、消えても受信の記録は残す）。
- `inbound_mails`（新）：
  - 取り込み：`id`、`source`（`imap`／`file`）、`imap_uidvalidity`・`imap_uid`（null 可。2つで unique）、`message_id`（null 可、unique）、`raw_hash`（sha256、unique）、`raw_path`、`raw_size`、`fetched_at`、`received_at`（Date ヘッダー。読めなければ取り込んだ日時）。
  - 中身：`from_email`、`from_name`、`to_addrs`（text。To・Cc・Delivered-To・X-Original-To をまとめたもの）、`subject`、`in_reply_to`、`references`（text）、`body_text`（mediumText。5 万字まで）、`attachments`（text。JSON で名前・種類・大きさだけ）、`auto`（string 10、null 可：`bounce`／`auto`）。
  - 紐付け：`status`（`linked`／`unlinked`／`ignored`）、`match_by`（`token`／`in_reply_to`／`subject`／`body`／`manual`、null 可）、`reply_token_id`・`case_mail_id`・`case_id`・`ses_id`・`engineer_id`（null 可、index）、`from_mismatch`（boolean。差出人が EG の登録アドレスと違う）、`linked_by`・`linked_at`、`note`（text。対象外の理由など）。
  - 知らせ：`notified_at`、`ses_read_at`。
- 設定（`settings` 表。`set_setting`）：`inbound_uidvalidity`、`inbound_last_uid`、`inbound_last_fetch_at`。

## 3. 仕様

### 3-1. 設定（`config/park.php`）

```php
'inbound' => [
    'host' => env('PARK_IMAP_HOST'), 'port' => (int) env('PARK_IMAP_PORT', 993),
    'user' => env('PARK_IMAP_USER'), 'password' => env('PARK_IMAP_PASSWORD'),
    'mailbox' => env('PARK_IMAP_MAILBOX', 'INBOX'),
    'reply_base' => env('PARK_REPLY_BASE'), // 空なら MAIL_FROM_ADDRESS（park@jona.jp）
],
```

- `Inbound::enabled()`＝host・user・password がすべて空でない。`.env.example` に鍵の名前だけ足す（値は書かない）。
- 返信先 `Inbound::replyAddress($token)`＝`reply_base`（無ければ `mail.from.address`）の `@` の前に `+<token>`。

### 3-2. 送る側（R13。`CaseMail::send` を直す）

1. 1人ごとに `ReplyTokens::issue()`：`token`＝英小文字と数字 20 字（`random_bytes` から。Xserver のプラスアドレスで使える文字だけ）。
2. 件名：`$it['subject']` の最後に ` [PARK-<token の頭 10 字>]`（同じ印が既にあれば足さない）。`case_mails.subject` には送った件名をそのまま残す。
3. 本文の最後（今の返事のリンク・案件の詳細のリンクのあと）に：
   ```
   ――――――――――
   ［システム伝達項目・消さないでください］
   案内番号：PARK-<token の頭 10 字>
   案件 ID：<CA-…>
   ```
4. 返信先：
   - 受信が使えるとき：自社の EG（`$own && $sesId`）は `park+<token>@…, <送った担当者のアドレス>`（2つ。先頭が PARK）。他社の EG は `park+<token>@…` だけ。
   - 使えないとき：今のまま。
   - EG への説明の文（今の 110 行）：使えるとき、自社＝「※このメールに返信すると、担当者と PARK に届きます。」、他社＝「※このメールに返信すると、PARK を通して案件の担当者に届きます。署名などの個人の連絡先は書かなくてかまいません。」。使えないときは今の文。
5. `queueMail` の戻りの `outbox_id` と、その行の `message_id` を `reply_tokens` に入れる（`case_mail_id` は `case_mails` の insert のあとで更新）。

`Notifier`：
- `queueMail`：全メールで `message_id`＝`<32 桁 16 進>@<From のドメイン>`（`<>` なしで保存）を作って `mail_outbox` に入れる。
- `deliver`：`self::headers($msg, $m)` を新設して呼ぶ。中で Message-ID を `$msg->getSymfonyMessage()->getHeaders()->addIdHeader('Message-ID', $m->message_id)`（null なら付けない）、`reply_to` を `,` で分けて配列で `replyTo`。テストで Symfony の `Email` を渡して確かめられる形にする。

### 3-3. 受け取る側（R14。`app/Services/Inbound.php`、`app/Support/MailParser.php`）

`Inbound::ingest(string $raw, string $source, ?int $uidvalidity = null, ?int $uid = null): array`（戻り：`['status' => 'saved'|'duplicate'|'self'|'too_large', 'id' => ?int]`）

1. 20MB を超えるものは、ヘッダーだけ読んで記録（`body_text` は「大きすぎるため本文を読んでいません」）。
2. `MailParser::parse($raw)`：ヘッダーの折り返しを戻す、エンコードされた語（`=?ISO-2022-JP?B?…?=` など）は `mb_decode_mimeheader`／`iconv_mime_decode`。multipart をたどり、`text/plain` を優先（無ければ `text/html` をタグを外して）。転送の符号化（base64・quoted-printable）と文字コード（UTF-8・ISO-2022-JP・Shift_JIS は `SJIS-win`・EUC-JP）を `mb_convert_encoding` で UTF-8 に。添付は名前・種類・大きさだけ。
3. 自分あて：From が `mail.from.address` と同じ → 記録しない（`self`）。見本宛ての差し替え・テストメールが受信箱に戻ってくるため。
4. 重複：`message_id` か `raw_hash` が既にある → `duplicate`。
5. 不達・自動返信：From が `MAILER-DAEMON`／`postmaster`、`Content-Type: multipart/report`、`Auto-Submitted` が `no` 以外、`X-Autoreply`・`X-Autorespond` があれば `auto` に印。
6. 元の文を `Storage::disk('local')`（`storage/app/private`）の `inbound/YYYY/MM/<id>.eml` に保存（公開しない）。
7. 紐付け（`Inbound::match`。見つかったら止める）：
   1. **宛先のトークン**：`to_addrs` の中の `<reply_base の前半>+<token>@<ドメイン>`（大文字小文字は区別しない）→ `reply_tokens.token`。
   2. **In-Reply-To / References**：その中の Message-ID → `reply_tokens.message_id`。
   3. **件名の `[PARK-xxxxxxxxxx]`**：頭 10 字が一致する `reply_tokens`（`token like 'xxxxxxxxxx%'`）。1件だけで、**差出人が その EG の登録アドレス**（`engineers.email` か、その EG の `users.email`）のときだけ。
   4. **本文**：a. 「案内番号：PARK-xxxxxxxxxx」→ 3 と同じ（差出人の確認つき）。b. 返事のリンク `/r/<32 桁>` → `case_mails.token`（128 ビットなので差出人は見ない。v0.12.0 より前の案内メールへの返信もこれで拾える）。c. 「CA-…」＋差出人が EG の登録アドレス → その案件・その EG の `case_mails`（1件だけのとき）。
   5. どれでもなければ `unlinked`。
   - 1・2・4b で見つかり、差出人が EG の登録アドレスと違えば `from_mismatch=true`（画面に「登録と違うアドレスから」と出す。紐付けはする）。
   - 紐付いたら `case_mail_id`・`case_id`・`engineer_id`・`ses_id`（`case_mails.ses_id`、空なら `cases.source_ses_id`）を入れる。
8. 紐付いたら 3-4 の知らせ。未紐付けなら管理者にお知らせ（メールなし）。`auto` は知らせない。
9. `case_mails.reply` は変えない（応募したい・見送りの判断は担当者が今までどおり記録する。自動で読むのは R16 の版）。

コマンド：
- `park:mail-ingest {path}`：.eml のファイル1つを `ingest`（`source=file`）。結果を1行で出す。手での確かめとテスト用。
- `park:mail-fetch {--from-start}`：3-5。

### 3-4. 記録と知らせ（R15。`Inbound::notify`）

- 届け先：`ses_id` の会社の担当者全員（`Notifier::toSes`。兼任の管理者も含む今の決まりどおり）。種類 `inbound`、リンク `/inbound/{id}`。
- 自社の EG（EG の今の `ses_id`＝届け先）：お知らせだけ（`mail=false`）。題「{氏名} さんから案内メールへの返信：{案件名}[CA-]」、本文は返信の頭 200 字。
- 他社の EG：お知らせ＋メール。題「{イニシャル（会社名）} さんから案内メールへの返信：{案件名}[CA-]」、本文「返信の内容は PARK の画面で見てください。」（**メールに本文を入れない**）。
- `notified_at` を入れる。同じ記録に2回は出さない。
- 管理者が手で紐付けたときも同じ（紐付けを外して付け直したときは、新しい会社にだけ出す）。
- EG には何も送らない（自動返信の行き来を避ける）。

### 3-5. IMAP の取り込み（`app/Support/ImapClient.php`、`app/Services/MailFetch.php`）

- `ImapClient`：`stream_socket_client('ssl://host:port')`（証明書を確かめる。タイムアウト 20 秒）。使う命令は `LOGIN`・`SELECT`（UIDVALIDITY・UIDNEXT を読む）・`UID SEARCH UID n:*`・`UID FETCH n (BODY.PEEK[])`（既読にしない）・`LOGOUT` だけ。**消す・移す・旗を変える命令は使わない**。
- `MailFetch::run(MailSource $src)`：`MailSource` は `fetchSince(int $uid, int $limit): iterable<[uid, raw]>` と `uidvalidity()` を持つインターフェース。本物は `ImapClient`、テストは配列で返す偽物（コンテナに `bind`）。
- 流れ：`Inbound::enabled()` でなければ何もしない → ロック（`Cache::lock('park-mail-fetch', 300)`。取れなければ「取り込み中」）→ UIDVALIDITY が保存と違えば `inbound_last_uid=0`（重複は 3-3 の 4 で防ぐ）→ **初回（`inbound_last_uid` が無い）は、今ある分は読まず `UIDNEXT-1` を保存して終わる**（`--from-start` のときだけ全部読む）→ 最大 50 通を `ingest` → 1通ごとに `inbound_last_uid` を進める → `inbound_last_fetch_at`。
- 失敗（接続・ログイン）は例外を捕まえて1行出し、`audit_log` に `mail-fetch-error`（パスワードは書かない）。
- 定期実行：公開版の cron で `*/5 * * * * cd <park> && php artisan park:mail-fetch`（**9章の承認のあと**。`routes/console.php` は使わない）。

### 3-6. 見える範囲（`Inbound::visible($u, $row)`）

| 立場 | 見えるもの |
|---|---|
| 管理者 | 全部（未紐付け・対象外も）。氏名を伏せない。.eml のダウンロード可 |
| SES・担当者 | `status=linked` で、その案件の今の持ち込み元が自社（`cases.source_ses_id = users.ses_id`。案件の画面の「案内メール」と同じ決まり）。他社の EG は伏せる。.eml は不可 |
| EG | なし（ルートで 403） |

- 伏せ方（`Inbound::masked($u, $row)`）：見る人が SES で、EG の今の所属が自社でないとき、件名・本文・添付の名前の中の、その EG の 氏名（姓名続き・姓・名）・ふりがな・`engineers.email`・`users.email`・電話・From のアドレスと名前を、`イニシャル（会社名）` か「（伏せています）」に置き換える。差出人の欄は `CaseThread::egName` と同じ。
- 未紐付けは管理者だけ（4章 D5）。

## 4. 決めたこと（Leo の推奨で仮置き。違えば直す）

| 番号 | 決めたこと | 理由 |
|---|---|---|
| D1 | トークンは新しい表 `reply_tokens`（`case_mails.token` は返事のリンクのまま別に残す） | R16 のプロバイダーへの送信でも同じ表を使える。返事のリンクを変えない |
| D2 | **Reply-To を `park+…` に変えるのは、受信が使えるとき（IMAP の `.env` がそろったとき）だけ**。トークン・件名の印・本文の一文は、いつも入れる | 取り込めない間に返信先だけ変えると、EG の返信が誰にも届かなくなる |
| D3 | 自社の EG あては Reply-To を2つ（PARK＋送った担当者）。他社の EG あては PARK だけ | 今の流れ（自社の EG の返信は担当者に直接届く。2026-10-16）を壊さずに、PARK にも記録を残す。要件メモの「どちらを正にするか」は、両方に届く形で解く |
| D4 | 件名の印は `[PARK-<トークンの頭 10 字>]`、本文の一文も同じ番号＋案件 ID。件名・本文の番号で紐付けるのは、差出人が EG の登録アドレスのときだけ | 頭 10 字は短く推測の余地があるため。完全なトークン・返事のリンクは差出人を問わない |
| D5 | **未紐付けは管理者だけが見て、手で紐付ける**（SES の画面には出さない） | park@jona.jp は運営の問い合わせ窓口でもある（全メールの署名）。EG が運営に書いた相談（所属の会社のことなど）を、その会社に見せないため。要件の「SES または管理者」のうち管理者を選ぶ |
| D6 | 自社の EG の返信はお知らせだけ、他社の EG の返信はお知らせ＋メール（本文なし） | 自社は本人のメールが直接届いている。他社の本文をメールで外に出さない |
| D7 | 返信の内容から `case_mails.reply` を自動で変えない。案件のやり取り（`case_threads`）にも入れない | 自動の読み取りは R16 の版。やり取りに入れると EG の所属の会社にも見え、決まりが増える |
| D8 | 元の .eml は保存するが、ダウンロードは管理者だけ。添付は名前・大きさだけ出し、SES は取り出せない | 伏せられない中身（添付の履歴書など）を他社に出さない。添付の取り出しは次の版 |
| D9 | 初回の取り込みは「今から」（受信箱の過去のメールは読まない）。受信箱のメールは消さない・既読にしない | 削除は了承が要る。TEZZO さんがウェブメールで読んでも取りこぼさない（UID で進める） |
| D10 | 定期実行は cron で `park:mail-fetch` を5分ごと＋管理者の［今すぐ取り込む］ | スケジューラーを使う理由が他に無い |
| D11 | 案内メール以外（連絡メール・配信の知らせ・お知らせのメール）には、トークンを付けない。それらへの返信は未紐付け（管理者） | 今回の範囲は案内メール（R13） |
| D12 | 版は `v0.12.0` | 受信という新しい機能 |

## 5. 画面と操作（プレーヤーごと）

| 立場 | 画面 | 操作 |
|---|---|---|
| 管理者 | ナビ「受信メール」（`/admin/inbound`。「メール」の隣）。上にタブ「未紐付け（n）／紐付け済み／対象外／すべて」、受信の状態（設定あり・なし、最後に取り込んだ日時）、［今すぐ取り込む］ | 一覧：受け取った日時・差出人・件名・案件（CA-）・会社・紐付け方・状態 |
| 管理者 | 詳細 `/inbound/{id}`：ヘッダー・本文・添付の名前・「登録と違うアドレスから」の印、［元のメール（.eml）］ | 未紐付け：案件 ID（CA-）を入れて［探す］→ その案件の案内メールの一覧（EG・会社・送った日）から選ぶ → 確認「この返信は {会社名} の担当者に見えるようになります」→［紐付ける］。紐付け済み：［紐付けを外す］。［対象外にする］（理由を書ける）／［未紐付けに戻す］ |
| SES・担当者 | ナビ「返信メール」（`/inbound`。「案件のやり取り」の隣）。自社の案件への返信の一覧（未読を太字・件数） | 開くと既読（`ses_read_at`。会社で1つ）。詳細は伏せたうえで本文を読む。案件・案内メールへのリンク |
| SES・担当者 | 案件の画面の「案内メール」の行に「返信メール n 件」（`/inbound?case={id}&eg={eid}`） | — |
| SES・担当者 | ダッシュボードの「今やること」：「{名前} さんから返信メール：{案件名}」→ `/inbound/{id}` | — |
| EG | 変わらない（案内メールの説明の文だけ。3-2 の 4） | — |

ルート：`role:admin,ses` に `GET /inbound`・`GET /inbound/{id}`。`role:admin` に `GET /admin/inbound`・`POST /admin/inbound/fetch`（throttle 6,1）・`GET /inbound/{id}/raw`・`POST /inbound/{id}/link`・`/unlink`・`/ignore`・`/restore`。コントローラーは `InboundController`（新）。

## 6. 境界の挙動

| 場面 | 挙動 |
|---|---|
| 権限がない | SES が他社の案件への返信・未紐付けを開く → 404。EG が `/inbound` → 403。SES が link・raw → 403 |
| 案件が別の会社に移った | 見えるのは今の持ち込み元（案件の画面と同じ）。知らせは紐付けたときの会社だけ |
| トークンが見つからない宛先（`park+知らない文字@`） | 2 以降で探す。なければ未紐付け |
| トークンの EG が退会・停止 | 紐付けて記録する（知らせも出す）。停止中でも返信の事実は残す |
| 案内メール（`case_mails`）が消えた | `reply_tokens` で案件・会社・EG は分かるので紐付ける（`case_mail_id` は残るが行は無い。画面は「案内メールの記録はありません」） |
| 二重の操作 | 同じメールの取り込み2回 → 1件。紐付けの2回押し → 2回目は「もう紐付いています」。取り込みの同時実行 → ロックで1つだけ |
| 0件 | 一覧は「まだ返信メールはありません」。取り込み0通 → 「新しいメールはありませんでした」 |
| `.env` が空 | `park:mail-fetch`・［今すぐ取り込む］は何もしない（メッセージだけ）。`park:mail-ingest` は動く（手での確かめ用） |
| 文字化け・読めない形式 | 読めた分だけ本文に入れ、元の .eml は残す |
| 自分（PARK）が送ったメール | 記録しない |
| 不達・自動返信 | 記録・紐付けはするが知らせない。一覧に「自動」の印 |

## 7. TEZZO さんへの確認

### 警告：グループ単位の変更（公開版に反映する前に報告する）

- **W1** 他社の EG（B 社の F）が、A 社が持ち込んだ案件の案内メールに返信すると、その本文が A 社の担当者全員に見える（今までは他社の EG の返信はどこにも届かなかった）。氏名・ふりがな・メール・電話は登録の値で伏せるが、本文に書かれた別の書き方（署名の会社名・ローマ字の名前など）は伏せきれない。
- **W2** A 社の担当者全員に、F の返信のお知らせとメール（「イニシャル（B 社の会社名）さんから返信」）が届く。
- **W3** 管理者が未紐付けを手で紐付けると、その本文が案件の持ち込み元の会社に見えるようになる（選び間違えると、別の会社に見える。確認の画面と `audit_log` で守る）。
- **W4** 案件の持ち込み元が変わると、過去の返信メールも新しい持ち込み元の担当者に見える（案件の画面の「案内メール」と同じ決まり）。
- **W5** 管理者は、すべての会社の EG の返信と元のメールを、伏せずに読める（兼任の管理者が担当者として見る範囲は SES と同じ）。公平性のルール・誓約書の対象。

### Leo が推奨で決めた件（4章の D1〜D12。TEZZO さんに上げない）

- 特に D3（自社の EG は返信先を2つ）と D5（未紐付けは管理者だけ）。違えば言ってもらい、直す。

### TEZZO さんに上げる件

- **T1（設定変更の承認）** 公開版の `.env` に `PARK_IMAP_HOST`・`PARK_IMAP_PORT`・`PARK_IMAP_USER`・`PARK_IMAP_PASSWORD`（park@jona.jp の受信用）を入れる。入れた時点で、案内メールの返信先が `park+…` に変わる（D2）。
- **T2（設定変更の承認）** 公開版（Xserver）の cron に `park:mail-fetch` を5分ごとで登録する。
- **T3（了承）** W1〜W5 を承知のうえで公開版に反映してよいか。

## 8. 作業の手順（implementer 向け）

### 段階1：データと送る側（R13）

1. マイグレーション（2章）。`php artisan migrate` が通り、`migrate:rollback` で戻ることを確かめる。
2. `config/park.php` に `inbound`（3-1）。`.env.example` に鍵の名前だけ。
3. `app/Services/ReplyTokens.php`（`issue`・`tagOf($token)`＝頭 10 字・`find($token)`）。
4. `Inbound::enabled()`・`Inbound::replyAddress()`（この段階では、この2つだけ）。
5. `Notifier::queueMail` で `message_id`、`deliver` から `headers()`（3-2）。
6. `CaseMail::send`：件名の印・本文の一文・返信先・説明の文・`reply_tokens` の行（3-2）。
7. テスト（9章の T-1〜T-6）。

### 段階2：受け取る側（R14）

1. `app/Support/MailParser.php`（3-3 の 2）。
2. `app/Services/Inbound.php`：`ingest`・`match`・保存（3-3）。知らせは段階3で入れるので、ここでは呼ぶ所だけ空にしておく。
3. `app/Console/Commands/MailIngest.php`（`park:mail-ingest`）。
4. テストの見本メール `tests/Fixtures/mail/*.eml`（架空の名前・`@park.example`。ISO-2022-JP の件名、quoted-printable、multipart/alternative、html だけ、添付つき、不達、自動返信、PARK 自身）。
5. テスト（T-7〜T-16）。

### 段階3：記録・知らせ・画面（R15・未紐付け）

1. `Inbound::notify`（3-4）、`Inbound::visible`・`masked`（3-6）、手での `link`・`unlink`・`ignore`・`restore`（`audit_log` に `inbound-link` など）。
2. `InboundController` とルート（5章）。画面 `resources/views/inbound/index.blade.php`・`show.blade.php`（SES と管理者で共通。管理者だけの欄は `@if`）、`admin/inbound.blade.php`。
3. ナビ（`layouts/app.blade.php` 5–6）。案件の画面の「案内メール」に「返信メール n 件」（`CaseController::show` で件数を1回の問い合わせで取る）。
4. `Todo::ses`（読んでいない返信）・`Todo::admin`（未紐付け）。
5. テスト（T-17〜T-30）。

### 段階4：IMAP の取り込み

1. `app/Support/ImapClient.php`（3-5。使う命令だけ）。`app/Contracts/MailSource.php`（インターフェース）、`app/Services/MailFetch.php`。
2. `app/Console/Commands/MailFetch.php`（`park:mail-fetch`）、管理者の［今すぐ取り込む］。
3. `park:status`（`Console/Commands/Status.php`）に AC18 の行。
4. テスト（T-31〜T-36。偽の `MailSource`）。**本物の IMAP にはテストでつながない**。

### 段階5：マニュアル・README・版

1. `config/park.php` の `version` を `v0.12.0`。
2. マニュアル（`resources/manuals/`）：
   - `ses.md`・`ses-steps.md`：案内メールの返信の届き方（受信が使えるとき：自社の EG の返信は担当者と PARK に、他社の EG の返信は PARK を通して「返信メール」に）、「返信メール」の画面と既読、伏せ方。96 行の表・140・146・169 行を直す。担当者も同じことができると書く。
   - `engineer.md`・`engineer-steps.md`：102・111 行。「このメールに返信すると担当者に届く。グループの案件も届く」「［システム伝達項目］の部分は消さないで」。
   - `admin.md`・`admin-steps.md`：「受信メール」の画面、未紐付けの紐付け・対象外、［今すぐ取り込む］、受信の設定（`.env` の鍵の名前・cron。値は書かない）、伏せずに読めること（公平性）。
3. README：受信の設定（鍵の名前）、`park:mail-ingest`・`park:mail-fetch`、cron の行、受信が使えないときの動き。
4. 直したら Leo が 41_Park-Project の `04_Manuals` の写しを写し直す（写しのテストも通す）。

## 9. テスト（`tests/Feature/V0120InboundTest.php` ほか）

受信が使えるかは `config(['park.inbound.host' => 'imap.example', 'park.inbound.user' => 'x', 'park.inbound.password' => 'y'])` で切り替える。メールは `mail_outbox` を見る。

| 番号 | 立場・手順 | 確かめること |
|---|---|---|
| T-1 | SES の担当者で、自社の EG に検索 → 送信（受信が使える） | `reply_tokens` に1行（案件・会社・EG・`case_mail_id`・`outbox_id`・`message_id`）。`mail_outbox.reply_to` が `park+<token>@…,<担当者のアドレス>`。件名の最後が `[PARK-<頭10字>]`。本文に「［システム伝達項目・消さないでください］」と案件 ID |
| T-2 | 同じで他社の EG に送る | `reply_to` が `park+<token>@…` だけ。説明の文が「PARK を通して案件の担当者に届きます」 |
| T-3 | 受信が使えない（`.env` 空）で送る | `reply_to` が今まで（自社＝担当者、他社＝空）。件名の印と本文の一文はある |
| T-4 | 2人に送る | トークンが2つとも違う。20 字の英小文字と数字 |
| T-5 | `Notifier::headers` に Symfony の `Email` を渡す | Message-ID が `mail_outbox.message_id`、Reply-To が2つ |
| T-6 | 管理者が案内メールを送ろうとする | 今までどおり 403（v0.11.0。トークンは作られない） |
| T-7 | `park:mail-ingest` で、宛先が `park+<token>@…` のメール | `inbound_mails` が `linked`・`match_by=token`、案件・会社・EG が入る。.eml が保存される |
| T-8 | 宛先はただの `park@…`、In-Reply-To が送ったときの Message-ID | `match_by=in_reply_to` |
| T-9 | 件名に `[PARK-…]`、差出人が EG の登録アドレス | `match_by=subject` |
| T-10 | T-9 で差出人が別のアドレス | `unlinked` |
| T-11 | 本文に返事のリンク `/r/<32桁>`（v0.12.0 より前の案内） | `match_by=body`、差出人は問わない |
| T-12 | 本文に「CA-…」だけ、差出人が EG の登録アドレス、その案件の案内が1件 | `match_by=body` |
| T-13 | どれにも当たらない | `unlinked`、管理者にお知らせ（`mail_outbox` に行が増えない） |
| T-14 | 同じ .eml を2回 | 1件だけ、知らせも1回 |
| T-15 | From が `MAIL_FROM_ADDRESS` | 記録しない |
| T-16 | ISO-2022-JP の件名・本文、quoted-printable、html だけ、添付つき、不達、自動返信 | 件名・本文が読める。添付は名前だけ。不達・自動返信は `auto` で知らせなし |
| T-17 | 自社の EG の返信を取り込む | 持ち込み元の担当者**全員**（SES と担当者）にお知らせ、メールは無し |
| T-18 | 他社の EG の返信を取り込む | 担当者全員にお知らせ＋メール。メールの本文に返信の本文・EG の氏名が無い |
| T-19 | 担当者（SES でない）で `/inbound` | 自社の案件への返信が一覧に出る。開くと既読、今やることから消える |
| T-20 | 他社の EG の返信を A 社の担当者が開く | 差出人が「イニシャル（会社名）」。本文の中の氏名・ふりがな・メール・電話が伏せてある |
| T-21 | B 社（EG の所属）の担当者が A 社の案件への返信を開く | 404。一覧にも出ない |
| T-22 | SES が未紐付けを開く・`/inbound/{id}/raw`・link を送る | 404／403／403 |
| T-23 | EG で `/inbound` | 403 |
| T-24 | 管理者で `/admin/inbound` | 全部見える。未紐付けのタブに件数 |
| T-25 | 管理者が未紐付けを CA- で探し、案内メールを選んで紐付ける | `match_by=manual`・`linked_by`、持ち込み元の担当者に知らせ、`audit_log` |
| T-26 | 紐付けを外して別の案内メールに付け直す | 新しい会社にだけ知らせ。前の会社からは見えない |
| T-27 | 対象外にする → 戻す | 状態が変わる。消えない |
| T-28 | 紐付けの2回押し | 2回目は「もう紐付いています」、知らせは1回 |
| T-29 | 案件の画面（持ち込み元の担当者） | 案内メールの行に「返信メール 1 件」 |
| T-30 | 管理者の「今やること」 | 未紐付けの件数 |
| T-31 | `.env` 空で `park:mail-fetch`・［今すぐ取り込む］ | 何もしない。「受信の設定がありません」 |
| T-32 | 偽の `MailSource`（3通）で初回 | 読まずに最後の UID を保存（D9） |
| T-33 | 2回目（新しい2通） | 2通取り込み、`inbound_last_uid` が進む |
| T-34 | `--from-start` | 全部読む。重複は1件 |
| T-35 | UIDVALIDITY が変わった | 最初から読み直すが、重複は増えない |
| T-36 | 偽の `MailSource` が例外 | 落ちずにメッセージ。`audit_log` にパスワードが無い |
| T-37 | `park:status` | 受信の行が出る |
| T-38 | 既存のテスト（案内メール・検索・返事のリンク・写し） | 件名に印が足されたことで落ちるものを直す（件名の完全一致を `str_starts_with` に） |

## 10. 次の版（今回は作らない）

- **R16 プロバイダーへの返信**：EG の返信を、プロバイダーへの返信の文に変える（手元の AI。案件・個人の情報を外のサービスに渡さない）、プロバイダーの宛先、SES ごとに分けるか・まとめるか（要件メモで未決。TEZZO さんに上げる）。送るメールにも `reply_tokens`（`kind=provider`）を付け、プロバイダーの返事を同じ仕組みで受ける。
- **R17 合否の登録**：プロバイダーからの合否メール（`kind=provider` で紐付け）を応募に登録する画面と、電話などで知った SES・担当者が手で登録する画面。
- 返信の中身から `case_mails.reply` を自動で記録する（応募したい・見送り）。
- 添付の取り出し（SES。伏せ方の決まりを先に決める）。
- 返信を案件のやり取り（`case_threads`）に入れるか。
- SES が自社の EG からの未紐付けを扱えるようにするか（D5 の見直し）。

## 11. 了承が要ること（Leo が TEZZO さんに取る）

- 公開版の `.env` に IMAP の4つを入れる（T1）。手元の FOCUS の `.env` に入れるときも同じ（本物の受信箱を読むため）。
- 公開版の cron の登録（T2）。
- 公開版への反映（W1〜W5 の報告のあと。T3）。
- 公開版で案内メールを実際に送って返信を確かめるとき（メールの送信）。宛先は見本の EG（park@jona.jp に届く）にする。
- 受信箱のメール・`inbound_mails` の行・保存した .eml を消すこと（今回の仕組みには、消す操作を作らない）。

---

（ここから下に、前の計画（v0.11.0 以前）を Leo が `git show HEAD:PLAN.md >> PLAN.md` で戻す）


---

# v0.11.0 TEZZO の答え（2026-11-15。これが本文の仮置きより優先）

- Q1：**管理者と SES 担当者の兼任を作る**（コードを変える）。管理者が、MRSY など1つの会社の担当者を兼ねられるようにする。兼任の仕組み（今は EG＋担当者／EG＋管理者）に倣い、planner の判断で最小の形にする。兼任した管理者が、担当者として案件を発信できる（管理者としては発信できない）。
- Q3：**元直属の契約も、すべて MRSY に請求する**。D9（fee_exempt の印）は採らない。移行した契約も、これからの契約と同じ請求の対象にする。
- Q4：持ち込み元が空の案件も MRSY に付け替える（推奨どおり）。
- Q5：管理者の連絡メール・一斉配信は残す（推奨どおり）。
- Q6：kind='direct' の行は消さずに残す（推奨どおり）。
- 公開版への反映は、W1〜W9 の警告を TEZZO に報告し、了承を取ってから（手元で作って試すのは進める）。

---

# PLAN：直属をやめ、管理者は案件を発信しない（v0.11.0。2026-11-15）

> **これが今の計画**。前の計画（v0.10.1 以前）は、この節の下に続く（Leo が git の前の版から戻す）。
> 依頼：TEZZO（Leo 経由。2026-11-15）「直属は廃止する。管理者が案件登録する場合、管理者であっても SES として登録し、SES として案件を発信する。管理者は案件発信をできない。現在直属となっているものは全て『合同会社MRSY』に付け替える。」
> 作る順：段階1 → 段階2 → 段階3 → 段階4（段階ごとに commit。`php artisan test` を通してから次へ）→ park-reviewer → park-tester。commit・push は Leo。**公開版への反映は、7章の確認（W1〜W9・Q1〜Q6）に TEZZO さんが答えてから**（グループ単位の変更のため）。
> プレーヤー：管理者・SES・担当者・EG の4つ（担当者＝`users.role=ses`。SES の代表者も担当者の1人。今回の権限は SES と担当者で同じ。**通知の届き先が変わるのは担当者**なので、担当者で必ず確かめる）。
> 外部のライブラリは足さない。名前・メールはこのファイルにもテストにも書かない（テストは架空の会社・人）。会社名「合同会社MRSY」はマイグレーションの中にだけ書く。
> **警告（グループ単位の変更）**：元直属のエンジニアの氏名・詳細・応募・契約・やり取りが、合同会社MRSY の SES・担当者に見えるようになり、請求書も MRSY あてになりうる。7章の W1〜W9 に載せ、完了報告でも警告する。

## 0. コードで確かめた今の形

- 直属＝`ses_companies.kind='direct'` の行（名前「管理者の直属」）。`Workflow::directSesId()`（無ければ作る）・`Workflow::isDirect()`。作られる道は `Affiliation::targets`（管理者が開くと作る）・`DummySeed`・`SamplePurge --move-kept-to-direct`。新しく直属のエンジニアを登録する入口は、もう無い。
- 直属の分岐がある所（`grep -rn "直属\|isDirect\|directSesId\|'direct'\|kind.*direct" app resources routes database/seeders`。全 63 ファイル）：
  - 処理：`helpers.php`（`user_label` 62）、`Support/Requirements.php` 98–99、`Affiliation` 11–30・95、`Billing` 44、`CaseMail` 125、`CaseSearch` 113–124、`CaseThread`（`partnerFor` 31・`isDirectThread`・`isMine` 95・`partnerName` 101・`canPost` 183–184・`notify` 258–263・`markRead` 268–274・`unreadDirect` 303–318）、`DirectMail` 13・79–132、`Notifier::toSes` 96–97、`Registration`（`companyAllows` 87・`companyOf` 93–98・374–375）、`SamplePurge`（29・40・117・128–145・162・178–180）、`DummySeed`（29・106・166–200・245・282・315）、`Todo` 105、`Workflow`（204・560–573・844・860・900・941–942・999）、`Status` 88、`Ids` 16（説明だけ）。
  - コントローラー：`AdminController` 137（直属の会社は 404）・367–375（「直属だけ」）、`CaseThreadController` 10、`DirectMailController` 51、`EgController` 30–31（`sesDirect`）、`EngineerController` 33・100、`FindController` 42–43、`PeopleController` 35–36、`PublicController` 41、`Console/Commands/PurgeSamples` 14、`SeedDummy` 16–34。
  - 画面：`admin/engineers`（「直属だけ」ボタン・「直属」の印）、`threads/index`・`threads/show`・`cases/show`（「相手：管理者」・「直属だけ書けます」）、`mails/index`、`partials/register-fields`（注釈）。
  - マニュアル：`admin.md`（118・119・128・171・174・207・223–227・289）、`admin-steps.md`（60・100・112・130・147・164–167）、`ses.md`（91・151）、`ses-steps.md`（136）、`engineer.md`（101・130・133・158・185）、`engineer-steps.md`（9・116・120・144）。README 17 か所。
- 案件の登録：`Workflow::saveCase` 828 `source_ses_id = ses なら自社、管理者は任意（空可）`。画面 `cases/form` 14 の選択肢は「（管理者が直接）」＋参加中の SES。編集では変わらない。
- 案件の「発信」の入口（今は管理者に開いている）：
  - `POST /cases/{id}/distribute`（`CaseController::distribute` → `Workflow::distribute`。画面のフォームは無いが道は残っている）
  - 案内メール `GET /cases/{id}/mail`・`POST …/mail/preview`・`POST …/mail/send`（`CaseMailController::load` → `CaseMail::canSend`＝管理者か持ち込み元の SES）
  - エンジニア検索 `GET/POST /cases/{id}/search`・`/searches/{sid}`・`…/add`・`…/send`・`…/members/{eid}`（`CaseSearch::canUse`＝`CaseMail::canSend`、`canOpen` は管理者なら全部）
  - 案内メールの後始末 `POST /case-mails/{id}/reply`・`…/submitted`・`GET …/doc/{kind}`（同じ `load`）
  - 画面：`cases/show` 101–106 管理者の「エンジニアに案内する」カード、64–83「案内メール」（`$canMail`）、`admin/dashboard` 28「マッチング」ボタン
- 発信ではないもの（管理者に残す）：案件の登録・編集・状態の変更・AI の読み直し、応募のあとの選考（`/applications/*`）、連絡メール（`/mails`。案件ではない自由なメール）、一斉配信（`/admin/mail/broadcast`。お知らせ）、メッセージ（`/engineers/{id}/messages`）。
- 合同会社MRSY：コード・seeder・`DummySeed` には無い。41_Park-Project の記録では、**手元と公開版の DB に `ses_companies` の行として既にある**（2026-10-16 に TEZZO さんのエンジニアの所属として使った）。id・コード・kind・status・担当者の数は、DB を開けないので確かめていない（段階1の手順 1 で確かめる）。
- `ses_id` を持つ表：`users.ses_id`、`engineers.ses_id`、`applications.ses_id`、`contracts.ses_id`、`invoices.ses_id`（`unique(ses_id, period)`）、`cases.source_ses_id`、`case_mails.ses_id`、`case_searches.ses_id`、`direct_mails.from_ses_id`、`case_threads.ses_id`（`unique(case_id, engineer_id, ses_id)`）・`case_threads.partner_ses_id`。`app_notifications`・`audit_log`・`messages` は会社の id を持たない（本文の「直属」は過去の記録として残す）。

## 1. 受け入れ条件

| 番号 | 立場 | 条件 |
|---|---|---|
| AC1 | 管理者 | 案件の登録で「案件を持ち込んだ SES」が**必須**。選べるのは参加中の SES だけ（「管理者が直接」「管理者の直属」は無い）。空・選べない会社なら登録されず、入力は残り「案件を持ち込んだ SES を選んでください。」 |
| AC2 | 管理者 | 登録した案件は `source_ses_id`＝選んだ会社、`registered_by`＝管理者。以後は、その会社が持ち込んだ案件と同じ扱い |
| AC3 | 管理者 | 案件を発信できない：0章の発信の入口（distribute・案内メール・検索・案内メールの後始末）はすべて 403。案件の画面に「エンジニアに案内する」のボタンが無く、「案内は、案件を持ち込んだ {会社名} の担当者が行います。」と出る |
| AC4 | 管理者 | 発信の記録は読める：案件の画面の「案内メール」（ボタン・記録する欄なし）と「配信済み」 |
| AC5 | 管理者 | 案件のやり取りは**すべて読むだけ**（書けない、既読をつけない、「今やること」に出ない） |
| AC6 | 管理者 | 「直属だけ」のボタン・「直属」の印・所属の変更先の「管理者の直属」が無い。どの画面を開いても `kind='direct'` の行が新しくできない |
| AC7 | 管理者 | 発信以外の操作（登録・編集・状態・AI・選考・連絡メール・一斉配信）は今までどおり |
| AC8 | SES・担当者 | 管理者が自社を選んで登録した案件で、自社が登録したのと同じことができる：編集・案内（検索→送信）・返事の記録・書類・やり取りの相手。登録したとき、担当者全員にお知らせ（とメール）が届き、「今やること」に「新しい案件」が出る |
| AC9 | SES・担当者（MRSY） | 元直属のエンジニアが自社のエンジニアとして見える（一覧・詳細・応募・契約・やり取り）。これまで管理者に届いていた知らせ（応募・面談・成約・質問・連絡メール）が担当者に届く |
| AC10 | SES・担当者（MRSY 以外） | 元直属のエンジニアは「合同会社MRSY」の所属として、他社のエンジニアと同じ扱い（イニシャル（合同会社MRSY）） |
| AC11 | EG（元直属） | 所属に「合同会社MRSY」と出る。連絡メール・やり取りの相手は MRSY の担当者（「管理者」とは出ない）。所属が変わったことを画面のお知らせで知る |
| AC12 | EG（ほか） | 変わらない。やり取りの相手の決め方から「直属」の例外が消えるだけ |
| AC13 | データ | 直属を参照する行がすべて合同会社MRSY に付け替わる（`park:status` で「直属の参照」0）。付け替えは冪等で、戻せる（バックアップ） |
| AC14 | 全員 | アプリ・マニュアル・README の画面の文から「直属」が消える（記録・過去のお知らせの本文は除く） |

## 2. 決めたこと（Leo の推奨で仮置き。7章で TEZZO さんに確認）

| 番号 | 決めたこと | 理由 |
|---|---|---|
| D1 | 管理者の案件登録で、持ち込みの SES は**必須**（AC1）。画面で確かめる（`CaseController::store`）。`Workflow::saveCase` は画面を通らない保存（見本・テスト）のため、今までどおり空でも通す（プロバイダーと同じ形） | 依頼「管理者であっても SES として登録」 |
| D2 | 「SES として発信」＝**発信するのは、選んだ会社の担当者**。管理者が代わりに送る道は作らない（AC3）。管理者が登録したら、その会社の担当者にお知らせ＋メール「管理者が貴社の案件として登録しました：{案件名}」→ `/cases/{id}`（種類 `case`） | 依頼「管理者は案件発信をできない」 |
| D3 | 管理者の発信を止める場所は、`CaseMail::canSend`（＝`CaseSearch::canUse`）から管理者を外す（案内メール・検索・後始末が一度に 403）＋ `CaseController::distribute` で管理者は 403。`CaseSearch::canOpen` の管理者も外す。`Workflow::distribute`・`CaseMail::send` の処理そのものは変えない（seeder・テストが直接呼ぶため） | 入口をまとめて閉じ、処理を壊さない |
| D4 | 管理者は発信の記録を読める（AC4）。`CaseController::show` で `canSeeMails = canSend || admin` を分け、管理者には一覧だけ（「記録」「書類を返した」「書類」「エンジニアに案内する」は出さない） | 運営として状況は見たい |
| D5 | **持ち込み元が空の今の案件**（管理者が直接登録したもの）も、直属と同じく合同会社MRSY に付け替える（データ移行で `source_ses_id = MRSY`） | 空のままだと誰も発信できない案件になる。「管理者が直接」は直属と同じ意味だったため |
| D6 | 案件の編集では持ち込み元を変えない（今のまま）。ただし、移行のあとに持ち込み元が空の案件が残っていたら（見本の削除で空になったもの）、管理者だけ編集で一度だけ選べる | 案件が宙に浮かないように |
| D7 | 案件のやり取り：**管理者はすべて読むだけ**。`isDirectThread`・`unreadDirect` を消し、`canPost` の管理者は「管理者は、やり取りを読むだけです。」。`isMine` の管理者は常に偽。`Todo::admin` のやり取りの行を消す。`partnerFor` の「EG が直属」の例外を消す。相手の会社に使える担当者がいないときに管理者へも知らせる決まり（`notify` 259）は残す | 直属が無いので、管理者が相手になる1本は無い |
| D8 | 直属向けの分岐は全部消す：`Notifier::toSes` の管理者への振り替え、`Workflow` の知らせの除外（204・860・900・942・999）、`CaseMail` 125、`Registration`（87・98・375）、`PublicController` 41、`helpers` 62、`Requirements` 98、`DirectMail`（宛先は所属の担当者だけ）、`EgController` の `sesDirect`、`CaseSearch` の管理者の own、`FindController`・`PeopleController`。`Workflow::directSesId`・`isDirect` は**関数ごと消す** | 依頼「直属は廃止」。残すと、また作られる |
| D9 | 請求：`Billing::build` の直属の除外を消す。**付け替えた契約（移行の時点で直属だったもの）は請求しない**：`contracts.fee_exempt`（boolean、既定 false）を足し、移行で true にし、`Billing::items` で飛ばす。契約の画面に「請求の対象外（元直属）」。移行のあとにできる契約は普通に請求 | 前の約束「直属は PARK 自身の契約なので請求しない」を、さかのぼって変えない（お金の件なので Q3 で確認） |
| D10 | 「直属だけ」ボタン・`?ses=direct`・「直属」の印、`Affiliation::targets` の直属（と行を作る呼び出し）を消す。`AdminController` 137 は「`kind` が `ses` でない会社は 404」に一般化して残す | 消した行・古い行を SES の画面で開かせない |
| D11 | `kind='direct'` の行は**消さない・変えない**（参照が0になるだけ）。消すのは TEZZO さんの了承のあと、別の作業で | 戻せるように。削除は了承が要る |
| D12 | `SamplePurge` の `--move-kept-to-direct` を `--move-kept-to=<会社の表示 ID>`（参加中の SES だけ）に変える。中身（残す人を移す・やり取りの1本が重なるなら止める）は同じ | 直属の行を作らない |
| D13 | `park:seed-dummy` の `--direct` と「エンジニア（直属）」の数を消す（全員 SES の所属） | 直属を作らない |
| D14 | `park:status`：「エンジニア：管理者の直属」を、「直属の参照（表ごとの合計。0 のはず）」「持ち込み元の無い案件」「請求の対象外の契約（元直属）」に替える。移行の前に見るための「合同会社MRSY の候補（id・状態・使える担当者の数）」も出す | 前後の件数の確認 |
| D15 | 元直属の EG と MRSY の担当者への知らせは、**画面のお知らせだけ**（メールなし）。EG：「所属の会社が「合同会社MRSY」になりました。連絡は合同会社MRSY のご担当者へ。」。MRSY の担当者：「管理者の直属だった {n} 名のエンジニアが、貴社の所属になりました。」→ `/ses/engineers` | メールの一斉送信は了承が要る |
| D16 | 版は `v0.11.0`（管理者の権限が変わるため） | — |

## 3. データ移行（マイグレーション1本＋サービス）

処理は `app/Services/DirectMerge.php` に置き（`plan()`・`run()`・`restore()`）、マイグレーション `database/migrations/2026_11_15_000002_merge_direct_into_mrsy.php` から呼ぶ（テストからも呼べるように）。

### 3-1. 付け替える先を探す

- `ses_companies` で、`kind='ses'` かつ名前を正規化（NFKC・空白を除く）したものが `合同会社MRSY` の行。
- 0件・2件以上・`status` が `active` でない → **何も変えずに止める**（例外。文：「合同会社MRSY が見つからない（または複数・参加中でない）ため、直属の付け替えを止めました。」）。ただし、下の「対象」が0件なら探さずに終わる（テストの DB・新しい DB）。
- 見つからないときに**作らない**（担当者のいない会社を作ると、知らせが誰にも届かない。Q2）。

### 3-2. 対象（D = `kind='direct'` の id の集合、M = 合同会社MRSY の id）

| 表・列 | 条件 | 変え方 |
|---|---|---|
| `engineers.ses_id` | in D | M |
| `users.ses_id` | in D かつ `role='eg'` | M |
| `applications.ses_id` | in D | M |
| `contracts.ses_id` | in D | M、`fee_exempt=1`（D9） |
| `case_threads.ses_id`・`partner_ses_id` | in D | M（それぞれ） |
| `cases.source_ses_id` | in D、または **null**（D5） | M |
| `case_mails.ses_id`・`case_searches.ses_id`・`direct_mails.from_ses_id` | in D | M（null は管理者の記録なので変えない） |

### 3-3. 止める条件（何か1つでも当たれば、何も変えずに止める）

- `users` で `ses_id` in D かつ `role` が `eg` 以外（想定外）。
- `invoices.ses_id` in D（直属には請求していないはず）。
- `case_threads` で、付け替えると `unique(case_id, engineer_id, ses_id)` がぶつかる行（同じ案件×エンジニアで、D と M の1本が両方ある）。
- 念のため：`information_schema.columns` で、今のデータベースの `%ses_id` の列のうち、上の表に無い列が D を参照していたら止める（表が増えたときの取りこぼし防止）。

### 3-4. 流れ（`run()`）

1. `plan()` で対象の行の id を表・列ごとに集める（件数も）。全部0なら何もせず終わる（**冪等**：2回目は対象が0）。
2. 3-3 を確かめる。
3. バックアップを `storage/app/backups/merge-direct-YYYYMMDD-HHMMSS.json` に書く（表・列ごとの id の一覧、元の値、D・M）。`SamplePurge::BACKUP_DIR` と同じ場所。
4. トランザクションで 3-2 を更新。
5. D15 のお知らせを `app_notifications` に入れる（メールなし）。
6. `audit_log` に `direct-merge`（表ごとの件数とバックアップのファイル名。名前は書かない）。
7. 行 D は残す（D11）。

### 3-5. 戻し方（`down()` → `restore()`）

- いちばん新しい `merge-direct-*.json` を読み、記録した id の行だけ元の値に戻す（`source_ses_id` は null に、`fee_exempt` は 0 に）。バックアップが無ければ何もしない。行 D を残しているので戻せる。お知らせは消さない。
- コードは `git revert`。`fee_exempt` の列は `down()` で消す（`Schema::hasColumn` で囲む）。

### 3-6. 前後の確かめ（Leo）

- 反映の前：手元で `php artisan park:status` を新しいコードで動かし、「合同会社MRSY の候補」と「直属の参照」の件数を見る。公開版は `park-remote-status.sh`（反映の前は古い表示の「エンジニア：管理者の直属」だけ見える）。
- 反映のあと：公開版の `park:status` で「直属の参照」0、「持ち込み元の無い案件」0、「請求の対象外の契約」が移行前の契約数と同じ。バックアップのファイルがサーバーにできたこと。

## 4. 画面と操作（プレーヤーごと）

| 立場 | どこで | 変わること |
|---|---|---|
| 管理者 | 案件の登録・取り込み `/cases/new` | 「案件を持ち込んだ SES（必須）」。空の選択肢は「選んでください」。登録したあとの文「登録しました。案内は {会社名} の担当者が行います（担当者にお知らせしました）。」 |
| 管理者 | 案件の画面 `/cases/{id}` | 右の「エンジニアに案内する」のカードを、「案内は、案件を持ち込んだ {会社名} の担当者が行います。」に替える。「案内メール」は一覧だけ（D4）。「配信済み」は今のまま（読むだけ）。やり取りの一覧の「相手」は会社名だけ |
| 管理者 | ホーム `/admin` の募集中の案件 | ボタン「マッチング」→「開く」 |
| 管理者 | エンジニアの一覧 `/admin/engineers` | 「直属だけ」ボタンと「直属」の印が無い（所属の会社名を出す） |
| 管理者 | エンジニアの「所属」カード | 変更先に「管理者の直属」が無い |
| 管理者 | 案件のやり取り `/threads`・やり取りの画面 | 読むだけ。入力欄の代わりに「管理者は、やり取りを読むだけです。」 |
| SES・担当者 | 管理者が自社を選んで登録した案件 | 自社の案件と同じ（編集・案内・返事・書類・やり取り）。ホームの「今やること」に「新しい案件」 |
| SES・担当者（MRSY） | エンジニアの一覧・詳細・応募・契約・やり取り・連絡メール | 元直属の人が自社の人として出る。ホームに D15 のお知らせ |
| SES・担当者（ほか） | 案内メール・検索・やり取り | 元直属の人は「イニシャル（合同会社MRSY）」 |
| EG（元直属） | ホームの「担当者への連絡」・案件の「担当者とのやり取り」・連絡メール | 相手が「合同会社MRSY の担当者」。D15 のお知らせ |

## 5. 境界の挙動

| 場面 | 動き |
|---|---|
| 管理者が持ち込みの SES を空で登録／停止中の会社の id を送る | 登録されない。入力が残り、AC1 の文 |
| 管理者が `/cases/{id}/mail`・`/search`・`/searches/{sid}`・distribute・`/case-mails/{id}/reply` などを開く・送る | 403。行（`case_mails`・`case_offers`・`case_searches`）は増えない |
| 管理者が前に作った検索（`case_searches.ses_id` が空） | 管理者も SES も開けない（記録として残る） |
| 持ち込み元の SES の担当者が0人 | 登録はできる。登録の知らせは管理者にも出す（「担当者がいないため、案内できる人がいません」） |
| 持ち込み元の会社が停止中 | 今までどおり（案内できるのは参加中の会社の人だけ） |
| 元直属のエンジニアが、他社が持ち込んだ案件で書く | 相手は持ち込み元（v0.10.1 の R1 どおり。直属の例外は無い） |
| 移行の前にできた「管理者が相手」の1本 | 移行で相手が MRSY になる。続けて書くと MRSY の担当者が相手。管理者が書いた過去の発言は「PARK 管理者」のまま |
| 移行の前の未読（管理者が読んでいない EG の発言） | MRSY の担当者の未読として出る（既読の印は変えない） |
| `park:merge` 相当の2回目の migrate | 対象0で何もしない |
| 合同会社MRSY が見つからない・参加中でない | migrate が止まる（3-1）。コードは新しくなっているので、Leo が TEZZO さんに上げる |
| `kind='direct'` の行を URL（`/admin/ses/{id}`）や表示 ID で開く | 404（D10） |
| 見本の削除で持ち込み元が空になった案件 | 管理者だけ、編集で一度だけ持ち込み元を選べる（D6）。それまでは誰も案内できない |
| 兼任（EG＋管理者）の TEZZO さんが案件を登録 | 管理者として持ち込みの SES を選ぶ。案内は、その会社の担当者のログインで行う（Q1） |

## 6. 作業の手順（implementer 向け）

### 段階1：データ移行（コードの分岐はまだ消さない）

1. 手元の DB で合同会社MRSY の行を確かめる（`php artisan tinker` などで `ses_companies` の id・kind・status、`users` の `role=ses` の人数。**名前・メールは PLAN・テストに書かない**）。違っていたら Leo に返す。
2. `DirectMerge`（3章）と、マイグレーション（`contracts.fee_exempt` を足す＋ `DirectMerge::run()`、`down()` で `restore()` と列の削除）。
3. `Billing::items` で `fee_exempt` の契約を飛ばす。契約の画面（`pipeline` の契約の詳細）に「請求の対象外（元直属）」。
4. `Status.php`（D14）。
5. テスト M1〜M8（8章）。`php artisan test`。

### 段階2：直属をコードから消す

1. 0章の一覧を上から直す（D7・D8・D10・D12・D13）。`Workflow::directSesId`・`isDirect` を消し、`grep -rn "isDirect\|directSesId\|kind.*direct\|'direct'\|直属" app resources/views routes database/seeders` が、マイグレーションと `AdminController` の一般化した 404 以外で0件になるまで。
2. `CaseThread`：`partnerFor` の例外・`isDirectThread`・`unreadDirect`・`isMine` の管理者・`partnerName` の管理者・`canPost` の管理者・`markRead` の管理者・`notify` の直属の枝。`Todo::admin` のやり取りの行。ビュー `threads/index`・`threads/show`・`cases/show` 89（相手は `partner_name`）。
3. `DirectMail`：EG の宛先は所属の担当者だけ（直属なら管理者、の枝を消す）。`mails/index` の文。`EgController`・EG のホームのカードの文。
4. `Affiliation::targets`：管理者も参加中の SES だけ。`move` の知らせのリンクは `/ses/engineers` に統一。
5. `SamplePurge`・`PurgeSamples`（D12）、`DummySeed`・`SeedDummy`（D13）。
6. 既存テストを直す（8章の「直す」）。`php artisan test`。

### 段階3：管理者は発信しない・登録は SES として

1. `CaseMail::canSend`：`ses` かつ持ち込み元＝自社だけ。`CaseSearch::canOpen`：管理者を外す。`CaseController::distribute`：管理者は 403（SES は今のまま）。`CaseMailController::submitted` の `|| admin` を消す。
2. `CaseController`：`sesList` の空の選択肢をやめる（`cases/form` の `blank` を「選んでください」、ラベル「案件を持ち込んだ SES（必須）」）。`store` で管理者は必須と参加中の SES かを確かめる（D1）。`edit`・`update` は D6（持ち込み元が空のときだけ、管理者が選べる）。
3. `Workflow::saveCase`：管理者が登録したら、持ち込み元の担当者に D2 のお知らせ＋メール（担当者0人なら管理者に出す）。SES の登録のときの管理者あての本文を「内容を確かめてください。案内は {会社名} の担当者が行います。」に（「マッチングして配信」をやめる）。`store` の完了の文（4章）。
4. `CaseController::show`：`canSeeMails`（D4）と `cases/show` の出し分け（管理者のカードの差し替え、案内メールの一覧のボタンを `canMail` のときだけ）。`admin/dashboard` のボタン名。
5. テスト N1〜N10。`php artisan test`。

### 段階4：マニュアル・README・版

1. マニュアル（9章）と README（「直属」17 か所、案件の発信・`CaseMail::canSend` の説明 115 行、版の行）。
2. `config/park.php` を `v0.11.0`。版を見ているテストを直す（grep `v0.10.1`）。
3. 全体を `php artisan test`。終わったら Leo に、直したファイルと、確かめていないこと（公開版の MRSY の行）を返す。

## 7. TEZZO さんへの確認（推奨で仮置きして作る。違えば直す）

### 警告：グループ単位の変更（合同会社MRSY に見える・届くようになるもの）

- **W1**：元直属のエンジニア全員の氏名・連絡先・プロフィール・スキル・書類（履歴書・業務経歴書）が、合同会社MRSY の SES・担当者全員に「自社のエンジニア」として見え、編集・停止・所属の変更もできるようになる。
- **W2**：元直属のエンジニアの応募・選考・面談・契約の記録（`applications`・`contracts` の会社が MRSY になる）が、MRSY の SES・担当者に見え、選考を進められる。
- **W3**：元直属のエンジニアの契約は、D9 の推奨なら請求しない（「対象外」の印付きで MRSY の画面に出る）。推奨を採らなければ、**MRSY あての請求書に初期費用・月額費用として載る**。
- **W4**：管理者と元直属のエンジニアのやり取り（案件の質問と管理者の答え）が、MRSY の担当者に読め、続きを書けるようになる。管理者が読んでいない発言は、MRSY の未読として出る。
- **W5**：管理者が直接登録した案件（持ち込み元が空）が全部 MRSY の案件になる（D5）。MRSY が編集・状態の変更・案内でき、その案件で**管理者が送った案内メールの記録と返事**が見える（他社のエンジニアはイニシャル（会社名）、MRSY のエンジニアは氏名）。他社からの推薦の知らせ、他社のエンジニアからの質問（やり取りの相手）も MRSY に届く。
- **W6**：これまで管理者に届いていた元直属のエンジニアについての知らせ（応募・取り下げ・面談・成約・停止・連絡メール・質問）が、これからは MRSY の担当者全員にお知らせとメールで届く。
- **W7**：他社の SES・担当者からは、元直属のエンジニアが「イニシャル（合同会社MRSY）」に見えるようになる（今は会社名が付かない）。他社に、MRSY が多くのエンジニアを抱えているように見える。
- **W8**：元直属のエンジニアの連絡メールの宛先が、管理者から MRSY の担当者に変わる。過去に管理者へ送った連絡メールは、受け取った人（管理者）にだけ見えるまま（implementer が `DirectMail` で確かめる）。
- **W9**：管理者が今後登録する案件は、選んだ会社（MRSY でも他社でも）の担当者に「管理者が貴社の案件として登録しました」と届き、その会社が案内する。

### 確認事項

- **Q1（いちばん大事）**：管理者は発信できなくなる。TEZZO さんが MRSY として案内するには、**MRSY の担当者のログイン**が要る。今の兼任は「EG＋担当者」か「EG＋管理者」だけで、**管理者と担当者は兼ねられない**。推奨：TEZZO さん用に、別のメールアドレスで MRSY の担当者を1人仮登録する（コードの変更なし）。管理者と担当者の兼任を作るなら、別の計画にする。
- **Q2**：合同会社MRSY に、本登録が済んだ担当者は何人いるか（0人だと W6 の知らせが誰にも届かず、案件も案内できない）。見つからないときは移行を止める（会社を勝手に作らない）。推奨：このまま。Leo が手元と公開版で段階1の手順1を確かめて報告する。
- **Q3（お金）**：元直属の契約を請求するか。推奨：移行の時点の契約は請求しない（D9。前の約束どおり）、これからの契約は MRSY に請求する。
- **Q4**：持ち込み元が空の案件（管理者が直接登録したもの）も MRSY に付け替えるか（D5・W5）。推奨：付け替える（空のままだと誰も案内できない）。
- **Q5**：管理者の連絡メール（案件ではない自由なメール）と一斉配信は残すか。推奨：残す（案件の発信ではないため）。案件のことを連絡メールで送るのは、運用で控える。
- **Q6**：`kind='direct'` の行（「管理者の直属」）を、移行のあとに消すか。推奨：今回は残す（戻せるように）。参照が0になって1週間ほどたったら、了承を取って消す。

## 8. テスト

土台（架空）：会社 A・B（参加中の SES、担当者各2人）、MRSY 役の会社（名前「合同会社MRSY」、参加中、担当者2人）、直属の行（テストの中で `kind='direct'` を直接入れる）、直属の EG D1・D2、A 所属の EG F、管理者。

### 新しい（`tests/Feature/V0110NoDirectTest.php`）

| 番号 | 受け入れ条件 | 手順（誰が・どこで・何を） | 確かめること |
|---|---|---|---|
| M1 | AC13 付け替え | 直属に D1・D2（`users` も）、D1 の応募・契約・やり取り（相手＝直属）・連絡メール、持ち込み元が空の案件を入れ、`DirectMerge::run()` | 3-2 の全列が MRSY。契約は `fee_exempt=1`。行 D は残る |
| M2 | 冪等 | M1 のあと、もう一度 `run()` | 何も変わらない。バックアップが増えない |
| M3 | 戻せる | M1 のあと `restore()` | すべて元の値（空の持ち込み元は空に戻る） |
| M4 | 止める（MRSY なし・複数・停止中） | MRSY 役を消す／2つにする／停止にして `run()` | 例外。1行も変わらない |
| M5 | 止める（1本が重なる） | 同じ案件×D1 で、直属と MRSY の1本を両方作って `run()` | 例外。1行も変わらない |
| M6 | 対象0なら何もしない | 直属も空の持ち込み元も無い DB で `run()` | 変わらない。MRSY が無くても例外にならない |
| M7 | D15 のお知らせ | M1 のあと | D1・D2 に1件ずつ、MRSY の担当者に1件ずつ。`mail_outbox` は増えない |
| M8 | 請求 | M1 のあと `Billing::build(MRSY, その月)` | 付け替えた契約が載らない。移行後に MRSY で作った契約は載る |
| N1 | AC1 | 管理者が `POST /cases`（持ち込みの SES なし／停止中の会社／A） | 前の2つは登録されず AC1 の文、A は登録される（`source_ses_id = A`） |
| N2 | AC2・AC8・D2 | N1 の A の案件 | A の担当者2人にお知らせ＋メール、A の担当者の `/ses` に「新しい案件」 |
| N3 | AC3 | 管理者が A の案件で `GET /cases/{id}/mail`・`POST …/mail/send`・`GET/POST …/search`・`POST …/distribute`・`POST /case-mails/{id}/reply`・`/searches/{sid}` | すべて 403。`case_mails`・`case_offers`・`case_searches` が増えない |
| N4 | AC3・AC4 | 管理者が `/cases/{id}`（A の担当者が案内を送ったあと） | 「エンジニアに案内する」のボタンが無く、「案内は、案件を持ち込んだ A の担当者が行います。」。「案内メール」の一覧は出るが「記録」のボタンが無い |
| N5 | AC8 | A の担当者が、管理者の登録した案件で検索 → 送信、返事の記録 | 送れる。`case_mails.ses_id = A` |
| N6 | AC5 | 管理者が `POST /cases/{id}/threads/{eid}`（どの1本でも） | 断り「管理者は、やり取りを読むだけです。」。開いても既読が変わらない。`/admin` の今やることに出ない |
| N7 | AC6 | 管理者が `/admin/engineers`・エンジニアの所属カード・`/admin/engineers?ses=direct` | 「直属」の文字が無い。変更先に直属が無い。`ses_companies` で `kind='direct'` の行が増えない |
| N8 | AC9・AC11 | M1 のあと、D1 が案件で質問を書く／連絡メールを送る／応募する | MRSY の担当者に届き、管理者には届かない（担当者が0人の会社のときの決まりは今のまま） |
| N9 | AC10 | M1 のあと、A の担当者が A の案件の案内メールの候補・検索で D1 を見る | 「イニシャル（合同会社MRSY）」。氏名・EG- が出ない |
| N10 | D6 | 持ち込み元が空の案件を作り、管理者が編集で A を選ぶ → もう一度編集 | 1回目は A になる。2回目は選ぶ欄が無い |

### 直す（既存。`grep -ln "直属\|isDirect\|directSesId\|'direct'" tests` と、管理者で案内・検索している所 `grep -n "/mail\|/search\|distribute" tests`）

| ファイル | 直し方 |
|---|---|
| `ProfileAndDirectTest` | 直属の部分（直属の登録・知らせ・一覧）を消し、プロフィールの部分は残す。「直属が無い」ことは N7 で見る |
| `V080DirectMailTest` | 直属の EG → 管理者の宛先のテストを消す。EG の宛先は所属の担当者だけ |
| `V081AffiliationTest` | 管理者の変更先に直属が出る・移せるテストを、「出ない」に替える |
| `V0100CaseThreadTest`・`V0101CaseThreadPartnerTest` | 直属の1本（管理者が書ける・既読・今やること）を、「管理者は読むだけ」に替える。N4（直属は管理者）を消す |
| `V030PermsTest`・`V070SearchTest`・`EmptyStateTest`・`UiUnifyTest`・`V090DisplayIdTest` | 管理者が案内メール・検索を使っている所を、持ち込み元の担当者に替え、管理者は 403 を確かめる |
| `PurgeSamplesTest` | `--move-kept-to-direct` を `--move-kept-to=<表示 ID>` に |
| `SeedDummyTest` | `--direct` と直属の数を消す |
| `Step1ReviewFixesTest`・`AdminToPeopleRegistrationTest`・`PostalAddressTest`・`NameSplitTest`・`EngineerDetailTest`・`DualRoleTest`・`SesDetailButtonsTest` | 直属の会社を使っている所は、参加中の SES に替える。直属だけの決まりを確かめる部分は消す |
| 版を見ているテスト | `v0.11.0` |

`Workflow::distribute($id, …, $admin)` を直接呼ぶテストは、処理を変えないので直さない（D3）。

## 9. マニュアル（`resources/manuals/`。直したら Leo が 41_Park-Project の `04_Manuals` の各フォルダの `マニュアル.md`・`操作手順書.md` に写し直す）

- `admin.md`・`admin-steps.md`：「直属」の段落を全部消す（0章の行）。案件の登録は「持ち込みの SES を必ず選ぶ」。**案内（エンジニア検索・案内メール）は持ち込み元の担当者が行い、管理者は送れない**（112 行・207 行の手順を、読むだけの説明に替える）。やり取りは読むだけ。所属の変更先。請求の「対象外（元直属）」。表示 ID の表の「会社（SES・直属）」→「会社」。
- `ses.md`・`ses-steps.md`：管理者が貴社の案件として登録することがある（お知らせが届く。案内は貴社で）。「直属への移動」の文を消す。151 行の「直属なら管理者」を消す。
- `engineer.md`・`engineer-steps.md`：「直属の方は管理者」の書き添えを全部消す（宛先は所属の会社の担当者）。管理者はやり取りを読むだけ。
- README：「直属」17 か所、`CaseMail::canSend` の説明（115 行）、版。

## 10. 了承が要ること

- **公開版への反映**：コードの反映と migrate は常時了承の範囲だが、今回の migrate は**他社（MRSY）に見える範囲を変えるデータの書き換え**。7章の W1〜W9 と Q1〜Q4 に TEZZO さんが答えるまで、Leo は公開版に反映しない（手元で作って試すのは進めてよい）。
- **データの削除**：しない。`kind='direct'` の行を消すのは Q6 の了承のあと、別の作業で。
- **メールの送信**：移行のときのお知らせは画面だけ（D15）。元直属の EG・MRSY にメールで知らせるなら、文と宛先を TEZZO さんに見せて了承を取ってから Leo が送る。移行のあとの普段の知らせ（W6・W9）は、アプリの普段の動き。
- **公開リポジトリ**：会社名「合同会社MRSY」がマイグレーションのコードに入る（41_Park-Project の CLAUDE.md で既に公開している名前）。人の名前・メールは書かない。
- 戻し方：`php artisan migrate:rollback --step=1`（バックアップから戻す）→ `git revert`。公開版で戻すのは、TEZZO さんに知らせてから。
# PLAN：案件のやり取りの相手を「案件を持ち込んだ SES」に変える（v0.10.1。2026-11-14）

> **これが今の計画**。下の「v0.10.0」以降は前の計画で、消さずに残している（この章は Leo が PLAN.md の先頭に足す）。
> 依頼：TEZZO（Leo 経由。2026-11-14）「A 社が出した案件を B 社の EG（F）が受けるなら、やり取りは A 社の担当者と F。B 社はどんなやり取りかを見られる（読むだけ）。管理者は読むだけ（直属の EG とだけ書ける）。EG が案件ごとに SES へ不明点を聞く使い方」。
> 作る順：段階1 → 段階2 → 段階3（段階ごとに commit。`php artisan test` を通してから次へ）→ park-reviewer → park-tester。commit・push・公開版への反映は Leo。
> プレーヤー：管理者・SES・担当者・EG の4つ（担当者＝`users.role=ses` の人。SES の代表者も担当者の1人。今回の権限は SES と担当者で同じ）。
> 外部のライブラリは足さない。名前・メールはこのファイルにもテストにも書かない（テストは架空の人）。
> **警告（グループ単位の変更）**：他社（B 社）の EG の質問と、イニシャル・会社名が、案件を持ち込んだ会社（A 社）に見えるようになる。逆に A 社の担当者の氏名・会社名が F に見える。7章の Q1 に載せ、完了報告でも警告する。

## 0. コードで確かめた今の形（v0.10.0）

- `case_threads` は `unique(case_id, engineer_id, ses_id)`。`ses_id`＝書いたときの EG の所属の会社で、同時に「相手（書ける側）」でもある。
- 見える・書ける：`CaseThread::visible`（SES は `t.ses_id == u.ses_id`）、`canPost`（SES は `e.ses_id == u.ses_id`、管理者は直属だけ書ける＝2026-11-14 TEZZO）、`CaseThreadController::target`（SES は所属か、自社の `ses_id` の1本があるときだけ。ほかは 404）。
- 既読：`read_by_side`＝相手側（今は所属の会社、直属なら管理者）が読んだか。`markRead` は SES と、直属の1本の管理者だけ。
- 通知 `notify`：EG→`N::toSes(所属)`（担当者0人なら管理者にも）／担当者→`N::toEg`／管理者→`N::toEg`＋（直属でなければ）`N::toSes`。**管理者は会社の1本に書けなくなったので、最後の枝は使われていない**。
- 名前：`from_label` は書いたときの表示名を保存（EG は**本名**）。一覧 `listQuery`・`unreadRows`・`Todo::ses`・通知の本文も `e.name`（本名）を使う。→ **A 社に見せると本名が出る**。他社の EG の見せ方は `CaseSearch::displayName`（管理者と自社＝氏名、他社＝イニシャル、後ろに「（所属の会社名）」）、`CaseMail::callName`、v0.9.0 D8（他社の EG の EG- は SES に見せない）、`CaseController::show` の `route='group'`（他社の人には名前・書類を出さない）。
- `cases.source_ses_id` は**登録のときだけ入り、編集では変わらない**（`Workflow::saveCase` 827 行）。変わるのは `SamplePurge`（見本の会社を消すとき空にする）だけ。管理者の登録では空か、管理者が選んだ会社。
- A 社（持ち込み元）は `CaseMail::canSend` で案件の案内メールの一覧（`case_mails`）を見ており、他社の人は「イニシャル（会社名）」で出ている。応募（`applications`）は SES には自社の分しか出ない。
- テスト `V0100CaseThreadTest` の土台：案件の持ち込み元は他社（SES2）、EG1 は SES1 の所属。T5（相手：所属の会社名）、T6（持ち込み元に届かない）、T13（持ち込み元から見えない）が今の決まりを確かめている。

## 1. 決定事項（Leo の推奨で仮置き。7章で TEZZO さんに確認）

| 番号 | 決めたこと | 理由 |
|---|---|---|
| R1 | **相手（書ける会社）P** を、1本を作るときに決めて `case_threads.partner_ses_id` に残す。決め方（`CaseThread::partnerFor($c, $e)`）：持ち込み元 `s = cases.source_ses_id` が空でなく、`s ≠ e.ses_id`、`s` が `kind=ses`、かつ EG が直属でない → **P = s（A 社）**。それ以外（管理者の登録で持ち込み元が無い／持ち込み元が所属と同じ／持ち込み元が直属の行／EG が直属）→ **P = e.ses_id**（今までどおり。直属なら相手は管理者） | TEZZO さんの指摘どおり。持ち込み元は案件の中身を一番知っている。依頼の「直属なら今までの相手」に合わせる |
| R2 | 1本のキーは**今のまま**（案件×EG×**所属の会社** `ses_id`）。`ses_id` の意味は「その1本を作ったときの EG の所属（読むだけの会社 B）」のまま、相手は新しい列 `partner_ses_id` に分ける | 所属が変わったら新しい1本（v0.10.0 D6、v0.8.1「新しい会社に前の会社の会話を見せない」）を崩さない。unique を変えないのでマイグレーションが小さい |
| R3 | 書ける人：EG 本人、P の担当者（P が直属なら管理者）。**所属の会社 B（P と違うとき）の担当者は読むだけ**。管理者は今までどおり、直属の1本だけ書け、ほかは読むだけ | 依頼どおり |
| R4 | 見える人（`visible`）：管理者＝全部、SES＝`t.ses_id == u.ses_id`（B）または `partner == u.ses_id`（A）、EG＝本人。**どちらでもない会社には今までどおり 404** | 無関係な SES に漏らさない |
| R5 | **A 社への EG の見せ方**：`CaseSearch::displayName` と同じ規則。A 社（＝EG の所属でない会社）の画面・お知らせ・メールでは「イニシャル（所属の会社名）」、EG- は出さない、エンジニアの詳細へのリンクを出さない。EG の発言の表示名は、保存した `from_label` ではなく、**見る人で出し分ける**（`CaseThread::egName($viewer, $e, $t)`）。B 社・管理者・本人には氏名 | v0.7.0・v0.9.0 の他社 EG の扱いに合わせる。本名を A 社に出さない |
| R6 | **EG への A 社の見せ方**：A 社の担当者の発言は今までどおり「氏名（会社名）」。EG の案件の画面の0件の文は「相手：{P の会社名}の担当者（{所属の会社名}も読めます）」。P＝直属なら「相手：PARK 管理者」 | 誰と話しているか、誰が読むかを EG に分かるようにする |
| R7 | 既読 `read_by_side` は **P の側**が読んだか。P の担当者が開くと EG の発言を既読（P が直属なら管理者）。B 社・管理者（直属でない）が開いても変えない | B 社が読んだだけで、A 社の未読が消えないように |
| R8 | **通知**：EG が書いた → `N::toSes(P)`（P が直属なら中で管理者へ。P に使える担当者がいなければ管理者にも＝今の決まり）。P の担当者・直属の管理者が書いた → `N::toEg`。**B 社には、発言ごとの通知は出さない**。1本が**初めてできたときだけ**、B 社に「お知らせ」1件（メールなし）：「{EG 氏名} さんと {P の会社名} の担当者が、案件「…」のやり取りを始めました（貴社は読むだけです）」→ `/cases/{id}/threads/{eid}`。P = B のときは出さない。管理者の「会社にも知らせる」枝は消す（書けないので） | 読むだけの人に毎回の通知は要らない。ただし、自社の EG が他社と話し始めたことは一度知らせる（控えめ） |
| R9 | **A 社が書き始められる相手**：P = A で、かつ A の画面に出ている EG（この案件の `case_mails` に行がある人）だけ。それ以外（管理者が配信しただけの人など）は、EG が先に書いてから。`CaseThreadController::target` で、A 社は「`partner_ses_id = A` の1本がある」か「P = A かつ `case_mails` に行がある」ときだけ通し、ほかは 404 | A 社が、ほかの道で届いた B 社の EG の存在を URL の番号で探れないように |
| R10 | P の会社が参加中でない（停止など）→ 書けない（理由：「案件を持ち込んだ会社が参加中ではないため、書けません。」）。EG の所属が参加中でない → 今までどおり書けない | 相手のいない1本に書かせない |
| R11 | 所属が変わった（v0.8.1）：前の1本（`ses_id`＝前の所属）は、EG・前の所属・P・管理者が読める（書けない）。新しく書くと、新しい所属で1本でき、P はそのときの決め方（R1）で決まる。**新しい所属の会社には前の1本は見えない**。P が A のままなら、A 社には前の1本と新しい1本の両方が見える（前のほうは読むだけ） | v0.10.0 D6 のまま |
| R12 | 持ち込み元が変わる道は、見本の削除（`SamplePurge` が `source_ses_id` を空にする）だけ。消える P の会社の1本は、`partner_ses_id` を `ses_id` に戻す（バックアップの「変える行」に書く）。`--move-kept-to-direct` で `ses_id` を直属に移すときは、`partner_ses_id` が同じ会社なら一緒に移す | 行は消さない。相手が消えたら所属の会社が相手に戻る |
| R13 | **v0.10.0 でできた1本**：マイグレーションで `partner_ses_id = ses_id`（今までの相手のまま）で埋める。**A 社には見せない**（作ったときの約束が「持ち込み元には見せない」だったため）。その1本に続けて書くと、相手は B 社のまま。`park:status` に「v0.10.1 より前の形で続く1本（持ち込み元と相手が違う）」の件数を出し、0 でなければ Leo が TEZZO さんに上げる（7章 Q3） | データを消さない・書き換えて他社に見せない。今日入れたばかりなので、ほぼ 0 件の見込み |

## 2. データ（マイグレーション1本）

`database/migrations/2026_11_14_000002_case_threads_partner.php`

- `case_threads` に `partner_ses_id`（`foreignId` → ses_companies、nullable、`index`）を足す（`Schema::hasColumn` で囲む）。
- 今ある行は `partner_ses_id = ses_id` で埋める（R13）。行は消さない・`ses_id` は変えない。
- コードでは `partner_ses_id ?? ses_id` で読む（`CaseThread::partnerOf($t)`）。
- `down()` は列を消す。

## 3. 画面と操作（プレーヤーごと）

| 立場 | どこで | 変わること |
|---|---|---|
| EG | 案件の詳細「担当者とのやり取り」 | 0件の文の相手（R6）。発言の表示名は今までどおり。前の所属のときの1本は「前の所属のときのやり取り（読むだけ）」 |
| A 社（持ち込み元）の SES・担当者 | ナビ「案件のやり取り」`/threads` | `partner = 自社` の1本も出る。エンジニアの列は「イニシャル（所属の会社名）」、EG- なし。未読を数える |
| A 社 | 案件の詳細の左「エンジニアとのやり取り」 | 自社が相手の1本が出る。案内メールの一覧（`case_mails`）の他社の人の行に［やり取り］（R9） |
| A 社 | やり取りの画面 `/cases/{id}/threads/{eid}` | 読めて書ける。見出しの EG はイニシャル（会社名）、エンジニアの詳細へのリンクなし |
| A 社 | ホーム「今やること」 | 「{イニシャル}（{会社名}）さんから案件の質問：{案件名}」 |
| B 社（所属）の SES・担当者 | `/threads`・案件の詳細・やり取りの画面 | 自社の EG の1本は全部見える。P が他社の1本は、未読の代わりに「読むだけ（相手：{A社}）」と出し、入力欄の代わりに理由「このやり取りの相手は、案件を持ち込んだ {A社} の担当者です。貴社は読むだけです。」。「今やること」には出さない。始まったときにお知らせ1件（R8） |
| B 社 | 相性表の［やり取り］ | 今のまま（自社の EG。開くと読むだけのことがある） |
| 無関係な SES・担当者 | どこも | 今までどおり見えない（404） |
| 管理者 | `/threads`・案件の詳細・やり取りの画面 | 全部読める。列に「相手」（P の会社名／直属なら「管理者」）を足す。書けるのは P が直属の1本だけ。「今やること」は P が直属の未読だけ |

## 4. 処理（`app/Services/CaseThread.php` を直す。画面はここを呼ぶ）

- 足す：`partnerFor(object $c, object $e): int`（R1）、`partnerOf(object $t): int`、`isOwnEngineer($u, object $t): bool`（`u.ses_id == t.ses_id`）、`egName($viewer, object $e, object $t): string`（R5。`CaseSearch::displayName` と同じ規則を使う）、`canStart($u, object $c, object $e): bool`（R9）。
- `isDirectThread` は `partnerOf($t)` で直属かを見る。`partnerName` は P の名前（直属なら `operator_name`）。
- `visible`：R4。`threadsFor`：SES は `ses_id = u.ses_id or partner_ses_id = u.ses_id`。
- `canPost`：SES は「`u.ses_id == partnerFor(c, e)`」でなければ、所属なら R3 の理由、それ以外は「書けません。」。管理者は `partnerFor` が直属のときだけ。R10 を足す。ほかの条件（届いている・募集中か選考中・停止・兼任）は今のまま。
- `post`：1本を作るとき `partner_ses_id = partnerFor(c, e)` を入れる（`insertOrIgnore` のまま）。初めてできたら R8 の B 社へのお知らせ。
- `notify`：送る先は `partnerOf($t)`。本文の EG の名前は、P が所属と違えば「イニシャル（会社名）」。管理者の枝の `N::toSes` を消す。
- `markRead`：SES は `u.ses_id == partnerOf(t)` のときだけ。管理者は P が直属のときだけ。
- `listQuery`：`left join ses_companies as ps on ps.id = coalesce(t.partner_ses_id, t.ses_id)` で `partner_name`・`partner_kind`、`e.initials` を足す。表示は呼ぶ側（ビュー）で `egName` を通す。
- `unreadRows`：`e.initials`・`t.ses_id`・`s.name` を足す。`unreadForSes($sesId)` は `coalesce(t.partner_ses_id, t.ses_id) = $sesId`。`unreadDirect` も同じ列で直属を見る。
- `CaseThreadController::target`：SES は R4／R9 で通す（所属・前の所属の1本・相手の1本・P=自社かつ `case_mails` あり）。ほかは 404。`index` の SES は `ses_id or partner_ses_id`、並べ替えの未読は「自社が相手の1本」だけで数える。`show` で `egName` を渡す。
- `CaseController::show`：`caseThreads` の SES の絞りを `ses_id or partner_ses_id` に。`case_mails` の他社の行に［やり取り］のリンク（`canStart` のときだけ）。
- `Todo::ses`：名前は `egName`。`Todo::admin`：`unreadDirect` のまま（中で列を変える）。
- ビュー：`threads/index`・`threads/show`・`partials/case-thread`・`cases/show` で、EG の名前・EG-・リンクを `egName` と「自社の EG か」で出し分ける。EG の発言の表示名も同じ（保存した `from_label` は B 社・管理者・本人のときだけ使う）。
- `Status.php`：R13 の件数（`partner_ses_id = ses_id` で、`partnerFor` なら別の会社になる、発言のある1本）。
- `SamplePurge`：R12（`plan()` に「`partner_ses_id` を `ses_id` に戻す1本」、バックアップの「変える行」、移すときの同時更新）。`Registration::cancel`：会社を取り消すときの「記録があれば取り消せない」に `case_threads.partner_ses_id` を足す。
- `config/park.php` を `v0.10.1`。

## 5. 境界の挙動

| 場面 | 動き |
|---|---|
| 案件を管理者が登録し、持ち込み元が空 | 相手は所属の会社（直属なら管理者）＝ v0.10.0 と同じ |
| 持ち込み元＝EG の所属 | 同上（B は自分が相手） |
| EG が直属 | 相手は管理者（持ち込み元があっても）。A 社には見えない |
| 持ち込み元の会社が停止中 | 誰も書けない（理由を表示）。読むのは EG・B・A・管理者 |
| A 社が、`case_mails` に無い B 社の EG の URL を開く（1本も無い） | 404 |
| A 社が、EG が先に書いた1本を開く | 読めて書ける |
| B 社が POST | 断り（R3 の理由）。行は増えない |
| 無関係な C 社 | GET・POST とも 404、一覧・案件の画面・今やることに出ない |
| B 社の担当者が開く | 既読は変わらない。A 社の未読は残る |
| 管理者が A–F の1本に POST | 断り「会社と EG のやり取りは、管理者は読むだけです。」 |
| 所属が B→C に変わった | R11 |
| 所属が B→A に変わった | 新しい1本は P = A = 所属（R1 の「同じ」）。前の1本は A・B・EG が読める |
| 兼任（A 社の担当者で、自分が F） | 今までどおり「ご自身のやり取りには、エンジニアとして入って書いてください。」 |
| v0.10.0 でできた1本 | R13 |
| 見本の削除で持ち込み元が消える | R12 |

## 6. 作業手順

### 段階1：列と決め方
1. マイグレーション（2章）。
2. `CaseThread` に `partnerFor`・`partnerOf`・`egName`・`canStart` を足し、`visible`・`threadsFor`・`canPost`・`post`・`notify`・`markRead`・`listQuery`・`unreadRows`・`unreadForSes`・`unreadDirect` を4章どおりに直す。
3. `CaseThreadController::target`・`index`・`show`。
4. 既存テストの直し（8章の「直す」）と、新しいテスト N1〜N8。

### 段階2：画面の出し分け
1. ビュー4つ（4章）。A 社には EG- とエンジニアの詳細へのリンクを出さない。B 社には「読むだけ（相手：…）」。管理者の一覧に「相手」の列。
2. `CaseController::show`（`caseThreads` の絞り、`case_mails` の行の［やり取り］）。
3. `Todo::ses` の名前。
4. テスト N9〜N13。

### 段階3：消す道・数え・版・マニュアル
1. `SamplePurge`・`Registration::cancel`（4章）。
2. `Status.php` に R13 の件数。
3. `config/park.php` を `v0.10.1`。版を見ているテスト（T24 ほか、grep で探す）を直す。
4. マニュアル（9章）。README の版の行があれば直す。
5. テスト N14〜N16。全体を `php artisan test`。

## 7. TEZZO さんへの確認（推奨で仮置きして作る。違えば直す）

- **Q1（警告：グループ単位の変更）**：B 社の EG の質問が、イニシャル（所属の会社名）で、案件を持ち込んだ A 社に見え、A 社と直接やり取りする。A 社の担当者の氏名・会社名は EG に見える。B 社は全部読める（書けない）。管理者は全部読める。推奨：このまま（TEZZO さんの指摘どおり。名前はイニシャルで、v0.7.0 の案内メールと同じ扱い）。各社の利用の同意の範囲に入るかは、契約の件として確かめてほしい。
- **Q2**：B 社への知らせは、1本が始まったときのお知らせ1件だけ（発言ごとには出さない。メールなし）。推奨：このまま。毎回知らせたいなら、お知らせだけ足す。
- **Q3**：v0.10.0 の間にできた1本で、持ち込み元が別の会社のものは、相手を B 社のままにして A 社に見せない（R13）。推奨：このまま。`park:status` で 0 件なら気にしなくてよい。
- **Q4**：直属の EG は、他社が持ち込んだ案件でも相手は管理者（依頼どおり）。A 社に直接聞けない。推奨：このまま（管理者が A 社に聞いて答える）。

## 8. 受け入れ条件とテスト（`tests/Feature/V0100CaseThreadTest.php` を直す＋ `tests/Feature/V0101CaseThreadPartnerTest.php` を新しく）

土台（架空）：会社 A（持ち込み元）・B（EG の所属）・C（無関係）、各社に担当者2人、EG F（B 所属）、直属の EG D、管理者。案件 X（持ち込み元 A、F に `case_offers`、A の担当者から F への `case_mails` あり）、案件 Y（管理者の登録・持ち込み元なし）、案件 Z（持ち込み元 B）。

**直す（既存）**

| 番号 | 直し方 |
|---|---|
| T5 | 相手の文：持ち込み元が他社の案件では「相手：{持ち込み元}の担当者」と「{所属}も読めます」。持ち込み元なしの案件で、今の「相手：{所属}の担当者」を確かめる |
| T6 | 名前を `test_t6_eg_post_reaches_partner` に。持ち込み元が他社の案件で：P の担当者2人にお知らせ＋メール、所属の担当者には「始まりました」のお知らせ1件だけ（メールなし）、`partner_ses_id`＝持ち込み元・`ses_id`＝所属。記録に本文が無いのは今のまま |
| T7・T8・T17 | 返事・書き始め・一覧と今やることは、P の担当者で行う。T8 は `case_mails` を作ってから |
| T13 | 名前を `test_t13_unrelated_company_cannot_see` に。持ち込み元は見えるので、**無関係な C 社**で 404・一覧に出ないことを確かめる |
| T16 | 管理者は持ち込み元が他社の1本も読むだけ、会社にも知らせない |
| T20 | 所属が変わったあと、P（持ち込み元）は前の1本と新しい1本の両方を読め、新しいほうに書ける。新しい所属には前の1本が見えない |
| T24 | `v0.10.1` |
| T6 の土台の仮定 | `mkCase` の既定の持ち込み元・ほかのテストで「所属の担当者が書く」前提のものは、持ち込み元なし（または所属と同じ）の案件に替える |

**新しい（V0101CaseThreadPartnerTest）**

| 番号 | 受け入れ条件 | 手順（誰が・どこで・何を） | 確かめること |
|---|---|---|---|
| N1 | 相手は持ち込み元 | F が `POST /cases/X/threads/F` | `case_threads.partner_ses_id = A`、`ses_id = B` |
| N2 | 持ち込み元なしなら所属 | F が案件 Y に POST | `partner_ses_id = B`。B の担当者に通知・メール、「始まりました」は出ない |
| N3 | 持ち込み元＝所属 | F が案件 Z に POST | `partner_ses_id = B` |
| N4 | 直属は管理者 | D が案件 X（持ち込み元 A）に POST | `partner_ses_id`＝直属の行。管理者に通知、A には出ない、A は 404 |
| N5 | A が返事を書ける・既読 | A の担当者が `/cases/X/threads/F` を開く → POST | F の発言が `read_by_side=1`、F に `case-thread` のお知らせ |
| N6 | B は読むだけ | B の担当者が GET → POST | GET は 200 で発言と「貴社は読むだけです」、入力欄なし。POST は断り、行が増えない、`read_by_side` は変わらない |
| N7 | C は見えない | C の担当者が GET・POST・`/threads`・`/cases/X`・`/ses` | 404／出ない |
| N8 | A が書き始められる範囲 | A の担当者が、`case_mails` のある F に初めて POST／`case_offers` だけの別の B 社 EG に GET・POST | 前者は1本ができ F に通知。後者は 404 |
| N9 | A には本名と EG- を出さない | F が書いたあと、A の担当者が `/threads`・`/cases/X`・やり取りの画面・`/ses`、お知らせとメールの本文 | F のイニシャル（B 社名）が出て、F の氏名・EG- が出ない。エンジニアの詳細へのリンクが無い |
| N10 | B・管理者には氏名 | B の担当者・管理者が同じ画面 | F の氏名が出る。管理者の一覧に「相手」の列で A の会社名 |
| N11 | B の今やることに出ない | F が書いたあと B の担当者が `/ses` | 「さんから案件の質問」が出ない |
| N12 | A の今やること | 同じく A の担当者が `/ses` | 「{イニシャル}（{B 社名}）さんから案件の質問：…」 |
| N13 | 管理者は読むだけ | 管理者が X の1本に POST | 断り。開いても既読が変わらない |
| N14 | 持ち込み元が停止中 | A を `suspended` → F が POST | 断り（R10 の文） |
| N15 | v0.10.0 の1本はそのまま | `partner_ses_id` を空にした1本（ses_id=B、案件 X）を用意してマイグレーションの埋め方を通す（`partnerOf` が B）→ A が GET、`park:status` | A は 404。status の件数が 1 |
| N16 | 見本の削除 | 見本の会社 A の案件の1本を作り `park:purge-samples --yes` | 1本は消えずに `partner_ses_id = ses_id`（案件ごと消える場合は今のまま消える） |

## 9. マニュアル（`resources/manuals/`。直したら Leo が 41_Park-Project の `04_Manuals` に写し直す）

- `engineer.md`・`engineer-steps.md`（4-6）：相手は**案件を持ち込んだ会社の担当者**（持ち込み元が無い・自社の案件・直属なら今までどおり）。所属の会社も読めること。管理者も読めること。
- `ses.md`・`ses-steps.md`（4-10）：自社が持ち込んだ案件では、他社の EG から質問が届く（相手はイニシャルと会社名で出る）。自社の EG が他社の案件で話すときは、読むだけ（始まったときにお知らせ1件）。他社の案件で答えたいことは、持ち込み元の担当者が答える。
- `admin.md`・`admin-steps.md`（4-8）：相手の決め方（R1）、一覧の「相手」の列、直属だけ書ける。
- README の版の行。

## 10. 了承と確かめ方

- マイグレーションは列を1つ足して、今の行に今までの相手を入れるだけ（消さない・ほかの列は変えない）。コードの反映と migrate は常時了承の範囲（`park-deploy.sh`）。
- **反映の前後に Leo が確かめること**：反映のあと `.claude/scripts/park-remote-status.sh` の `park:status` で R13 の件数を見る（読むだけ）。0 でなければ Q3 として TEZZO さんに上げる。データの削除・書き換えはしない。
- 反映した時点から、**他社の EG の質問が持ち込み元に届く**（Q1 の警告）。完了報告で必ず警告し、返事待ちに載せる。
- メールの送信：新しく一斉に送るものは無い（発言があったときの通知だけ）。公開版でテスト用 EG に案内を送るのは、v0.10.0 で TEZZO さんが了承済み（送る EG の特定待ち）。
- 戻し方：park は `git revert`（足した列は残る。残っても v0.10.0 のコードは読まない）。列を消すのは了承を取ってから。
# PLAN：案件ごとのやり取り（EG と担当者）と、案件の知らせに一覧へのリンク（v0.10.0。2026-11-14）

> **これが今の計画**。下の「v0.9.0」以降は前の計画で、消さずに残している。
> 依頼：TEZZO（Leo 経由。2026-11-14）①SES から EG に届く「案件新着」の知らせに、その案件の詳細と「届いた案件」の一覧へのリンクを載せたい。②案件の詳細（EG の画面）に、応募の前に担当者と確かめるためのやり取りの欄（履歴も見られる）が欲しい。
> 作る順：段階1 → 段階2 → 段階3 → 段階4（段階ごとに commit。`php artisan test` を通してから次へ）→ park-reviewer → park-tester。commit・push・公開版への反映は Leo。
> プレーヤー：管理者・SES・担当者・EG の4つ。担当者＝`users.role=ses` の人（SES の代表者も担当者の1人。今回の権限は SES と担当者で同じ）。
> 外部のライブラリは足さない。名前・メールはこのファイルにもテストにも書かない（テストは架空の人）。
> **会社の枠を越えて見えるもの（管理者が会社の中のやり取りを読める、など）がある**。9章の確認事項に載せ、完了報告でも警告する。

## 0. コードで確かめた今の形（「届いた案件に入っていない」の結論を含む）

- 「届いた案件」（`/eg/offers`、`EgController::offers`）は `case_offers` の本人の行を並べるだけ。行を作るのは2か所：
  - `Workflow::distribute`（`case_offers` を作り、`N::toEg` でお知らせ＋メール。リンクは `/cases/{id}`）。**ただし今の画面には、この配信のフォームが無い**（v0.7.0 で案件の画面の右が「エンジニアに案内する」＝検索に置き換わった。`POST /cases/{id}/distribute` の道だけが残っている）。
  - `CaseMail::send`（検索 → 送信、古い `/cases/{id}/mail` とも。ここを通る）。`engineers.registered_at` がある人に、案内メールを送り、`case_mails` と `case_offers` を作る。
- **結論**：TEZZO さんのテスト用 EG の一覧が空なのは、**その EG あてに案件を案内（送信）していない＝データの問題**とみる。コードの欠陥で行ができない、ということは無い。ただし、近くに次の欠け（今回直す）がある。
  - K1：`CaseMail::send` は EG に**お知らせ（ベル）を出さない**。届くのはメールだけで、本文は返事のリンク（`/r/{token}/…`）だけ。**案件の詳細（`/cases/{id}`）にも、届いた案件の一覧（`/eg/offers`）にもリンクが無い**。→ TEZZO さんの ① はここ。
  - K2：`Workflow::distribute` の知らせにも、一覧へのリンクが無い。
  - K3：`CaseMail::send` は送る直前に、停止中・退会済・会社が参加中でない人を確かめていない（候補の段階 `Matcher::forCase` では除いている。検索で手で足した人などがすり抜ける余地）。`distribute` と同じ `Registration::usableEngineers` で確かめる。
  - K4：仮登録の EG（`engineers.registered_at` が空）は、黙って送られない（今の決まりどおり。マニュアルに書く）。
- 案件を見られる人（`Perms::caseView`）：管理者は全部。SES・担当者はグループの案件全部（下書きは自社のものだけ）。EG は `case_offers` か `applications` に自分の行がある案件だけ。→ 届いた案件は、終わったあとも開ける。
- 今のやり取り：`messages`（エンジニア×管理者。案件ごとではない。`/engineers/{id}/messages`）。案内メールの「質問がある」リンク（`ReplyController`）は `case_mails.reply='question'` と `reply_note` を残し、送った人にお知らせするだけで、続きの話はできない。連絡メール（v0.8.0）は案件に紐づかない。
- 所属の変更（v0.8.1 `Affiliation`）：`engineers.ses_id` だけ付け替え、過去の記録は元の会社のまま。
- 表示 ID（v0.9.0）：やり取り・お知らせには ID を付けない（D12）。お知らせの題には案件の ID を `Ids::tag` で付ける（D11）。
- 行を消す道：`SamplePurge`（見本を消す。`ORDER` と `plan()` に表を並べる）、`Registration::cancel`（仮登録の取り消し。記録がある EG は取り消せない表の一覧が 652 行）。テストの `EmptyStateTest`・`PurgeSamplesTest` にも表の一覧がある。
- 版は `config/park.php`（今 `v0.9.1`）。

## 1. 決定事項（Leo の推奨で仮置き。9章で TEZZO さんに確認）

| 番号 | 決めたこと | 理由 |
|---|---|---|
| D1 | やり取りは**案件×エンジニア×会社ごとの1本**（「案件のやり取り」）。新しい表 `case_threads`（1本）と `case_thread_posts`（発言）。最初の発言のときに作る | 案件の話が混ざらない。応募の前でも後でも同じ1本が続く |
| D2 | **相手は、そのエンジニアの所属の会社の担当者全員（SES も含む）**。直属のエンジニアは**管理者**。案件を持ち込んだ他社の SES には**見せない・届けない** | 会社の枠を越えて、エンジニアの名前や質問が他社に出ない（グループの案件は今もイニシャルで扱っている）。所属の会社はグループの案件をすべて見られるので、案件票を見て答えられる。分からないことは、所属の会社が持ち込んだ側・管理者に今の手段（連絡メールなど）で聞く |
| D3 | **管理者は、すべてのやり取りを読めて、書ける**（書くと「PARK 管理者」と出る。管理者の個人名は出さない） | 運営として、案件の中身の質問に答えられる・もめごとを確かめられる。今も管理者は応募・案内の記録をすべて見ている。**ただし会社の中の会話を管理者が読める**ので警告する（9章 Q2） |
| D4 | 作れる・書ける条件（`CaseThread::canPost` の1か所）：そのエンジニアがこの案件の `case_offers` か `applications` を持っている（＝EG が案件を見られる）こと、かつ「案件が募集中」か「選考中の応募がある（`Affiliation::IN_SELECTION`）」。エンジニアが停止中・退会済、会社が参加中でない、のときは書けない。**読むのはいつでも**（応募のあと・見送りのあと・募集の終わったあとも） | 応募前の確認が目的。選考中の質問にも使える。終わった案件は履歴として残す |
| D5 | 担当者（SES）からも先に書き始められる（例：「この案件いかがですか」）。管理者も同じ | 依頼①の「SES から EG へのメッセージ」を、PARK の中でも続けられる |
| D6 | 所属が変わったら：前の会社とのやり取りはそのまま残り、前の会社は読める（書けない）。EG も読める（書けない）。新しい会社とは新しい1本になる（`case_threads` は `case_id`・`engineer_id`・`ses_id` で一意） | v0.8.1 の「過去の記録は元の会社のまま」と同じ考え。新しい会社に前の会社の会話を見せない |
| D7 | 既読：発言ごとに `read_by_eg`・`read_by_side`（side＝所属の会社の担当者、直属なら管理者）。EG が案件の画面を開くと、相手側の発言を既読に。担当者がやり取りの画面を開くと、EG の発言を既読に（直属は管理者が開いたとき）。管理者が会社のやり取りを開いても既読にしない | 担当者が気づく前に、管理者が読んだだけで消えないように |
| D8 | お知らせ（`Notifier`。種類 `case-thread`。お知らせとメールの両方）：EG が書いた → `N::toSes(会社)`（直属は `toSes` の中で管理者へ）。会社に使える担当者が1人もいないときは `N::toAdmins` にも。担当者が書いた → `N::toEg`。管理者が書いた → `N::toEg` と、直属でなければ `N::toSes`（メールなし、お知らせだけ）。題の末尾に `Ids::tag(案件の ID)`。本文は発言の頭の 200 字 | 今の `messages` と同じ作り。管理者が口を出したことは会社にも分かるように |
| D9 | 発言は 1〜2000 字。同じ人が同じ1本に同じ文を 60 秒以内に送ったら、2回目は保存せず「送りました」と返す（二重押し）。`throttle:20,1` | 連絡メールと同じ程度の守り |
| D10 | 案件の知らせに**リンクを2つ**足す：「案件の詳細（PARK）`/cases/{id}`」と「届いた案件の一覧 `/eg/offers`」。対象は `CaseMail::send` の本文の末尾（返事のリンクの下。送る人は消せない固定の文）と、`Workflow::distribute` の `N::toEg` の本文。あわせて `CaseMail::send` で EG に**お知らせ（ベル）を出す**（メールは案内メールが行くので、お知らせは `mail=false`）。題「案件のご案内が届きました：{案件名}［CA-…］」、リンク `/cases/{id}` | 依頼①。PARK の画面で案件を開き、質問・応募ができるようにする |
| D11 | 案内メールの「質問がある」の返事の画面（`/r/{token}/question`）に、「PARK にログインすると、案件の画面から担当者とやり取りできます」とリンクを出す。返事の記録の仕組みは変えない（やり取りの表には入れない） | グループ経由の返事は持ち込んだ側に届く今の流れなので、混ぜると D2 が崩れる |
| D12 | やり取りに表示 ID は付けない。題に案件の ID を付けるだけ（v0.9.0 D12 のまま） | 番号で呼ぶ単位ではない |
| D13 | 記録（`audit_log`）は `case-thread`、詳細は「#thread {id} 案件 {案件の ID}」だけ（本文・名前は書かない） | 個人の情報を記録に残さない |

## 2. データ（マイグレーション1本）

`database/migrations/2026_11_14_000001_case_threads.php`（今ある表は触らない。表を2つ足すだけ）。

- `case_threads`：`id`、`case_id`（foreignId → cases）、`engineer_id`（foreignId → engineers）、`ses_id`（foreignId → ses_companies。作ったときの所属の会社。直属の会社の行も可）、`last_post_at` dateTime null、`created_at` dateTime。`unique(['case_id','engineer_id','ses_id'], 'ct_unique')`、`index('ses_id')`。
- `case_thread_posts`：`id`、`thread_id`（foreignId → case_threads、cascadeOnDelete）、`from_user_id`（foreignId → users、nullable、nullOnDelete）、`from_side` string(5)（`eg`／`ses`／`admin`）、`from_label` string(200)（書いたときの表示名。人が消えても読める）、`body` text、`read_by_eg` boolean 既定 false、`read_by_side` boolean 既定 false、`created_at` dateTime。`index(['thread_id','id'])`。
- 表示名：EG＝本人の氏名。担当者＝「氏名（会社名）」。管理者＝設定の `operator_name`（既定「PARK 管理者」）。
- `down()` は2つの表を消す。

## 3. 画面と操作（プレーヤーごと）

| 立場 | どこで | 何ができるか |
|---|---|---|
| EG | 案件の詳細 `/cases/{id}` の右の列に「担当者とのやり取り」カード（`id="thread"`。応募する／見送る／応募済み／募集終了のカードの下。どの状態でも出す） | 発言の一覧（古い順、相手の名前・日時）。書ける条件（D4）なら入力欄と［送る］。書けないときは理由を1行（「募集を終えた案件なので、読むだけです」など）。0件は「まだやり取りはありません。案件について確かめたいことを書いて送ってください。相手：{会社名}の担当者（直属なら：PARK 管理者）」。前の所属の会社とのやり取りは「前の所属（{会社名}）とのやり取り（読むだけ）」として下に出す |
| EG | 届いた案件 `/eg/offers` | 列「やり取り」を足す：未読があれば「未読 n」、発言があれば「あり」、なければ「—」 |
| EG | ホームの「今やること」（`Todo::eg`） | 未読の相手側の発言がある案件：「担当者から返事「{案件名}」」→ `/cases/{id}#thread` |
| SES・担当者 | ナビに「案件のやり取り」（`/threads`） | 自社の `ses_id` の1本だけを並べる（未読のあるものが上、次に新しい順）。列：案件（ID・名前）、エンジニア（名前・EG-）、最後の発言、未読、［開く］ |
| SES・担当者 | 案件の詳細 `/cases/{id}` の左に「エンジニアとのやり取り」カード | その案件の、自社の1本だけ（無ければカードを出さない）。［開く］ |
| SES・担当者 | やり取りの画面 `/cases/{id}/threads/{eid}` | 発言の一覧と入力欄。自社のエンジニアで、D4 を満たすなら1本目を書き始められる。他社のエンジニアの eid は 404（あるかどうかも漏らさない） |
| SES・担当者 | エンジニアの「相性」表（案件の詳細の右） | 案内済み・応募済みの行に［やり取り］のリンク（自社の人だけ） |
| SES・担当者 | ホームの「今やること」（`Todo::ses`） | 未読の EG の発言：「{EG 名} さんから案件の質問：{案件名}」→ やり取りの画面 |
| 管理者 | ナビに「案件のやり取り」（`/threads`） | すべての1本。列に「会社」を足す。会社で絞れる（`?ses=`） |
| 管理者 | 案件の詳細の左「エンジニアとのやり取り」 | その案件のすべての1本 |
| 管理者 | やり取りの画面 | どの1本も読めて書ける（D3）。直属の1本では、相手（side）として既読になる |
| 管理者 | ホームの「今やること」（`Todo::admin`） | 直属のエンジニアの未読の発言（会社の1本は出さない） |

- 「やり取り」（`/eg/messages`、管理者とのやり取り）と連絡メールは今のまま残す。
- ナビの名前は「案件のやり取り」で、今の「やり取り」（管理者 × EG）と取り違えないよう、`/admin/messages` のナビは「EG とのやり取り」に名前だけ変える（EG のナビの「やり取り」は「管理者とのやり取り」に）。

## 4. 処理（`app/Services/CaseThread.php` を新しく作る。画面からはここを呼ぶ）

- `partnerSesId(object $e): int` …… エンジニアの今の所属の会社。
- `visible($u, object $thread): bool` …… 管理者＝true／SES＝`thread.ses_id == u.ses_id`／EG＝`thread.engineer_id == u.engineer_id`。
- `threadsFor($u, int $caseId, int $engineerId)` …… 見られる1本を（今の所属の1本を先に）返す。
- `canPost($u, object $c, object $e): ?string` …… 書けないときの理由の文（書けるなら null）。D4 と、SES は「自社のエンジニア」「自分自身（兼任のエンジニア）ではない」、EG は「自分」。
- `post($u, object $c, object $e, string $body): array` …… `DB::transaction` で1本を `firstOrCreate` 相当（unique に当たったら読み直す）→ 発言を入れる（書いた側の既読は true）→ `last_post_at` → 通知（D8）→ 記録（D13）。二重押し（D9）。戻り値は `['type' => 'ok'|'bad', 'msg' => 文]`。
- `markRead($u, object $thread)` …… D7。
- `unreadForEg(int $engineerId)`・`unreadForSes(int $sesId)`・`unreadDirect()` …… 件数（一覧・今やること）。
- コントローラー：`CaseThreadController`（`index`＝`/threads`、`show`＝`GET /cases/{id}/threads/{eid}`、`store`＝`POST /cases/{id}/threads/{eid}`）。EG が GET を開いたら `/cases/{id}#thread` へ移す。EG の書き込みも同じ POST（`eid` が自分でなければ 404）。
- ルート：`/threads` は `role:admin,ses`、`/cases/{id}/threads/{eid}` は `role`（全員。中で確かめる）、POST に `throttle:20,1`。
- 権限の順：案件が無い → 404。案件を見られない（`Perms::caseView`）→ 404。エンジニアが見られない範囲（SES の他社の人、EG の他人）→ 404。

## 5. 境界の挙動

| 場面 | 動き |
|---|---|
| EG が届いていない案件の URL を開く | 今どおり 403（やり取りの欄も出ない） |
| 他社の SES・担当者が、他社のエンジニアのやり取りの URL | 404。案件の画面にも、そのエンジニアのやり取りは出ない |
| 案件を持ち込んだ他社の SES | どの画面にも、他社のエンジニアのやり取りは出ない（D2） |
| ほかの EG | 自分の1本しか見えない |
| 応募したあと | 同じ1本が続く。選考中なら書ける |
| 見送ったあと | 案件が募集中なら書ける（見送りを取り消す話もできる）。募集を終えたら読むだけ |
| 案件が filled・closed、選考中の応募が無い | 読むだけ（理由を表示） |
| エンジニアが停止中・退会済 | 書けない（EG はそもそも入れない）。担当者・管理者も書けない（理由を表示）。履歴は読める |
| 所属の会社が停止中など（参加中でない） | 書けない。管理者は読める |
| 所属が変わった | D6 |
| 0件 | 案内の文を出す（3章） |
| 空の文・2000 字を超える | 「1〜2000 字で書いてください。」で戻す（入力は残す） |
| 同じ文の二重押し | D9 |
| 会社に使える担当者が0人 | 管理者にも知らせる（D8） |
| 兼任（担当者で、自社のエンジニアでもある人）が SES の立場で自分のやり取りに書く | 「ご自身のやり取りには、エンジニアとして入って書いてください。」で断る |
| 見本データ（`@park.example`）あてのメール | v0.7.0 の差し替えで park@jona.jp に届く（何もしない） |

## 6. 作業手順

### 段階1：表と知らせのリンク（K1〜K3、D10・D11）
1. マイグレーション（2章）。
2. `CaseMail::send`：送る前に `Registration::usableEngineers` と会社が参加中かで確かめ、外れた人は飛ばす（K3）。本文の末尾（返事のリンクの下）に「▼案件の詳細（PARK にログインして見られます。担当者への質問もここから）」`url("/cases/{id}")` と「▼あなたに届いた案件の一覧」`url('/eg/offers')` を足す。他社の人（route=group）にも同じ。`N::toEg(…, mail=false, 'offer')` でお知らせを出す（D10）。
3. `Workflow::distribute`：`N::toEg` の本文の最後に「届いた案件の一覧：」`url('/eg/offers')` を足す。
4. `resources/views/public/reply.blade.php`（`/r/{token}/question` の画面）に D11 の文とリンク（`url("/cases/{case_id}")`）。
5. テスト T1〜T4。

### 段階2：やり取りの処理と EG の画面
1. `app/Services/CaseThread.php`（4章）。
2. `CaseThreadController` とルート。
3. `cases/show.blade.php` の EG の枝に「担当者とのやり取り」カード、`CaseController::show` の EG の枝で `threadsFor`・`canPost`・`markRead`。
4. `eg/offers.blade.php` に「やり取り」の列、`EgController::offers` で未読を数える（`selectSub`）。`Todo::eg` に1行。
5. テスト T5〜T12、T19・T20。

### 段階3：担当者・管理者の画面
1. `resources/views/threads/index.blade.php`・`threads/show.blade.php`（`<x-card>`・`<x-table>`・`<x-code>` を使う）。
2. ナビ（`layouts/app.blade.php`）に「案件のやり取り」（管理者・SES）。今の「やり取り」の名前を変える（3章）。`$current` の対応に `/cases/{id}/threads/…` → `/threads` を足す。
3. 案件の詳細（SES・管理者）の左に「エンジニアとのやり取り」、SES の相性表に［やり取り］。
4. `Todo::ses`・`Todo::admin` に1行ずつ。
5. テスト T13〜T18、T21。

### 段階4：消す道・数え・版・マニュアル
1. `SamplePurge`：`ORDER` の `messages` の後ろに `'case_thread_posts' => 'id', 'case_threads' => 'id'`。`plan()` で、1本＝`engineer_id ∈ 消すエンジニア` または `case_id ∈ 消す案件` または（`ses_id ∈ 消す会社` かつ `engineer_id ∉ 残す人`）。発言＝その1本の発言。残る1本にある見本の人の発言は、`from_user_id` を空にする（`regBy` と同じ扱い。バックアップの「変える行」に書く）。`--move-kept-to-direct` のときは、残す人の1本の `ses_id` を直属へ（159 行の表の並びに `case_threads` を足す）。お知らせの掃除の正規表現に `cases/\d+/threads/(\d+)` → 消すエンジニアを足す。
2. `Registration::cancel` の「記録があれば取り消せない」表の並び（652 行）に `case_threads`。
3. `park:status`（`Status.php`）に「案件のやり取り：返事のない EG の発言（3日以上）」の件数。
4. `config/park.php` を `v0.10.0`。版の文字を見ているテストを grep で探して直す。
5. テストの表の一覧（`EmptyStateTest`・`PurgeSamplesTest`）に2表を足す。
6. マニュアル（8章）。README に版の行があれば直す。
7. テスト T22〜T25。全体を `php artisan test`。

## 7. 受け入れ条件とテスト（`tests/Feature/V0100CaseThreadTest.php`。人は架空、メールは `@park.example` 以外の架空のドメイン）

| 番号 | 受け入れ条件 | 手順（誰が・どこで・何を） | 確かめること |
|---|---|---|---|
| T1 | 案内メールに案件の詳細と一覧のリンク | SES が自社の EG に `CaseMail::send`（検索の送信 `POST /searches/{sid}/send`） | `mail_outbox.body` に `/cases/{id}` と `/eg/offers` の URL、`case_offers` が1行 |
| T2 | 案内メールで EG にお知らせ | T1 のあと | EG の `app_notifications` に題「案件のご案内が届きました：…［CA-…］」、`link=/cases/{id}`、`for_role=eg`。お知らせ由来のメールは増えない（案内メールの1通だけ） |
| T3 | 配信の知らせに一覧のリンク | 管理者が `POST /cases/{id}/distribute` | EG あてメールの本文に `/eg/offers` |
| T4 | 停止中・退会済・会社が停止中の人には案内しない | 3人を混ぜて送る | その3人の `case_mails`・`case_offers`・メールが無い |
| T5 | EG の案件の画面にやり取りの欄 | EG が `/cases/{id}` | 「担当者とのやり取り」と、0件の文と相手の会社名 |
| T6 | EG が書くと所属の担当者全員に届く | EG が `POST /cases/{id}/threads/{自分}` | `case_threads` 1行（`ses_id`＝所属）、`case_thread_posts` 1行（`from_side=eg`、`read_by_eg=1`）。所属の担当者2人それぞれに `app_notifications`（`kind` 相当のメール `case-thread`）。持ち込んだ他社の SES には0件 |
| T7 | 担当者が返事 → EG に届く・既読 | 担当者が同じ URL に POST → EG が `/cases/{id}` | EG に `case-thread` のお知らせ。EG が開いたあと、相手の発言の `read_by_eg=1` |
| T8 | 担当者から書き始められる | 担当者が、案内済みの自社 EG のやり取りに初めて POST | 1本ができる。EG にお知らせ |
| T9 | 案件を届けていない EG には書けない | 担当者が、`case_offers`・応募の無い自社 EG に POST | 断り（bad）、行は増えない |
| T10 | 応募のあとも残り、書ける | EG が応募 → `/cases/{id}` → POST | 前の発言が見え、新しい発言が入る |
| T11 | 募集終了後は読むだけ | 案件を closed（選考中の応募なし）→ EG が開く／POST | 発言は見える。POST は断り、行は増えない |
| T12 | 選考中なら募集終了後も書ける | 応募 status=interview、案件 filled → EG が POST | 入る |
| T13 | 他社の SES・担当者からは見えない | 他社の担当者が `/cases/{id}`・`/cases/{id}/threads/{eid}`・`/threads` | 案件の画面にやり取りのカードなし、やり取りの画面は 404、一覧に出ない |
| T14 | ほかの EG からは見えない | 同じ案件の別の EG が `/cases/{id}/threads/{他人}` を GET・POST | 404。自分の案件の画面に他人の発言が出ない |
| T15 | 直属のエンジニアの相手は管理者 | 直属の EG が POST | 管理者全員にお知らせ。管理者が開くと `read_by_side=1` |
| T16 | 管理者は会社のやり取りを読めて書ける | 管理者が会社の1本を開く → POST | 開いても `read_by_side` は変わらない。POST で EG にお知らせ、会社の担当者にお知らせ（メールなし）、表示名は「PARK 管理者」 |
| T17 | 一覧と今やること（担当者） | EG が書いたあと、担当者が `/ses` と `/threads` | 「今やること」に「…さんから案件の質問：…」、一覧に未読 1 |
| T18 | 一覧と今やること（EG） | 担当者が書いたあと、EG が `/eg`・`/eg/offers` | 「担当者から返事「…」」、届いた案件の「やり取り」に「未読 1」 |
| T19 | 文の長さと二重押し | 空・2001 字・同じ文を2回 | 空と 2001 字は断り。同じ文の2回目は行が増えない |
| T20 | 所属が変わったら前の1本は読むだけ | EG が A 社で発言 → 管理者が B 社へ移す → EG・A 社・B 社が開く | EG は前の1本を読めるが書けない、新しく書くと B 社の1本ができる。A 社は前の1本を読めるが書けない。B 社には前の1本が見えない |
| T21 | 停止中のエンジニアには書けない | エンジニアを停止 → 担当者が POST | 断り、履歴は担当者に見える |
| T22 | 見本を消すと、やり取りも消える | 見本の EG・案件の1本を作って `park:purge-samples --yes` | 2表の行が消える。見本ではない1本は残り、見本の人の発言は `from_user_id` が空で残る |
| T23 | 記録がある仮登録は取り消せない | 1本のある EG の仮登録を取り消す | 断りの文 |
| T24 | 版 | `config('park.version')` | `v0.10.0` |
| T25 | 「質問がある」の画面に案内 | `GET /r/{token}/question` | `/cases/{id}` へのリンクと案内の文 |

## 8. マニュアル（`resources/manuals/`。直したら Leo が 41_Park-Project の `04_Manuals` に写し直す）

- `engineer.md`：「4. 案件が届いたら」に、知らせの2つのリンク、案件の画面の「担当者とのやり取り」（相手は所属の会社の担当者。直属は管理者。応募の前でも後でも。募集が終わると読むだけ。前の所属とのやり取りは読むだけ）、届いた案件の「やり取り」の列。「管理者に見てもらう」の「やり取り」の名前の変更。
- `engineer-steps.md`：「4-1. 案件のご案内メールに返事をする」に PARK のリンクから開く手順、新しく「4-6. 案件について担当者に質問する」。
- `ses.md`：「5. 案件」に「エンジニアとのやり取り」（自社のエンジニアだけ。他社の案件の質問も貴社に届くこと。持ち込んだ他社には見えないこと。管理者も読めること）。
- `ses-steps.md`：「4-3. エンジニアから返事が来たら」に「案件のやり取り」の一覧からの返事、新しく「4-10. 案件のやり取りに返事をする（SES・担当者）」。
- `admin.md`：「6. マッチングと案内」に、配信・案内の知らせのリンク、「案件のやり取り」（すべて読める・書ける。直属は管理者が相手。会社の1本は読んでも既読にならない）、テストで EG に案件を届ける手順（下の 10 章）。ナビの名前の変更。
- `admin-steps.md`：「4-1. PARK から届く知らせ」に直属のエンジニアの質問。新しく「4-8. 案件のやり取りを見る・返事をする」。

## 9. TEZZO さんへの確認（推奨で仮置きして作る。違えば直す）

- **Q1（会社の枠）**：やり取りの相手は**エンジニアの所属の会社**（直属は管理者）。**案件を持ち込んだ他社の SES には届かない**。他社が持ち込んだ案件の細かい質問は、所属の会社が持ち込んだ側か管理者に聞いて答える形になる。推奨：このまま（エンジニアの名前と質問が他社に出ない）。持ち込んだ側に直接届けたいなら、名前を伏せて中継する仕組みが別に要る。
- **Q2（警告：会社の中の会話を管理者が読める）**：管理者は、各社の担当者と自社のエンジニアのやり取りを、すべて読めて書ける。推奨：このまま（運営として、もめごとの確認と案件の質問への回答のため）。各社に見せたくない場合は「直属だけ」に絞る。
- Q3：ナビの今の「やり取り」の名前を変える（管理者「EG とのやり取り」、EG「管理者とのやり取り」）。推奨：変える（新しい「案件のやり取り」と取り違えないため）。

## 10. 確かめ方と了承

- **TEZZO さんのテスト用 EG に案件を届ける手順**（「届いた案件」に入れる）：①管理者でログイン → ②案件（募集中）を開く → ③［エンジニアに案内する］→ 検索 → ④一覧に出なければ「エンジニアを検索して足す」でテスト用 EG を足す → ⑤チェックして［エンジニアへメールを送信］。テスト用 EG が仮登録のままだと送られない（K4）。本登録まで済ませてから。手元（`MAIL_MAILER=log`）ではメールは保存だけで、管理者の「メール」画面で中身を見られる。
- 自動テストでは、`case_offers` を直接 insert するか `CaseMail::send` を呼ぶ（7章）。開発用の新しいコマンドは作らない。
- **了承**：
  - マイグレーションは表を2つ足すだけ（今の行は変えない・消さない）。コードの反映と migrate は、常時了承の範囲（`park-deploy.sh`）。
  - **公開版でテスト用 EG に案内を送るのは、メールの送信なので TEZZO さんの了承を取ってから**（宛先は TEZZO さんのテスト用アカウント。ダミー宛は park@jona.jp に届く）。
  - 反映のあと、案内メールの本文の末尾が変わる（リンク2つ）。送られるのは、誰かが案内を送ったときだけ（一斉には送らない）。
- 戻し方：park は `git revert`（足した2表は残る。消すときはデータの削除なので TEZZO さんの了承）。

# PLAN：表示 ID（通し番号つきの ID）（v0.9.0。2026-11-13）

> **これが今の計画**。下の「v0.8.1」以降は前の計画で、消さずに残している。
> 依頼：TEZZO（Leo 経由。2026-11-13）「案件、管理者・SES・EG など、全てに ID を振ってほしい」。
> 作る順：段階1 → 段階2 → 段階3 → 段階4（段階ごとに commit。`php artisan test` を通してから次へ）→ park-reviewer → park-tester。commit・push・公開版への反映は Leo。
> プレーヤー：管理者・SES・担当者・EG の4つ（担当者は `users.role=ses` の人。SES の代表者も担当者の1人）。
> 外部のライブラリは足さない。名前・メールはこのファイルにもテストにも書かない（テストは架空の人）。
> **本番で既存データに ID が付く**（段階1のマイグレーション）。完了報告で必ず警告する（R1）。
> TEZZO に上げる件（末尾 9章）は、推奨で仮置きして作る。答えが違えば直す。

## 0. コードで確かめた今の形

- 画面に出る ID は無い。どの表も内部の `id`（自動連番）だけ。URL は `/cases/{id}` などで内部 id を使う（今回 URL は変えない）。
- 人の持ち方：ログインは `users` の1行。`role` は `admin`／`ses`（担当者）／`eg` の1つだけで、兼任は `role=ses|admin` の行が `engineer_id` を持つ形（`Workflow::isDual`）。エンジニアの技術登録は `engineers`（`users.role=eg` の行は `engineer_id` でここを指す）。
- `users.role` は変わる：`Workflow::addSesRole`（eg→ses、361行）、`addAdminRole`（eg→admin、371行。`ses_id` を空に）、兼任を外すと eg に戻る（93行・157行）。→ **役割で接頭辞を決める ID を `users` の1列に持たせると、不変にできない**（D2）。
- 会社：`ses_companies.kind` は `ses`／`direct`（直属。`Workflow::directSesId` が必要なときに1行だけ作る）。`status` は pending／provisional／active／suspended など。
- 行が消える経路がある：`Registration::cancel`（仮登録の取り消しで users・engineers・ses_companies を消す）、`SamplePurge`（見本の会社と人を消す）、`Billing::build`（明細が無くなった下書きの請求書を消す）。テストは sqlite（`:memory:`）。→ 内部 id から ID を作ると、消したあとに番号が戻る心配がある。**採番は専用の数え表で行う**（D3）。
- 請求書は「下書き（draft）→ 発行（issued、`AdminController::issueInvoice` 439行）→ 入金（paid）」。下書きは何度も作り直し・削除される。`issueInvoice` は今、状態を確かめずに更新する（2回押しで2回知らせる）。
- 作成の経路（`DB::table(...)->insert` を全部 grep で確認）：
  - users：`Workflow::createUser`（はじめの設定・見本・テスト）、`Registration::provisionalSes`（153行）・`provisionalAdmin`（190行）・`provisionalPerson`（464行）・`resendEngineer`（552行。eg）・`provisionalNextRep`（601行）、`InviteAdmin`（40行）、`DummySeed::createUser`（331行）。
  - ses_companies：`Workflow::applySes`（566行）・`directSesId`（553行）、`Registration::provisionalSes`（151行）、`DummySeed`（252行）、`SampleSeeder`（31・40行。直書き）。
  - engineers：`Workflow::createEngineer`（637行）だけ（見本・仮登録・画面すべてここを通る）。
  - cases：`Workflow::saveCase`（815行）だけ（画面の登録・取り込み後の登録・見本）。
  - applications：`Workflow::apply`（871行）だけ（画面・案内メールの返事 `CaseMailController` 94行・推薦）。
  - contracts：`Workflow::makeContract`（972行）だけ。
  - invoices：`Billing::build`（52行。下書き）。
- テストは 17 ファイル・35 か所で、これらの表へ直接 `insert` している（code は入らない）。→ 列を NOT NULL にするとテストが大量に壊れる。**列は空を許し、作成の経路で必ず入れる＋穴埋めの道具を持つ**（D5）。
- 一覧の絞り込み：管理者のエンジニア一覧（`AdminController::engineers` 359〜368行）は `q` を名前・かな・技術・会社名で探す。ほかの一覧に文字の検索は無い。
- 名前を伏せた書類：`EngineerDocs::careersheet(..., $anon)`（イニシャルを出す）、スキルシートの `?anon=1`、`CaseMailController` 103〜114行（グループのほかの SES には名前を伏せた業務経歴書だけ）。グループの他社の人は `CaseMail::callName` でイニシャル・呼び名だけ。
- お知らせとメール：`N::toSes`・`N::toEg`・`N::toAdmins`・`N::notify` は、同じ `title` をお知らせの題とメールの件名に使う。テストに件名・題を完全一致で見ているところが 12 ファイル・35 か所ある。
- 案件の案内メール：`CaseMail::defaultSubject`（`【案件のご案内】{title}`）・`defaultBody`（`{案件名}` などの差し込み）。送る人が件名・本文を直せる。
- 見える範囲：`Perms::engineer`・`caseView`・`caseEdit`・`app`（応募・契約）。請求書は管理者と自社の SES。
- 版は `config/park.php`（今 `v0.8.1`）。版の文字を見ているテスト：`RegistrationFoundationTest`・`SkillQuestionsTest`・`V030RegressionTest`・`V050CatalogTest`・`V060CaseAiTest`・`V070SearchTest`・`V080DirectMailTest`・`V081AffiliationTest`。

## 1. 決定事項

| 番号 | 決めたこと | 理由 |
|---|---|---|
| D1 | ID を付ける相手と接頭辞：管理者 **A-**、担当者（SES の人）**T-**、会社 **S-**（SES も直属も）、エンジニア **E-**、案件 **C-**、応募 **AP-**、契約 **CT-**、請求書 **IV-**。形は「接頭辞＋`-`＋6桁のゼロ埋め」（例 `C-000012`）。100万件を超えたら桁が増えるだけ（`C-1000000`） | 依頼の「全てに」を、人が話題にする単位（人・会社・案件・選考・契約・請求）で満たす。`-` があるので A と AP は取り違えない |
| D2 | **人の ID は役割ごとに持つ**。`users.admin_code`（A-）と `users.staff_code`（T-）の2列。その役割を初めて持ったときに付け、役割が外れても消さない（戻ったら同じ ID）。エンジニアの ID は `engineers.code`（E-）で、`role=eg` の `users` の行には付けない（EG の人の ID は E-）。兼任の人は2つ持つ（例：T- と E-）。直属の会社の行も S- を持つ（名前は「管理者の直属」のまま） | `users.role` は変わるので、1列に役割の接頭辞を固めると不変にできない（0章）。エンジニアは会社を移っても `engineers` の行は同じなので E- が一生ついて回る |
| D3 | 採番は `app/Services/Ids.php` の1か所。数え表 `id_counters`（`kind` 主キー・`last_no`）の行を `lockForUpdate` で読んで +1 する（`DB::transaction` の中）。**作る前に番号を取り、insert の配列に入れる**。番号の欠け（insert の失敗・行の削除）は許し、**一度使った番号は二度と使わない** | 内部 id から作ると、消した行の番号が戻ることがある（0章）。同時に2人が作っても、行の鍵で重ならない。列の unique が最後の守り |
| D4 | **請求書の番号は発行のときに付ける**（下書きには付けない）。発行は `where status='draft'` で1回だけ通す（2回押しは番号もお知らせも1回分） | 下書きは作り直し・削除を繰り返すので、付けると欠番だらけになる。請求書番号は発行順にそろうのが自然 |
| D5 | 列は空を許す（`nullable`＋`unique`）。作成の経路では必ず入れる。空の行を埋める `php artisan park:fill-ids`（既定は件数を見るだけ、`--apply` で埋める）を作り、`park:status` に「ID の無い行」の件数を出す | テストの直接 insert（35 か所）を壊さない。万一の付け漏れも後から埋められ、朝の点検で気づける |
| D6 | 既存データはマイグレーションで、**作成の順（`id` の小さい順）** に付ける。請求書は発行済み・入金済みだけ、`issued_at`→`id` の順。人：今 `role=admin` の行に A-、`role=ses` の行に T-（過去の役割は記録が無いので見ない）。エンジニア・会社は退会・停止・申込中・仮登録・直属も含め全部。**何度流しても安全**：列・表は無ければ作る、埋めるのは空の行だけ、数え表は「今の値と、付いている最大の番号の大きいほう」にする | 依頼どおり、作成順の通し番号。2回目に流しても何も変わらない |
| D7 | ID は付けたら変えない。直す画面は作らない。`Ids` は空でない列を上書きしない | 不変の約束 |
| D8 | 見せる相手（詳細は 3章）：管理者は全部。SES・担当者は「自社の会社 S-・自社の担当者 T-・自社のエンジニア E-・見られる案件 C-・自社の応募 AP-・契約 CT-・請求 IV-」。EG は「自分の E-・届いた／応募した案件 C-・自分の応募 AP-・契約 CT-」。**A- は管理者にだけ見せる**。**他社のエンジニアの E- は SES に見せない**（今のイニシャルの扱いのまま） | 今の `Perms` の範囲を超えて新しい情報を見せない（仮置き。9章 1） |
| D9 | **書き出す書類には ID を入れない**：名前を伏せた／伏せない業務経歴書・履歴書（xlsx）、スキルシートの印刷（`?anon=1` も無しも） | 書類は案件をくれた先（PARK の外）へ渡る。外に PARK の内部の番号を出さない。名前を伏せた書類に E- があると、別の機会に出た書類と同じ人だと突き合わせられる（仮置き。9章 1） |
| D10 | **ID で探す**：上の帯に「ID で探す」の小さな入力欄（ログインした全員）。`GET /find?code=…` で、見つかればその詳細へ飛ぶ。**見られない ID と無い ID は同じ文**（「その ID は見つからないか、開けません。」）で返す。入力のゆれは受ける（全角→半角、小文字→大文字、前後の空白、ゼロ埋め・ハイフンの省略。例 `c12`・`Ｃ－１２` → `C-000012`）。あわせて、一覧の絞り込み（`q`）でも ID が当たるようにする | 存在だけが漏れるのを防ぐ。電話やメールで「C-12 の件」と言われてもすぐ開ける |
| D11 | メール・お知らせ：**題（＝メールの件名）の末尾に `［ID］`** を付ける。対象は案件・応募・面談・契約・請求のお知らせ（4章の表）。案件の案内メール（`CaseMail`）は件名を送る人が直せるので、既定の本文に `■案件 ID：{案件ID}` の行と差し込み `{案件ID}` を足す（既定の件名は変えない）。請求書の画面に「請求書番号」、明細の文に契約の ID（新しく作る下書きから。発行済みの明細は書き換えない） | 受け取った人がメールから ID で探せる。件名を直せる案内メールは、本文で確実に伝える |
| D12 | ID を付けないもの：面談（「AP-000012 の2回目」で言える）、配信（`case_offers`）・案件の案内（`case_mails`）・連絡メール・やり取り・お知らせ・記録・技術の表 | 人が番号で呼ぶ単位ではない。親の ID で足りる |
| D13 | URL・記録（`audit_log` の文）の形は変えない | 範囲を広げない。リンク・ブックマークを壊さない |
| D14 | 画面の出し方は1つの部品：Blade の `<x-code :v="$e->code" />`（`<span class="code">E-000015</span>`。空なら `—`）。等幅の小さな文字（`public/css/park.css` に `.code` を足す） | 書き方をそろえる |

## 2. データ（マイグレーション1本）

`database/migrations/2026_11_13_000003_display_ids.php`（名前は実装時に今日の日付の次の番号で）。

- `id_counters`：`kind` string(4) 主キー、`last_no` unsignedBigInteger 既定 0。行は `A`・`T`・`S`・`E`・`C`・`AP`・`CT`・`IV` を最初に作る（`insertOrIgnore`）。
- 列を足す（無ければ）：`ses_companies.code`、`engineers.code`、`cases.code`、`applications.code`、`contracts.code`、`invoices.code`、`users.admin_code`、`users.staff_code`。どれも `string(12)->nullable()->unique()`。
- 穴埋め：`Ids::fillMissing()` を呼ぶ（`park:fill-ids --apply` と同じ処理。D6）。
- `down`：列と表を消す。
- `Schema::hasColumn`・`hasTable` で囲み、2回流しても落ちない・変わらない。

## 3. 見せる場所（プレーヤーごと）

| 画面 | 管理者 | SES・担当者 | EG |
|---|---|---|---|
| アカウント（`/account`） | 自分の A-（兼任なら E- も） | 自分の T-・会社の S-（兼任なら E- も） | 自分の E- |
| 管理者の一覧・詳細（`admin/admins`・`admin-show`） | A- の列・見出し | — | — |
| SES の一覧・詳細（`admin/ses-index`・`ses-show`・`ses-user-show`） | S-、担当者ごとの T- | — | — |
| 自社の画面（`ses/company`） | — | S-、担当者ごとの T- | — |
| エンジニアの一覧・詳細（`admin/engineers`・`ses/engineers`・`engineers/show`） | E- | 自社の人の E- | 自分の E-（`eg/home`・プロフィール） |
| 仮登録の直し（`people/edit`） | その人の ID（付いていれば） | 自社の人の ID | — |
| 案件の一覧・詳細（`admin/cases`・`ses/cases`・`cases/show`・`eg/offers`） | C- | 見られる案件の C- | 届いた／応募した案件の C- |
| 案件のマッチング・検索・案内の候補（`cases/search*`・`cases/mail*`） | 候補の E- | 自社の人だけ E-。他社の人は出さない（D8） | — |
| 応募の一覧・詳細（`admin/applications`・`ses/applications`・`eg/applications`・`pipeline/application`・`pipeline/interview`） | AP-（と C-・E-） | 自社の AP-（と C-・E-） | 自分の AP-（と C-） |
| 契約（`admin/contracts`・`ses/contracts`・`pipeline/contract`） | CT- | 自社の CT- | 自分の CT-（応募の画面から） |
| 請求（`admin/invoices`・`ses/invoices`・`pipeline/invoice`） | IV-（下書きは「発行時に付けます」） | 自社の IV- | — |
| 書き出す書類（xlsx・スキルシートの印刷） | 出さない（D9） | 出さない | 出さない |

- 詳細の画面は見出しの近く（題の横）、一覧は先頭の列に出す。並び順は今のまま。

## 4. メール・お知らせに付ける ID（D11）

| 呼んでいる所 | 付ける ID |
|---|---|
| `Workflow::saveCase` の `toAdmins`（SES から案件が登録された） | C- |
| `Workflow::distribute` の `toEg`・`toSes` | C- |
| `CaseMail::send` の `toSes`（グループの案件が届いた） | C- |
| `CaseController::recommend` のお知らせ | C- |
| `Workflow::apply`・`setStatus`・`scheduleInterview`・`interviewResult` | AP- |
| `Workflow::makeContract`・`extendContract`・契約の終了（`PipelineController::end` の先） | CT- |
| `AdminController::issueInvoice` | IV-（発行で付けた番号） |
| `CaseMail::defaultBody` | 本文に `■案件 ID：{案件ID}`（`render` に差し込みを足す） |
| `Billing::items` の明細の文 | 契約の CT- を足す（例 `初期費用 CT-000003 {案件名}（{名前}）`。新しい下書きから） |

- 題の形：`今の題［C-000012］`。ID が空なら何も足さない（`Ids::tag(?string)` が `［…］` か空文字を返す）。
- エンジニアの名前・SES の名前の扱いは今のまま（ID を足すだけ）。

## 5. 作業の段階

### 段階1　土台（画面なし）
1. `app/Services/Ids.php` を作る：
   - `const KINDS`（kind → 表・列・接頭辞。A→`users.admin_code`、T→`users.staff_code`、S→`ses_companies.code`、E→`engineers.code`、C→`cases.code`、AP→`applications.code`、CT→`contracts.code`、IV→`invoices.code`）。
   - `next(string $kind): string`：`DB::transaction` の中で `id_counters` の行を `lockForUpdate` で読み、`last_no+1` を保存して `接頭辞-` ＋ `str_pad(6桁)` を返す。行が無ければ作ってから読む。
   - `format(string $kind, int $n): string`、`normalize(string $input): ?string`（D10 のゆれを吸収。形が合わなければ null）。
   - `assign(string $kind, int $rowId): ?string`：その行の列が空のときだけ `next` で付けて返す（空でなければ今の値を返す）。役割の付与・発行で使う。
   - `fillMissing(bool $apply = true): array`：D6 の順で空の行を埋め、数え表を「今の値と最大の番号の大きいほう」にそろえる。kind ごとの件数を返す。
   - `tag(?string $code): string`。
2. マイグレーション（2章）。
3. コマンド `app/Console/Commands/FillIds.php`（`park:fill-ids {--apply}`）。
4. `park:status`（`Console/Commands/Status.php`）に「ID の無い行」の件数を足す（JSON にも）。
5. テスト T1〜T6 → `php artisan test` → commit。

### 段階2　作成のすべての経路で付ける
1. insert の配列に `'code' => Ids::next('…')` を足す：`Workflow::createEngineer`・`saveCase`（新規だけ）・`apply`・`makeContract`・`applySes`・`directSesId`、`Registration::provisionalSes`（会社）、`DummySeed`（会社）、`SampleSeeder`（会社の直書き2か所）。
2. users：`Workflow::createUser`・`Registration::provisionalSes`・`provisionalAdmin`・`provisionalPerson`・`provisionalNextRep`・`InviteAdmin`・`DummySeed::createUser` で、`role` が admin なら `admin_code`、ses なら `staff_code` を入れる（eg は入れない）。`addSesRole` は `Ids::assign('T', $userId)`、`addAdminRole` は `Ids::assign('A', $userId)`。eg へ戻す処理（93・157行）は ID に触らない。
3. 請求：`issueInvoice` を「`where('id')->where('status','draft')->update(...)` が1件のときだけ、`Ids::assign('IV', $id)` してお知らせ」に変える。0件（発行済み）なら知らせず「すでに発行済みです。」。
4. 付け漏れの検出：`app` の下で `DB::table('<8つの表>')->insert` を新しく足したら気づけるよう、テスト T13（経路を全部通して空の行が無いこと）を置く。
5. テスト T7〜T14 → test → commit。

### 段階3　画面に出す・探す
1. 部品 `resources/views/components/code.blade.php` と `.code` の CSS（D14）。
2. 3章の表の画面すべてに出す。コントローラは、`select` に `code` が入っていない所だけ足す（`e.*` などで取っている所はそのまま）。他社のエンジニアを並べる所（`CaseMail::candidates` の group、`CaseSearch` の他社の人）では、SES の画面に E- を出さない。
3. `FindController`（`GET /find`）と、上の帯の入力欄（`layouts/app.blade.php`。ログイン・2要素認証・立場選びが済んだときだけ）。`Ids::normalize` → kind ごとに行を読み → 下の権限で判定 → 詳細へ redirect。だめなら同じ文で `back()`。
   - A-：管理者だけ → `/admin/admins/{id}`。T-：管理者 → `/admin/ses/{ses_id}/users/{id}`、同じ会社の SES → 自社の画面。S-：管理者 → `/admin/ses/{id}`、自社の SES → `/ses/company`。E-：`Perms::engineer` → `/engineers/{id}`。C-：`Perms::caseView` → `/cases/{id}`。AP-・CT-：`Perms::app` → `/applications/{id}`・`/contracts/{id}`。IV-：管理者か自社の SES → `/invoices/{id}`。EG は A-・T-・S-・IV- を開けない。
   - 兼任の人は、今の立場（`session('acting_role')` で入れ替わった `role`）で判定する。
4. 一覧の `q`：管理者のエンジニア一覧の検索対象に `code` を足す。案件・応募・契約・請求・SES・管理者の一覧には、今の画面に検索欄が無ければ足さない（帯の「ID で探す」で足りる）。
5. テスト T15〜T22 → test → commit。

### 段階4　メール・請求書・マニュアル・版
1. 4章の表のとおり、題と本文に ID を足す。件名・題を完全一致で見ているテスト（0章）を、ID 込みの形に直す（`assertStringContainsString` にしない。期待する題をそのまま書く）。
2. 請求書の画面（`pipeline/invoice.blade.php`）に「請求書番号 IV-…」。下書きは「発行時に付けます」。
3. マニュアル（`resources/manuals/admin.md`・`ses.md`・`engineer.md`）に「ID（番号）」の短い節：何に何が付くか、どこに出るか、「ID で探す」の使い方、書類には出ないこと。操作手順書（`*-steps.md`）に「ID で探す」の手順を1つ。README に「表示 ID（v0.9.0）」の節（`park:fill-ids` と `park:status` の見方）。
4. `config/park.php` を `v0.9.0` に。0章の版を見ているテスト8つを直す。
5. テスト T23〜T26 → test → commit。

## 6. 受け入れ条件

| 番号 | 受け入れ条件 |
|---|---|
| A1 | 管理者・担当者・会社（SES・直属）・エンジニア・案件・応募・契約・発行済みの請求書のすべてに、1章 D1 の形の ID が付く |
| A2 | 新しく作ったものには、どの経路（画面・仮登録・申し込み・見本・ダミー・コマンド・案内メールの返事からの応募）でも、作ったその時に ID が付く |
| A3 | ID は重ならない。同じ種類で続けて／同時に作っても別の番号。消した行の番号は使い直さない |
| A4 | ID は変わらない（所属の変更・停止・退会・兼任の付け外し・編集の保存のどれでも） |
| A5 | 既存のデータには、マイグレーションで作成順に ID が付く。マイグレーション（と `park:fill-ids --apply`）を2回流しても何も変わらない |
| A6 | 担当者に管理者や担当者の役割を足すと、その役割の ID が付く。役割を外して戻すと、前と同じ ID |
| A7 | 請求書の ID は発行のときに付き、下書きには無い。発行の2回押しで、番号もお知らせも1回分 |
| A8 | 3章の画面に、その人が見てよい ID だけが出る。A- は管理者にだけ、他社のエンジニアの E- は SES に出ない |
| A9 | 書き出す書類（名前を伏せた／伏せない業務経歴書・履歴書、スキルシートの印刷）に ID は出ない |
| A10 | 「ID で探す」で、見てよい ID はその詳細が開く。見られない ID と無い ID は同じ文。入力のゆれ（全角・小文字・ゼロ埋めの省略）を受ける |
| A11 | 4章のお知らせ・メールの題に `［ID］` が付く。案件の案内メールの既定の本文に案件 ID が入る。請求書に請求書番号が出る |
| A12 | `park:status` に ID の無い行の件数が出る（ふだんは 0） |
| A13 | マニュアル3つ・手順書・README を直し、版が v0.9.0 になる。既存のテストがすべて通る |

## 7. テスト（`tests/Feature/V090DisplayIdTest.php`。人・会社は架空の名前だけ）

| 番号 | 手順 → 確かめること | 条件 |
|---|---|---|
| T1 | `Ids::next('C')` を3回 → `C-000001`・`C-000002`・`C-000003` | A1・A3 |
| T2 | `Ids::format('C', 1234567)` → `C-1234567` | A1 |
| T3 | `Ids::normalize`：`c12`・`Ｃ－１２`・` C-000012 `・`ap5` → `C-000012`・`C-000012`・`C-000012`・`AP-000005`。`X-1`・`C-`・空 → null | A10 |
| T4 | code の無い行を直接 insert（会社2・エンジニア3・案件2・発行済みの請求1・下書きの請求1・admin と ses の users）→ `Ids::fillMissing()` → id 順に 1 から付く。下書きの請求と eg の users は空のまま。続けて `next('E')` → 4 番 | A5・A7 |
| T5 | `fillMissing()` を2回 → 2回目は件数 0・値は同じ。マイグレーションを `migrate:refresh --path=…` で流し直しても落ちない | A5 |
| T6 | エンジニアを作って消し、もう1人作る → 消した番号を使わない | A3 |
| T7 | 管理者が画面からエンジニアを仮登録（`POST /engineers`）→ `engineers.code` が E-、その人の `users`（eg）の `admin_code`・`staff_code` は空 | A1・A2 |
| T8 | SES が案件を登録（`POST /cases`）→ C-。編集の保存（`POST /cases/{id}`）で C- が変わらない | A2・A4 |
| T9 | 応募（`POST /cases/{id}/apply`）→ AP-。契約（`POST /applications/{id}/contract`）→ CT- | A2 |
| T10 | 参加の申し込み（`POST /apply`）→ 会社に S-。管理者が SES を仮登録 → 会社に S-・代表者に T-。管理者を仮登録 → A- | A2 |
| T11 | eg のアカウントに担当者の役割を足す → T-。外して eg に戻し、もう一度足す → 同じ T- | A6 |
| T12 | 請求の下書きを作る（`POST /admin/invoices/build`）→ code は空。発行（`POST /admin/invoices/{id}/issue`）→ IV-。もう一度発行 → 番号もお知らせ（`app_notifications`）も増えない | A7 |
| T13 | 見本（`SampleSeeder`）と `DummySeed` を入れる → 8つの対象のどの行にも空が無い（eg の users と下書きの請求を除く）。`park:status --json` の「ID の無い行」が 0 | A2・A12 |
| T14 | エンジニアの所属を変える（`POST /engineers/{id}/affiliation`）・停止・再開 → E- が変わらない | A4 |
| T15 | 管理者で `/admin/engineers`・`/engineers/{id}`・`/admin/admins` を開く → E-・A- が出る | A8 |
| T16 | SES で `/ses/engineers`・`/ses/company`・`/cases/{id}` を開く → 自社の E-・S-・T-・C- が出る。管理者の A- は出ない | A8 |
| T17 | SES で、他社のエンジニアが候補に入る案件の検索・案内の画面を開く → 他社の人の E- が出ない | A8 |
| T18 | EG で `/eg`・`/eg/offers`・`/eg/applications` → 自分の E-・案件の C-・応募の AP- | A8 |
| T19 | スキルシート `/engineers/{id}/sheet?anon=1` と無し、業務経歴書（名前を伏せた版）の書き出し → 画面・xlsx の文に E- が無い | A9 |
| T20 | 管理者で `/find?code=e1`（小文字・ゼロ埋め無し）→ `/engineers/{id}` へ。`/find?code=C-999999`（無い）→ 決まった文 | A10 |
| T21 | SES で、他社のエンジニアの E-・他社の請求書の IV-・管理者の A- を探す → 無い ID と同じ文。自社の E- → 開く | A10 |
| T22 | EG で、自分の AP- → 開く。他人の AP-・S- → 同じ文。兼任（担当者＋EG）の人は、今の立場で結果が変わる | A10 |
| T23 | 配信・応募・発行のあと、`app_notifications.title` と `mail_outbox.subject` に `［C-…］`・`［AP-…］`・`［IV-…］` | A11 |
| T24 | 案件の案内の画面（`/cases/{id}/mail`）の既定の本文に `■案件 ID：C-…` | A11 |
| T25 | 請求書の画面に「請求書番号 IV-…」。下書きは「発行時に付けます」 | A11 |
| T26 | `config('park.version')` が `v0.9.0`、`/login` に出る | A13 |

## 8. 境界の挙動

- 0件：データが空の DB でマイグレーション → 数え表だけでき、どれも 0。最初の1件が `-000001`。
- 権限が無い：`/find` は無い ID と同じ文（存在を漏らさない）。詳細の画面は今の 403 のまま。
- 二重の操作：作成の2回押しは今までどおり（それぞれ別の ID）。請求の発行は1回分（D4）。役割の付与の2回は `assign` が空のときだけ付けるので1つ。
- 同時の作成：`id_counters` の行の鍵で順番に取る。万一ぶつかっても列の unique で insert が失敗し、画面は今の失敗の扱い（番号は欠けるだけ）。
- 仮登録の取り消し・見本の削除：消えた行の番号は欠番のまま。使い直さない。
- 退会・停止・申込中・仮登録の人と会社：ID を持ったまま。画面の出し方は今のまま。
- 兼任：A-／T- と E- の両方を持つ。「ID で探す」は今の立場で判定。担当者が管理者を兼ねると（`addAdminRole`）、T- は残り、A- が増える。
- 所属の変更：E- は変わらない。元の会社の SES は、次から「ID で探す」でもその人を開けない。
- 1件目より前の古い通知・メール：ID は入らない（書き換えない）。
- 発行済みの請求書の明細の文：書き換えない（新しい下書きから CT- が入る）。
- 999999 を超えた：桁が増えるだけ（並べ替えは id で行うので崩れない）。

## 9. リスク

| 番号 | リスク | 手当て |
|---|---|---|
| R1 | **本番（Xserver）で、反映のマイグレーションが既存データに ID を付ける**。付いた ID は戻さない約束なので、やり直しにくい | 先に手元で `migrate` → `park:fill-ids`（見るだけ）→ 件数を確かめる。完了報告で「本番で既存データに ID が付く」と警告する。`down` はあるが、使えば ID が消えて付け直しになる |
| R2 | テストの直接 insert で code が空の行がある（テストの中だけ） | 画面は空を `—` で出す。付け漏れは T13・`park:status` で見る |
| R3 | 件名の変更で、件名を完全一致で見ている既存のテストが落ちる | 段階4でまとめて直す（0章の 12 ファイル） |
| R4 | 外への書類に ID が混ざる（今後の変更で） | T19 で、書類に E- が無いことを見張る |
| R5 | 連番から件数がわかる（9章の TEZZO 1） | 推奨で仮置き。答えが違えば `Ids::format` と開始の番号だけ直せば済む形にしておく |
| R6 | 数え表の行の鍵が、外側の長いトランザクション（仮登録など）の間持たれる | 作る件数が少ないので許容。重い一括処理（ダミー）は、すでに1件ずつ作っている |

## 10. TEZZO に上げる件

**1** SES・エンジニアに見せる ID の範囲と、連番が件数を表すこと
- 背景：ID を SES・エンジニアの画面とメールに出すと、`C-000012` から「案件はまだ12件」、`E-000040` から「エンジニアは40人くらい」と、PARK の規模が外の人（SES・エンジニア）に読み取れる。また、名前を伏せた業務経歴書は案件をくれた先（PARK の外）へ渡るので、そこに E- を入れると、別々の機会に出た書類を「同じ人」と突き合わせられる。
- 推奨：**a. 通し番号を 1 から付け、SES・エンジニアにも出す。ただし書き出す書類には出さず、他社のエンジニアの E- も SES に見せない**（今の仮置き）。理由：依頼は「通し番号」。件数が見えるのは始めたばかりの今だけの話で、ID で話が通じる便利さのほうが大きい。書類と他社の人は、今も名前を伏せている所なので、そこから同じ人とわからないようにする。
- 選択肢：a. 上の推奨のとおり／b. 番号の始まりを大きくする（例：`C-100001` から。件数は読みにくくなるが通し番号のまま）／c. SES・エンジニアには ID を出さず、管理者の画面だけにする。

**2** メールの件名に ID を付けること
- 背景：SES・エンジニアに届くお知らせのメールの件名が、`…［AP-000012］` の形に変わる（外の人に見える文の変更）。
- 推奨：**付ける**（今の仮置き）。理由：メールから「ID で探す」でそのまま開ける。案件の案内メールは送る人が件名を直せるので、件名ではなく本文に入れる。
- 選択肢：a. 付ける（推奨）／b. 件名には付けず、本文の最後に `ID：…` の行を足す。
# PLAN：エンジニアの所属 SES を変えられるようにする（v0.8.1。2026-11-13）

> **前の計画（実装済み）**。下の「v0.8.0」から「v0.4.1」までの節は前の計画で、消さずに残している。
> 依頼：TEZZO（Leo 経由。2026-11-13）「エンジニアの所属 SES（直属あり）が変更できない（エンジニア自身はできなくても、管理者・SES・担当者はできていいのではないか）」。
> 作る順：段階1 → 段階2 → 段階3（段階ごとに commit。`php artisan test` を通してから次へ）→ park-reviewer → park-tester。commit・push・公開版への反映は Leo。
> プレーヤー：管理者・SES・担当者・EG の4つ（担当者は今のアプリでは `role=ses`。SES の代表者と同じ扱い）。
> **表の追加・マイグレーションは無し**。過去の応募・契約・請求・案内メールなどの記録は書き換えない。外部のライブラリは足さない。名前・メールはこのファイルにもテストにも書かない（テストは架空の人）。

## 0. コードで確かめた今の形

- 編集の保存 `POST /engineers/{id}`（`EngineerController::update`）は `$r->except('_token', 'ses_id')` で、所属は変わらない。編集画面 `engineers/form.blade.php` に所属の欄は無い（`edit` は `sesFixed => null` を渡すが、画面は使っていない）。
- 所属を選べるのは新規の仮登録（`store`）だけ：管理者は参加中の SES（`kind=ses`・`status=active`。直属は選べない）、SES は自社に固定。
- 仮登録中（`users.role=eg`・`registered_at` が空）の人は、`/engineers/{id}/edit` から `/people/{uid}/edit` へ回される（`guardProvisional`）。そこで **管理者だけ** が所属を参加中の SES に直せる（`Registration::editProvisionalPerson` 508〜525行。`users.ses_id`・`engineers.ses_id` を直し、仮登録のご案内を送り直す）。
- 見える範囲：`Perms::engineer`（管理者は全員、`role=ses` は `engineers.ses_id = users.ses_id`、EG は本人）。所属を変えると、元の会社の SES・担当者はその人の画面を開けなくなる（403）。
- 直属：`ses_companies.kind='direct'`（`Workflow::isDirect`）。直属には SES の人がいないので、`N::toSes` は管理者全員に回る。
- 兼任：1つの `users` の行が `role=ses|admin` と `engineer_id` を持つ（`Workflow::isDual`）。SES との兼任は「同じ会社のエンジニア」が条件（`Workflow::egShareTarget`・`sesShareTarget`）。
- 本人のアカウント（`role=eg`）も `users.ses_id` を持ち、`user_label`（宛名・差出人の会社名）と `DirectMail::fromSesId` が読む。
- 退会の守り（`destroy`）：稼働中の契約（`contracts.status=active`）があれば不可。詳細の画面は選考中の応募（`applications.status` が `applied`・`submitted`・`doc_passed`・`interview`・`interviewed`）を数えている（`show` 129行）。
- お知らせ：`N::toSes($sesId, …)`・`N::toEg($eid, …)`（停止中・退会の人には届かない）・`N::toAdmins(…)`。どれも `$mail` で、メールを重ねるかを選べる。
- 前例：`SamplePurge` は見本の会社を消すとき、残す EG を直属へ移し、`applications`・`contracts`・`case_mails` の `ses_id` まで直属に書き換える（今回はこの書き換えをしない。D4）。
- 版は `config/park.php`（今 `v0.8.0`）。版の文字を見ているテスト：`RegistrationFoundationTest`・`SkillQuestionsTest`・`V030RegressionTest`・`V050CatalogTest`・`V060CaseAiTest`・`V070SearchTest`・`V080DirectMailTest`。

## 1. ses_id を持つ表と、所属を変えたときの扱い

| 表・列 | 何の会社か | 扱い | 理由 |
|---|---|---|---|
| `engineers.ses_id` | エンジニアの所属 | **付け替える** | 今回の本体 |
| `users.ses_id`（`engineer_id` がこの人で `role=eg` の行） | 本人のログインの所属 | **付け替える**（停止・退会の印がある行も） | `user_label`・`DirectMail::fromSesId` が読む。ずれると宛名の会社名が古いまま |
| `users.ses_id`（`role=ses`・`admin` の兼任の行） | 兼任の人の、担当者としての会社 | **変えない。兼任の人は所属を変えられない**（D3） | 付け替えると担当者としての会社まで変わる。付け替えないと「同じ会社」の兼任の条件が崩れる |
| `applications.ses_id` | 応募したときの所属 | 変えない（記録） | 選考中は変えられない（D3）ので、残るのは終わった応募。新しい応募は新しい所属で入る（`Workflow` 872行は `e.ses_id` を読む） |
| `contracts.ses_id` | 成約したときの所属（請求先） | 変えない（記録） | 稼働中は変えられない。終わった契約の請求先は元の会社のまま（`Billing`） |
| `invoices.ses_id` | 請求先 | 変えない | 契約から作るので、上と同じ |
| `case_mails.ses_id`・`route` | 案内を送った会社・自社あてか | 変えない（記録） | 送ったときの事実。元の会社が開くと、`CaseMailController` 110行の「他社の人」の扱い（名前を伏せた書類だけ）に自然に切り替わる |
| `case_offers`（ses_id なし） | — | 変えない | 会社は `engineers.ses_id` から読むので、届いている案内は新しい会社の画面に出る |
| `case_searches.ses_id` | 検索を作った人の会社 | 変えない | エンジニアの所属ではない。メンバーの自社・他社は `CaseSearch` が表示・送信のたびに `engineers.ses_id` で決める |
| `case_search_members`（ses_id なし） | — | 変えない | 元の会社の検索では、次に開いたときから他社の人（イニシャル）になる |
| `direct_mails.from_ses_id` | 送った人の会社 | 変えない（記録） | 送ったときの事実。見られる人（送った人・受け取った人・管理者）は変わらない |
| `cases.source_ses_id` | 案件を持ち込んだ会社 | 変えない | エンジニアの所属ではない |
| `interviews`・`application_events`・`follow_ups` | 応募・契約にぶら下がる | 変えない | 応募・契約と同じ |
| `engineer_skill_checks`・`engineer_projects`・`engineer_histories`・`engineer_certs`・`engineer_files`・`messages` | エンジニアの持ち物 | 変えない（人に付いて移る） | 新しい会社から見え、元の会社からは見えなくなる（確認の文で知らせる。G2） |
| `app_notifications` | 前に出したお知らせ | 変えない | 元の会社の人が古いお知らせを押すと 403（許容。8章 Q6） |
| `audit_log` | 記録 | 1行足す（`engineer-move`） | A8 |

- その時点の `engineers.ses_id` を読むものは、何もしなくても新しい所属で動く：`Perms::engineer`、SES の一覧・ホーム（`SesController`）、`Todo`、`Matcher`、`CaseMail` の own／group、`CaseSearch` の自社・他社、`DirectMail` の宛先、新しい応募とそのお知らせ。

## 2. 受け入れ条件

| 番号 | 受け入れ条件 |
|---|---|
| **段階1** | **だれが・どこへ・いつ変えられるか（画面なし）** |
| A1 | 管理者は、どの会社（直属を含む）のエンジニアも、参加中の SES（`kind=ses`・`status=active`）か直属へ変えられる |
| A2 | SES・担当者は、自社のエンジニアだけを、ほかの参加中の SES へ変えられる。直属へは変えられない |
| A3 | SES・担当者は、他社・直属のエンジニアを動かせない（自社へ引き入れることもできない） |
| A4 | EG 本人は変えられない。本人が `ses_id` を送っても何も変わらない |
| A5 | だれが押しても変えられないとき：退会済／稼働中の契約がある／選考中の応募がある／兼任（SES 担当者・管理者を兼ねている）／変更先が今と同じ・参加中でない・存在しない。理由の文を出し、何も変えない |
| A6 | 停止中のエンジニアは管理者だけが変えられる。仮登録中の人はここでは変えず、管理者には今の仮登録の直し（`/people/{uid}/edit`）を、SES・担当者には「管理者にご依頼ください」を案内する |
| A7 | 変えると、`engineers.ses_id` と本人のアカウント（`role=eg`）の `users.ses_id` が新しい会社になる。1章で「変えない」とした表・列は1つも変わらない |
| A8 | 変更の記録（`audit_log` の `engineer-move`。元と先の会社）が残る |
| A9 | お知らせ（アプリ内）が、元の会社・新しい会社（直属なら管理者）・本人に出る。SES・担当者が変えたときは管理者にも出る。本人にはメールも送る（お知らせのメールを断っていなければ） |
| A10 | 2回押し・同時の2回でも、変更・お知らせ・記録は1回分だけ |
| **段階2** | **画面** |
| G1 | エンジニアの編集画面に「所属」の欄が出る（管理者・SES・担当者）。今の所属、変更先の選択（管理者：直属＋参加中の SES、SES・担当者：自社以外の参加中の SES）、［所属を変える］。変更先が0件なら「移せる参加中の SES がありません」 |
| G2 | ［所属を変える］で確認「所属を「A」から「B」に変えます。A の SES・担当者からは、この方（スキル・経歴・書類を含む）が見えなくなります。よろしいですか」 |
| G3 | 変えられないとき（A5・A6）は、選択を出さずに理由の文だけを出す |
| G4 | EG 本人の編集画面には、所属を読み取りだけで出す（「変えるときは、所属の会社か管理者にご相談ください」） |
| G5 | 変えたあと：管理者はエンジニアの詳細へ戻り「所属を「B」に変えました」。SES・担当者は自社のエンジニア一覧へ戻り「{名前} さんの所属を「B」に変えました。これからは B が扱います」 |
| G6 | 変えたあと、元の会社の SES・担当者がその人の詳細・編集を開くと 403。新しい会社の SES・担当者の一覧・詳細に出る |
| **共通** | **権限（直打ち）・境界** |
| P1 | `POST /engineers/{id}/affiliation` を手で送ったとき：EG は 403（ルートの `role:admin,ses`）。SES・担当者が他社・直属のエンジニアを送ると 403。自社のエンジニアでも、変更先に直属・自社・参加中でない会社・数字でない値を送ると「選べない会社です」などで何も変わらない |
| P2 | `POST /engineers/{id}`（今の編集の保存）に `ses_id` を混ぜても、だれが送っても所属は変わらない（今のまま） |
| P3 | ログインしていなければログインへ。存在しないエンジニアは 404 |
| **段階3** | **まわり** |
| V1 | 版を v0.8.1 にする。README・マニュアル（`resources/manuals` の admin・ses・engineer と各 -steps）を直す |

## 3. 決めたこと（Leo の仮の推奨をもとに planner が仮置き。違えば8章で直す）

| 番号 | 決めたこと | 理由 |
|---|---|---|
| D1 | 変更は専用のルート `POST /engineers/{id}/affiliation`（`role:admin,ses` のグループ）→ `EngineerController::affiliation`。手続きは新しい `app/Services/Affiliation.php`。今の `update` の `except('_token', 'ses_id')` は残す | ほかの欄の保存と一緒に所属が変わる事故を防ぐ。判定を1か所に |
| D2 | 変更先の一覧：管理者は直属（`kind=direct`）を先頭に、参加中の SES を名前順。SES・担当者は自社を除く参加中の SES を名前順。**SES・担当者にも他社の会社名の一覧を見せる** | グループの中では、案件の「持ち込み」・案内メールの他社の人の「（会社名）」で、他社の会社名をすでに見せている。伏せると移す先を選べない |
| D3 | 変えられない条件は `Affiliation::blocker($u, $e): ?string` の1か所。上から：退会済 → 仮登録中 → 停止中で管理者でない → 兼任（`users` に `engineer_id`＝この人で `role` が ses・admin の行がある） → 稼働中の契約 → 選考中の応募 | 画面の出し分けと送信が同じ判定を使う |
| D4 | 付け替えるのは `engineers.ses_id`・`updated_at` と、`users`（`engineer_id`＝この人・`role=eg`）の `ses_id`・`updated_at` だけ。応募・契約・案内メールなどの記録は書き換えない（1章） | 終わった応募・契約・請求は、そのときの会社の事実。`SamplePurge` の書き換えは見本を消すための特別な処理 |
| D5 | 本人の「確認しました」（`self_confirmed_at`）は戻さない。`W::editedBy` は呼ばない | 中身（スキル・経歴）は変わっていない |
| D6 | 手続き `Affiliation::move($u, int $id, $to)`：トランザクションの中で `engineers` の行を `lockForUpdate` で読み直し、`Perms::engineer`・`blocker`・変更先の検査をやり直してから書く。今と同じ会社なら「すでに「B」の所属です」で何もしない | 開いている間に状態が変わっても安全。同時の2回は、2回目が「すでに」になる（A10） |
| D7 | お知らせ（A9）。題「{名前} さんの所属が変わりました」、本文「{名前} さんの所属が「A」から「B」に変わりました。」：元の会社へ `N::toSes(from, …, $link, false, 'engineer-move')`（`$link` は元が直属なら `/engineers/{id}`、SES なら `/ses/engineers`）、新しい会社へ `N::toSes(to, …, "/engineers/{id}", false, 'engineer-move')`。SES・担当者が変えたときは `N::toAdmins(…, "/engineers/{id}", false, 'engineer-move')`。本人へ `N::toEg(id, '所属の会社が変わりました', 'あなたの所属の会社が「A」から「B」に変わりました。分からないことは、B のご担当者にお問い合わせください。', '/eg', true, 'engineer-move')` | 元・先・本人の3者が知るべきこと。本人は個人の情報が別の会社から見えるようになるので、メールでも知らせる。直属の側は `toSes` が管理者に回すので、管理者あてを重ねない |
| D8 | 記録：`N::audit($u->id, 'engineer-move', W::selfMark($u, $id)."エンジニア #{$id}：{A} → {B}")` | 会社名と id だけ（エンジニアの名前は書かない） |
| D9 | 画面：編集画面の保存の form の下に、別の form のカード「所属」。確認の文（G2）は、選んだ会社の名前を入れるため、画面の中の小さな JavaScript（`onsubmit` で `confirm`）。JavaScript が無いときは確認なしで送られる（送信側の守りは D6） | form の入れ子を作らない。今の画面の作り（Blade＋少しの JS）に合わせる |
| D10 | 変えたあとの戻り先（G5）：管理者は `/engineers/{id}`、SES・担当者は `/ses/engineers`（もう開けないため） | |
| D11 | 版は **v0.8.1**（表の変更なし・小さな機能） | |

## 4. 仕様の詳細

### 4-1. `Affiliation`（段階1）

- `const IN_SELECTION = ['applied', 'submitted', 'doc_passed', 'interview', 'interviewed']`（`EngineerController::show` の数え方と同じ。`show` もこの定数を使うように直す）。
- `targets($u, object $e): array`（[id => 名前]。D2）。EG・それ以外は空。
- `blocker($u, object $e): ?string`（D3）。文：
  - 退会済：「退会済のエンジニアは、所属を変えられません。」
  - 仮登録中：管理者「仮登録中のため、ここでは変えられません。仮登録の直し（氏名・メール・所属）から変えてください。」（画面は `/people/{uid}/edit` へのリンクを付ける）／SES「仮登録中のため変えられません。所属を変えるときは、管理者にご依頼ください。」
  - 停止中（SES・担当者）：「停止中のエンジニアの所属は、管理者だけが変えられます。」
  - 兼任：「この方は SES 担当者（または管理者）を兼ねているため、所属を変えられません。管理者にご相談ください。」
  - 稼働中の契約：「稼働中の契約があるため、所属を変えられません。契約が終わってから変えてください。」
  - 選考中：「選考中の応募が n 件あるため、所属を変えられません。選考が終わってから変えてください。」
- `move($u, int $id, $to): array`（`['ok' => 文, 'to' => 戻り先]`・`['bad' => 文]`・`['forbidden' => true]`）。`$to` が数字の文字でなければ「変更先を選んでください。」、`targets` に無ければ「選べない会社です。」。D6 → D4 → D8 → D7（お知らせはトランザクションの後）。

### 4-2. ルートとコントローラ

- `routes/web.php` の `role:admin,ses` のグループ（`/engineers/{id}/suspend` の近く）に `Route::post('/engineers/{id}/affiliation', [Eng::class, 'affiliation']);`。
- `EngineerController::affiliation`：`load()`（404・403）→ `Affiliation::move` → bad は `back()` と flash（`bad`）、forbidden は 403、ok は `$this->go(to, 文)`。
- `edit`：管理者・SES のとき `affTargets`・`affBlocker`・`sesName`（今の会社名。`load` の `ses_name`）を渡す。EG には `sesName` だけ。

### 4-3. 画面（`engineers/form.blade.php`）

- 保存の form の下に `<x-card>`「所属」：
  - 管理者・SES・担当者：「今の所属：A」。`affBlocker` があればその文（仮登録中の管理者には `/people/{uid}/edit` へのリンク）。無ければ `<form method="post" action="/engineers/{id}/affiliation">` に `<select name="ses_id">`（先頭は「変更先を選んでください」で値は空）・［所属を変える］・注意「変えると、今の会社の SES・担当者からは見えなくなります。過去の応募・契約・請求の記録は、今の会社のまま残ります。」。SES・担当者には「移す先の会社にも、あらかじめお伝えください。」も出す。変更先が0件なら G1 の文。
  - EG：「所属：A（変えるときは、所属の会社か管理者にご相談ください）」。form は出さない。
- 確認（G2・D9）：`onsubmit` で、選んだ `option` の文から「所属を「A」から「B」に変えます。…」を作り `confirm`。何も選んでいなければ送らない。

## 5. プレーヤーごとに変わること

| プレーヤー | できること | 見えるもの・届くもの |
|---|---|---|
| 管理者 | 全エンジニア（停止中も）を参加中の SES か直属へ。仮登録中は今の仮登録の直しから | 編集画面の「所属」。SES・担当者が変えたときのお知らせ。直属が元・先のときのお知らせ |
| SES | 自社のエンジニアを、ほかの参加中の SES へ（停止中・仮登録中・直属へは不可） | 編集画面の「所属」（他社の会社名の一覧）。自社から出た・自社に来たお知らせ。移したあとは、その人の画面を開けない |
| 担当者 | SES と同じ（`role=ses`） | SES と同じ（会社あてのお知らせは担当者にも届く） |
| EG | 変えられない | 編集画面に所属が読み取りで出る。変わるとお知らせとメール。連絡メールの宛先・宛名の会社名が新しい会社になる |

- 境界：変更先0件 → G1 の文。2回押し → D6。変えたあとに元の会社の人が古いお知らせを押す → 403。移した先の会社があとで停止 → 今どおり（その会社の人として扱い、配信の候補から外れる）。ログインの無い古いエンジニアの行 → `engineers` だけ変わる。

## 6. 作業の手順（implementer）

### 段階1：仕組み

1. `app/Services/Affiliation.php`（4-1）。`EngineerController::show` の選考中の数え方を `Affiliation::IN_SELECTION` に。
2. ルートと `EngineerController::affiliation`（4-2）。
3. `tests/Feature/V081AffiliationTest.php` の T1〜T14 を書き、`php artisan test`。

### 段階2：画面

1. `edit` に渡すもの（4-2）、`engineers/form.blade.php` のカード（4-3）。
2. T15〜T18 を書き、`php artisan test`。

### 段階3：まわり

1. `config/park.php` を `v0.8.1`。版を見ているテスト（0章の7つ）を直す。
2. README：エンジニアの節に「所属の変更」（だれが・どこへ、変えられない条件、付け替えるもの・残すもの（1章の表の要約）、お知らせ、仮登録中は仮登録の直しから）。
3. マニュアル（`resources/manuals`）：
   - `admin.md`・`admin-steps.md`：編集画面の「所属」で変える手順、直属へも移せること、変えられない条件、仮登録中は仮登録の直しから、SES・担当者が変えるとお知らせが届くこと、過去の応募・契約・請求は元の会社のまま。
   - `ses.md`・`ses-steps.md`：自社のエンジニアをほかの参加中の SES へ移す手順（担当者も同じ）、移すと自社から見えなくなること、直属へ・停止中・仮登録中は管理者に依頼、移す先の会社に前もって伝えること、他社から移ってきたときのお知らせ。
   - `engineer.md`・`engineer-steps.md`：所属は本人は変えられない（会社か管理者に相談）、変わるとお知らせとメールが届くこと、変わったあとの連絡メールの宛先は新しい会社の担当者。
   - 04_Manuals の写しは Leo（または park-manager）が push のあとに写し直す。implementer は park の中だけ直す。
4. T19・T20 を書き、`php artisan test` が全部通ることを確かめる（zip の既知の1件を除く）。

## 7. テスト（`tests/Feature/V081AffiliationTest.php`）

立場は今のやり方で作る：管理者、会社 A の SES（代表）・担当者、会社 B の SES、会社 C（停止中）、直属、A の EG（本登録済み）、B の EG、直属の EG。名前・メールは架空のものだけ。

| 番号 | 条件 | 手順 | 確かめること |
|---|---|---|---|
| T1 | A1・D10 | 管理者が `POST /engineers/{id}/affiliation` で A の EG を B へ／直属へ、直属の EG を A へ | `engineers.ses_id` と本人の `users.ses_id` が変わる。`/engineers/{id}` へ戻り「所属を「B」に変えました」 |
| T2 | A2・D10 | A の担当者が A の EG を B へ | 変わる。`/ses/engineers` へ戻り G5 の文 |
| T3 | A2・P1 | A の SES が A の EG を、直属へ／C（停止中）へ／A（自社）へ／存在しない id へ／`abc` へ | どれも変わらない。「選べない会社です」「変更先を選んでください」など |
| T4 | A3・P1 | A の SES が B の EG・直属の EG を A へ | 403。変わらない |
| T5 | A4・P1・P2 | EG が自分の id で affiliation を POST／EG・管理者・SES が `POST /engineers/{id}` に `ses_id` を混ぜて保存 | 前は 403。後は保存は成功し、所属は変わらない |
| T6 | A5 | 稼働中の契約のある EG／選考中の応募（5つの状態それぞれ）のある EG／退会済の EG／兼任（`role=ses` で `engineer_id` あり）の EG を管理者が動かす。終わった応募（`rejected`）・終わった契約だけの EG も | 前の4つは変わらず、それぞれの理由の文。最後は変わる |
| T7 | A6 | 停止中の EG を A の SES が／管理者が。仮登録中の EG を管理者が／A の SES が | 停止中：SES は不可・管理者は可。仮登録中：どちらも不可で案内の文 |
| T8 | A7・D4 | 終わった応募・終わった契約・請求・`case_mails`（own）・`case_offers`・`case_searches`（A の SES が作ったもの）・`direct_mails`（A の SES から送ったもの）を持つ EG を管理者が B へ | それらの行の `ses_id`・`route`・`from_ses_id` などが1つも変わらない。変わるのは `engineers` と `users`（`role=eg`）だけ |
| T9 | A8 | T1 のあと | `audit_log` に `engineer-move` が1行（元と先の会社名が入り、エンジニアの名前は入らない） |
| T10 | A9・D7 | 管理者が A→B／A の SES が A→B／管理者が直属→A | A・B の `role=ses` の人に `app_notifications`（`for_role=ses`、メールなし）。本人に `for_role=eg` と `mail_outbox` 1通。SES が変えたときは管理者にも（メールなし）。直属→A では管理者あては1人1件だけ |
| T11 | A10・D6 | 同じ変更を2回送る | 2回目は「すでに「B」の所属です」。記録・お知らせ・メールが増えない |
| T12 | G6 | 移したあと、A の SES・担当者で `GET /engineers/{id}`・`/engineers/{id}/edit`／B の SES で `GET /ses/engineers` | 403／一覧に出る |
| T13 | 1章 | 移したあと、A の SES が A の案件の案内の候補（`/cases/{id}/mail`）・エンジニア検索の一覧を開く／A の SES と B の SES が連絡メールの作成画面にその人の id を入れる | 他社の人（イニシャル（B の会社名））として出る／A は 403、B は開ける |
| T14 | P3 | ログインなしで POST／存在しない id | ログインへ／404 |
| T15 | G1 | 管理者・A の SES で `GET /engineers/{id}/edit` | 「所属」の欄と form の action。select の中身：管理者は直属が先頭＋参加中の SES、A の SES は B だけ（C・直属・A は無い） |
| T16 | G3 | 稼働中の契約のある EG の編集画面 | select が無く、理由の文 |
| T17 | G2 | T15 の画面 | `confirm` の文に「から」「見えなくなります」が入っている |
| T18 | G4 | EG 本人で `GET /engineers/{id}/edit` | 所属が読み取りで出て、affiliation の form が無い |
| T19 | V1 | `/login` | `v0.8.1` |
| T20 | 既存 | 既存のテスト一式 | 版の文字のほかは変えずに通る |

- 確認のダイアログの動き（選んだ会社の名前が入るか）は reviewer がコードを読み、Leo が手元の画面で確かめる。

## 8. 確認したいこと（Leo が決める。答えが来るまで推奨で作る）

- **Q1 退会済の人**：Leo の仮の推奨は「管理者だけ」。退会済は記録として残す人で、ログインもできず、移す意味が無い。**推奨：だれも変えられない**（A5）。要れば管理者だけ開ける（`blocker` の1行）。
- **Q2 兼任の人**：Leo の仮の推奨は「兼任も含めて付け替える」。兼任の行の `users.ses_id` を付け替えると担当者としての会社まで変わり、付け替えないと「担当者は A、エンジニアは B」になって兼任の条件（同じ会社）が崩れる。**推奨：兼任の人は変えられない**（A5）。移したいときは、先に兼任を外してから（担当者の退会・管理者から外す）。
- **Q3 SES に他社の会社名の一覧を見せるか**：**推奨：見せる**（D2）。グループの中で、すでに他社の会社名は見えている。
- **Q4 移す先の会社の了承**：この案では、SES・担当者が自社のエンジニアを他社へ一方的に移せる（先の会社はお知らせを受けるだけ）。**推奨：今回はこのまま**（お知らせで分かり、おかしければ管理者が戻せる）。行き違いが出たら、次の版で「先の会社が受け入れる」手順を足す。
- **Q5 本人の同意**：個人の情報（スキル・経歴・書類）が別の会社から見えるようになる。**推奨：今回は同意を取らず、お知らせとメールで本人に知らせる**（D7）。ただし、PARK の利用の同意の文が「所属の会社とグループで使う」の範囲を超える読み方になるなら、契約・外の人に見せる内容の件なので TEZZO に上げる。
- **Q6 古いお知らせのリンク**：元の会社の人が前のお知らせを押すと 403。**推奨：このまま**（お知らせは消さない）。
- **Q7 仮登録中の人を直属へ**：今の仮登録の直しは参加中の SES だけ。**推奨：今回は変えない**（直属へは本登録のあと、この機能で）。

## 9. 了承が要ること

- マイグレーション・データの削除・`.env` の変更は無い。公開版への反映（`park-deploy.sh`）は常時了承の範囲。
- **メールの送信**：所属を変えると、本人あてに「所属の会社が変わりました」のメールが出る（使う人が押したときだけ）。実装・テストでは `mail_outbox` に入るだけ。**公開版で実際の人の所属を試しに変えることは、TEZZO の了承を取ってから**（試すなら見本のデータの人で。`@park.example` は差し替え先に届く）。
- 本番のエンジニアの所属を Leo が代わりに変えるときも、TEZZO の了承を取る。

---

> 以下は前の計画（v0.8.0。2026-11-13）。消さずに残す。

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
