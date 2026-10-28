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
