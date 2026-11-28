# PLAN v0.23.0：戻るボタン・「企業」と「PARK窓口担当者」・企業代表者・状況の言葉の整理

TEZZO の依頼（2026-11-28）を、park-implementer がそのまま作れる形にした計画。前の計画は PLAN-v0220.md（v0.22.0）ほか。
この版に上書きする前の PLAN.md（中身は v0.16.0 以前の計画。PLAN-v0220.md とは別の内容）は、park の git の履歴に残っている。残すなら Leo が、この計画を commit する前に `git show HEAD:PLAN.md > PLAN-v0160.md` で書き出して一緒に commit する。

- プレーヤーは 4 つ：**管理者・PARK窓口担当者（旧 SES代表担当者）・一般担当者・エンジニア**。アプリでは窓口と一般はどちらも `users.role=ses`、区別は `ses_companies.rep_user_id`（`Requirements::isRep`・`staff_kind`）。
- 外部のライブラリは足さない。名前・メール・合言葉はこのファイルにもテストにも書かない（テストは架空の名前、`@park.example`）。
- 作る順は 7 章。段階ごとに `php artisan test` を通して commit → park-reviewer → park-tester。commit・push・公開版への反映（migrate を含む）は Leo。**メールの一斉送信はしない**（企業代表者の確認を各社に頼むメールは、送るなら TEZZO の了承が要る。この版の範囲外）。

## 0. コードで確かめた今の形

- 画面の題：全画面が `@extends('layouts.app', ['title' => …])`。題の行は `layouts/app.blade.php` 141 行の `<div class="ttl"><img class="sprig"><h1>{{ $title }}</h1></div>`（`$noTitle`・`$print` のときは出ない）。`x-card` は中の小見出し（`<header><h2>` と `actions` の欄）で、ページの題ではない。→ 戻るボタンは **`.ttl` の右端** に置く（レイアウト 1 か所）。
- 今ある「戻る」：フォームの［戻る］（保存の隣。取り消しの意味）と、本文の下の「〜一覧に戻る」（`cases/show`・`pipeline/*`・`inbound/show`・`threads/show`・`admin/ses-show` など。`resources/views` を「戻る」で grep すると 40 か所）。一覧のパスは `list_url($kind)`（helpers.php 455 行）。
- 「会社」：画面・メール・マニュアル・テストに広くある（`会社` は 157 ファイル・1406 か所。うち PLAN・コメント・テストの架空の社名を含む）。**「株式会社」「有限会社」「合同会社」「子会社」「証券会社」「カード会社」は社名・辞書の語なので変えない**（`DirectMerge::MRSY_NAME`、`ProviderReply` 99 行の正規表現、`SkillCatalogData`、テストの架空の社名）。
- 「SES代表担当者」：`Requirements::ROLE_NAMES['rep']`（23 行）、`RegistrationText`（60・87・91 行）、`Registration`（569・642 行ほか）、`AdminController`・`SesController` のメッセージ、views 18 ファイル、manuals 6 本、README、テスト。`staff_kind()` が `ROLE_NAMES` を引くので、ヘッダーの札・ログイン中の帯も同じ値で変わる。
- 企業の項目：`ses_companies` に `name`・`rep_name`/`rep_sei`/`rep_mei`（窓口の氏名。交代のとき `Registration` 573 行で新しい窓口の氏名にそろう）・`contact_*`（申し込んだ人）・`phone`・`postal_code`・`address` ほか。代表者（社長など）の列は無い。
- 企業の項目を入れる画面：①申込 `/apply`（`public/apply`、`Workflow::applySes`）②管理者の仮登録・承認 `/admin/ses/new`・`/admin/ses/{id}/approve`（`admin/ses-new` → `admin/ses-form` の mode=provisional、`Registration::provisionalSes`）③仮登録中の直し `/admin/ses/{id}/edit`（mode=provisional、`editProvisionalSes`）④申込中の直し（mode=pending）⑤本登録 `/invite/{token}`（`partials/register-fields`。項目は `Requirements::fields('ses', true)`、保存は `Registration::finishPending`）⑥足りない項目 `/register/complete`（同じ partial、`saveMissing`）⑦本登録のあとの編集：管理者 `/admin/ses/{id}/edit`（mode=registered）、窓口 `/ses/company/edit`（`ses/company-edit`、`Registration::companyInput`・`checkCompanyInput`）。見る画面：管理者 `admin/ses-show`、SES `ses/company`（窓口も一般も開ける。編集ボタンは `$isRep` だけ）。
- 「状態」の言葉（helpers.php）：
  - `PARK_LABELS['sesStatus']`＝企業：申込中・仮登録・参加中・停止・退会済み。
  - `user_state()`＝人のアカウント：仮登録（＋（期限切れ）（心当たりなし））・参加中・停止・退会済み。`engineer_state()` はこれに「仮登録（未送信）」「参加中（エンジニアの項目が未入力）」を足す。
  - `PARK_LABELS['engStatus']`＝エンジニアの仕事：待機中・提案中・稼働中・休止・退会済み。提案中・稼働中は応募・契約で自動（`Workflow` 992・1005・1089 行）、待機中・休止は本人・SES が編集で選ぶ（`EngineerController` 255 行）。休止は案内の候補から外れる（`CaseMail` 66 行・`CaseSearch` 224 行）。
  - **依頼文の「エンジニア一覧の『状態』列は engineer_state()」は正確ではない**：今は「状態」列＝`engStatus` のバッジ＋停止中のバッジ、「アカウント」列＝`engineer_state()`。絞り込みの「状態」は `engStatus`。同じ「状態」の語が、企業・人・仕事の 3 つに使われているのが問題の本体。
  - 停止したエンジニアは `users.active=0` になる（`Workflow::suspendEngineer`）ので、アカウントの側でも「停止」と出る。今は「状態」列の「停止中」バッジと二重になっている。
