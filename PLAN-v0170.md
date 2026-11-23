# PLAN：EG ごとの担当者、プロバイダーを伏せる、プロバイダーのタブに原文、パートナー側の追跡（v0.17.0。2026-11-23）

> **これが今の計画**。v0.16.0 以前の計画は `PLAN.md`（そのまま残す）。
> 要求：v0.16.0（commit 105b207、公開版反映済み）への TEZZO さんの答え（2026-11-23。決定）。
> 1. パートナーの担当者＝その EG の PARK への登録申請を出した人。不明なら SES（代表）。**1人の EG に担当者は必ず1人。同じ会社の中なら付け替えられる**。
> 2. 依頼元＝受注 SES なら問題ない。**プロバイダー（名前・案件の原文・連絡先）はパートナーに伏せる**。
> 3. EG の金額は v0.16.0 のまま（案内メールの単価の文）。**プロバイダーからの案件の原文（金額を含む）は、SES メニューの「プロバイダー」タブから一覧で見られるようにする**（受注 SES の自社の分だけ）。
> 4. 受注 SES は振ったあと追跡しなくてよい。成約したら報告が届けばよい。**振られたパートナー側は、最後（成約／不成約）まで成り行きを把握し、記録する**。
> プレーヤー：管理者・SES・担当者・EG の4つ（アプリでは SES と担当者はどちらも `users.role=ses`。SES＝`ses_companies.rep_user_id` の人）。
> 外部のライブラリは足さない。名前・メール・合言葉はこのファイルにもテストにも書かない（テストは架空の名前、`@park.example`）。
> 作る順：段階1 → 6（段階ごとに `php artisan test` を通して commit）→ park-reviewer → park-tester。commit・push・公開版への反映は Leo。**本物のアドレスへの送信の試しは TEZZO さんの了承を取ってから**。

## 0. コードで確かめた今の形（v0.16.0）

- **EG の担当者**：列は無い。`CaseSearch::staffFor` が毎回推定（EG のアカウントの `users.registered_by` → 会社の SES → id 最小の担当者）。担当者を見る・変える画面は無い。
  - EG を作る所は1か所：`Workflow::createEngineer($sesId, $d, $actor, …)`（エンジニアの登録 `EngineerController::store`、仮登録 `Registration::provisionalPerson` の両方がここを通る）。
  - 担当者が使えなくなる所：`Workflow::suspendSesUser`・`leaveSesUser`（SES 本人は `repGuard` で止められない＝SES は常に使える）、`Workflow::leaveAdmin`（兼任の管理者）。
  - EG の会社が変わる所：`Affiliation::move`、`Registration::editProvisionalPerson`（管理者が仮登録の所属を直す）。
- **パートナーに見えているプロバイダーの情報**（受注 SES ではない SES・担当者）：
  - 案件の画面（今までの配置）の「案件票」`cases/_card_case`：**金額（プロバイダーからの金額）・金額のメモ・元請け**が出ている。原文（`raw_text`）は新しい配置（受注 SES だけ）にしか出ない。
  - `/ses/cases`（`ses/cases.blade.php`）と `/ses` のダッシュボード（`ses/dashboard.blade.php`）の「金額」列：プロバイダーからの金額。
  - 「紹介の依頼」カード・紹介のメール・お知らせ：プロバイダーの名前・原文は出ない（雛形 `staffTemplate` に無い）。単価ははじめ案件の金額で、受注 SES が直す（確認ダイアログあり）。
  - `/ses/providers`：グループの全プロバイダーの名簿（企業名・氏名・メール・電話）。案件とは結び付いていない。
  - プロバイダーからの返事（`inbound_mails.sender_kind='provider'`）・プロバイダーへの返信（`provider_mails`）：受注 SES と管理者だけ（`ProviderReply::canEdit`・`Inbound::query`）。
- **SES メニューの「プロバイダー」タブ**：ある（`/ses/providers`、`ProviderController`、画面は `admin/providers-index`・`providers-show` を共用）。中身は名簿だけで、案件の原文・金額の一覧は**無い**。案件の原文は `cases.raw_text`、プロバイダーは `cases.provider_id`、金額は `cases.price`・`price_note`。
- **パートナー側の追跡**：パートナーが送った案内（`case_mails.mode='partner'`、`ses_id`＝パートナー）は、パートナーの画面に一覧が**無い**（「案内済メール」は `canSeeMails`＝受注 SES と管理者だけ）。返事の記録（`CaseMailController::reply`）・結果の登録（`result`）・書類（`doc`）・プロバイダーへの下書き（`ProviderMailController::fromCaseMail`）は、受注 SES だけ（partner の行は 403）。
  - v0.16.0 の不具合：`Todo::ses` はパートナーの行も「応募したい」などで出すが、行き先 `/cases/{id}#mails` はパートナーには出ない。
  - 返信メール（`/inbound`）は、パートナーが自社から送った分を見られる（`Inbound::query` 355〜356行）。
  - 先方の結果（`CaseResult::set`）は、他社の EG なら所属の会社にお知らせ（`toSes(e.ses_id)`、氏名つき）。
- 版：`config/park.php` の `version` は `v0.16.0`。

## 1. 受け入れ条件

