# PLAN：「SES・担当者一覧」（人の一覧）と、登録順・企業ごとの並び。呼び方を「SES代表担当者」「一般担当者」に（v0.21.0。2026-11-26）

> **これが今の計画**。前の計画は `PLAN-v0200.md`・`PLAN-v0190.md`・`PLAN-v0170.md`・`PLAN-v0110-dual.md`・`PLAN.md`（そのまま残す）。
> 版：`config/park.php` は `v0.20.0` → **`v0.21.0`**。
> 要求（TEZZO さん、2026-11-26。原文のまま）：「タブ、「SES一覧」を「SES・担当者一覧」とする。今までのただのSESはSES代表担当者、それ以外は一般担当者。「SES・担当者一覧」に一覧表示される。一覧は企業関係なく登録順と企業ごとにソートされた順番と両方表示できると良い。エンジニアも同様。」
> プレーヤー：管理者・SES代表担当者・一般担当者・EG の4つ（アプリではどちらの担当者も `users.role=ses`。SES代表担当者＝`ses_companies.rep_user_id` の人）。
> **DB の変更なし（マイグレーションなし）**。外部のライブラリは足さない。実在の名前・メールは書かない（テストは架空の名前、`@park.example`）。
> 作る順：段階1 → 7（段階ごとに `php artisan test` を通して commit）→ park-reviewer → park-tester。commit・push・公開版への反映は Leo。

## 0. コードで確かめた今の形（v0.20.0）と、Leo の解釈との突き合わせ

| 項目 | 今の形 |
|---|---|
| メニュー | `layouts/app.blade.php` の `$nav['admin']`：`['/admin/ses', 'SES一覧']`、`['/admin/engineers', 'エンジニア一覧']`。SES 側は `['/ses/company', 'SES']`（会社情報のタブ。会社の意味） |
| `/admin/ses` | `AdminController::sesIndex`／`admin/ses-index`。**行は会社**。名前順、`filter_left('ses')`、`page_slice`、［SES を仮登録］、行に 送り直し・停止・再開・退会・詳細（戻り先は `sesBack` → `list_back('/admin/ses', back_query)`）。検索・状態の絞り込みは**無い** |
| 人の行 | 会社の人＝`users.ses_id=会社` で `role='ses'`（代表と一般）＋担当者を兼ねる管理者（`role='admin'` かつ `ses_id` あり）。ID は `users.staff_code`（ST-）。代表は `ses_companies.rep_user_id`、交代の途中は `next_rep_user_id`（`Requirements::isRep()` は次の代表も代表として扱う） |
| 人の詳細 | `role='ses'` は `/admin/ses/{sid}/users/{uid}`（`sesUserShow`）、兼任の管理者は `/admin/admins/{id}` |
| 担当者がいない会社 | 申込中（`pending`。`rep_user_id` なし）、`park:move-ses` の元の会社など。会社の行にしか出ない |
| エンジニアの一覧 | 管理者 `AdminController::engineers`、SES 側 `SesController::engineers`。どちらも `sort_kana()`（あいうえお順。2026-11-13 から）。管理者の「SES」列は会社名だけ、両方の「担当者」列は氏名だけ（**ID が無い**） |
| 会社の画面の表 | `sesShow`：担当者は `role=ses` を順不同（`get()`）、兼任の管理者は別ループ。`SesController::company`：`role` で管理者を最後・`id` 順。エンジニアはどちらも `orderBy('name')` |
| 呼び方 | 人の「SES」：`Requirements::ROLE_NAMES['rep']='SES'`、`['staff']='SES のご担当者'`、`PARK_LABELS['role']['ses']='SES'`、バッジ「SES」「次の SES」、「SES の交代」、「SES 担当者」（兼任・ログインの立場・通知の見出し）など。`app/`・`resources/` に「担当者」726 か所、「SES の／に／が…」261 か所（会社の意味が多い） |