- 版：`config/park.php` の `version` は `v0.22.0`。

## 1. 仮定（Leo の判断で変えてよい）

- H1 **企業の「申込中」は「申込済み」にする**。依頼の推奨案は「申込中」だが、TEZZO の指定の文に「申込済み」とあり、申し込む人から見ると手続きは済んで管理者の確認を待つ状態なので。違えば `PARK_LABELS['sesStatus']['pending']` の 1 か所を直すだけ。
- H2 企業代表者のラジオの文は TEZZO の言葉どおり「窓口に同じ」「記入」。表示は「（窓口に同じ）」を添える。
- H3 企業代表者が「窓口に同じ」のときは、**PARK窓口担当者の今の氏名**（`Registration::repName($s)[0]`）を出す。窓口が替われば表示も替わる（保存した名前は持たない）。窓口の氏名がまだ無い（申込で空）ときは「—（窓口に同じ）」。
- H4 企業代表者は PARK のアカウントではないので **ID が無い**。「記入」の氏名は ID なしで出す（企業情報の中の項目で、ページの題に SE- がある）。「窓口に同じ」のときは窓口の ST- を添える。
- H5 フォームから `ceo_same` が来ない（古い画面・今のテストの POST）ときは「窓口に同じ」として扱う。画面のラジオは初めから「窓口に同じ」に印が付いているので、利用者が選ばずに送ることはない（＝必須を満たす）。
- H6 エンジニアには企業代表者を出さない（今もエンジニアは所属の企業名しか見ない）。他社の SES にも出さない（他社の企業情報を見る画面は無い）。
- H7 本文の下の今の「〜一覧に戻る」ボタンとフォームの［戻る］は消さない（題の行の［戻る］と並ぶが、テストと手順書の写真が今の位置を前提にしているため）。整理は次の版の候補。
- H8 上のタブで開く一覧（エンジニア一覧・案件一覧など）にも［戻る］を出し、戻り先はその立場のダッシュボードにする（「どのページも」に合わせる）。
- H9 退会したエンジニア（`engineers.status='left'`）の稼働状況は「—」と出し、退会は登録状況で「退会済み」と出す。`engStatus` の `left` の値（DB）は変えない。
- H10 送信済みのメール・お知らせ・監査の記録（DB の文）は、古い言葉（会社・SES代表担当者）のまま。書き換えない。

## 2. 受け入れ条件（AC）

### A 戻るボタン
- A1 ログインして確認コードを通った人（兼任の人は立場を選んだあと）が開く、題の行（`.ttl`）のある画面には、題の行の右端に［戻る］（`<a class="btn ghost small ttl-back noprint">戻る</a>`）が 1 つ出る。管理者・PARK窓口担当者・一般担当者・エンジニアのどの立場でも。
- A2 出さない画面：ダッシュボード（`/admin`・`/ses`・`/eg`）、`$noTitle` の画面（トップ `/`、区切りのページ `/start/*`、スキルシート、請求書。印刷用で独自の［戻る］がある）、ログイン前の画面（ログイン・申込・本登録・パスワード再設定・はじめの設定・返事のリンク `/r/…`・お知らせの文の画面）、確認コード・立場の選択・`/welcome`・`/register/complete`・`/eg/consent`（先に済ませる手続きの途中）。
- A3 戻り先は 3.1 の表の「親」。表に無い画面は、同じサイトから来ていれば `history.back()`、そうでなければ（直接開いた・別のサイトから）その立場のダッシュボード。
- A4 幅 360px でも、題と［戻る］が重ならず、題が折り返しても［戻る］は右に残る。
- A5 印刷では［戻る］が出ない（`noprint`）。

### B 呼び名（企業・PARK窓口担当者）
- B1 画面・メール・お知らせ・バリデーションの文・マニュアル（`resources/manuals/*.md`）・README の「会社」は「企業」になる（「会社情報」→「企業情報」、「会社名」→「企業名」、「所属の会社」→「所属の企業」、一覧の列「会社」→「企業」、SES・担当者一覧の表示切り替え［会社］→［企業］など）。社名・辞書の語（株式会社・有限会社・合同会社・子会社・証券会社・カード会社）は変えない。「自社」「他社」「全社」「貴社」はそのまま。
- B2 「SES代表担当者」は全部「PARK窓口担当者」になる（「次のSES代表担当者」→「次のPARK窓口担当者」、「SES代表担当者の交代」→「PARK窓口担当者の交代」、ヘッダーの札、ログイン中の帯、`staff_kind()`、本登録の画面の「PARK の○○として仮登録されています」、交代のお知らせの題と本文）。「一般担当者」「担当者」（総称）、業態としての「SES」（SES 企業・SES・担当者一覧・受注 SES など）はそのまま。
- B3 DB の列・値・URL・クエリ（`rep_user_id`・`view=company`・`kind=rep` など）は変えない。
- B4 3.2 の検査テスト（ファイルを読んで探す）で、views・manuals・メール文の定数に「会社」（社名の語を除く）と「SES代表担当者」が 0 件。