### A. EG ごとの担当者（答え1）
| 番号 | 立場 | 条件 |
|---|---|---|
| A1 | システム | すべての EG に担当者が1人入っている（`engineers.staff_user_id`）。マイグレーションで今の EG を埋める（2.2） |
| A2 | SES・担当者 | 自社で EG を登録・仮登録すると、登録した人が担当者になる。管理者が登録したときは、その会社の SES |
| A3 | SES・担当者 | 自社の EG の詳細に「担当者」が出る。編集の画面の「担当者を付け替える」で、同じ会社の使える担当者（SES を含む）から選んで付け替えられる |
| A4 | 管理者 | どの会社の EG も、その会社の使える担当者の中で付け替えられる。ほかの会社の人は選べない |
| A5 | EG | 自分の詳細（プロフィール）に担当者の氏名が出る。自分では付け替えられない（画面に出ない、送っても 403） |
| A6 | ほかの会社の SES・担当者 | 他社の EG の担当者を付け替えられない（403） |
| A7 | システム | 付け替えるたび記録（`engineer_staff_logs`）と監査（`eg-staff-change`、名前は書かない）が残り、編集の画面に「付け替えの記録」（日時・前→後・した人・理由）が出る。新しい担当者・前の担当者・EG 本人にお知らせ（メールは無し） |
| A8 | システム | 担当者が停止・退会（兼任で担当者から外れるを含む）になると、その人の EG はすべて会社の SES に移る（理由「担当者の停止」「担当者の退会」） |
| A9 | システム | EG の所属が変わると、担当者は新しい会社の SES になる（理由「所属の変更」） |
| A10 | 受注 SES | 「エンジニアを選択」のパートナーの行の担当者が、EG の担当者（`staff_user_id`）になる。紹介の依頼もその人に届く。入っている人が使えないときだけ、その会社の SES（それも使えなければ「担当者がいません」） |
| A11 | SES・担当者／管理者 | `/ses/engineers`・`/admin/engineers` の一覧に「担当者」の列 |

### B. プロバイダーを伏せる（答え2）
| 番号 | 立場 | 条件 |
|---|---|---|
| B1 | 受注 SES ではない SES・担当者 | 案件の画面の「案件票」に、プロバイダーからの金額・金額のメモ・元請けが出ない。金額の欄は、自社あての紹介の依頼の単価の文（`case_search_staff.price_text`、辞退していない新しいもの）、無ければ「受注 SES から伝えられます」 |
| B2 | 同上 | `/ses/cases` と `/ses` のダッシュボードの金額の列：自社が受注 SES の案件だけプロバイダーからの金額、ほかは B1 と同じ文（無ければ「—」） |
| B3 | 同上 | 紹介の依頼のカード・紹介のメール・お知らせ・自社の検索・作成画面・パートナー用の追跡（D 章）・`/inbound`・やり取りのどこにも、プロバイダーの企業名・氏名・メール・電話、案件の原文（`raw_text`）が出ない（テストで全部の画面の HTML とメールを見る） |
| B4 | 同上 | プロバイダーからの返事（`sender_kind='provider'`）は、受注 SES と管理者にしか見えない（パートナーの案内に紐付いた返事でも） |
| B5 | 受注 SES・管理者 | 今どおり、案件の画面で原文・金額・元請けが見える |

### C. 「プロバイダー」タブの原文の一覧（答え3）
| 番号 | 立場 | 条件 |
|---|---|---|
| C1 | SES・担当者 | `/ses/providers` の上にタブ「受けた案件（原文）」「プロバイダーの一覧」。「受けた案件（原文）」（`/ses/providers/cases`）に、自社が受注 SES の案件（下書きを含む）が新しい順に出る：ID・案件名・プロバイダー・金額（＋金額のメモ）・状態・登録日・［原文］（開くと原文）・［開く］ |
| C2 | 同上 | プロバイダー・状態・言葉（案件名・ID）で絞れる。20件ずつ（今の `page_slice`） |
| C3 | 同上 | 他社が受注 SES の案件は、紹介の依頼を受けていても出ない（URL を直しても出ない） |
| C4 | 同上 | プロバイダーの詳細に「この会社から受けた案件（自社）」の表（C1 と同じ列、自社の分だけ） |
| C5 | 管理者 | `/admin/providers/cases` に全社の分（「受注 SES」の列つき）。読むだけ |
| C6 | EG | どちらも開けない（今どおりルートで止まる） |

### D. パートナー側の追跡と成約の報告（答え4）
| 番号 | 立場 | 条件 |
|---|---|---|
| D1 | パートナーの SES・担当者 | 案件の画面に「自社から案内したエンジニア」（id="partner-mails"）が出る。自社から送った案内（`mode='partner'`、`ses_id`＝自社）が全部並ぶ：氏名・EG-・送った日・返事・返信メールの件数・やり取り・提案の依頼・先方の結果・最後の結果。会社の誰が開いても同じ |
| D2 | 同上 | 返事（応募する／見送る／質問など）を記録できる（受注 SES の「記録する」と同じ） |
| D3 | 同上 | 返事が「応募する」の人に［受注 SES に提案を頼む］（ひとこと任意）。押すと受注 SES の依頼した人にお知らせとメール。同じ人に2回は頼めない |
| D4 | 同上 | 「最後の結果」で［成約］［不成約］（メモ任意）を記録できる。記録したあとも直せる |
| D5 | 受注 SES の依頼した人 | 成約が記録されると、お知らせとメール「成約の報告」が届く（企業名・イニシャル・案件名。EG の氏名は出ない）。不成約はお知らせだけ（11章 仮置き1） |
| D6 | 受注 SES の SES・担当者 | 案件の画面の「担当者への依頼」に「提案の依頼」の表：イニシャル（企業名[担当者名]）・頼まれた日・ひとこと・［プロバイダーへの返信を作る］・［業務経歴書（名前を伏せる）］・先方の結果（登録できる）・最後の結果（読むだけ）。返信の本文・EG の氏名は出ない |
| D7 | 同上 | 提案の依頼の人について、プロバイダーへの下書き（`fromCaseMail`）・名前を伏せた業務経歴書・先方の結果の登録ができる（依頼の無い partner の行は今どおり 403） |
| D8 | パートナー | 受注 SES が先方の結果（合格・不合格）を登録すると、パートナーの会社と EG 本人にお知らせ（今の `CaseResult`）。行き先は `/cases/{id}#partner-mails` |
| D9 | パートナー | やることの一覧（ダッシュボードの ToDo）に、最後の結果が未記録の人が出る（返事待ち・提案を頼む・結果を記録する）。案件が終わっても、記録するまで消えない。行き先は `#partner-mails`（v0.16.0 の `#mails` の不具合も直す） |
| D10 | 受注 SES | 「担当者への依頼」の表に「提案の依頼 n 名」「成約 n 名」の数が出る（中身は D6 の表だけ） |
| D11 | 管理者 | 「案内済メール」の partner の行に、提案の依頼の日・最後の結果が読むだけで出る |
| D12 | ほかの会社（依頼も案内もしていない） | 「自社から案内したエンジニア」は出ない。`/case-mails/{id}/reply`・`handoff`・`outcome` は 403 |
| D13 | EG | 何も増えない（担当者のお知らせ A7・先方の結果 D8 だけ）。最後の結果の記録は EG に知らせない |

