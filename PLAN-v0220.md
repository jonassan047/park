# PLAN v0.22.0：緑の基調・若枝・派手なトップ・区切りのページ

TEZZO の要望（2026-11-26）を、Leo の決定（仕様の方針。変えない）に沿って実装する。DB の変更なし。メール送信なし。

## 仮定
- H1 要望の「PG」はエンジニアのこと（Leo の仮置き）。
- H2 区切りのページの箇条書き・ステップの担当は、`resources/manuals/ses.md`・`engineer.md` に書いてある事実だけで作る。新しい約束・数字は足さない。
- H3 兼任の人（`is_dual()` で使える立場が2つ）は、ログイン直後に立場を選ぶ今の流れ（/ses → /login/role）を崩さない。既定の行き先は今のまま（`$u->home()`）とし、立場を選んだあとの /login/role の行き先も変えない。区切りのページは home の入口・リンクから開ける。
- H4 `/start/*` は公開の画面だが、ログイン中なら `ActingRole`（立場の差し替え）が効く前提。効かなければ、画面の判定で `session('acting_role')` を見る（下の 4-3）。

## 受け入れ条件（AC）
- AC1 どの立場（未ログイン・管理者・SES代表担当者・一般担当者・エンジニア）でも、地・文字・カード・ボタン・リンクの色は緑。青・紫の配色はどこにも出ない（下の AC2 の3か所を除く）。
- AC2 立場の見分けは、ヘッダー上の帯・ログイン中の帯（.loginbar）・役割の札（.rolepill）の色だけ。管理者=青、SES（代表・一般とも）=緑、エンジニア=紫。兼任の人は、選んだ立場の色。
- AC3 h1 を出す全画面で、題の左に若枝の丸い画像（`img/sprig.jpg`、alt=""）が出る。共通レイアウト1か所で出す。印刷用（$print）では出ない。
- AC4 ヘッダーのロゴの 🌳 が若枝の丸画像になる（alt=""）。
- AC5 幅 360px の画面でも、題・若枝・ヘッダーが重ならず、横にはみ出さない。
- AC6 ログイン前のトップ（/）が派手になる：大きな hero、濃い緑のグラデーション、白い大きな見出し、hero.jpg を大きく、若枝。文言は今のまま（足さない・削らない）。
- AC7 ダッシュボード3画面（/admin・/ses・/eg）の先頭に、共通 partial の帯（hero.jpg 背景＋若枝＋立場の一言）が出る。今の h1（ID と名前の題）はそのまま残る。
- AC8 GET /start/ses・/start/engineer がログインなしで開ける（200）。それ以外の /start/{x} は 404。
- AC9 区切りのページに大きく「ここからは SES の作業です」／「ここからはエンジニアの作業です」と、その立場でやることの箇条書き（マニュアルの事実だけ）が出る。DB の個人情報（名前・メール・会社名・ID）は一切出ない。
- AC10 区切りのページの下のボタン：その立場でログイン済み（2要素認証まで済み）なら「作業を始める（ダッシュボードへ）」→ /ses・/eg。ログイン前なら「ログイン」（/login）と「使い方」（/manual/ses・/manual/engineer）。別の立場でログイン中なら「今の画面へ戻る」（今の立場のホーム）と「使い方」。
- AC11 区切りのページに、仕事の流れの小さなメニュー（横並びのステップ。下の 4-2）が出て、各ステップに担当が小さく添えてあり、そのページの立場が担当するステップが強調される。「SES の作業」「エンジニアの作業」の切り替わりが見て分かる。画面の文は「EG」でなく「エンジニア」。一般担当者を抜かさない。
- AC12 home の2つの .spot のリンク先が /start/ses・/start/engineer になる。
- AC13 確認コードを通ったあとの既定の行き先：ses → /start/ses、eg → /start/engineer、admin → /admin（今のまま）。intended があるときはそちら、本登録が完了したとき（`Registration::$lastResult === 'done'`）は今のまま `$u->home()`。兼任の人は今のまま（H3）。`User::home()` と、ほかの redirect は変えない。
- AC14 フッターの版が v0.22.0。`php artisan test` が全部通る。