### C 企業代表者（必須）
- C1 企業の項目に「企業代表者」が加わる。入力はラジオ「〇 窓口に同じ　〇 記入［＿＿］」。初めは「窓口に同じ」に印。「記入」を選んだときだけ氏名を使い、空なら「企業代表者の氏名を入れてください。」で断る（50 文字まで、`clean_name` で整える）。
- C2 入れる画面：申込 `/apply`（申し込む人）、管理者の仮登録・承認・仮登録中の直し・申込中の直し・本登録後の編集（`admin/ses-form` の 3 つの mode すべて）、本登録 `/invite/{token}`（PARK窓口担当者の本登録だけ。確かめて直せる）、`/register/complete`（足りないときだけ）、窓口の企業情報の編集 `/ses/company/edit`。承認の画面は、申込で入れた値を初めから入れておく。
- C3 見る画面：管理者 `/admin/ses/{id}` と PARK窓口担当者・一般担当者 `/ses/company` の「企業情報」に「企業代表者」の行。「窓口に同じ」なら `<x-code>`（ST-）＋窓口の氏名＋「（窓口に同じ）」、「記入」なら記入した氏名。
- C4 直せる人：管理者（全企業）と、その企業の PARK窓口担当者。一般担当者は見るだけ（編集の画面は今どおり開けない／ボタンなし）。エンジニア・他社の SES には出ない。
- C5 窓口が替わる（管理者の交代、次の窓口の本登録で入れ替わる）と、「窓口に同じ」の企業は表示が新しい窓口の氏名になる。交代のお知らせの本文に「企業代表者は「窓口に同じ」のため、企業代表者の表示も新しい PARK窓口担当者の氏名になります。」を足す（「窓口に同じ」のときだけ）。「記入」の企業は変わらない。
- C6 migrate すると、今ある全企業が「窓口に同じ」になる（`ceo_same=1`、`ceo_name=null`）。足りない項目の画面に急に回される窓口はいない。
- C7 申込の受付メール（申し込んだ人あて）と管理者へのお知らせに企業代表者は足さない。受付メールの「会社名：」は「企業名：」に。

### D 状況の言葉
- D1 言葉を 3 つに分ける（表示だけ。DB の値は変えない）。

| 何の | 見出し・列の名前 | 値（DB → 表示） |
|---|---|---|
| 企業 | **参加状況** | pending 申込済み／provisional 仮登録中／active 参加中／suspended 停止中／left 退会済み |
| 人のアカウント（管理者・担当者・エンジニア） | **登録状況** | 仮登録中（＋（期限切れ）（心当たりなし）（未送信））／参加中（＋（エンジニアの項目が未入力））／停止中／退会済み |
| エンジニアの仕事 | **稼働状況** | available 待機中／proposing 提案中／working 稼働中／unavailable 休止中（left は「—」） |

- D2 見出し・列・絞り込みの「状態」を上の名前に変える：企業の詳細（管理者・SES）・SES・担当者一覧（企業の表示と人の表示）・管理者一覧と詳細と編集・担当者の詳細・管理者のダッシュボードの仮登録の表・エンジニア一覧（管理者・SES）の「状態」→「稼働状況」と「アカウント」→「登録状況」、絞り込み「すべての状態」→「すべての稼働状況」（エンジニア）／「すべての登録状況」（人）・企業の詳細のエンジニアの表・エンジニアの詳細（「状態」→「稼働状況」、「アカウント」→「登録状況」）・エンジニアの編集の「状態」→「稼働状況」・エンジニアのホームの「今の状態」→「稼働状況」・案件の案内（`cases/mail`・`cases/show` の候補）とエンジニアの詳細（`cases/search-member`）の名前の下の小さな文に「稼働状況：」を添える。
- D3 エンジニアの「停止中」は登録状況にだけ出す（稼働状況の欄のバッジ「停止中」をやめる）。`engineer_state()` は、退会済みでなく `suspended_at` があればアカウントの有無にかかわらず「停止中」。
- D4 エンジニアの絞り込み（稼働状況）の選択肢に「退会済み」を出さない（退会済みは今の「退会済みを表示する／しない」で選ぶ）。`?status=left` が来たら、今と同じく絞り込みに使う（壊れたリンクにしない）。
- D5 案件・応募・契約・請求・メール・受信・AI・技術マスタの「状態」は変えない。
- D6 欄外の説明：共通の部品 `<x-status-legend kind="ses|account|work" />`（`kind=account` は `:eg="true"` でエンジニアだけの 2 行を足す）を、表・項目の外のすぐ下に出す（置き場所は 3.4）。中身は 3.4 の文。1 行に 1 つの値と短い説明。
- D7 説明の値の語は `<b>` で出し、`.badge` の `<span>` にしない（一覧の行のバッジと見分けるため。今のテストの「停止中</span>」の判定ともぶつからない）。説明の文に「仮登録中（期限切れ）」のような行の値そのものの組み合わせを書かない（ページ全体で「出ない」を確かめているテストがあるため）。

### E 全体
- E1 フッターの版が v0.23.0。
- E2 `php artisan test` が全部通る（今のテストの期待の文字列は、新しい言葉に直す。消してよいのは言葉の置き換えだけで、確かめている中身は減らさない）。
- E3 マニュアル 6 本と README が新しい言葉・新しい項目・［戻る］に合っている。

## 3. 仕様

### 3.1 戻るボタン（layouts/app.blade.php・public/js/park.js・public/css/park.css）

