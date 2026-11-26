# PLAN：退会済みの SES・担当者・エンジニアを一覧でグレーにし、既定では隠す（v0.20.0。2026-11-26）

> **これが今の計画**。前の計画は `PLAN-v0190.md`・`PLAN-v0170.md`・`PLAN.md`（そのまま残す）。
> 版：`config/park.php` は `v0.19.1` → **`v0.20.0`**。
> 要求（TEZZO さん、2026-11-26。原文の「大会済み」は「退会済み」の変換間違い）：
> 1. 退会済みの SES・担当者・EG は、一覧ではグレーアウト表示にする。
> 2. 詳細画面でも、タイトルに「ID　名称」に続けて「退会済み」と明記する。
> 3. 一覧には「退会済みを表示しない／表示する」の選択を置き、「表示しない」が既定。
> プレーヤー：管理者・SES・担当者・EG の4つ（アプリでは SES と担当者はどちらも `users.role=ses`。SES＝会社の代表）。
> **DB の変更なし（マイグレーションなし）**。外部のライブラリは足さない。実在の名前・メールは書かない（テストは架空の名前、`@park.example`）。
> 作る順：段階1 → 6（段階ごとに `php artisan test` を通して commit）→ park-reviewer → park-tester。commit・push・公開版への反映は Leo。

## 0. コードで確かめた今の形（v0.19.1）

「退会済み」は、相手ごとに別の列で表している。**3つとも今の列で表せる（足す列はない）**。

| 相手 | 退会済みの印（正） | 入る所 | 備考 |
|---|---|---|---|
| SES（会社） | `ses_companies.status = 'left'` | 管理者の［退会］（`AdminController::sesDelete`）／`park:move-ses`（`MoveSes`） | `sesDelete` は会社・所属 EG（`engineers.status='left'`）・会社の人（`users.left_at`）を全部退会済みにする。**`park:move-ses` は会社だけを `left` にし、EG・担当者は付け替え先へ移す**（退会済みにならない）。だから move-ses の元の会社は「退会済み・EG 0 人・担当者 0 人」になる |
| 担当者 | `users.left_at IS NOT NULL`（`role='ses'`、または担当者を兼ねる管理者 `role='admin'` かつ `ses_id` あり） | `Workflow::leaveSesUser`／`sesDelete`／`leaveAdmin`（兼任の管理者） | 判定は `user_state()`（`helpers.php`）の先頭。エンジニアを兼ねる担当者の退会は `role` を `eg` に変えるだけで退会済みにならない（担当者の表から消える。今のまま） |
| EG | `engineers.status = 'left'` | EG の［退会］（`EngineerController::destroy`）／`sesDelete` | 本人のアカウントも `users.left_at` が入る。**ログインの無い EG（仮登録・未送信）は `engineers.status` だけ**。今の `engineer_state()` はアカウント基準なので、ログインの無い退会済み EG の「アカウント」欄が「仮登録（未送信）」になる（ずれ。段階1 で直す） |

- 表示の語は今「退会済」（`PARK_LABELS` の `sesStatus.left`・`engStatus.left`、`user_state()` の戻り値、操作のメッセージ・確認の文・マニュアル）。
- 一覧の件数：`x-table` は件数を出さない（0 件で空の文）。`partials/pager` が2ページ以上のとき「全 N 件」を出す。`page_slice()` は1ページ 20 件。
- 一覧から押す停止・退会の戻り先は `list_back()`（残す鍵は `q`・`status`・`ses`・`page`）。
- 選択肢（プルダウン）は、所属の変更・案件の持ち込み SES・SES の交代・EG の担当者の付け替え・連絡メールの宛先が、すでに参加中・使える人だけに絞っている（`status='active'`・`whereNull('left_at')`）。

## 1. 対象の画面（4プレーヤーごと）

### 一覧（グレー＋切り替え）
| 番号 | 画面（URL） | 見る立場 | 表 | 退会済みの判定 | コントローラ／ビュー |
|---|---|---|---|---|---|
| L1 | SES の一覧 `/admin/ses` | 管理者 | SES | `s.status='left'` | `AdminController::sesIndex`／`admin/ses-index` |
| L2 | エンジニアの一覧 `/admin/engineers` | 管理者 | EG | `e.status='left'` | `AdminController::engineers`／`admin/engineers` |
| L3 | SES の詳細 `/admin/ses/{id}` の「担当者」「エンジニア」の表 | 管理者 | 担当者（兼任の管理者の行も）・EG | `u.left_at`／`e.status='left'` | `AdminController::sesShow`／`admin/ses-show` |
| L4 | エンジニアの一覧 `/ses/engineers` | SES・担当者 | 自社の EG | `e.status='left'` | `SesController::engineers`／`ses/engineers` |
| L5 | 会社情報 `/ses/company` の「担当者」「エンジニア」の表 | SES・担当者 | 自社の担当者（兼任の管理者の行も）・自社の EG | `u.left_at`／`e.status='left'` | `SesController::company`／`ses/company` |