### E. 共通
| 番号 | 条件 |
|---|---|
| E1 | 同時に2回押しても、提案の依頼・成約の報告のお知らせとメールは1回 |
| E2 | メールを「保存のみ」にしているときは記録だけ残る |
| E3 | 今のテストが全部通る（`staffFor` を見ていたテスト、`/ses/cases` の金額を見ていたテストは新しい形に直す） |

## 2. データ（マイグレーション1本、足すだけ）

`database/migrations/2026_11_23_000002_eg_staff_and_partner_track.php`。外部キーは付けない。`down()` は足したものを消すだけ。

### 2.1 足すもの
- `engineers.staff_user_id`（unsigned big int null、index）＝その EG の担当者（users.id）
- 新しい表 `engineer_staff_logs`：`id`、`engineer_id`（index）、`from_user_id`（null）、`to_user_id`、`changed_by`（null。システムの付け替えは null）、`reason`（string 20）、`created_at`
- `case_mails` に足す：`handoff_at`（datetime null）、`handoff_by`（null）、`handoff_note`（text null）＝パートナーが受注 SES に提案を頼んだ／`outcome`（string 10 null。`won`／`lost`）、`outcome_note`（text null）、`outcome_by`（null）、`outcome_at`（datetime null）＝パートナーが記録した最後の結果

### 2.2 埋め戻し（同じマイグレーションの中。500件ずつ `chunkById`）
今ある EG 全部（退会済も）に、上から順に最初に当たった1人を入れ、`engineer_staff_logs` に `reason='backfill'`・`changed_by=null` で1行書く。
1. その EG のアカウント（`users.engineer_id`＝EG の id。role=eg を先に）の `registered_by` の人が、使える担当者（2.3）なら、その人
2. その会社の SES（`ses_companies.rep_user_id`）が使える担当者なら、その人
3. その会社の使える担当者のうち id が一番小さい人
4. 誰もいなければ空のまま（会社が停止・退会済のときだけ起きる。読むときの代わりの決まり 3.1 で扱う）

理由：TEZZO さんの「登録申請を出した人。不明なら SES」。アカウントの無い EG は登録した人が記録に無い（監査の文は名前で、当てにならない）ので SES にする。マイグレーションの中では app のクラスを使わず、2.3 の条件を `DB::table` で書く（後で条件が変わっても、移行が変わらないように）。

### 2.3 「使える担当者」
`role in (ses, admin)` かつ `ses_id`＝EG の会社 かつ `active=1`・`left_at is null`・`registered_at is not null`、かつ会社が `status='active'`。今の `CaseSearch::usableStaff` と同じ。

## 3. 決めたこと（細部。理由つき）

### 3.1 担当者の手続き（新しい `app/Services/EgStaff.php`）
- `EgStaff::usable(?object $user, int $sesId): bool`：2.3。`CaseSearch::usableStaff` はこれを呼ぶだけにする。
- `EgStaff::of(object $e): ?object`：`staff_user_id` の人が使えればその人。使えなければ会社の SES、それも使えなければ null。**読むだけで書き換えない**（画面を開いただけで記録が増えないように）。
- `EgStaff::candidates(int $sesId): Collection`：その会社の使える担当者。SES を先頭、あとは氏名の順。
- `EgStaff::initial(int $sesId, $actor): ?int`：登録した人が使える担当者ならその人、違えば（管理者など）その会社の SES。
- `EgStaff::assign(object $e, ?int $toUserId, $actor, string $reason): bool`：同じ人なら何もしない。`usable($to, e.ses_id)` でなければ false。`engineers.staff_user_id` を書き、記録と監査（`eg-staff-change`、「エンジニア #id：#前 → #後（理由）」）。お知らせ（`N::notify`、`mail=false`、kind `eg-staff`）：新しい担当者「{EG の氏名} さんの担当になりました」、前の担当者（使える人だけ）「{EG の氏名} さんの担当が {新しい人の氏名} さんに替わりました」、EG 本人（`N::toEg`、`mail=false`）「担当者が {氏名} さんに替わりました」。`reason='backfill'` のときはお知らせを出さない。
- `EgStaff::release(int $userId, $actor, string $reason): int`：その人が担当の EG を全部、会社の SES に `assign`。戻りは件数。
- 理由の名前 `EgStaff::REASONS`：`backfill` 初めの設定／`create` 登録／`manual` 付け替え／`move` 所属の変更／`suspend` 担当者の停止／`leave` 担当者の退会。