- レイアウトに変数 `$back` を足す（`@extends` の配列で渡す）：文字列＝戻り先の URL／`false`＝出さない／渡さない＝ history。
- 出す条件：`$u && $passed && !$print && !($noTitle ?? false) && !$noPersonal && ($back ?? null) !== false && !in_array($path, ['/admin', '/ses', '/eg'], true)`。
- 形：`.ttl` の `</h1>` のあとに `<a class="btn ghost small ttl-back noprint" href="…">戻る</a>`。history のときは `data-back="history"` を付け、`href` はその立場のダッシュボード（今の立場の `/admin`・`/ses`・`/eg`）。
- park.js：`[data-back=history]` を押したとき、`document.referrer` が同じ origin で `history.length > 1` なら `preventDefault()` して `history.back()`。そうでなければ `href` のまま。
- CSS：`.ttl` を `display:flex; align-items:center; gap` に、`.ttl-back { margin-left:auto; flex:none; }`。h1 は `min-width:0` で折り返す。印刷で隠す。
- 戻り先（`'back' => …` を各 view の `@extends` に足す。`$base`・`list_url` は今の書き方に合わせる）：

| 画面 | 戻り先 |
|---|---|
| 管理者：`/admin/reports`・`/admin/ai`・`/admin/admins`・`/admin/settings`・`/admin/audit`・`/admin/ses`・`/admin/engineers`・`/admin/providers`・`/admin/cases`・`/admin/applications`・`/admin/contracts`・`/admin/invoices`・`/admin/messages`・`/admin/mail`・`/admin/inbound` | `/admin` |
| `/admin/skills` | `/admin/engineers` |
| `/admin/admins/new`・`/admin/admins/{id}` | `/admin/admins` |
| `/admin/admins/{id}/edit` | `/admin/admins/{id}` |
| `/admin/ses/new` | `/admin/ses` |
| `/admin/ses/{id}` | `/admin/ses?view=company` |
| `/admin/ses/{id}/edit`・`/approve`・`/users/new`・`/users/{uid}` | `/admin/ses/{id}` |
| `/admin/mail/{id}` | `/admin/mail` |
| プロバイダー（管理者・SES 共通の views）：一覧 | その立場のダッシュボード |
| プロバイダー：受けた案件（原文）・登録・詳細 | プロバイダーの一覧（`$base`） |
| プロバイダー：編集 | その詳細 |
| SES：`/ses/engineers`・`/ses/cases`・`/ses/applications`・`/ses/contracts`・`/ses/invoices`・`/ses/company` | `/ses` |
| `/ses/company/edit`・`/ses/company/users/new` | `/ses/company` |
| エンジニア：`/eg/offers`・`/eg/applications` | `/eg` |
| `/cases/new`・`/cases/{id}` | `list_url('cases')` |
| `/cases/{id}/edit`・`/cases/{id}/mail`・案内の確認の画面・`/searches/{sid}`（エンジニアを選択） | `/cases/{id}` |
| `/searches/{sid}/compose`・`/searches/{sid}/members/{eid}` | `/searches/{sid}` |
| `/cases/{id}/threads/{eid}` | 管理者・SES：`/threads`、エンジニア：`/cases/{id}` |
| `/engineers/{id}` | 本人（エンジニア）：`/eg`、ほか：`list_url('engineers')` |
| `/engineers/{id}/edit`・`/skills`・`/projects/*`・`/profile`・`/intro` | `/engineers/{id}` |
| `/engineers/{id}/messages` | 本人：`/eg`、ほか：`/engineers/{id}` |
| `/engineers/new` | 今のフォームの［戻る］と同じ（`$sesFixed` があれば `/admin/ses/{id}`、無ければ `list_url('engineers')`） |
| `/applications/{id}` | `list_url('applications')` |
| `/interviews/{id}` | `/applications/{aid}` |
| `/contracts/{id}` | `list_url('contracts')` |
| `/inbound/{id}` | 管理者：`/admin/inbound`、SES：`/inbound` |
| `/mails/{id}` | `/mails` |
| `/provider-mails/{id}` | 案件があれば `/cases/{id}`、無ければ history |
| `/account/profile` | `/account` |
| `/people/{id}/edit` | 今の `$back` |
| `/manual/{kind}`（ログイン中） | `/manual` |
| `/threads`・`/inbound`・`/mails`・`/mails/new`・`/notifications`・`/account`・`/manual` | history（無ければダッシュボード） |

- `false` を渡す画面：`account/welcome`・`account/complete`・`eg/consent`・`two-factor/challenge`・`public/login-role`（どれも `$passed` が偽のことが多いが、念のため明示）。

### 3.2 呼び名の置き換え

- 置き換えの表（表示する文だけ。コメントは直さなくてよいが、直してもよい）：

| 今 | 新 |
|---|---|
| SES代表担当者 | PARK窓口担当者 |
| 次のSES代表担当者 | 次のPARK窓口担当者 |
| 会社情報 | 企業情報 |
| 会社名 | 企業名 |
| 会社の電話番号・会社の郵便番号・会社の住所 | 企業の電話番号・企業の郵便番号・企業の住所（`Requirements` 46 行） |
| 所属の会社・この会社・その会社・前の会社・新しい会社・会社の全員 など | 所属の企業・この企業・その企業・前の企業・新しい企業・企業の全員 |
| 一覧の列・ボタン「会社」、表示の切り替え［会社］、「会社の表示で見る」 | 「企業」、［企業］、「企業の表示で見る」 |
| 「SES の会社」 | 「SES 企業」 |
| 会社を代表して（守秘義務の同意） | 企業を代表して |
| `AccountController` の項目名 `'ses_name' => 'SES名'` | `'企業正式名称'` |
| `PublicController::apply` の項目名 `'name' => '会社名'` | `'企業名'` |
| `ProviderReply` の注意の種類「会社名」 | 「企業名」 |
| `Requirements::ROLE_NAMES['rep']` | `'PARK窓口担当者'` |