### 詳細（タイトルに「退会済み」）
| 番号 | 画面（URL） | 見る立場 | 判定 |
|---|---|---|---|
| D1 | SES の詳細 `/admin/ses/{id}` | 管理者 | `s.status='left'` |
| D2 | 担当者の詳細 `/admin/ses/{id}/users/{uid}` | 管理者 | `u.left_at` |
| D3 | エンジニアの詳細 `/engineers/{id}` | 管理者・自社の SES・担当者（本人は退会済みならログインできない） | `e.status='left'` |
| D4 | 会社情報 `/ses/company` | SES・担当者 | `s.status='left'`（退会済みの会社の人はログインできないので、ふだんは出ない。そろえるために入れる） |

### EG
- **対象の画面なし**。EG は SES・担当者・EG の一覧を持たない（自分の担当者は `EgStaff::of` が使える人に差し替えて出す）。退会済みの EG はログインできない。

### 対象にしないもの（今のまま）
- 案件・応募・契約・請求・案件のやり取り・連絡メール・受信メールの一覧、ダッシュボードの表、レポート：**記録の一覧**なので、退会済みの人の行も今のまま出す（切り替えを置かない）。
- 管理者の一覧 `/admin/admins`：要求が SES・担当者・EG のため対象外（語だけは「退会済み」に変わる。6章 判断 1）。
- EG の編集・スキル・経験票・プロフィール・推薦文・やり取りなどの下の画面のタイトル：詳細画面ではないので今のまま（状態の欄には「退会済み」が出る）。
- 選択肢（プルダウン）：今回の対象外。すでに参加中・使える人だけに絞っているので退会済みは出ない。過去の記録の絞り込み（`/threads` の SES の選択肢）は、退会済みの会社も残す（記録を探すため）。
- 件数の列：SES の一覧の「エンジニア」「稼働中」は今のまま（退会済みの EG も数える。記録の数）。

## 2. 受け入れ条件

| 番号 | 立場 | 条件 |
|---|---|---|
| A1 | 管理者・SES・担当者 | L1〜L5 の表で、退会済みの行はグレー（行に `class="is-left"`）。状態の欄は「退会済み」。名前の前の ID（`<x-code>`）もそのまま出す |
| A2 | 管理者・SES・担当者 | L1〜L5 を開いたとき、既定では退会済みの行を出さない（`left` の指定なし） |
| A3 | 管理者・SES・担当者 | L1〜L5 に「退会済みを表示しない／表示する」の選択（プルダウン、既定は「表示しない」）と［絞り込む］。「表示する」で `?left=1` になり、退会済みの行がグレーで出る |
| A4 | 管理者・SES・担当者 | 隠しているとき、退会済みが1件以上あれば表の下に「退会済み N 件は表示していません。」と［表示する］のリンク（今の条件のまま `left=1` を足す）。表示しているときは「退会済み N 件を含みます（グレーの行）。」。0 件なら何も出さない |
| A5 | 管理者 | L2 で状態の絞り込みに「退会済み」を選んだときは、切り替えが「表示しない」でも退会済みを出す（選んだ条件を優先） |
| A6 | 管理者 | L2 の名前・スキル・SES・ID の検索、状態の絞り込み、切り替えは同時に効く。隠した件数（A4）は検索・絞り込みに合う退会済みだけを数える |
| A7 | 管理者・SES・担当者 | ページ送りの「全 N 件」とページ数は、表に出す行だけで数える。切り替えるとページ 1 に戻る。ページ送りのリンクは `left` を引き継ぐ |
| A8 | 管理者・SES・担当者 | 一覧から押した停止・再開・退会のあと、戻った一覧は `left` を保つ（`list_back` に鍵 `left` を足す）。隠しているときに退会させた行は消え、A4 の文に数が入る |
| A9 | 管理者 | D1・D2・D3 のタイトル（ページの `<title>` と見出し）が `SE-000011　会社名　退会済み`／`ST-000003　氏名　退会済み`／`EG-000007　氏名　退会済み`（ID・全角空白・名前・全角空白・「退会済み」）。退会済みでなければ今のまま |
| A10 | SES・担当者 | D3（自社の退会済み EG）、D4（会社が退会済みのとき）のタイトルも A9 と同じ形 |
| A11 | 全員 | 画面・メール・マニュアルに出す語は「退会済み」にそろえる（バッジ・状態の欄・アカウントの欄・操作の結果・確認の文・断りの文・マニュアル）。DB の値（`left`）は変えない |
| A12 | 権限 | `left=1` を付けても、見える範囲は広がらない。SES・担当者は自社の行だけ（他社の退会済み EG・担当者は出ない）。L1〜L3 は管理者だけ（SES・担当者・EG は今どおり 403）。L4・L5 に EG が入れないのも今どおり |
| A13 | 個人情報 | A4 の文は件数だけ（名前・メールを出さない）。隠した行のメール・名前は HTML にも出さない（CSS で隠すのではなく、サーバーで除く） |
| A14 | システム | ログインの無い退会済み EG の「アカウント」欄は「退会済み」（今は「仮登録（未送信）」）。送り直し・取り消しは出さない（今どおり） |
| A15 | システム | `left` が `1` 以外（空・`0`・配列・長い文字）は「表示しない」として扱い、500 にしない |