### 3.2 担当者が決まる・替わる所（つなぐ場所）
- `Workflow::createEngineer`：insert のときに `staff_user_id = EgStaff::initial($sesId, $actor)` を入れ、記録（`create`。お知らせは出さない）。仮登録もここを通る。
- `Workflow::suspendSesUser`・`leaveSesUser`（兼任で担当者から外れる分を含む）・`leaveAdmin`（兼任の管理者が外れる分）：更新のあとに `EgStaff::release($uid, $actor, 'suspend'|'leave')`。再開しても元に戻さない（記録を見て手で戻す）。
- `Affiliation::move`：所属を書き換えたあと、新しい会社の SES に `assign(…, 'move')`。理由：動かした人は前の会社の人なので、新しい会社のことは SES に任せる。
- `Registration::editProvisionalPerson`：管理者が仮登録の EG の所属を変えたとき、新しい会社の SES に `assign(…, 'move')`。
- `DummySeed`・`SeedDummy`・`DemoReset`：作り終わったあと、空の EG に 2.2 と同じ決まりで入れる（`EgStaff::fillMissing()`。マイグレーションと同じ順。お知らせは出さない）。
- SES の交代（`changeRep`）は何もしない（前の SES は担当者として残り、使えるまま）。

### 3.3 付け替えの画面と権限
- 付け替えられる人：その EG の会社の SES・担当者（だれの担当の EG でも）と、管理者。理由：今も担当者は自社のどの EG でも編集・所属の変更ができる（`Perms::engineer`）。担当者だけに絞ると、休みの人の EG を動かせない。
- EG 本人・他社の人：できない。退会済の EG：画面に出さない（送っても「退会済のエンジニアは…」で止める）。
- 選べる人：`EgStaff::candidates(e.ses_id)`。ほかの値を送ってきたら「選べない担当者です。」。
- `POST /engineers/{id}/staff`（`EngineerController::staff`、`role:admin,ses` の並び）：`Perms::engineer` と role が eg でないこと → `assign(…, 'manual')` → `/engineers/{id}/edit#staff` に「担当者を {氏名} さんにしました。」

### 3.4 パートナーに出すもの（B 章）
- 判定は `CaseMail::canSend($u, $c)`（＝受注 SES の SES・担当者）。それ以外の SES・担当者を「パートナー（受注 SES ではない会社）」として扱う。管理者は今どおり全部見える。
- 新しい関数 `CaseMail::priceForSes($u, object $c): string`：受注 SES なら `man($c->price)`（無ければ「相談」）。ほかの SES・担当者は、自社あて（`partner_ses_id`＝自社）・送信済み・辞退していない紹介の依頼の新しい1件の `price_text`、無ければ $fallback（画面ごとに「受注 SES から伝えられます」か「—」）。一覧で使うときは、案件の id でまとめて読む（`priceTextsForSes($sesId, array $caseIds)`。1件ずつ問い合わせない）。
- `_card_case`：`$u->role === 'ses' && !$canMail` なら、金額＝`priceForSes`、金額のメモと「元請け」の行を出さない。「受注 SES」の行は出す（依頼元が受注 SES であることが分かるように。TEZZO さんの答え2）。
- 作業内容・その他の条件は今どおり出す（エンジニアを選ぶのに要るため）。原文ではないが、受注 SES が中にプロバイダーの名前を書くと見えてしまう → 11章 仮置き4。
- `Inbound::query`（SES）：`sender_kind='provider'` の行は「案件の今の受注 SES が自社」のときだけ（パートナーの案内に紐付いた返事でも）。今の2つの条件の前に、この条件を足す。

### 3.5 「受けた案件（原文）」（C 章）
- `ProviderController::cases`（`GET /ses/providers/cases`・`/admin/providers/cases`）。SES・担当者は `cases.source_ses_id = 自社`、管理者は全部。`leftJoin providers`。絞り込み：`provider`（id）・`status`（draft／open／filled／closed）・`q`（案件名か ID の部分一致）。新しい順（`id desc`）。`page_slice`。
- 画面 `admin/providers-cases.blade.php`（新規。SES と管理者で共用、`$base` で出し分け）。原文は行ごとの `<details><summary>原文</summary><pre class="mailbody">…</pre></details>`（原文が無ければ「—」）。
- タブ：`providers-index` と `providers-cases` の上に `<p class="tabs">`（今の `ses/cases` と同じ形）。メニューの「プロバイダー」はそのまま `/ses/providers`（名簿）を開く。理由：今の名簿のテストと「案件の登録で選ぶ」流れを変えない。
- プロバイダーの詳細（`providers-show`）の下に「この会社から受けた案件（自社）」（SES は自社が受注 SES の分、管理者は全部）。
- 名簿（`/ses/providers`）は今どおりグループ全体（11章 仮置き3）。