- 対象：`resources/views/**`、`app/**` の画面・メール・お知らせ・flash・検査の文（`RegistrationText`・`Requirements`・`Registration`・`Workflow`・`DirectMerge`（お知らせの文「所属の会社が…」）・`EgStaff`・`CaseThread`・`Inbound`・`ProviderReply`・`DirectMail`・`AdminController`・`SesController`・`PublicController`・`AccountController`・`EngineerController` ほか grep の結果）、`resources/manuals/*.md`、`README.md`、`tests/**` の期待の文字列。
- 変えないもの：社名・辞書の語（0 章）、DB の列・値・URL、`database/migrations` の中身（終わった移行）、`PLAN-*.md`。
- 検査テスト（新規 `tests/Feature/V0230WordingTest.php`）：`resources/views` の全 blade と `resources/manuals/*.md` を読み、Blade のコメント `{{-- … --}}` を除き、社名の語（`株式会社|有限会社|合同会社|子会社|証券会社|カード会社`）を消してから、「会社」と「SES代表担当者」が 0 件であること。`app/Support/RegistrationText.php`・`app/Support/Requirements.php` も同じ（PHP の `//` コメントを除く）。

### 3.3 企業代表者

- migrate `2026_11_28_000001_ses_companies_ceo.php`（足すだけ）：`ses_companies.ceo_same` boolean not null default 1（`rep_mei` のあと）、`ceo_name` string(50) nullable。down は 2 列を落とす。既存の行は default で 1。
- helpers.php に `ceo_label(object $s): string`：`ceo_same` が真なら `(Registration::repName($s)[0] ?: '—').'（窓口に同じ）'`、偽なら `ceo_name`（空なら `—`）。ST- は view 側で `<x-code :v="$repCode" />` を前に置く（「窓口に同じ」のときだけ）。
- 入力の部品 `resources/views/partials/ceo-field.blade.php`（`$same`・`$name` を受ける）：見出し「企業代表者（必須）」、ラジオ `name="ceo_same"` 値 `1`「窓口に同じ」・`0`「記入」＋テキスト `name="ceo_name"`（`maxlength=50`、ラベル「企業代表者の氏名」）。JS なしでも送れる形（氏名の欄は常に出しておき、「窓口に同じ」のときは保存で無視する）。
- 検査（共通の関数を `Registration` に 1 つ。例 `ceoInput(array $all)`：整えた `['ceo_same' => 0|1, 'ceo_name' => ?string]` か、断る文を返す）：`ceo_same` が無い→ 1（H5）。`0`・`1` 以外→「企業代表者の選び方が正しくありません。」。`0` で氏名が空→「企業代表者の氏名を入れてください。」、51 文字以上→「企業代表者の氏名は50文字以内で入れてください。」。`1` のときは `ceo_name` を null にして保存する。
- 保存する所：`Workflow::applySes`（申込。管理者が登録する場合も）、`Registration::provisionalSes`（仮登録・承認）、`editProvisionalSes`、`AdminController` の企業の保存（mode=pending・registered）、`SesController` の `/ses/company`（窓口だけ。今の権限の確認のまま）、本登録 `finishPending`（窓口の本登録のとき）、`saveMissing`。
- 本登録・足りない項目：`Requirements::fields('ses', isRep=true)` に `'ceo_same' => ['label' => '企業代表者', 'mode' => CONFIRM, 'store' => 'company']` を足す（`ses_name` の後ろ）。`values()` は `ceo_same=1` なら `'1'`、`0` で氏名ありなら `'0'`、`0` で氏名なしなら null（＝足りない）。`register-fields` は `ceo_same` のとき `partials.ceo-field` を出す。`rules()` は `ceo_same` を `nullable|in:0,1`、`ceo_name` を `nullable|string|max:50|required_if:ceo_same,0`。`checkNotEmpty` は `ceo_same` を飛ばす。一般担当者・エンジニア・管理者の項目は変えない。
- 承認の画面（`sesApproveForm`）：申込の行の `ceo_same`・`ceo_name` を `$v` に入れる。
- 表示：`admin/ses-show`・`ses/company` の企業情報の `dl` で、「PARK窓口担当者」の次に `<dt>企業代表者</dt><dd>…</dd>`。
- 交代のお知らせ：`RegistrationText` の交代の本文に、`ceo_same` のときだけ C5 の 1 文を足す（引数を 1 つ足す）。
- 監査：企業代表者を変えたら、今の企業情報の保存と同じ監査に含める（氏名は監査の文に書かない。「企業代表者を変更」だけ）。

### 3.4 状況の言葉と欄外の説明

