# PLAN：担当者ごと・エンジニアごとの「停止」「退会」（2026-10-20）

## 背景

tezzo の要求（2026-10-20）：停止と退会を「担当者ごと」「エンジニアごと」に置く。

1. SES 詳細の「担当者のアカウント」に、担当者ごとの「停止」「退会」を置く（ボタン名に「する」は付けない。カードの操作ボタン「担当者を登録」の左側に置く流儀に合わせる）。
2. エンジニアにも「停止」を足す（退会は今ある）。エンジニア詳細の「編集」の左に、停止・退会を並べる。
3. 停止の効果と、解除（再開）の手段を決める。退会は元に戻せない今の方針に合わせる。最後の担当者を止めたときなどの境界、権限、稼働中の契約がある場合を整理する。

前の計画（管理者の情報・登録と招待）は実装済み。この計画で上書きした（経緯は 41_Park-Project の記録）。

今のつくり（調べた結果）：

- 会社単位：`AdminController::sesSuspend`（`ses_companies.status = suspended`。担当者は `PublicController::login` の会社の状態チェックでログインできない。再開は「承認して招待を送る」）、`sesDelete`（会社・所属エンジニアを `left`、担当者・エンジニアの `users.active = 0`。稼働中の契約があれば断る）。
- エンジニア：`EngineerController::destroy`（`/engineers/{id}/delete`、`role:admin,ses`。`engineers.status = left`、`users.active = 0`。稼働中の契約があれば断る）。停止は無い。
- `users.active = 0` は「停止」と「退会」の両方で使われていて、区別できない。
- ログインの関門：`Auth::attempt(... 'active' => 1)`、`Role` ミドルウェアが毎回 `active` を見る（止めたら次の操作で締め出される）、`forgot` も `active = 1` だけ。`N::sesUsers`・`N::egUsers`・`N::admins` は `active = 1` だけに知らせる。
- 穴：`W::userByToken` は `active` を見ない（止めた招待中の人がリンクでパスワードを設定できる）。`W::invite` は停止中・退会済の人にも招待を出し直す。`Matcher::forCase(..., true)`（案件メールの候補）と `CaseMail::candidates` は `left` を除いていない。`W::refreshEngineerStatus` は `left` を `available` に戻しうる。
- 配信候補：`Matcher::forCase`（`s.status = active`、`includeBusy` でなければ `available`・`proposing`）、`CaseMail::candidates`（`unavailable` を除く）、`W::distribute`（除外なし）、`CaseController` 83 行目（SES の応募画面の自社エンジニア。`unavailable` を除く）、`W::apply`（状態の確認なし）。

守ること：Laravel 12・Blade・`DB::table`、業務の手続きは `app/Services`、画面の文は日本語のやさしい言い方、外部ライブラリは足さない。PLAN・コード・テストに実在の人の名前・メールアドレスを書かない（テストは `@park.example` と `@example.com` だけ）。

## tezzo に決めてほしいこと（推奨案で実装を進め、返事で違えば直す）

**1** SES の担当者を、SES 自身（同じ会社の担当者）が止められるか
- 背景：担当者を増やすのは、今は管理者だけ（SES のマニュアルは「管理者にご連絡ください」）。
- a. 管理者だけ（推奨。増やすのと同じ立場が止める。SES の画面は変えない）
- b. SES も自社の担当者を止め・退会にできる（ただし自分自身は不可）

**2** エンジニアの停止・再開を、所属の SES もできるか
- 背景：エンジニアの退会は、今も管理者と所属の SES の両方ができる。
- a. 管理者と所属の SES（推奨。退会と同じ）
- b. 管理者だけ

**3** その会社の「使える担当者」が最後の1人のときに、停止・退会を許すか
- 背景：止めると、その SES には誰もログインできず、PARK からのお知らせも誰にも届かなくなる。
- a. 許す。確認の文で「この会社で使える担当者がいなくなります」と知らせ、SES 詳細に注意を出す（推奨。管理者が分かって押すため。会社ごと止めるときと同じ扱い）
- b. 断る（先に別の担当者を登録してもらう）