**Leo の解釈で直す点（1案に決めた）**
- 「担当者／SES担当者 → 一般担当者」の一律の置き換えはしない。今の「担当者」「SES 担当者」の多くは**代表も含む総称**（EG の担当者 `engineers.staff_user_id` は代表のこともある、ログインの立場「SES 担当者」は代表も選ぶ、「担当者がいません」「担当者経由」など）。**新しい語の体系は「担当者＝総称。その中に SES代表担当者 と 一般担当者」**とし、総称は「担当者」のまま残す（3.4）。
- 「次の SES」（交代の途中の人）は、区分は「一般担当者」、印は「次のSES代表担当者」とする（本登録が済むと代表になる。権限の判定 `isRep()` は今のまま）。

## 1. 受け入れ条件

| 番号 | 立場 | 条件 |
|---|---|---|
| A1 | 管理者 | メニューの「SES一覧」が「**SES・担当者一覧**」（URL は `/admin/ses` のまま）。ページの見出しも「SES・担当者一覧」 |
| A2 | 管理者 | `/admin/ses` の初期表示は**人の一覧**（担当者の表示）。1行＝1人。列：ID（ST-）・氏名・区分・会社（`<x-code>` SE-＋会社名。会社が参加中でなければ会社の状態のバッジ）・メール・状態・最終ログイン・［詳細］［会社］ |
| A3 | 管理者 | 区分：`rep_user_id` の人は「SES代表担当者」、ほかは「一般担当者」。`next_rep_user_id` の人には印「次のSES代表担当者」、兼任の管理者には印「管理者（兼任）」、エンジニア兼任には今の印「エンジニア兼任」 |
| A4 | 管理者 | ［詳細］は人の詳細（`role=ses` は `/admin/ses/{sid}/users/{uid}`、兼任の管理者は `/admin/admins/{id}`）、［会社］は `/admin/ses/{sid}` |
| A5 | 管理者 | 同じページの「会社」の表示（`/admin/ses?view=company`）で、今の会社の一覧がそのまま使える（行・列・［SES を仮登録］・送り直し・停止・再開・退会・詳細・退会済みの切り替え・件数）。「担当者」「会社」の表示はページ上のリンクで行き来する |
| A6 | 管理者 | 人の表示に、担当者がまだいない会社があれば、表の下に「担当者がまだいない会社が N 社あります。［会社の表示で見る］」（会社の数だけ。名前は出さない。退会済みの会社は切り替えに従う） |
| A7 | 管理者 | 人の表示に検索（氏名・ふりがな・ST-・メール・会社名・SE-）、区分の絞り込み（すべて／SES代表担当者／一般担当者）、状態の絞り込み（参加中・停止・仮登録・退会済み）、並び、退会済みの切り替えを1つのフォームで置き、同時に効く |
| A8 | 管理者 | 並びの選択：「**登録順**」（初期）・「**企業ごと**」・「名前順」。登録順＝会社に関係なく ID（ST-）の小さい順。企業ごと＝会社の ID（SE-）の小さい順に、会社の中は SES代表担当者 → 次のSES代表担当者 → 一般担当者（ST- 順）→ 兼任の管理者（ST- 順）。名前順＝今の `sort_kana()` |
| A9 | 管理者 | エンジニアの一覧 `/admin/engineers` に同じ並びの選択（登録順〈初期〉＝EG- の小さい順／企業ごと＝SE- 順、会社の中は EG- 順／名前順＝今のあいうえお順）。今の絞り込みフォームの中に置く |
| A10 | 管理者 | `/admin/engineers` の「SES」列を `<x-code>` SE-＋会社名、「担当者」列を `<x-code>` ST-＋氏名にする（名前と ID はセット） |
| A11 | SES代表担当者・一般担当者 | `/ses/engineers` に並びの選択「登録順（初期）／名前順」（自社だけなので「企業ごと」は出さない）。「担当者」列も `<x-code>` ST-＋氏名 |
| A12 | 管理者・SES代表担当者・一般担当者 | 1社だけの表（`/admin/ses/{id}` と `/ses/company` の担当者・エンジニアの表）には並びの選択を置かない。並びを固定：担当者は A8 の「会社の中」の順、エンジニアは EG- 順 |
| A13 | 全員 | v0.20.0 の退会済みの扱いが、人の一覧・会社の表示・エンジニアの一覧の並び替え・検索・絞り込み・ページ送りと一緒に効く（既定は隠す、`?left=1` でグレー、件数の文、隠した件数は検索・絞り込みに合うものだけ、状態「退会済み」を選べば切り替えに関係なく出す） |
| A14 | 管理者・SES代表担当者・一般担当者 | 並び・表示・検索を変えても、ページ送りのリンクと「表示する」のリンクがそれを引き継ぐ。並び・表示を変えるとページ 1 に戻る。一覧から押した停止・再開・退会のあと、戻った一覧が `view`・`order`・`left` などを保つ（`list_back` に鍵を足す） |
| A15 | 全員 | 人を指す呼び方を「SES代表担当者」「一般担当者」、総称は「担当者」にそろえる（画面・メール・お知らせ・操作のメッセージ・確認の文・マニュアル・`park:status` などの CLI の出力）。会社を指す「SES」はそのまま。DB の値（`role=ses` など）は変えない |
| A16 | 権限 | `/admin/ses`（両方の表示）は今どおり管理者だけ（SES代表担当者・一般担当者・EG は 403）。`/ses/engineers` は今どおり自社の EG だけ、EG は 403。`?view=`・`?order=` を何にしても見える範囲は広がらない |
| A17 | 個人情報 | 人の一覧のメールは管理者の画面だけ（今の SES の詳細と同じ範囲）。A6 の文は会社の数だけ。隠した行の名前・メールは HTML に出さない（サーバーで除く） |
| A18 | システム | `view`・`order`・`kind`・`status` が決まった値以外（空・配列・長い文字）は初期値として扱い、500 にしない |