- helpers.php：
  - `sesStatus` を `['pending' => '申込済み', 'provisional' => '仮登録中', 'active' => '参加中', 'suspended' => '停止中', 'left' => '退会済み']`。
  - `engStatus` の `unavailable` を `休止中` に（`left` は残す。H9）。
  - `user_state()` の返す語：`仮登録`→`仮登録中`（`（期限切れ）`・`（心当たりなし）` も `仮登録中（…）`）、`停止`→`停止中`。`engineer_state()` の `仮登録（未送信）`→`仮登録中（未送信）`、D3 の停止中。`is_provisional_state()` は「仮登録」で始まるかのままで通る。
  - **語で比べている所を全部直す**：helpers.php 176 行（`'仮登録（期限切れ）'`）、`AdminController` 18 行 `PERSON_STATUSES`（表示を 仮登録中・停止中）・55 行 `=== '仮登録'`・141 行 `=== '停止'`、views の `$state === '停止'`（`admin/admin-show`・`admin/admins`・`admin/ses-show`・`admin/ses-user-show`・`ses/company`）、`admin/ses-index` 13 行 `!== '仮登録'`、`engineers/show` 29 行、`DummySeed` の `SES_LABEL`・`STATE_LABEL`（表示用）。比べるのは語でなく定数にしてもよい（例：`const ST_SUSPENDED = '停止中'`）。
  - 稼働状況の表示の関数 `work_badge(object $e)`：`status === 'left'` なら `—`、ほかは `badge('engStatus', …)`。
  - 説明の文の正本（定数 `PARK_STATUS_LEGEND`）：

**参加状況（企業）**
- 申込済み：参加のお申し込みを受け付け、管理者の確認を待っています。
- 仮登録中：管理者が確認し、PARK窓口担当者の本登録（ご案内から7日以内）を待っています。
- 参加中：PARK を使える状態です。
- 停止中：管理者が一時的に止めています。この企業の担当者はログインできません（再開できます）。
- 退会済み：PARK をやめた企業です。記録だけ残ります。

**登録状況（アカウント）**
- 仮登録中：ご案内のメールを送り、本人の本登録を待っています。期限が切れたもの・心当たりなしと返されたものは、後ろに（ ）で書きます。
- 参加中：本登録が済み、ログインできます。
- 停止中：一時的にログインできなくしています（再開できます）。
- 退会済み：ログインできません。記録だけ残ります。
- （エンジニアだけ）未送信：ログインのご案内をまだ送っていないエンジニアです。
- （エンジニアだけ）エンジニアの項目が未入力：ログインはできますが、エンジニアとしての登録がまだです。

**稼働状況（エンジニアの仕事）**
- 待機中：案件を探しています。案件のご案内の候補に出ます。
- 提案中：案件に応募して、選考が進んでいます（応募すると自動でこうなります）。
- 稼働中：契約した案件で働いています（契約を登録すると自動でこうなります）。
- 休止中：今は案件を受けません。ご案内の候補に出ません（本人か担当者が選びます）。

- 部品 `resources/views/components/status-legend.blade.php`：`@props(['kind', 'eg' => false])`。`<div class="status-legend noprint"><p class="muted"><b>{見出し}</b>とは</p><ul><li><b>{値}</b>：{説明}</li>…</ul></div>`。小さめの字（CSS `.status-legend { font-size:.85em; margin:.4em 0 1em; }`）。
- 置き場所（表・項目の外のすぐ下）：

| 画面 | 出す説明 |
|---|---|
| 管理者 SES・担当者一覧（企業の表示）`admin/ses-index` | ses |
| 管理者 SES・担当者一覧（人の表示）`admin/ses-people` | account・ses（企業の列に参加中以外の札が出るため） |
| 管理者 企業の詳細 `admin/ses-show` | 企業情報のカードの `dl` の下に ses、担当者の表の下に account、エンジニアの表の下に work と account(eg) |
| SES 企業の詳細 `ses/company`（窓口・一般） | 同上 |
| エンジニア一覧（管理者・SES） | 表（とページ送り）の下に work と account(eg) |
| エンジニアの詳細 `engineers/show`（管理者・SES・本人） | 基本の `dl` の下に work と account(eg) |
| エンジニアの編集 `engineers/form` | 稼働状況の選択のすぐ下に work |
| エンジニアのホーム `eg/home` | 数字の札の下に work |
| 管理者一覧・管理者の詳細・担当者の詳細 | account |

- SES ダッシュボードの数字の表の下の説明文に「稼働中・提案可（待機中と提案中）は、エンジニアの稼働状況で数えます。」を足す（帯の「稼働状況」の語はそのまま）。

### 3.5 プレーヤーごとの見え方（まとめ）

| | 管理者 | PARK窓口担当者 | 一般担当者 | エンジニア |
|---|---|---|---|---|
| 戻るボタン | 出る | 出る | 出る | 出る |
| 新しい呼び名 | 見る | 見る（札が「PARK窓口担当者のアカウント」） | 見る | 見る（所属の「企業名」） |
| 企業代表者 | 全企業を見る・直す（申込済み・仮登録中・参加中） | 自社を本登録で確かめる・企業情報で直す | 自社を見るだけ | 出ない |
| 参加状況 | 見る | 自社を見る | 自社を見る | 出ない |
| 登録状況 | 見る | 自社の人を見る | 自社の人を見る | 自分（詳細） |
| 稼働状況 | 見る・直す | 自社を見る・直す | 自社を見る・直す | 自分を見る・待機中／休止中を選ぶ |

## 4. 警告（グループ全体に効く変更）