**4** 稼働中の契約があるエンジニアを、停止できるか
- 背景：退会は今、稼働中の契約があると断っている。停止は元に戻せる。
- a. できる（推奨。乗っ取りなど急ぎで止めたいときに断らない。確認の文で「稼働中の契約があります。契約・請求はそのまま続きます」と知らせる）
- b. 断る（退会と同じ）

**5** 退会した人のメールアドレスで、もう一度登録できるか
- 背景：`users.email` は重複できない。退会は記録を残す方針。
- a. できない（推奨。今のまま「このメールアドレスはすでに使われています」。戻したい人は別のアドレスで登録する）
- b. 退会した人のアドレスを空けて、登録し直せるようにする

## 仮定（tezzo が違えば直す）

- a. 停止・退会・再開をしても、止められた本人にはメールもお知らせも送らない（本人はもう使えないため）。代わりに、相手側にアプリ内のお知らせだけ出す（管理者が止めたエンジニア → 所属の SES、SES が止めたエンジニア → 管理者。メールは送らない＝`mail=false`）。担当者の停止・退会は管理者だけが行うので、お知らせは出さない。
- b. ログイン画面の文は今のまま（「メールアドレスかパスワードが違います。」）。止められたことは画面に出さない。
- c. 停止したエンジニアの「選考中の応募」はそのまま残す（自動で辞退にしない）。管理者・SES は進められる。確認の文で件数を知らせる。
- d. 「再開」のボタン名は「再開」（「する」は付けない）。退会済の人には何のボタンも出さない。
- e. 担当者の停止・退会は、その SES の会社の状態（参加中・停止・申込中）にかかわらずできる。会社が参加中でなければ、再開してもログインはできない（会社の関門が先）。会社が退会済なら、担当者は全員退会済なのでボタンは出ない。
- f. エンジニアの停止は、アカウントが無い（招待していない）エンジニアにもできる（配信の候補から外すため）。

## 受け入れ条件

### A. データ

- A1. マイグレーション `2026_10_20_000003_suspend_and_leave.php`：`users.left_at`（datetime、null 可）と `engineers.suspended_at`（datetime、null 可）を足す。
- A2. 同じマイグレーションで今のデータを直す：`active = 0` のうち、エンジニアの `status = left` の `role = eg`、会社の `status = left` の `role = ses` の人に `left_at` を入れる（それ以外の `active = 0` は「停止」のまま）。`down` は2列を消す。
- A3. 状態の決め方（1か所の関数 `user_state($u)` を helpers か `W` に置く）：`left_at` あり →「退会済」、`active = 0` →「停止」、`password` あり →「使用中」、それ以外 →「招待中」。

### B. 担当者（SES の users）の停止・再開・退会（管理者だけ。決めごと 1-a）