## 2. 決めた仕様

### 2.1 URL と画面の置き場所
- `/admin/ses`（`sesIndex`）を1つの画面にし、`view` で切り替える：`view` なし＝**担当者**（人の一覧・新規）、`view=company`＝**会社**（今の一覧をそのまま）。上に `［担当者］［会社］` のリンク（今の表示は `btn`、もう一方は `btn ghost`。リンクは `left` だけ引き継ぎ、ほかは落とす）と［SES を仮登録］。
- 新しいビュー `admin/ses-people.blade.php`（人）を作り、今の `admin/ses-index.blade.php` は会社の表示として残す。`sesIndex` の中で `view` を見て振り分ける（メソッドを2つに分けてよい：`sesPeople()`・`sesCompanies()`）。
- 会社の行の操作（停止・再開・退会）の `back_query` は今どおり `request()->getQueryString()`（`view=company` を含む）。
- 「SES の一覧に戻る」（`admin/ses-show`）は「SES・担当者一覧に戻る」にし、`/admin/ses?view=company` へ。人の詳細 `admin/ses-user-show` にも「SES・担当者一覧に戻る」（`/admin/ses`）を足す。
- 人の一覧の行には操作ボタンを置かない（［詳細］［会社］だけ）。停止・退会などは今どおり人の詳細・会社の詳細から。だから人の一覧から戻る処理は無い。

### 2.2 人の一覧のデータ（`sesPeople`）
- 取得：`users as u` join `ses_companies as s`（`s.id = u.ses_id`、`s.kind = 'ses'`）、`u.role = 'ses'` または（`u.role = 'admin'` かつ `u.ses_id` あり）。`select u.*, s.code as ses_code, s.name as ses_name, s.status as ses_status, s.rep_user_id, s.next_rep_user_id`。直属の会社（`kind≠ses`）は今の一覧と同じく出さない。担当者から外れて `role=eg` になった人は出ない（今のまま）。
- 行に足す値：`kind`（`rep`／`general`）、`state`（`user_state()`）、`is_dual_admin`（`role==='admin'`）、`next_rep`。
- 退会済みの判定：新しい種類 `is_left_row('person', $row)` ＝ `!empty($row->left_at) || $row->ses_status === 'left'`（会社が退会済みなら、その人も退会済みとして隠す・グレー。兼任の管理者は `sesDelete` で `left_at` が入らないため）。状態の欄は `user_state()` のまま、会社の列に会社の状態のバッジが出る。
- 順番：取得 → 検索 `q`・区分 `kind`・状態 `status` → `filter_left($list, 'person', $status === 'left' || show_left())` → 並べ替え（2.4）→ `page_slice()`。
- 状態の値：`active`＝参加中、`suspended`＝停止、`provisional`＝`is_provisional_state()`、`left`＝`is_left_row('person')`。区分の値：`rep`・`general`。
- A6 の数：`kind='ses'` の会社で、上の取得条件の人が1人もいない会社の数（退会済みの会社は `show_left()` のときだけ数える）。