- W1 呼び名・状況の言葉は、**全企業の全員の画面と、これから送るメール・お知らせ**で一斉に変わる（「SES代表担当者」→「PARK窓口担当者」、「会社」→「企業」、「申込中」→「申込済み」ほか）。送信済みのメール・お知らせ・監査の文は古いまま（H10）。
- W2 公開版で migrate すると、**今ある全企業の企業代表者が「窓口に同じ」**になり、企業情報に PARK窓口担当者の氏名が企業代表者として出る（その企業の窓口・一般担当者と管理者に見える。他社には見えない）。実際の代表者と違う企業は、窓口か管理者が直す必要がある。各社に直してもらうよう**連絡するなら、メールの送信になるので TEZZO の了承が要る**（この版では送らない）。
- W3 窓口の交代で、「窓口に同じ」の企業の企業代表者の表示が自動で変わる（交代のお知らせに 1 文を足して知らせる）。
- 他社に情報が見えるようになる変更は無い。

## 5. テスト（`tests/Feature`。新規は V0230 で始める）

| AC | 立場と手順 | 確かめること |
|---|---|---|
| A1・A3 | 管理者で `/admin/ses/1` を開く | `ttl-back` が 1 つ、`href` が `/admin/ses?view=company`。`data-back` なし |
| A1・A3 | PARK窓口担当者で `/ses/company/edit`、一般担当者で `/ses/company`、エンジニアで `/eg/offers` | 戻り先が `/ses/company`・`/ses`・`/eg` |
| A3 | 管理者で `/notifications` | `data-back="history"` と `href` が `/admin` |
| A1 | 案件 `/cases/{id}` を管理者・SES・エンジニアで | 戻り先が各立場の案件一覧（`/admin/cases`・`/ses/cases`・`/eg/offers`） |
| A2 | `/admin`・`/ses`・`/eg`・`/`・`/start/ses`・`/login`・`/engineers/{id}/sheet`・確認コードの画面・兼任の人の `/login/role` | `ttl-back` が無い |
| A5 | `ttl-back` の要素 | `noprint` が付いている |
| B1・B2 | 管理者で `/admin/ses/1`、窓口で `/ses/company`、一般で `/ses/company` | 「企業情報」「PARK窓口担当者」が出て、「会社情報」「SES代表担当者」が出ない。窓口のヘッダーの札が「PARK窓口担当者」 |
| B2 | 管理者が窓口を交代（`POST /admin/ses/{id}/rep`） | お知らせの題が「PARK窓口担当者が変わりました」 |
| B2 | 窓口を仮登録して本登録の画面 `/invite/{token}` | 「PARK窓口担当者として仮登録されています」 |
| B4 | 3.2 の検査テスト | 0 件 |
| C1・C2 | `/apply` に `ceo_same=0`・`ceo_name` 空で POST | 断られ、`ses_companies` が増えない。氏名ありなら `ceo_same=0`・`ceo_name` が入る。`ceo_same` なしなら `ceo_same=1` |
| C2 | 管理者で `/admin/ses/{申込済み}/approve` を開く | 申込で入れた「記入」と氏名が初めから入っている。承認の POST で値が保たれる |
| C2 | 管理者で `POST /admin/ses` に `ceo_same=0`・氏名 51 文字 | 断られる（「50文字以内」） |
| C2 | 窓口の本登録 `/invite/{token}` の画面 | 「企業代表者」のラジオが出る。一般担当者・エンジニアの本登録の画面には出ない。POST → 確認コードのあとに値が保存される |
| C3 | 管理者 `/admin/ses/{id}`、窓口・一般 `/ses/company` | 「企業代表者」の行。「窓口に同じ」なら ST- と窓口の氏名と「（窓口に同じ）」、「記入」なら記入した氏名 |
| C4 | 一般担当者で `GET /ses/company/edit`・`POST /ses/company` | 今と同じく断られ、`ceo_*` が変わらない。エンジニアで `/eg`・`/engineers/{自分}` に「企業代表者」の語が出ない |
| C5 | 「窓口に同じ」の企業で窓口を交代 | `/ses/company` の企業代表者が新しい窓口の氏名。お知らせの本文に C5 の 1 文。「記入」の企業では 1 文が無く、表示も変わらない |
| C6 | migrate 後（テストの DB）の既存の企業 | `ceo_same=1`。窓口でログインしても `/register/complete` に回されない |
| D1・D2 | 管理者 `/admin/ses?view=company`・`/admin/ses?view=people`・`/admin/engineers`、窓口 `/ses/engineers`、エンジニア `/eg` | 列・見出しが「参加状況」「登録状況」「稼働状況」。これらの表の見出しに「状態」が無い（案件などの画面は除く） |
| D1 | 申込済みの企業・休止のエンジニア・期限切れの仮登録の人 | 「申込済み」「休止中」「仮登録中（期限切れ）」 |
| D3 | 管理者がエンジニアを停止して `/engineers/{id}`・`/admin/engineers` | 登録状況が「停止中」。稼働状況の欄に「停止中」が出ない |
| D4 | `/admin/engineers` の稼働状況の選択肢 | 「退会済み」が無い。`?status=left` でも 200 |
| D6・D7 | 3.4 の表の各画面 | `class="status-legend` と見出し（「参加状況」とは など）が出る。説明の値が `<b>` |
| E1 | どこかの画面 | `v0.23.0` |

- 今のテストで直すもの（grep で探す）：「会社情報」「会社名」「SES代表担当者」「次のSES代表担当者」、`user_state` の `'仮登録'`・`'停止'` の assertSame（`AdminToAdminRegistrationTest`・`AdminToSesRegistrationTest`・`Step1AcceptanceTest`・`RegistrationFlowsTest` ほか）、`SuspendLeaveTest` の「停止中</span>」（D3 で場所が変わる）、`V0220ThemeTest` 167 行の `.ttl` の正規表現（`</h1>` のあとに［戻る］が入る。ログイン前の `/login` は今のまま）、`EmptyStateTest`・`ProfileAndDirectTest` などの「停止」。期待の語を変えるだけで、確かめる中身は残す。