## 仕様

### 1. 基調色（public/css/park.css）
- `:root` を緑の配色の正本にする（今の緑のまま）。322・323 行の `body.role-admin`／`body.role-eg` の配色の上書き（--ink・--blue など）を消す。
- 新しい変数 `--role` を足す。`:root{--role: <緑>}`、`body.role-admin{--role:#3b6fb6}`、`body.role-ses{--role:<緑>}`、`body.role-eg{--role:#8a5bb5}`。
- `header.top` の上の帯（324 行）、`.loginbar`（329）、`.rolepill`（325）だけ `var(--role)` を使う。327 行（ロゴの影）は若枝の画像に合わせて直す。
- `.hero`（164 行と 283 行の2つの定義）は 4-1・3 の作りに合わせて整理する（どちらかに寄せ、他の画面で `.hero` を使っていれば崩さない。`grep "class=\"hero"` で確かめる）。

### 2. 若枝（resources/views/layouts/app.blade.php）
- 99 行：`<h1>{{ $title }}</h1>` を `<h1 class="ttl"><img class="sprig" src="{{ asset('img/sprig.jpg') }}" alt="">{{ $title }}</h1>` のように（`$print` のときは img を出さない）。h1 の文字（テキスト）は今と同じになること（IdTitleTest などが h1 の中身を見ている）。
- 63 行：`<span class="mark">🌳</span>` を若枝の丸画像（`<img class="mark sprig" … alt="">`）にする。
- CSS：`.sprig{width:40px;height:40px;border-radius:50%;object-fit:cover;flex:none}`、`h1.ttl{display:flex;align-items:center;gap:10px;flex-wrap:wrap}`。狭い画面（既存の @media）で 30px 程度。
- ダッシュボード・home など `noTitle` の画面は、各自の帯・hero に若枝を置く。

### 3. ダッシュボードの帯（新 partial）
- `resources/views/partials/role-hero.blade.php`（引数：`kind` = admin/ses/eg）。hero.jpg を背景（濃い緑の半透明を重ねて白い文字）＋若枝＋立場の一言。
- 一言（事実だけ、名前・ID は入れない）：管理者「グループの案件と、みなさんの進み具合を見守ります。」、SES「グループで、仕事を回す。」、エンジニア「合う案件が、届く。」（後の2つは home の .spot の見出しと同じ文）。
- `admin/dashboard.blade.php`・`ses/dashboard.blade.php`・`eg/home.blade.php` の @section('content') の先頭に `@include`。h1 はレイアウトのまま（その上に帯が来ても、帯の下に来てもよいが、h1 は消さない）。帯に h1 は使わない（`<div>`＋`<p>`）。

### 4. 区切りのページ
- 4-1 ルート：`routes/web.php` の公開の並び（`/manual/{kind}` の近く）に `Route::get('/start/{kind}', [Pub::class, 'start'])->whereIn('kind', ['ses','engineer']);`。他の値は 404。
- 4-1 画面：`resources/views/public/start.blade.php`（`noTitle`・`wide`）。上に派手な帯（home と同じ作り：濃い緑・若枝・白い大きな文字）で「ここからは SES の作業です」／「ここからはエンジニアの作業です」。次に小さなメニュー（4-2）、やることの箇条書き（4-4）、ボタン（AC10）。
- 4-2 小さなメニュー（`partials/flow-steps.blade.php`、引数 `kind`）。横並び、狭い画面では折り返す。今の立場が担当するステップは濃い緑の地＋白文字で強調し、「SES の作業」「エンジニアの作業」の見出しの境目を付ける。