### 2.3 ヘルパー（`app/helpers.php`）
- `staff_kind(object $u, ?object $s = null): string`：`$s`（無ければ `ses_id` で読む）の `rep_user_id === $u->id` なら「SES代表担当者」、ほかは「一般担当者」。
- `list_order(array $allowed, string $default = 'reg'): string`：`request()->query('order')` が文字列で `$allowed` にあればそれ、なければ `$default`（A18）。
- `sort_reg(Collection $list, string $codeKey): Collection`：`$codeKey`（ST-・EG-）の小さい順、空は最後、同じなら `id`。
- `sort_by_company(Collection $list, string $codeKey, ?callable $rank = null): Collection`：`ses_code` の小さい順 → `$rank($row)`（人は 0 代表／1 次の代表／2 一般／3 兼任の管理者。エンジニアは無し）→ `$codeKey` の順。
- `list_back()` の残す鍵に `view`・`order`・`kind` を足す。
- `is_left_row()` に `'person'` を足す（上）。
- `PARK_LABELS['role']['ses']` を「担当者」に。

### 2.4 並び（A8・A9・A11・A12）
| 画面 | 選べる並び | 初期 |
|---|---|---|
| 人の一覧 `/admin/ses` | `reg` 登録順（ST- 昇順）／`company` 企業ごと／`kana` 名前順 | `reg` |
| 会社の表示 `/admin/ses?view=company` | 選択なし（今の名前順のまま） | — |
| `/admin/engineers` | `reg`（EG- 昇順）／`company`（SE- 昇順 → EG- 昇順）／`kana`（今の順） | `reg` |
| `/ses/engineers` | `reg`／`kana` | `reg` |
| `/admin/ses/{id}`・`/ses/company` の表 | 選択なし。担当者：代表 → 次の代表 → 一般（ST- 順）→ 兼任の管理者（ST- 順）。エンジニア：EG- 順 | — |

- 登録順は**古い順（ID の小さい順）**。ID は登録のときに順に振られるので、画面の ID の並びと一致する（新しい人は最後のページ）。
- エンジニアの一覧の初期は、今のあいうえお順から登録順に変わる。あいうえお順は「名前順」で選べる（機能は失わない）。
- 選択の部品：`<x-field name="order" type="select" :options="…" :value="…" />`。

### 2.5 フォームと引き継ぎ（A13・A14）
- 人の一覧と `/admin/engineers`：検索・絞り込み・並び・退会済みを**1つの GET フォーム**にまとめる（別のフォームにしない。送ると `page` は落ちる）。人の一覧のフォームには `view` を持たない（人の表示が初期のため）。
- `/ses/engineers`・会社の表示：`partials/left-toggle` を直し、今の問い合わせのうち `view`・`order`・`q`・`status`・`kind` を hidden で引き継ぐ（`left` と `page` 以外）。並びの選択は `/ses/engineers` ではこの部品に足す（引数 `orders` を渡したときだけ並びの選択を出す）。
- `partials/pager`・`partials/left-note` は今の問い合わせを引き継ぐので変更なし。

### 2.6 呼び方の置き換え（A15）
**語の体系**：担当者（総称）＝ SES代表担当者 ＋ 一般担当者。会社は「SES」。空白は入れない（「SES代表担当者」「次のSES代表担当者」）。