## 6. マニュアル・README・用語集

- `resources/manuals/admin.md`・`admin-steps.md`・`ses.md`・`ses-steps.md`・`engineer.md`・`engineer-steps.md`：
  - 3.2 の置き換え（全部）。
  - 共通：「画面の題の右の［戻る］で、一つ前の画面（一覧など）に戻れます。」を、各マニュアルのはじめの画面の説明に 1 行。
  - 管理者・SES：「参加状況・登録状況・稼働状況」の節（3.4 の説明を短く。どの画面に出るか）。
  - 管理者：仮登録・承認・企業情報の編集の手順に「企業代表者（窓口に同じ／記入）」。
  - SES：本登録（窓口）と企業情報の編集の手順に「企業代表者」。一般担当者は見るだけ、と書く。
  - エンジニア：「状態」→「稼働状況」（待機中・休止中の選び方）、「アカウント」→「登録状況」。
- `README.md`：言葉の置き換え、版、migrate が 1 本増えること。
- `04_Manuals` の写し（41_Park-Project）は Leo が写し直す。
- **用語集（41_Park-Project の `06_Glossaries/用語集.md`）で Leo が直す項目**：
  - 10 行「担当者」：「PARK窓口担当者と一般担当者」に。
  - 11 行「SES代表担当者」：「PARK窓口担当者」の行を作り（企業に 1 人。2026-11-28 TEZZO が SES代表担当者から改名）、旧名は「使わない（2026-11-26〜11-27 に使った）」に。
  - 12 行「一般担当者」：「PARK窓口担当者以外の担当者」に。
  - 13 行「代表者」：「代表者」単独は今までどおり使わない。新しく「**企業代表者**」（企業を代表する人。社長など。PARK のアカウントではない。窓口に同じ／記入。2026-11-28 TEZZO）を足し、窓口（PARK窓口担当者）とは別の人と書く。
  - 14 行「仮登録」：状態の語は「仮登録中」。
  - 16 行「プレーヤー」：「管理者・PARK窓口担当者・一般担当者・EG」。
  - 新しい行：「企業」（PARK の画面では「会社」と言わず「企業」。業態の SES はそのまま。2026-11-28 TEZZO）、「参加状況」「登録状況」「稼働状況」（3.4 と同じ意味）。
  - あわせて 41_Park-Project の `CLAUDE.md`（「プレーヤー」の節の「SES代表担当者」）、`.claude/agents/park-*.md` の「SES代表担当者」も Leo が直す。

## 7. 作業の手順（段階ごとにテストを通して commit）

**段階 1 戻るボタン**
1. レイアウトに `$back` と出す条件・`ttl-back` を足す（3.1）。park.js に history の動き、park.css に `.ttl` の flex と `.ttl-back`。
2. 3.1 の表どおり、各 view の `@extends` に `'back' => …`（または `false`）を足す。
3. `V0220ThemeTest` 167 行の正規表現を直す。新規 `V0230BackTest`（5 章の A）。

**段階 2 状況の言葉**
4. helpers.php：`sesStatus`・`engStatus`・`user_state`・`engineer_state`（D3）・`work_badge`・`PARK_STATUS_LEGEND`。
5. 語で比べている所を全部直す（3.4 の一覧。`'停止'`・`'仮登録`・`'参加中'` を grep して漏れがないか）。
6. 見出し・列・絞り込みの名前（D2）、エンジニアの絞り込みから退会済みを外す（D4）、稼働状況の欄の「停止中」をやめる（D3）。
7. `x-status-legend` を作り、3.4 の表の場所に置く。SES ダッシュボードの説明文。
8. 今のテストの期待の語を直す。新規 `V0230StatusTest`（D）。

**段階 3 呼び名**
9. `Requirements::ROLE_NAMES`・`RegistrationText`・各 Service・Controller の文を 3.2 の表で置き換える。
10. views・manuals・README を置き換える（社名の語は残す）。
11. 今のテストの期待の語を直す。新規 `V0230WordingTest`（B。3.2 の検査を含む）。

**段階 4 企業代表者**
12. migrate を作る（3.3）。テストの DB で通ることだけ確かめる（手元・公開版の migrate は Leo）。
13. `ceo_label`・`partials/ceo-field`・`Registration::ceoInput`。
14. 申込・仮登録・承認・仮登録中の直し・申込中の直し・本登録後の編集（管理者・窓口）・本登録・足りない項目に入れる（3.3）。
15. 企業情報の表示 2 画面、交代のお知らせの 1 文、監査。
16. 新規 `V0230CeoTest`（C）。

**段階 5 仕上げ**
17. マニュアル 6 本・README（6 章）。
18. `config/park.php` を `v0.23.0`。
19. `php artisan test` 全体。→ park-reviewer → park-tester。

**Leo がやること（実装の外）**：前の PLAN.md の退避（冒頭）、commit・push、公開版への反映（`park-deploy.sh`。migrate は列 2 つの追加だけ）、`04_Manuals` の写し、用語集・CLAUDE.md・agents の言葉、W2 の連絡をするかの TEZZO への確認、公開版の画面の確認（企業の詳細の企業代表者・［戻る］・状況の説明）。