### 3.6 パートナー側の追跡（D 章。新しい `app/Services/PartnerTrack.php`）
- `PartnerTrack::canTrack($u, object $m): bool`：`$u->role === 'ses'` かつ `$m->mode === 'partner'` かつ `(int) $m->ses_id === (int) $u->ses_id`。理由：案内を送った会社の記録なので、EG があとで所属を変えても、送った会社で追いかける。
- `PartnerTrack::rows($u, int $caseId)`：自社の partner の行（EG の氏名・EG-・返信メールの件数（`inbound_mails` の linked）・やり取りの可否・提案の依頼・先方の結果・最後の結果）。新しい順。
- 返事の記録：`CaseMailController::reply` を「受注 SES の自社の EG（今の条件）」か「`canTrack`」で通す（`load` の `canSend` を、この2つの OR にする）。記録の中身は今の `CM::reply` のまま。
- 提案の依頼 `PartnerTrack::handoff($u, int $mid, ?string $note): array`：`canTrack`、案件が募集中、`reply='interested'`、まだ頼んでいない。`update … where handoff_at is null` で先に印（0行なら「すでに頼んでいます。」）。お知らせとメール（`N::notify(…, mail=true, kind 'partner-handoff', 'ses')`）の相手＝この案件で自社あての紹介の依頼（送信済み）の新しい1件の `sent_by` が使える担当者ならその人、違えば受注 SES の SES。文：「提案の依頼：{案件名}[CA-]」「{企業名} から、{イニシャル} さんの提案の依頼が届きました。案件の画面の「担当者への依頼」から、プロバイダーへの返信を作ってください。」、行き先 `/cases/{id}#staffreq`。ひとことは500字まで（お知らせにも入れる）。監査 `partner-handoff`（案件コード・EG コードだけ）。
- 最後の結果 `PartnerTrack::outcome($u, int $mid, string $outcome, ?string $note): array`：`canTrack`、`won`／`lost`、メモ500字まで。案件が終わっていても記録できる。同じ値・同じメモなら「変わっていません。」。書いたら監査 `partner-outcome`。
  - `won` に変わったとき（前が `won` 以外）だけ、成約の報告：相手は提案の依頼と同じ決め方。お知らせとメール（kind `partner-won`）「成約の報告：{案件名}[CA-]」「{企業名} の {イニシャル} さんが成約しました。」、行き先 `/cases/{id}#staffreq`。同時の二重押しは `update … where (outcome is null or outcome != 'won')` で1回にする。
  - `lost` に変わったときは、同じ相手にお知らせだけ（`mail=false`、kind `partner-lost`）。
- 受注 SES の側（D6・D7）：
  - `ProviderMailController::fromCaseMail`：partner の行でも `handoff_at` があれば通す。
  - `CaseMailController::doc`：partner の行で `handoff_at` があれば `careersheet-anon` だけ通す（ほかは 403）。
  - `CaseMailController::result`：partner の行で `handoff_at` があれば通す。`CaseResult::set` の他社へのお知らせの行き先を、partner の行なら `/cases/{id}#partner-mails` にする。
  - `ProviderReply::draftFromInbound` は今どおり作らない（EG の返信はパートナーの中の話）。下書きは提案の依頼から受注 SES が作る。
- プロバイダーとのやり取りの主体は受注 SES（11章 仮置き2）。パートナーはプロバイダーを知らないまま、PARK の中で受注 SES に頼み、結果を受け取る。
- 今の［推薦する］（`CaseController::recommend`）はそのまま残す（紹介の依頼の無い案件で使うため）。

### 3.7 ToDo（`Todo::ses`）
- 今の「案内メールの返事」の問い合わせから partner の行を外し、partner の分を別に作る（案件の状態を問わない）：`m.ses_id = 自社`・`mode='partner'`・`outcome is null` の行ごとに、上から最初に当たる1つ。
  1. 先方の結果が合格／不合格 →「{氏名} さん：先方の結果が出ました（{案件名}）」「最後の結果（成約／不成約）を記録する」
  2. 案件が終わった（filled／closed）→「最後の結果を記録する」
  3. 返事が「応募する」で提案を頼んでいない →「受注 SES に提案を頼む」
  4. 返事が「見送る」→「最後の結果（不成約）を記録する」
  5. 返事が「質問」→ 今の文（答えて、返事を記録する）
  - 行き先はどれも `/cases/{id}#partner-mails`。
- 受注 SES に足す：`handoff_at` があり、その案内メールの送信済みのプロバイダーへの返信が無い行 →「提案の依頼：{イニシャル}（{企業名}）{案件名}」「プロバイダーへの返信を作って送る」、行き先 `/cases/{id}#staffreq`。

## 4. 画面（ボタン・文言）

### 4.1 エンジニアの詳細・編集
- 詳細 `engineers/show` の「所属 SES」の次に「担当者」：氏名（使えない人が入っていれば、代わりの SES の氏名と「（代わり）」）。見えるのは管理者・その会社の SES・担当者・本人（今の `Perms::engineer` の範囲）。
- 編集 `engineers/form` の所属の変更の下にカード「担当者を付け替える」（id="staff"。退会済と EG 本人には出さない）：説明「同じ会社の担当者の中で付け替えられます。」、選ぶ欄（`candidates`、今の人を選んだ状態）、［付け替える］（確認「担当者を付け替えます。新しい担当者と、前の担当者・ご本人にお知らせが届きます。」）。下に「付け替えの記録」の表（日時・前・後・した人・理由。新しい順20件。システムは「—」）。

### 4.2 案件の画面（受注 SES ではない SES・担当者。今までの配置）
- 右の列の順：「紹介の依頼」→「自社から案内したエンジニア」（行があるときだけ）→「エンジニアとの相性」。
- 「自社から案内したエンジニア」（id="partner-mails"）の表：
  - 列：エンジニア（氏名・EG-、返信メール n 件へのリンク）／送った日／返事（＋記録の欄。今の受注 SES と同じ select と要点）／提案の依頼（返事が「応募する」で未依頼なら、ひとことの欄＋［受注 SES に提案を頼む］。依頼済みなら「頼みました（日付）」）／先方の結果（`_result` を `canEdit=false` で）／最後の結果（記録済みならその値・メモ・日付。select［成約／不成約］＋メモ＋［記録］）／［やり取り］。
  - 表の上に「最後の結果を記録していない人：n 名」。下に説明「プロバイダーへの提案は、受注 SES が行います。応募したい人がいたら［受注 SES に提案を頼む］を押してください。成約したら、最後の結果に［成約］を記録してください（受注 SES に報告が届きます）。」