| 今の書き方の意味 | 新しい書き方 | 例 |
|---|---|---|
| 会社の代表の人を指す「SES」 | SES代表担当者 | バッジ「SES」、`ROLE_NAMES['rep']`、`<dt>SES</dt>`（会社の詳細・会社情報の代表の欄）、会社の一覧の列「SES」、「SES の姓・名」、「SES に仮登録のご案内を送りました」、「SES の交代」、「次の SES」、「SES は、停止・退会にできません」、「担当者の登録・停止・退会は、SES だけ」、`park:status` の「SES のいない会社」、ダッシュボードの未登録の行の「SES」 |
| 代表ではない人だけを指す「担当者」「SES のご担当者」 | 一般担当者 | `ROLE_NAMES['staff']`、［担当者を登録］（登録すると必ず一般）、「担当者を仮登録し…」、「（担当者は 403）」「担当者は見るだけ」、「エンジニアのアカウントに SES 担当者の役割を足しました」 |
| 代表も含む人の総称（「担当者」「SES・担当者」「SES 担当者」） | 担当者 | 「SES・担当者」→「担当者」、EG の担当者（`staff_user_id`）、表の見出し「担当者」、「使える担当者がいません」、「担当者経由」、通知の見出し「【SES 担当者あて】」→「【担当者あて】」、`AccountController`・`layouts/app` の `otherName['ses']`、`park:status` の「兼任（SES 担当者）」→「兼任（担当者）」 |
| 立場が1人に決まる所（ログインの立場の選択、アカウントの兼任の立場、ヘッダーの立場の札） | `staff_kind()` の結果＋（会社名） | `public/login-role`「SES 担当者（会社名）」→「SES代表担当者（会社名）」または「一般担当者（会社名）」、`account/show` の `$dualNames`、`layouts/app` の `rolepill`（`role=ses` のときだけ `staff_kind($u)`） |
| 会社を指す「SES」 | そのまま | 「SES を仮登録」「SES の詳細」「受注 SES」「SES ごと」「参加中の SES」「SES の停止・退会」、メニュー `/ses/company` の「SES」 |
| PARK の外の人 | そのまま | 「プロバイダーの担当者」「案件の担当者」「ご担当者 様」「担当者にご確認ください」（EG あての金額の文。総称なのでそのまま） |

**やり方**：一括置換はしない。`rg -n "SES|担当者" app resources/views resources/manuals` の当たりを1件ずつ上の表で分けて直す（コメントは直さなくてよい）。最初に直す決まった所：`Requirements::ROLE_NAMES`、`PARK_LABELS['role']`、`RegistrationText`（メールの文）、`Workflow`・`Registration`・`AdminController`・`SesController`・`EngineerController`・`Affiliation`・`Notifier` の戻りのメッセージ、ビュー `admin/ses-index`・`admin/ses-show`・`admin/ses-user-show`・`admin/ses-form`・`admin/ses-new`・`admin/ses-edit`・`admin/ses-user-new`・`ses/company`・`ses/company-edit`・`ses/user-new`・`public/login-role`・`account/show`・`engineers/show`・`layouts/app`、`Console/Commands/Status.php`・`MoveSes.php`・`SeedDummy.php`。
- `park:status` の行の名前が変わる（例「SES：SES代表担当者のいない会社（申込中を除く）」）。PARK の点検スクリプト（41_Park-Project の `.claude/scripts`）はこの文字を見ていない（確認済み）。
- テストの期待値で完全一致・部分一致が変わるもの（例 `assertSee('SES 担当者')`、メッセージ全文）は新しい語に直す。