- B1. SES 詳細（`/admin/ses/{id}`）の「担当者のアカウント」の表に、操作の列を足す（見出しは空）。状態の列は A3 の言葉（今の「使用中／招待中」だけを置き換える）。
- B2. 行の操作：使用中・招待中の人 →「停止」（`ghost small`）「退会」（`danger small`）の順。停止の人 →「再開」（`small`）「退会」（`danger small`）。退会済 → 何も出さない。カードの右上は今のまま「担当者を登録」だけ（行の操作は、その左側＝表の中に置く）。
- B3. `POST /admin/ses/{id}/users/{uid}/suspend`：`users.active = 0`、`token`・`token_expires` を null（招待リンクを使えなくする）。監査 `ses-user-suspend`（中身は会社名と担当者名。メールは書かない）。戻り先は SES 詳細、「{名前} さんを停止しました。」（ok）。
- B4. `POST /admin/ses/{id}/users/{uid}/resume`：`active = 1`。監査 `ses-user-resume`。「{名前} さんを再開しました。」（ok）。パスワード未設定（招待中だった）の人は、再開だけでは招待リンクが無いので、文に「招待を送り直すには、『担当者を登録』で同じメールアドレスを入れてください。」を足す（`sesUsers` が同じ人に招待を出し直す今の動きを使う）。
- B5. `POST /admin/ses/{id}/users/{uid}/delete`：`active = 0`・`left_at = now`・`token` を null。監査 `ses-user-leave`。「{名前} さんを退会済にしました。」（ok）。元に戻すボタンは無い。
- B6. 境界：`uid` がその SES の `role = ses` でない（別の会社の人・管理者・エンジニア・無い番号）→ 404。すでに停止の人の停止・すでに使用中の人の再開 →「すでに停止中です。」「すでに使えます。」（ok、何も変えない）。退会済の人への停止・再開・退会 →「退会済の人は操作できません。」（bad、何も変えない）。二重押しは、この判定で害が無い。
- B7. 最後の1人（決めごと 3-a）：その会社で `active = 1` の担当者が対象の人だけのとき、確認の文に「この会社で使える担当者がいなくなります（PARK からのお知らせも届かなくなります）。」を足す。止めた後の SES 詳細の「担当者のアカウント」の上に注意「使える担当者がいません。担当者を登録するか、再開してください。」を出す。
- B8. 確認の文：停止「{名前} さんを停止します。ログインできなくなります（再開できます）。」、退会「{名前} さんを退会済にします。ログインできなくなり、元に戻せません（記録は残ります）。よろしいですか？」、再開「{名前} さんを再開します。」。
- B9. 稼働中の契約は担当者の停止・退会を妨げない（契約は会社のもの）。
- B10. SES・エンジニアの立場で B3〜B5 を送る → 403（`role:admin` の中に置く）。

### C. エンジニアの停止・再開（管理者と所属の SES。決めごと 2-a）

- C1. エンジニア詳細（`/engineers/{id}`）の「基本情報」の右上の並び：〔停止 または 再開〕〔退会〕〔編集〕〔招待を送る〕。停止は `ghost small`、再開は `small`、退会は今のまま `danger small`。本人（`eg`）の画面には出さない（今の退会と同じ条件）。退会済のエンジニアには停止・再開・退会を出さない。停止中は「招待を送る」を出さない。
- C2. `POST /engineers/{id}/suspend`（`role:admin,ses`、`load()` で所属の SES だけ）：`engineers.suspended_at = now`、そのエンジニアの `role = eg` の users を `active = 0`・`token` null。`engineers.status` は変えない。監査 `engineer-suspend`。「エンジニア「{名前}」を停止しました。」（ok）。仮定 a のお知らせ（管理者が押したら所属の SES へ、SES が押したら管理者へ。直属の SES には出さない＝`W::isDirect`）。
- C3. `POST /engineers/{id}/resume`：`suspended_at = null`、eg の users を `active = 1`（`left_at` が無い人だけ）。監査 `engineer-resume`。「エンジニア「{名前}」を再開しました。」（ok）。お知らせは C2 と同じ向き。
- C4. 稼働中の契約があっても停止できる（決めごと 4-a）。確認の文に「稼働中の契約があります。契約・請求はそのまま続きます。」を足す。選考中の応募（`applied`〜`interviewed`）があれば「選考中の応募 N 件はそのまま残ります。」を足す（仮定 c）。基本の文は「このエンジニアを停止します。ログインできなくなり、案件の配信の候補に出なくなります（再開できます）。」。
- C5. 境界：すでに停止中の停止・停止していない人の再開 →「すでに停止中です。」「停止していません。」（ok、何も変えない）。退会済 →「退会済のエンジニアは操作できません。」（bad）。他社の SES・エンジニア本人 → 403（他社は `Perms::engineer`、本人は `role`）。
- C6. 詳細の「状態」に、停止中なら状態のバッジの横に「停止中」（`badge` の mute と同じ見た目）を出す。管理者の「エンジニア」一覧と、SES の「エンジニア」一覧の状態の列にも同じ表示。SES の一覧の「アカウント」は A3 の言葉（使用中・招待中・停止・退会済・なし）。
- C7. エンジニアの退会（今の `destroy`）：`users.left_at = now` も入れる。停止中のエンジニアも退会にできる（`suspended_at` は残す）。稼働中の契約があれば断る（今のまま）。