## 3. 決めた仕様

### 3.1 判定（1か所に集める）
- `app/helpers.php` に足す：
  - `show_left(): bool` … `request()->query('left') === '1'`（文字列の `'1'` だけ真。A15）。
  - `is_left_row(string $kind, object $row): bool` … `'ses'`→`($row->status ?? '') === 'left'`、`'staff'`→`!empty($row->left_at)`、`'eg'`→`($row->status ?? '') === 'left'`。
- `engineer_state()`：先頭に `if (($e->status ?? '') === 'left') return '退会済み';`（A14）。

### 3.2 語「退会済み」（A11）
- `PARK_LABELS` の `sesStatus.left`・`engStatus.left` を「退会済み」、`user_state()` の戻り値を「退会済み」。
- ビューの比較 `'退会済'` を `'退会済み'` に直す（`admin/admin-show`・`admin/admins`・`admin/ses-show`・`admin/ses-user-show`・`ses/company`）。比べるのは文字ではなく、できれば `is_left_row()`・`!empty($u->left_at)` に置き換える。
- `app/`・`resources/views/`・`resources/manuals/` の「退会済」で、後ろに「み」が無いものは全部「退会済み」に（例：「退会済にしました」→「退会済みにしました」、「退会済の SES」→「退会済みの SES」）。`park:status`・`park:move-ses` の出力も合わせる。コメントは直さなくてよい。前の `PLAN*.md`・README の過去の版の節は直さない。
- テストの期待値で「退会済」と完全一致を見ているもの（`assertSame('退会済', …)`、メッセージ全文）は「退会済み」に直す。`assertSee('退会済')` は部分一致で通るのでそのまま。

### 3.3 タイトル（A9・A10）
- `id_title(?string $code, ?string $name, bool $left = false)`：`$left` のとき末尾に「　退会済み」（全角空白＋退会済み）。今の呼び出しはそのまま動く。
- D1 `admin/ses-show`・D4 `ses/company`：`id_title($s->code, $s->name, $s->status === 'left')`。D2 `admin/ses-user-show`：`id_title($u->staff_code, $u->name, !empty($u->left_at))`。D3 `engineers/show`：`id_title($e->code, $e->name, $e->status === 'left')`。

### 3.4 一覧の絞り込み（A2〜A8・A12・A13）
- **サーバーで行を除く**。除くのは、今の見える範囲のクエリ（会社で絞った後）の結果からだけ。クエリ自体は広げない。
- 順番：今の取得 → 検索・状態の絞り込み（L2）→ 退会済みを数えて、隠すなら除く → 並べ替え（今のまま：L1 名前順、L2・L4 あいうえお順、L3・L5 は今の順）→ `page_slice()`。
- 退会済みの行の並びは、今の並びのまま混ぜる（末尾に寄せない）。
- L2：`$st === 'left'` のときは隠さない（A5）。
- コントローラは `leftHidden`（隠した件数）・`leftShown`（出している退会済みの件数）・`showLeft` をビューに渡す。
- **L3（`sesShow`）は、`$users` を表示以外にも使っている**（`noUsable`・`repCandidates`・「次の SES」の名前）。これらは今の全件の `$users` のまま計算し、表には別の変数（例 `$usersShown`・`$dualAdminsShown`・`$engsShown`）を渡す。`working`・`inSel` も今のまま。
- L3・L5 は1つの画面に表が2つ（担当者・エンジニア）。**切り替えは画面に1つ**で、両方の表に効く。A4 の文は表ごとに出す。
- `list_back()` の残す鍵に `left` を足す（A8）。`partials/pager` は今の問い合わせを引き継ぐので変更なし（A7）。