- 「案件票」の金額（4.2 は 3.4 のとおり）。

### 4.3 案件の画面（受注 SES。「案内済メール」の中の「担当者への依頼」）
- 今の表に列「提案の依頼」（n 名）・「成約」（n 名）。
- その下に「提案の依頼」の表（id は今の `staffreq` の中）：イニシャル（企業名[担当者名]）／頼まれた日・ひとこと／［プロバイダーへの返信を作る］（下書きがあれば［下書きを開く］、送っていれば［送ったメール］）／［業務経歴書（名前を伏せる）］／先方の結果（`_result`。プロバイダーへの返信を送ったあと登録できる。今の決まり）／最後の結果（成約・不成約・未記録。読むだけ）。
- 管理者：「案内済メール」の partner の行の返事の欄の下に「提案の依頼：日付」「最後の結果：成約（日付）」。

### 4.4 プロバイダーのタブ
- `/ses/providers` と `/ses/providers/cases` の上：［受けた案件（原文）］［プロバイダーの一覧］のタブ。説明「自社が受注 SES の案件の、プロバイダーからの原文と金額です。パートナーの会社の人には見えません。」
- 表：ID／案件名／プロバイダー（詳細へのリンク。無ければ「—」）／金額（＋金額のメモ）／状態／登録日／原文（折りたたみ）／［開く］。管理者は「受注 SES」の列も。
- 絞り込み：プロバイダー（select）・状態（select）・言葉（input）・［絞り込む］。0件は「まだありません。」

### 4.5 一覧
- `/ses/engineers`・`/admin/engineers`：「担当者」の列（`EgStaff::of` の氏名。まとめて読む）。
- `/ses/cases`・`/ses` のダッシュボード：金額の列は 3.4。

## 5. ルート（`routes/web.php`）

```
POST /engineers/{id}/staff              Eng@staff           （role:admin,ses の並び）
POST /case-mails/{id}/handoff           CMail@handoff       （role:admin,ses の並び。throttle:20,1）
POST /case-mails/{id}/outcome           CMail@outcome       （同上）
GET  /ses/providers/cases               Prov@cases          （role:ses。/ses/providers/{id} より前）
GET  /admin/providers/cases             Prov@cases          （role:admin。/admin/providers/{id} より前）
```
- `handoff`・`outcome` は `PartnerTrack::canTrack` でなければ 403（管理者も 403。読むだけのため）。
- 今の `/case-mails/{id}/reply`・`result`・`doc`・`provider-draft` の権限は 3.6 のとおり広げる。

## 6. 作業の手順（段階ごとに `php artisan test` を通して commit）

### 段階1：データと担当者
1. マイグレーション（2章。埋め戻しを含む）。
2. `EgStaff`（3.1）。`CaseSearch::usableStaff` は `EgStaff::usable` を呼ぶだけに、`CaseSearch::staffFor` は `EgStaff::of` を呼ぶだけに（呼び出し元は変えない）。
3. つなぐ場所（3.2）。`fillMissing` と見本のデータ。
4. テスト T1〜T10。

### 段階2：付け替えの画面と一覧の列
1. `EngineerController::staff`、`engineers/form` のカードと記録、`engineers/show` の行。
2. `/ses/engineers`・`/admin/engineers` の列。
3. テスト T11〜T16。

### 段階3：プロバイダーを伏せる
1. `CaseMail::priceForSes`・`priceTextsForSes`。
2. `_card_case`、`ses/cases`、`ses/dashboard`。
3. `Inbound::query` の provider の行の条件。
4. テスト T17〜T22。

### 段階4：「受けた案件（原文）」
1. `ProviderController::cases`、`providers-cases.blade.php`、タブ、詳細の表。
2. テスト T23〜T28。

### 段階5：パートナー側の追跡
1. `PartnerTrack`（3.6）、`CaseMailController::reply` の権限、`handoff`・`outcome`。
2. `CaseController::show`：受注 SES ではない SES・担当者に `partnerMails`、受注 SES の `staffReq` に数と「提案の依頼」の行、管理者の partner の行の表示。
3. `cases/_card_partner_mails.blade.php`（新規）、`_card_mails` の「提案の依頼」の表、`show.blade.php` の配置。
4. `ProviderMailController::fromCaseMail`・`CaseMailController::doc`・`result`、`CaseResult::set` の行き先。
5. `Todo::ses`（3.7）。
6. テスト T29〜T44。

### 段階6：マニュアル・版
1. `resources/manuals/ses.md`・`ses-steps.md`：担当者の付け替え、「プロバイダー」タブの「受けた案件（原文）」、紹介の依頼のあとの追跡（返事・提案を頼む・最後の結果）、受注 SES が提案の依頼を受けたとき（下書き・書類・結果）と成約の報告、受注 SES ではない案件で金額・元請けが出ないこと。
2. `resources/manuals/admin.md`・`admin-steps.md`：担当者の付け替え（全社）、「受けた案件（原文）」（全社）、partner の行の読み方。
3. `resources/manuals/engineer.md`・`engineer-steps.md`：プロフィールに担当者が出ること、替わったらお知らせが届くこと。
4. 写し：41_Park-Project の `04_Manuals` の `管理者`・`SES`・`エンジニア` の `マニュアル.md`・`操作手順書.md`（写しのテストがある）。
5. `config/park.php` の `version` を `v0.17.0`、README の版と変更点。版を見るテストを直す。
6. 全テスト。

## 7. 送信・公開の扱い