### D. 停止の効果（ログイン・招待・配信・応募）

- D1. 停止・退会の人は、ログインできない（今の `active = 1` の関門）。ログイン中だった人は、次の操作でログイン画面に戻る（`Role` の関門。今のまま）。パスワードの再設定（`/forgot`）もメールが出ない（今のまま）。
- D2. 招待のリンク：`W::userByToken` は `active = 1` の人だけ返す。止めた後に古いリンクを開くと「リンクの期限が切れています。」になる。
- D3. `W::invite`：相手が `active = 0`（停止・退会済）なら招待しない（null を返す）。`sesUsers` で停止・退会済の人のアドレスを入れたら「このメールアドレスは使えません（停止中か退会済です）。」（bad）。エンジニアの「招待を送る」も、停止中なら「停止中のため、招待を送れません。」（bad）。
- D4. 配信の候補から外す：`Matcher::forCase` は常に `e.suspended_at is null` かつ `e.status != left`（`includeBusy` でも）。これで案件の画面の候補・おすすめ・`CaseMail::candidates` の両方から消える。
- D5. `W::distribute`：停止中・退会済のエンジニアは飛ばす（数に入れない）。
- D6. `W::apply`：停止中なら「このエンジニアは停止中のため、応募できません。」、退会済なら「このエンジニアは退会済のため、応募できません。」で断る（`RuntimeException`。呼ぶ側の今の表示に乗る）。
- D7. SES の応募画面の自社エンジニアの選択肢（`CaseController` 83 行目）から、停止中・退会済を除く。
- D8. `W::refreshEngineerStatus`：`status = left` も何もしない（退会済が待機中に戻らないように）。

### E. ダッシュボードの「招待の状況」

- E1. 状態は A3 の言葉。退会済の人は出さない。
- E2. 「担当者が未登録」の判定は、退会済でない担当者がいない SES（全員退会済なら「担当者が未登録」に出る）。停止だけの SES は、その人が「停止」の行で出る。

### F. マニュアル（正本は `resources/manuals`。写しは JOE が `41_Park-Project/04_Manuals` に写す）

- F1. `admin.md` の「3. SES の管理」：担当者ごとの「停止」「再開」「退会」を足す（B の効果・最後の1人の注意・退会は戻せない）。会社の「停止」との違い（会社の停止は担当者全員と所属エンジニアの配信をまとめて止める）を1行で。
- F2. `admin.md` の「4. エンジニアの管理」：「停止」「再開」（ログインできない・配信の候補に出ない・応募できない・稼働中の契約と選考中の応募はそのまま）と、「退会」との違い。ボタンの並び（停止・退会・編集）。
- F3. `admin.md` の「招待の状況」の説明（33 行目付近）に「退会済の人は出ません」を足す。`admin-steps.md` の 14 行目も同じ。
- F4. `ses.md`：エンジニアの管理のところに、自社のエンジニアの「停止」「再開」「退会」を足す。「会社情報」の担当者の表に状態が出ること。担当者を止める・退会にするときは管理者に連絡すること（決めごと 1-a）。`ses-steps.md` の 42 行目に「担当者の停止・退会」も管理者への連絡に足す。
- F5. `engineer.md`：変えない（停止中の本人はログインできないので、画面の説明は要らない）。

## 作業手順（park-implementer）

1. マイグレーションを作る（A1・A2）。`php artisan migrate` で手元に当てる（公開版への反映は JOE が `park-deploy.sh` で行う。常時了承の範囲）。
2. `app/Services/Workflow.php` に手続きを置く：
   - `suspendSesUser(int $sesId, int $uid, $actor)`・`resumeSesUser`・`leaveSesUser`（B3〜B7。戻り値は `['type' => ..., 'msg' => ...]`、該当しなければ null で 404）。
   - `suspendEngineer(int $id, $actor)`・`resumeEngineer`（C2〜C5）。
   - `userByToken` に `active = 1`（D2）、`invite` に停止・退会済の除外（D3）、`apply` の断り（D6）、`distribute` の除外（D5）、`refreshEngineerStatus` の `left`（D8）。
   - `user_state($u)`（A3。helpers に置くなら `app/helpers.php`）。