### 3.5 画面の部品
- 新しい部分テンプレート `resources/views/partials/left-toggle.blade.php`：GET のフォーム（`class="filters"`）に `<x-field name="left" type="select" :options="['' => '退会済みを表示しない', '1' => '退会済みを表示する']" :value="show_left() ? '1' : ''" />` と［絞り込む］。L2 は今の絞り込みフォームの中に同じ選択だけを足す（別のフォームにしない。q・status と一緒に送る）。ほかの画面はこの部品を表の上（L3・L5 は右の列の先頭）に置く。
- 新しい部分テンプレート `resources/views/partials/left-note.blade.php`（引数：`hidden`・`shown`）：A4 の文。［表示する］のリンクは `url(request()->path()).'?'.http_build_query(array_merge(request()->except('page'), ['left' => '1']))`（`request()->query()` から作る）。
- 行：`<tr @class(['is-left' => is_left_row(…)])>`。
- `public/css/park.css`：`tr.is-left > td { background: #f2f2f2; color: #8a8a8a; }`、`tr.is-left .badge, tr.is-left a:not(.btn) { opacity: .7; }`。ボタン（詳細）は押せるまま。
- 空の表：退会済みを隠して 0 行になったときは、今の空の文（「SES はまだありません。…」など）の代わりに A4 の文だけを出す（「まだありません」と誤解させない）。

## 4. 境界の挙動

| 場面 | 挙動 |
|---|---|
| 全員が退会済みの会社（`sesDelete` 後）を L3 で開く | 表は空、A4「退会済み N 件は表示していません。［表示する］」。タイトルは「…　退会済み」 |
| `park:move-ses` の元の会社 | L1 で既定では出ない。表示するとグレーの1行（EG 0）。L3 の表はどちらも 0 件（移したため）で、A4 の文も出ない |
| 退会済みの担当者を兼ねる管理者（`role=admin`・`ses_id` あり・`left_at` あり） | L3・L5 の担当者の表で、担当者と同じく隠す／グレー |
| エンジニアを兼ねる担当者を担当者から外した | `role` が `eg` になり担当者の表から消える（退会済みではない。今のまま） |
| 停止中（会社の `suspended`・`users.active=0`・`engineers.suspended_at`） | 退会済みではない。隠さない・グレーにしない（今のバッジのまま） |
| 隠しているときに一覧から［退会］ | 戻った一覧から行が消え、A4 の件数が1つ増える。メッセージは「…退会済みにしました。」 |
| `?left=1&page=99` | `page_slice()` が最後のページに丸める（今どおり） |
| `?left[]=1`・`?left=yes` | 表示しない扱い（A15） |
| SES が `/ses/engineers?left=1` | 自社の退会済み EG だけ増える。他社は出ない（A12） |
| SES・担当者・EG が `/admin/ses?left=1`・`/admin/engineers?left=1` | 今どおり 403 |

## 5. 作業の手順

**段階1 判定と語**
1. `helpers.php`：`show_left()`・`is_left_row()` を足す。`id_title()` に第3引数。`engineer_state()` の先頭に退会済み。`PARK_LABELS`・`user_state()` を「退会済み」。`list_back()` に鍵 `left`。
2. `app/`・`resources/views/`・`resources/manuals/` の「退会済」（後ろに「み」なし）を「退会済み」に。ビューの `'退会済'` 比較を直す。
3. テストの完全一致の期待値を直し、`php artisan test` を通す → commit。

**段階2 詳細のタイトル**
4. `admin/ses-show`・`admin/ses-user-show`・`engineers/show`・`ses/company` の `id_title` に退会済みを渡す → テスト → commit。

**段階3 部品と CSS**
5. `partials/left-toggle`・`partials/left-note`、`park.css` の `tr.is-left` → commit。

**段階4 管理者の一覧（L1・L2）**
6. `AdminController::sesIndex`：数える・隠す・`page_slice`。`admin/ses-index`：切り替え・行のクラス・A4・空の文。
7. `AdminController::engineers`：検索・状態のあとに隠す（`$st==='left'` は隠さない）。`admin/engineers`：絞り込みフォームに選択、行のクラス、A4 → テスト → commit。

**段階5 SES の詳細と SES 側（L3・L4・L5）**
8. `AdminController::sesShow`：表示用の変数を分ける（3.4 の注意）。`admin/ses-show`：切り替え・2つの表・A4。
9. `SesController::engineers`・`company`：同じ。`ses/engineers`・`ses/company` → テスト → commit。