| # | ステップ | 担当（小さく添える） |
|---|---|---|
| ① | 登録・準備 | SES（SES代表担当者・一般担当者）：エンジニアの登録／エンジニア：プロフィール・スキル・経験票／管理者：スキルシートの確認 |
| ② | 案件の登録・案内 | SES（代表・一般）・管理者 |
| ③ | 応募・確かめごと | エンジニア（返事・質問）・SES（代表・一般。書類を返す） |
| ④ | 面談 | SES（代表・一般。面談依頼・結果の記録）・エンジニア（面談スクリプトで準備） |
| ⑤ | 成約・契約 | SES（代表・一般。成約の記録）・エンジニア（経験票の更新）・管理者（契約の延長・終了、請求書） |

  - 強調：/start/ses は ①②③④⑤ のうち SES が担当するもの全部、/start/engineer はエンジニアが担当する ①③④⑤。担当の札は立場の色（AC2 と同じ3色）。表の担当の書き方は実装時に `ses.md`・`engineer.md`・`admin-steps.md` と突き合わせ、違えば マニュアルに合わせて直す（ステップ名は変えてよいが5つ以内）。
- 4-3 ボタンの判定（`PublicController::start`）：ログイン中 かつ `session(TwoFactor::SESSION_KEY) == $u->id`（または `TwoFactor::confirmed($u)`）かつ 今の立場（兼任なら `session('acting_role')`、でなければ `$u->role`）が `ses`/`eg` と一致 → 「作業を始める（ダッシュボードへ）」。兼任で立場が未選択なら「立場を選ぶ」→ /login/role。ログインしていない（またはコード未入力）なら「ログイン」「使い方」。別の立場なら「今の画面へ戻る」（その立場のホーム）「使い方」。
- 4-4 箇条書き（事実だけ。マニュアルの見出しから）：
  - SES：一般担当者の登録（SES代表担当者が行う）／エンジニアの登録・推薦文／案件の登録と、エンジニアへの案内メール／返信・やり取り／応募から成約まで（書類・面談依頼・結果・成約の記録）／契約と請求の確認。
  - エンジニア：プロフィール・スキル・経験票を整える／管理者に見てもらう／届いた案件に返事・質問する／面談スクリプトで準備する／成約したら経験票を更新する。
- 個人情報：コントローラから DB の値をビューに渡さない（立場の判定だけ）。

### 5. 入口と行き先
- `public/home.blade.php` 15・17 行の .spot の href を `url('/start/ses')`・`url('/start/engineer')` に。hero を AC6 の作りに（クラス `hero big` など。文言・リンクはそのまま、`.photo` の🌱の札も残す）。
- `TwoFactorController::challenge` 70 行：`redirect()->intended(url($this->startOf($u)))`。`startOf`：兼任（使える立場2つ以上）なら `$u->home()`、`ses`→`/start/ses`、`eg`→`/start/engineer`、それ以外は `$u->home()`。67〜69 行（本登録の完了）と 18 行（challengeForm）は変えない。

### 6. 版
- `config/park.php` の version を `v0.22.0`。