### 2.7 名前と ID
- 人の一覧：ID 列 `<x-code :v="$u->staff_code" />`＋氏名、会社の列 `<x-code :v="$u->ses_code" />`＋会社名。
- 会社の表示の列「SES」（→「SES代表担当者」）：`$reps[$s->rep_user_id]` の `staff_code` を `<x-code>` で前に出す（代表がまだいない申込中は今どおり申込の氏名だけ、ID は「—」）。
- 会社の詳細・会社情報の「SES代表担当者」の欄も、代表の `staff_code` を `<x-code>` で前に出す。
- エンジニアの一覧の会社・担当者の列（A10・A11）。`AdminController::engineers` の select に `s.code as ses_code` を足す。担当者の ST- は `EgStaff::ofMany` が返す行の `staff_code`。

## 3. 境界の挙動

| 場面 | 挙動 |
|---|---|
| 申込中の会社（人なし） | 人の一覧に出ない。A6 の数に入る。会社の表示には今どおり出る |
| 仮登録の会社（代表は本登録前） | 代表の行が「SES代表担当者」・状態「仮登録（…）」で出る |
| `sesDelete` で退会済みの会社 | 人はどれも `left_at` があり、既定では隠す。`left=1` でグレー |
| 退会済みの会社の兼任の管理者（`left_at` なし） | `person` の判定で会社が退会済みなので隠す／グレー。状態の欄は `user_state()`（参加中など）、会社の列に「退会済み」のバッジ |
| `park:move-ses` の元の会社 | 人がいないので人の一覧に出ない。退会済みなので A6 の数にも既定では入らない |
| 代表の交代の途中 | 今の代表は「SES代表担当者」、次の人は「一般担当者」＋印「次のSES代表担当者」。企業ごとでは代表のすぐ後 |
| `rep_user_id` が空の会社（代表がいない） | その会社の人は全員「一般担当者」 |
| 停止中の会社・人 | 退会済みではない。隠さない・グレーにしない |
| `staff_code` が空の人（ふつうは無い） | 登録順・企業ごとの中で最後（`id` 順） |
| `?order=company` を `/ses/engineers` に付けた | 許していない値なので初期（登録順）。500 にしない |
| `?view=company&order=kana` | 会社の表示は並びの選択なし。`order` は無視（名前順のまま） |
| 一覧の会社の表示から［退会］ | 戻り先は `/admin/ses?view=company&…`（`left` などを保つ）。隠していれば行が消え、件数の文が増える |
| `/admin/engineers?order=company&left=1&page=2` から［停止］ | 戻り先が同じ問い合わせ（`list_back` の鍵） |
| SES代表担当者・一般担当者・EG が `/admin/ses?view=company`・`/admin/ses?order=company` | 403（今どおり） |
| 一般担当者が `/ses/engineers?order=kana&left=1` | 自社の EG だけ。他社は出ない |

## 4. 作業の手順

**段階1 ヘルパー**
1. `helpers.php`：`staff_kind()`・`list_order()`・`sort_reg()`・`sort_by_company()`、`is_left_row('person')`、`list_back()` の鍵に `view`・`order`・`kind`、`PARK_LABELS['role']['ses']='担当者'`。単体のテスト → commit。

**段階2 人の一覧と会社の表示（管理者）**
2. `AdminController::sesIndex`：`view` で振り分け。人は 2.2 のとおり（`sesPeople`）、会社は今の処理（`sesCompanies`）。
3. 新しいビュー `admin/ses-people`：表示の切り替えのリンク、［SES を仮登録］、1つの GET フォーム（`q`・`kind`・`status`・`order`・`left`）、表（A2〜A4）、`partials/pager`、`partials/left-note`、A6 の文。0 件の文は「担当者はまだいません。「SES を仮登録」から入れます。」（退会済みを隠して 0 行のときは件数の文だけ。v0.20.0 と同じ）。
4. `admin/ses-index`（会社の表示）：見出しを「SES・担当者一覧」、表示の切り替えのリンクを足す、列「SES」→「SES代表担当者」と ST-。
5. `layouts/app`：メニューを「SES・担当者一覧」→ テスト → commit。