3. `Matcher::forCase` の除外（D4）。`CaseController` 83 行目（D7）。
4. `AdminController`：`sesUserSuspend`・`sesUserResume`・`sesUserDelete`（W を呼ぶだけ）、`sesShow` に「使える担当者がいない」の判定と、行ごとの最後の1人の判定を渡す（B7）、`sesUsers` の bad の文（D3）、`inviteRows`（E1・E2）。`sesDelete` で `users.left_at` も入れる。
5. `EngineerController`：`suspend`・`resume`（W を呼ぶ。お知らせは仮定 a）、`destroy` に `left_at`（C7）、`invite` の停止中の断り（D3）、`show` に確認の文の材料（稼働中の契約の有無・選考中の応募の数）を渡す。
6. `routes/web.php`：`/admin/ses/{id}/users/{uid}/suspend|resume|delete`（admin のグループ。`uid` は `[0-9]+`）、`/engineers/{id}/suspend|resume`（`role:admin,ses` のグループ）。
7. 画面：`admin/ses-show.blade.php`（B1・B2・B7・B8）、`engineers/show.blade.php`（C1・C4・C6）、`admin/engineers.blade.php`・`ses/engineers.blade.php`（C6）、`ses/company.blade.php` の担当者の表に「状態」列（A3）。
8. マニュアル（F1〜F4）。
9. テスト `tests/Feature/SuspendLeaveTest.php` を足す（下）。`UiUnifyTest` の既存テストが通ることも確かめる。`php artisan test` を全部通す。

## テスト（`tests/Feature/SuspendLeaveTest.php`。見本データ `$seed = true`、ログインは `UiUnifyTest` の `as()` と同じ）

- T1（B1・B2）：管理者で `/admin/ses/1` → 担当者の行に「停止」「退会」があり、「担当者を登録」も残る。停止の人の行は「再開」、退会済の行はボタン無し・「退会済」。
- T2（B3・D1・D2）：`POST /admin/ses/1/users/{ses1のid}/suspend` → ok、`users.active = 0`・`token` null、監査 `ses-user-suspend` が1行。その人で `/ses` を開く → ログイン画面へ。`POST /login`（正しいパスワード）→ 失敗の flash。止める前に発行した招待トークンの `/invite/{t}` → `/login` へ「リンクの期限が切れています。」。
- T3（B4）：続けて `resume` → `active = 1`、ok。もう一度 `resume` →「すでに使えます。」で変化なし。
- T4（B5・B6）：`delete` → `active = 0`・`left_at` あり。その後の `suspend`・`resume`・`delete` → bad、行は変わらない。
- T5（B6・B10）：別の会社の担当者の `uid`・管理者の `uid` を ses/1 の URL で送る → 404。`ses1@park.example`・`eg1@park.example` で送る → 403。
- T6（B7）：会社1の使える担当者を1人にして、その人の行の確認の文に「使える担当者がいなくなります」が出る。止めた後の `/admin/ses/1` に「使える担当者がいません。」。
- T7（D3）：停止・退会済の担当者のアドレスで `POST /admin/ses/1/users` → bad、`mail_outbox` の invite が増えない。
- T8（C1・C6）：管理者で `/engineers/1` → 「停止」「退会」「編集」がこの順に出る（HTML 中の位置で比べる）。停止後は「再開」になり「停止中」が出て、「招待を送る」が消える。`eg1@park.example` 本人の `/engineers/{自分}` には停止・退会が無い。
- T9（C2・C3・D1）：管理者で `suspend` → `suspended_at` あり、eg の users が `active = 0`、所属の SES の担当者に `app_notifications` が1件（`mail_outbox` は増えない）。eg1 でログインできない。`resume` → 戻る（`left_at` のある eg ユーザーは戻らない）。
- T10（C2 の権限）：`ses1@park.example` で自社のエンジニアを `suspend` → ok、管理者にお知らせ。他社のエンジニア → 403。eg で → 403。
- T11（C4）：稼働中の契約のあるエンジニア → 停止できる（ok）、契約は `active` のまま。詳細の確認の文に「稼働中の契約があります」。
- T12（D4・D5・D6・D7）：停止したエンジニアが、`Matcher::forCase($case, true)` と `false` の両方、`CaseMail::candidates` に出ない。`W::distribute` に渡しても `case_offers` が増えない。`POST /cases/{id}/apply` で応募 → 断りの文、`applications` が増えない。SES の応募画面の選択肢に出ない。退会済のエンジニアも同じく出ない。
- T13（C5・C7・D8）：退会済のエンジニアへの `suspend`・`resume` → bad。停止中のエンジニアを退会にできる（`status = left`、users に `left_at`）。退会済のエンジニアで `W::refreshEngineerStatus` を呼んでも `left` のまま。
- T14（A2）：マイグレーションの直し：`left` の会社の担当者・`left` のエンジニアの eg ユーザーに `left_at` が入る（テストでは、`sesDelete` を通した後の行に `left_at` があることで確かめる）。
- T15（E1・E2）：`/admin` の「招待の状況」に退会済の担当者が出ない。会社の担当者を全員退会済にすると、その会社が「担当者が未登録」で出る。停止の担当者は「停止」で出る。