- 公開版への反映は常時了承の範囲（Leo）。migrate は列8つ・表1つの追加と、`engineers.staff_user_id` の埋め戻し（今のデータを読んで入れるだけ。消さない）。
- **反映の前に**、手元で `php artisan migrate --pretend` と、埋め戻しのあと「担当者が空の EG の数」を確かめる（`park:status` に1行足す：`EG の担当者が空：n 件`）。
- 本物の担当者に「提案の依頼」「成約の報告」のメールを送る試しは、TEZZO さんの了承を取ってから（デモ用の会社・アドレスで）。
- データの削除は無い。

## 8. テスト

架空の会社 A（受注 SES）・B（パートナー）・C（関係ない会社）、それぞれ SES と担当者2人、EG。メールは `@park.example`。`Notifier` は保存のみ。ファイル：`tests/Feature/V0170EgStaffTest.php`（T1〜T16）、`V0170HideProviderTest.php`（T17〜T28）、`V0170PartnerTrackTest.php`（T29〜T44）。

| 番号 | 手順 | 確かめること |
|---|---|---|
| T1 | マイグレーション前の形の EG（アカウントを B の担当者 X が登録）で埋め戻しを流す | `staff_user_id`＝X、記録 `backfill` が1行（A1） |
| T2 | 登録した人が停止中／管理者／アカウント無し | B の SES になる（A1） |
| T3 | B の SES も使えない（会社が停止）| 空のまま。`EgStaff::of` は null（A1・A10） |
| T4 | B の担当者 X が `POST /engineers` で EG を登録 | `staff_user_id`＝X、記録 `create`（A2） |
| T5 | 管理者が B の EG を仮登録 | B の SES（A2） |
| T6 | X を停止（`/admin/ses/{B}/users/{X}/suspend`） | X の EG が全部 B の SES に、記録 `suspend`、お知らせ（A8） |
| T7 | X を退会／兼任の X を担当者から外す | 同じく SES に、記録 `leave`（A8） |
| T8 | B の EG を A へ所属変更 | 担当者が A の SES、記録 `move`（A9） |
| T9 | A の担当者で検索 → 一覧 | B の EG の行が「イニシャル（B社[X]）」（`staff_user_id` の人）。X が停止なら B の SES（A10） |
| T10 | 紹介の依頼を送る | X にお知らせとメール（A10） |
| T11 | B の担当者 Y が B の EG を X → Y に付け替え | 列が Y、記録 `manual`（前 X・後 Y・した人 Y）、監査に名前が無い、X・Y・EG にお知らせ（`mail=false`）（A3・A7） |
| T12 | B の担当者が、A の人の id を送る | 「選べない担当者です。」変わらない（A3） |
| T13 | 管理者が B の EG を付け替え | できる。A の人は選べない（A4） |
| T14 | EG 本人が `POST /engineers/{自分}/staff` | 403。詳細に担当者の氏名が出て、付け替えのカードは出ない（A5） |
| T15 | C の担当者が B の EG に `POST /engineers/{id}/staff` | 403（A6） |
| T16 | `/ses/engineers`・`/admin/engineers` | 「担当者」の列（A11） |
| T17 | 案件（プロバイダー・原文・金額・金額のメモ・元請けあり）を B の担当者で開く | 案件票にプロバイダーからの金額・金額のメモ・元請けの文字が無い。金額は「受注 SES から伝えられます」。受注 SES の企業名は出る（B1） |
| T18 | A が B に紹介の依頼（単価「60万円」）→ B で開く | 案件票の金額が「60万円」（B1） |
| T19 | `/ses/cases`・`/ses`（B） | A の案件の金額は「60万円」か「—」、B が受注 SES の案件は金額が出る（B2） |
| T20 | B の担当者で、案件の画面・紹介の依頼・検索・作成画面・`#partner-mails`・`/inbound`・やり取り・届いたメールとお知らせを全部開く | プロバイダーの企業名・氏名・メール・電話、`raw_text` の中の目印の文字が、どこにも無い（B3） |
| T21 | パートナーの案内に紐付いたプロバイダーの返事（`sender_kind=provider`）を作る | B の `/inbound` に出ず、`/inbound/{id}` は 404。A には出る（B4） |
| T22 | A の担当者で案件を開く | 原文・金額・元請けが今どおり（B5） |
| T23 | A の担当者で `/ses/providers/cases` | A が受注 SES の案件だけ。原文・プロバイダー・金額が出る（C1） |
| T24 | プロバイダー・状態・言葉で絞る | 合う行だけ（C2） |
| T25 | B の担当者で `/ses/providers/cases`（A から紹介の依頼あり）、`?provider=` を A の案件のプロバイダーに | A の案件は出ない（C3） |
| T26 | A でプロバイダーの詳細 | 「この会社から受けた案件（自社）」に A の分だけ（C4） |
| T27 | 管理者で `/admin/providers/cases` | 全社の分と「受注 SES」の列（C5） |
| T28 | EG で両方 | 403（C6） |
| T29 | B が案内を送る（v0.16.0 の流れ）→ B の SES・Y で案件を開く | 「自社から案内したエンジニア」に氏名・EG-（D1） |
| T30 | B で返事を「応募する」と記録 | `case_mails.reply` が変わる（D2） |
| T31 | ［受注 SES に提案を頼む］（ひとことあり） | `handoff_*` が入る。A の依頼した人にお知らせとメール（イニシャルだけ、EG の氏名が無い）。2回目は「すでに頼んでいます。」で1通だけ（D3・E1） |
| T32 | 返事が「応募する」でない人に handoff | 断られる（D3） |
| T33 | A で案件を開く | 「担当者への依頼」に提案の依頼 1 名、「提案の依頼」の表にイニシャル（B社[X]）、EG の氏名が無い（D6・D10） |
| T34 | A が［プロバイダーへの返信を作る］→ 送る | 下書きができ、本文に B の会社名・EG の氏名が無い（`leaks` が空）（D7） |
| T35 | A が名前を伏せた業務経歴書／氏名つきの書類 | 伏せたものは取れる、ほかは 403（D7） |
| T36 | 提案の依頼の無い partner の行で、A が `provider-draft`・`result` | 403（今どおり）（D7） |
| T37 | A が先方の結果「合格」を登録 | B の会社と EG にお知らせ、行き先 `#partner-mails`（D8） |
| T38 | B が最後の結果［成約］ | `outcome='won'`。A の依頼した人に「成約の報告」のお知らせとメール（企業名・イニシャル、氏名なし）。もう一度同じ値は「変わっていません。」（D4・D5） |
| T39 | B が［不成約］に直す | お知らせだけ（メール無し）。もう一度［成約］にすると、報告がまた出る（D4・D5） |
| T40 | 同時に［成約］を2回 | 報告は1回（E1） |
| T41 | B のダッシュボードの ToDo（返事「応募する」・未依頼／結果が出た／案件を終了にした） | 3.7 の文、行き先 `#partner-mails`。最後の結果を入れると消える（D9） |
| T42 | A のダッシュボード | 「提案の依頼」の ToDo。プロバイダーへ返信を送ると消える（3.7） |
| T43 | C の担当者で案件を開く、`/case-mails/{id}/reply`・`handoff`・`outcome` | カードが無い、どれも 403。管理者も `handoff`・`outcome` は 403、案内済メールで partner の行の提案の依頼・最後の結果は読める（D11・D12） |
| T44 | EG で案件の画面 | 最後の結果・提案の依頼の文字が無い（D13） |