**段階3 エンジニアの一覧の並び**
6. `AdminController::engineers`：`s.code as ses_code`、検索に SE- を足す、`list_order(['reg','company','kana'])` で並べ替え（`sort_kana` の所）。`admin/engineers`：フォームに並びの選択、会社と担当者の列に ID。
7. `SesController::engineers`：`list_order(['reg','kana'])`。`partials/left-toggle` に hidden の引き継ぎと並びの選択（2.5）。`ses/engineers`：担当者の列に ID → テスト → commit。

**段階4 1社の表の並び**
8. `AdminController::sesShow`・`SesController::company`：担当者の並びを A8 の「会社の中」の順、エンジニアを `sort_reg($engs, 'code')` に（`noUsable`・`repCandidates` などの計算は今のまま）→ テスト → commit。

**段階5 呼び方（2.6）**
9. 決まった所（`ROLE_NAMES`・`RegistrationText`・メッセージ・ビュー・CLI）を直す。`staff_kind()` を `login-role`・`account/show`・`layouts/app` の立場の札・ダッシュボードの `inviteRows` に使う。
10. 残りの当たりを 2.6 の表で1件ずつ。テストの期待値を直し `php artisan test` → commit。

**段階6 名前と ID の残り**
11. 会社の詳細・会社情報の代表の欄、人の詳細の戻るリンク（2.1・2.7）→ commit。

**段階7 版・README・マニュアル**
12. `config/park.php` を `v0.21.0`。README に「SES・担当者一覧と並び（v0.21.0。2026-11-26）」の節（メニュー名、人と会社の表示、並び、語の体系、マイグレーションなし、`park:status` の行の名前が変わったこと）。
13. マニュアル：`resources/manuals/admin.md`・`admin-steps.md`（メニュー名、人と会社の表示、検索・絞り込み・並び、エンジニアの一覧の並び、語）、`ses.md`・`ses-steps.md`（語、エンジニアの一覧の並び、会社情報の表の順）、`engineer.md`・`engineer-steps.md`（語だけ。「担当者」の意味を一言）。3つの `*.md` の冒頭近くに「担当者＝SES代表担当者と一般担当者」を1行 → commit。04_Manuals の写しと 41_Park-Project の用語集は Leo が直す。

## 5. テスト（`tests/Feature/V0210StaffListTest.php`）

用意：参加中の SES A（代表・一般2人・次の代表・退会済みの一般・兼任の管理者・EG 数人）、参加中の SES B（代表・一般）、申込中の SES C（人なし）、`sesDelete` で退会済みの SES D（代表・一般）と D を兼ねる管理者。ST-・EG- の番号は作った順に振られることを使う。名前は架空、メールは `@park.example`。