## 実装の手順
1. park.css：配色の上書きを消し、`--role` を足して帯・loginbar・rolepill に使う。`.sprig`・`h1.ttl`・`.hero.big`・`.role-hero`・`.flow-steps` の CSS を足す。
2. layouts/app.blade.php：ロゴと h1 に若枝（$print では出さない）。
3. home.blade.php：hero を派手に、.spot のリンク先を /start/* に。
4. partials/role-hero.blade.php を作り、ダッシュボード3画面の先頭に入れる。
5. ルート・`PublicController::start`・`public/start.blade.php`・`partials/flow-steps.blade.php`。
6. TwoFactorController::challenge の既定の行き先。
7. version を v0.22.0。
8. テストを足し（下）、既存テストを直す（下）。`php artisan test` が全部通るまで。
9. マニュアル：`ses.md`・`engineer.md` の「2回目からのログイン」に「ログインすると、まず『ここからは SES／エンジニアの作業です』の画面が出ます。『作業を始める』でホームへ」を足す。`ses-steps.md`・`engineer-steps.md` のログイン手順も同じ。`admin.md` は変更なし（管理者は /admin のまま）。写し（41_Park-Project の 04_Manuals）は Leo が写す。
10. README の画面一覧に /start/ses・/start/engineer があれば足す。

## 既存テストの直し
- 確認コードのあとに `assertRedirect(url('/eg'))`／`url('/ses'))` を見ているもので、**ふつうのログイン**かつ **intended がない**ものだけ /start/* に直す。直す前に、その前の行で `get('/eg')` などをしていないか（intended ができる）を見る。
  - 候補：`TwoFactorAuditTest` 63・113・123・133・173・197 行、`ParkTest` 182・204 行（180・201 行で `get('/eg')` をしているなら intended で /eg のまま＝直さない）。
  - 本登録の完了（招待からの登録）のもの（`ParkTest` 141、`Step1AcceptanceTest` 90・183・208、`AdminToSesRegistrationTest` 184、`RepresentativeTest` 154）は今のまま /ses・/eg のはず。落ちたら、本登録の完了かどうかを確かめてから直す。
  - `DualRoleTest`・`V0110AdminStaffDualTest`（兼任）は変わらないはず（H3）。
- `assertSee('🌳')` など、ロゴの文字を見ているテストがあれば直す（`grep -r "🌳" tests`）。

## 足すテスト（tests/Feature/V0220ThemeTest.php）
- AC1/AC2：admin・ses（代表）・ses（一般担当者）・eg でログインして / ダッシュボードを開き、`body` のクラスが `role-…`、park.css に `body.role-admin {` の --blue 上書きがなく `--role` の定義があること（CSS ファイルの文字列で確かめる）。
- AC3/AC4：ログインした詳細画面（例：/ses の会社の詳細）で `img/sprig.jpg` と `alt=""` が出る。印刷用の画面（請求書の印刷など、$print の画面）では h1 の若枝が出ない。h1 のテキストが今と同じ（IdTitleTest がそのまま通る）。
- AC6/AC12：未ログインで / を開き、`/start/ses`・`/start/engineer` へのリンクがあり、`/manual/ses` への .spot がない。今の文言（「同じ木の下で」など）が残っている。
- AC7：/admin・/ses・/eg に帯（`role-hero`）と、今の h1 が両方ある。
- AC8：未ログインで /start/ses・/start/engineer が 200、/start/admin・/start/x が 404。
- AC9：文言「ここからは SES の作業です」「ここからはエンジニアの作業です」が出る。ログイン中に開いても、そのユーザーの名前・メール・会社名・`SE-`/`EG-` の ID が出ない。「EG」の語が出ない。
- AC10：未ログイン → /login と /manual/ses のリンク。ses でログイン（コード済み）→ /start/ses に /ses へのボタン。eg でログイン → /start/ses は「今の画面へ戻る」(/eg)。コード未入力（パスワードだけ）→ ログインのボタン。兼任で立場未選択 → /login/role。
- AC11：/start/ses に5つのステップと「一般担当者」の語、強調のクラスが SES 担当のステップに付く。/start/engineer ではエンジニア担当に付く。
- AC13：ses1 でふつうにログイン → コードのあと /start/ses。eg1 → /start/engineer。admin → /admin。`get('/ses/…')` してからログイン → そのページ（intended）。招待からの本登録の完了 → /ses（今のまま）。兼任 → /ses（その先で /login/role）。
- AC14：フッターに v0.22.0。

## 了承が要るもの
- なし（データの削除・メール送信・.env の変更はしない）。公開版への反映は常時了承の範囲（Leo が park-deploy.sh）。画面の見た目（緑・若枝・派手さ）は TEZZO が自分で確かめる件として報告する。