直すテスト：`V0160PartnerComposeTest`（担当者の決め方を `staff_user_id` で用意する）、`/ses/cases` の金額を見ていたテスト、`CaseResult` のお知らせの行き先を見ていたテスト、版を見るテスト、マニュアルの写しのテスト。

## 9. 4つのプレーヤーでの見え方（まとめ）

| 立場 | 変わること |
|---|---|
| 管理者 | EG の担当者を付け替えられる（全社）。「受けた案件（原文）」（全社）。partner の行の提案の依頼・最後の結果を読める。handoff・outcome は押せない |
| SES（受注 SES のとき） | 自社の EG の担当者を付け替えられる。「プロバイダー」タブで自社の案件の原文・金額。提案の依頼を受けてプロバイダーへ返し、先方の結果を登録。成約の報告が届く |
| SES・担当者（パートナーのとき） | 案件票に金額（伝えられた単価）だけで、プロバイダーの金額・元請けは見えない。「自社から案内したエンジニア」で最後（成約／不成約）まで記録。ToDo に未記録が残る |
| 担当者 | 自分が担当の EG に紹介の依頼が届く。付け替えはSESと同じくできる。停止・退会になると担当の EG は SES へ移る |
| EG | プロフィールに担当者の氏名。替わるとお知らせ。それ以外は変わらない |

## 10. 警告（グループ単位の変更。完了報告で TEZZO さんへ）

- W1 受注 SES ではない SES・担当者の画面（案件票・案件の一覧・ダッシュボード）から、プロバイダーからの金額・金額のメモ・元請けが消える（今まではグループの全員に見えていた）。紹介の依頼の無い案件は、金額が「受注 SES から伝えられます」になる。
- W2 紹介の依頼の届く先が、推定から「EG ごとの担当者」に変わる。埋め戻しで、アカウントの無い EG は全部 SES の担当になる（各社で付け替えが要るかもしれない）。
- W3 担当者を停止・退会にすると、その人の EG が自動で SES に移る（元には戻らない）。
- W4 受注 SES に、パートナーから「提案の依頼」と「成約の報告」がメールで届くようになる。受注 SES は、提案の依頼の人だけイニシャルで見える（返信の本文・氏名は今どおり見えない）。

## 11. 仮置き（TEZZO さんの答えで変わり得る。答えが来るまでは、この形で作る）

1. **不成約の報告**：受注 SES の依頼した人に、お知らせだけ（メールなし）。理由：受注 SES は追跡しなくてよい（答え4）が、案件を閉じる目安にはなる。要らなければ止める。
2. **プロバイダーとのやり取りの主体**：受注 SES。パートナーは［受注 SES に提案を頼む］で頼み、受注 SES がプロバイダーへの返信（名前を伏せた業務経歴書）を送り、先方の結果を登録する。理由：プロバイダーとの契約・連絡先は受注 SES のもので、パートナーには伏せる（答え2）。今のコードもプロバイダーへの送信は受注 SES だけ。
3. **プロバイダーの名簿（`/ses/providers`）**：今どおりグループ全体に見える（案件とは結び付かないので、どの案件がどのプロバイダーかは分からない）。自社の分だけにすると、グループ全体の見え方が変わるので、この版では変えない。
4. **作業内容・その他の条件**：パートナーにも今どおり出す（エンジニアを選ぶのに要る）。受注 SES がここにプロバイダーの名前を書かないよう、マニュアルに書く。
5. **成約のあと**：最後の結果［成約］は記録と報告だけ。契約・請求（成功報酬をどの SES に請求するか、応募・契約の行をどちらの会社で作るか）は、今の契約の登録（管理者）のまま。パートナー経由の成約の請求先は決めていない。
6. **付け替えられる人**：会社の SES・担当者の全員（自分の担当でない EG も）。担当者は自分の担当だけ、に絞るかは使ってみてから。
7. **［推薦する］との関係**：紹介の依頼が無い案件では今の［推薦する］、依頼があれば［受注 SES に提案を頼む］。1つにまとめるかは次の版。