**段階6 版・README・マニュアル**
10. `config/park.php` を `v0.20.0`。README に「退会済みの表示（v0.20.0。2026-11-26）」の節（何が変わったか・マイグレーションなし）。
11. マニュアル：`resources/manuals/admin.md`・`admin-steps.md`（SES の一覧・エンジニアの一覧・SES の詳細の切り替え、グレーの行、詳細のタイトル）、`ses.md`・`ses-steps.md`（エンジニアの一覧・会社情報の切り替え）。`engineer.md` は変更なし（EG の画面は変わらない）。04_Manuals の写しは Leo が写し直す → commit。

## 6. テスト（`tests/Feature/V0200LeftHiddenTest.php`）

用意：参加中の SES A・B、退会済みの SES C（`sesDelete` で作る）、move-ses の元の会社 D（`status='left'`・人なし）。A に参加中の EG・退会済みの EG（ログインあり）・退会済みの EG（ログインなし、`engineers.status='left'` だけ）、参加中・退会済みの担当者、退会済みの兼任の管理者（`role=admin`・`ses_id=A`・`left_at`）。B に退会済みの EG。名前は架空、メールは `@park.example`。

| 条件 | 手順 → 確かめること |
|---|---|
| A1・A2・A3 | 管理者で `/admin/ses` → C・D の ID・名前が出ない。`?left=1` → C・D が `is-left` の行で出て「退会済み」。A を `is-left` にしない |
| A1〜A3 | 管理者で `/admin/engineers` → 退会済み EG が出ない／`?left=1` で `is-left` の行。`/admin/ses/{A}` の担当者・エンジニアの表も同じ（兼任の管理者の退会済みの行も） |
| A1〜A3・A12 | A の SES で `/ses/engineers`・`/ses/company`（担当者・エンジニアの表）→ 既定で出ない／`?left=1` で出る。どちらでも B の退会済み EG は出ない。A の担当者（代表でない）でも同じ |
| A4 | 既定で「退会済み N 件は表示していません。」と `left=1` のリンク。`?left=1` で「退会済み N 件を含みます」。退会済みが無い会社では文が出ない。文に退会済みの人の名前・メールが含まれない（A13） |
| A5・A6 | `/admin/engineers?status=left` → 切り替えなしでも退会済みが出る。`?q=<退会済みの名前の一部>` → 0 行で A4 の件数が 1 |
| A7 | 参加中 EG 21 人＋退会済み 3 人 → 既定で「全 21 件」・2 ページ、`?left=1` で「全 24 件」。2 ページ目のリンクに `left=1` が付く |
| A8 | `/admin/engineers?left=1` から `back=list`・`back_query=left=1` で［退会］→ 戻り先の URL に `left=1` |
| A9・A10 | `/admin/ses/{C}`・`/admin/ses/{A}/users/{退会済み}`・`/engineers/{退会済み}` の `<title>` と見出しに `<code>　<name>　退会済み`。参加中の相手では「退会済み」が付かない。A の SES で `/engineers/{A の退会済み}` も同じ |
| A11 | `user_state()` が「退会済み」。管理者の EG の［退会］のメッセージが「退会済みにしました」 |
| A12 | SES・担当者・EG で `/admin/ses?left=1`・`/admin/engineers?left=1` → 403。EG で `/ses/engineers?left=1` → 403 |
| A13 | 既定の `/ses/company` の HTML に、退会済みの担当者のメールが含まれない |
| A14 | ログインなしの退会済み EG の行の「アカウント」が「退会済み」、［送り直し］が無い |
| A15 | `?left=0`・`?left[]=1`・`?left=yes` → 200 で隠したまま |
| 回帰 | L3 で、退会済みを隠しても「使える担当者がいません」の注意・SES の交代の候補・「次の SES」の名前が今どおり（`sesShow` の全件で計算） |

最後に `php artisan test` 全体（既知の zip の1件以外が合格）。

## 7. 守ること

- DB の変更・データの削除・メールの送信はしない（この版には無い）。公開版への反映は常時了承の範囲（コードと キャッシュだけ。migrate は空）。
- **グループ単位の変更：なし**（見える範囲は広がらない。他社に見える・動くものは無い）。
- 名前を出すところは ID とセット（今の `<x-code>`＋名前、タイトルは `id_title`）。A4 の文は件数だけ。

## 8. TEZZO さんの判断が要る点

- **なし（進行を止めるものはない）**。次の1点は仮置きで進める。
  - 判断 1（任意）：管理者の一覧 `/admin/admins` は要求の外なので、今回はグレー・切り替えを入れない（語だけ「退会済み」になる）。同じ形にそろえたければ次の版で足す。