| 条件 | 手順 → 確かめること |
|---|---|
| A1 | 管理者で `/admin` → メニューに「SES・担当者一覧」、「SES一覧」が無い |
| A2・A3・A4 | 管理者で `/admin/ses` → A・B の人が1人1行。A の代表の行に「SES代表担当者」、一般に「一般担当者」、次の代表に「次のSES代表担当者」、兼任に「管理者（兼任）」。各行に ST- と SE-。［詳細］の URL（`/admin/ses/{A}/users/{uid}`、兼任は `/admin/admins/{id}`）と［会社］`/admin/ses/{A}` |
| A5 | `/admin/ses?view=company` → A・B・C の会社の行と［SES を仮登録］・停止・退会のボタン（今の `ListButtonsTest` と同じ確かめ）。列「SES代表担当者」に ST- |
| A6 | `/admin/ses` → 「担当者がまだいない会社が 1 社」（C）。`?left=1` でも D は人がいるので数は 1 |
| A7 | `?q=<B の会社名の一部>` → B の人だけ。`?kind=rep` → 代表だけ。`?status=provisional` → 次の代表だけ |
| A8 | `?order=reg` と初期 → 行の ST- が昇順。`?order=company` → A の代表 → A の次の代表 → A の一般（ST- 順）→ A の兼任の管理者 → B の代表 → B の一般（A が SE- で先）。`?order=kana` → `sort_kana` の順 |
| A9・A10 | 管理者で `/admin/engineers` → 初期は EG- 昇順。`?order=company` → SE- 順で、会社の中は EG- 順。会社の列に SE-、担当者の列に ST- |
| A11 | A の一般担当者で `/ses/engineers` → 初期は EG- 昇順、`?order=kana` であいうえお順。B の EG は出ない。並びの選択に「企業ごと」が無い |
| A12 | `/admin/ses/{A}`・A の代表で `/ses/company` → 担当者の表が代表 → 次の代表 → 一般 → 兼任の管理者の順、エンジニアが EG- 順。並びの選択が無い |
| A13 | `/admin/ses` 初期 → D の人・A の退会済みの一般・D を兼ねる管理者が出ず、「退会済み N 件は表示していません」。`?left=1` → `is-left` の行で出る。`?status=left` → 切り替えなしでも出る。`?q=<A の会社名>` → 隠した件数は A の退会済みだけ |
| A14 | 人 25 人で `?order=company` → 2 ページ目のリンクに `order=company`。「表示する」のリンクに `order`・`q` が残る。`/admin/ses?view=company&left=1` から `back=list`・`back_query=view=company&left=1` で［停止］→ 戻り先に `view=company` と `left=1`。`/admin/engineers?order=company` から［停止］→ 戻り先に `order=company` |
| A15 | A の代表に `Requirements::roleName` が「SES代表担当者」、一般が「一般担当者」。ログインの立場の画面で、兼任の一般担当者に「一般担当者（会社名）」。管理者で一般担当者を仮登録したメッセージが「一般担当者を仮登録し…」。`/admin/ses/{A}` にバッジ「SES」・「次の SES」・見出し「SES の交代」が無く、「SES代表担当者の交代」がある。`park:status` の出力に「SES代表担当者のいない会社」 |
| A16 | A の代表・一般・EG で `/admin/ses`・`/admin/ses?view=company`・`/admin/ses?order=company` → 403。EG で `/ses/engineers?order=kana` → 403 |
| A17 | A の代表で `/ses/engineers` の HTML に B の人の名前・メールが無い。管理者で `/admin/ses` 初期の HTML に D の人のメールが無い |
| A18 | `?view[]=company`・`?order=x`・`?order[]=reg`・`?kind=zzz`・`?status[]=left` → 200、初期の扱い |
| 回帰 | `/admin/ses/{A}` の「使える担当者がいません」の注意・交代の候補・次の代表の名前が今どおり |

前の版のテストで `/admin/ses` を会社の一覧として開いているもの（`ListButtonsTest`・`V0200LeftHiddenTest` など）は `?view=company` を付ける。最後に `php artisan test` 全体（既知の zip の1件以外が合格）。

## 6. 守ること

- DB の変更・データの削除・メールの送信はしない（この版には無い）。公開版への反映は常時了承の範囲（コードとキャッシュだけ。migrate は空）。
- **グループ単位の変更（警告）**：見える範囲は広がらない。ただし、メール・お知らせ・画面の**語が全社で変わる**（SES代表担当者・一般担当者が、次に届くメールから使われる）。送信の動き・宛先は変えない。
- 名前を出すところは ID とセット（`<x-code>`＋名前、タイトルは `id_title`）。A6・件数の文は数だけ。他社のエンジニアのイニシャルだけの表示（`CaseSearch` など）は今のまま。

## 7. TEZZO さんの判断が要る点

- **なし（進行を止めるものはない）**。次は仮置きで進め、違えば直す。
  - 仮置き 1：登録順は**古い順**（ID の小さい順。新しい人は最後のページ）で、人の一覧・エンジニアの一覧とも**登録順を初期**にする。エンジニアの一覧の今の初期（あいうえお順）は「名前順」で選べる。
  - 仮置き 2：総称は「担当者」のまま残し、代表だけ・一般だけを指す所を「SES代表担当者」「一般担当者」にする（一律に「一般担当者」へ置き換えない）。
  - 仮置き 3：今の会社の一覧は、同じタブの「会社」の表示（`/admin/ses?view=company`）に置く。人の一覧の行には停止・退会のボタンを置かない（人の詳細・会社の詳細から）。