## 追記（2026-10-20 tezzo の決定とレビューの反映。上の本文より優先）

- 決めごと 1 は **b**：担当者の停止・再開・退会は、管理者に加えて SES もできる（自社の担当者だけ。ご自身は不可）。他社の担当者・他社の会社は 404、eg は 403。ルートは `role:admin,ses` のグループ（URL は `/admin/ses/{id}/users/{uid}/suspend|resume|delete` のまま）。戻り先は、管理者は SES の詳細、SES は `/ses/company`。`ses/company.blade.php` の担当者の表に、停止または再開・退会のボタン（ご自身の行と退会済の行には出さない）。B10 は「eg は 403」に読み替える。
- 決めごと 3 は **b**：その会社で使える（`active` かつ `left_at` なし）担当者がその人だけのとき、停止・退会は断る（bad。「会社ごと止めるときは、SES の「停止」か「退会」を使ってください」）。確認の文の「最後の1人」注意（B7 前半）は外した。「使える担当者がいません」の注意は、会社が参加中で担当者がいるのに使える人がいないときだけ出す。止めている人がいれば「止めている担当者を再開するか、担当者を登録してください。」、全員退会済なら「担当者を登録してください。」。
- 決めごと 4 は **a** のまま。
- レビュー：エンジニアの編集で状態「退会済」を選べない・退会済から変えられない（画面とサーバー）、`EngineerController::invite` は退会済を断る、`CaseController::recommend` は停止中・退会済を断る、`ReplyController` は停止中・退会済の返事を記録せず断る（やさしい文面）、`sesApprove` と `sesUsers` は招待が出せなかったときに「送りました」と出さない（`W::approveSes` は招待を出したかを bool で返す）、`resumeEngineer` は退会の記録（`left_at`）のない eg ユーザーを戻す（コメントで意図を書いた）。
- テスト：`SuspendLeaveTest` の `count` を `rowCount` に改名。T2・T3 は2人目の担当者を足してから止める。T5 は SES・eg の権限を直した。T6 は断りと注意の文に書き換えた。T16（SES の操作）、T17（編集で退会済）、T18（退会済に招待）、T19（推薦）、T20（返事リンク）、T21（承認の文）を足した。

## 公開版への反映

実装・レビュー・テストが通ったら、JOE が commit・push し、`park-deploy.sh` で反映する（コード・migrate・キャッシュ。常時了承の範囲）。公開版の実際の人を停止・退会にする操作は、この計画では行わない（行うときは tezzo の了承を取る）。
