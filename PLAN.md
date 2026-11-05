# PLAN：EG のスキル画面で、案件に合うスキルにチェックを入れる（2026-11-05）

> **これが今の計画**。前の計画（v0.3.0 スキル登録・v0.2.0 仮登録 → 本登録・ダミーデータ・EG 同期）は上書きした。見るときは `git show fe7cf0e:PLAN.md`（park の履歴）。
> 依頼：TEZZO「スキル一覧のチェックボックスから、EG がマッチするスキルにチェックを入れる機能」。方針：経済大臣 Leo（2026-11-05）。作る順：park-implementer → park-reviewer → park-tester。commit・push・公開版への反映は Leo。
> プレーヤー：管理者・SES・担当者・EG の4つ。**この機能が出るのは EG 本人だけ**。管理者・SES・担当者の画面と動きは変えない。
> DB は変えない（マイグレーションなし。`engineer_skills` に列を足さない）。外部のライブラリは足さない。

## 0. コードで確かめた今の形

- 画面：`resources/views/engineers/skills.blade.php`。行は `$row` のクロージャで HTML を作る。入力の誤りで戻ったときは `old('skill_name')` から行を作り直す（3〜9 行）。空の行3つと `<template id="skillrow">` も同じ `$row()`。
- 処理：`EngineerController::skills`（243 行）は `loadForEdit` → `SkillTable::forDisplay($id)` と候補（`skill_terms` の確認済み）を渡すだけ。`saveSkills`（251 行）は `W::saveSkills($id, $r->all())` で、送られた `skill_*[]` だけを読む。
- 誰が開けるか：`load()` → `Perms::engineer`（管理者＝全員、SES／担当者＝自社、EG＝本人だけ）。兼任の人は `ActingRole` が `role` を「いまの立場」に差し替える。
- EG が見てよい案件：`Perms::caseView`（`app/Support/Perms.php:16`）で、EG は **自分に案内が届いた（`case_offers`）か、応募した（`applications`）案件だけ**。募集中の案件の一覧を EG が見る画面は今は無い。
- 一致の決まり：`Matcher::hasSkill($idx, $name)`（`Matcher.php:51`）＝ `norm_skill` で同じ、または両方3文字以上で `loose_match`。スキルシートの `isHit`（`EngineerController.php:320`）も同じ形。必須・尚可は `parse_list($c->required_skills / preferred_skills)`。
- 案件の状態：`draft`（下書き）・`open`（募集中）・`filled`・`closed`。

## 1. Leo の方針から変えた点（理由つき）

- **案件の選択肢は「案内が届いた案件・応募した案件」だけにする**（方針の「公開中の案件、または案内が届いた案件」のうち、公開中の全件は入れない）。理由：今の権限（`Perms::caseView`）では、EG は届いていない案件を見られない。全件の名前を EG に出すと、見える範囲が広がる（案件名に取引先が推測できる語が入ることがある）。広げるかは事業の判断なので、今回は今の権限に合わせる。→ 7章の返事待ち **P1**。
- 下書き（`draft`）は選択肢に出さない。募集中を上、それ以外（充足・終了）は名前の後ろに状態を付けて下に出す（終わった案件で振り返る用）。

## 2. 受け入れ条件（AC）

| 番号 | 受け入れ条件 |
|---|---|
| AC-01 | EG 本人がスキル画面を開くと、上に「案件に合わせてチェックする」欄（案件を選ぶ `select` と「合わせる」ボタン）が出る。選択肢は、その EG に案内が届いた案件と応募した案件（下書きを除く）。選択肢には **案件名と状態だけ** を出す。 |
| AC-02 | 案件を選ぶ（`GET /engineers/{id}/skills?case={caseId}`）と、表の左端に「合う」列が出て、各行にチェックボックスが付く。案件の必須・尚可に合うスキルの行は、チェックが入った状態で出る。行には「必須」「尚可」の小さな印も出る（両方に合えば必須）。 |
| AC-03 | 一致は既存の決まりと同じ（`norm_skill` で同じ／両方3文字以上で `loose_match`）。表記ゆれ（大文字小文字・全角半角・別名など `norm_skill` が吸収するもの）は合うものとして扱う。同じ技術の行が2つあれば、両方にチェック。 |
| AC-04 | EG はチェックを外せる・入れられる（画面の上だけ）。チェックボックスに `name` を付けないので、保存しても送られない。DB は変わらない。 |
| AC-05 | 案件を選んだ状態で「保存する」を押しても、今までどおり保存できる（`engineer_skills` の中身は、チェックの有無に関係なく、入力した行のとおり）。保存後は今までどおり `/engineers/{id}` に戻る。 |
| AC-06 | 案件を選ばない（`?case` なし・空）ときは、今までどおり。「合う」列もチェックボックスも出ない。 |
| AC-07 | 案件の必須のうち、スキル表のどの行にも合わないものを「スキル表に無い必須：A、B」と出す（スキル名だけ）。全部合えば出さない。 |
| AC-08 | 画面に、案件の金額・取引先・説明・場所など、スキル名と案件名・状態以外は出さない。 |
| AC-09 | 管理者・SES・担当者（兼任でいまの立場が EG 以外のときも）がスキル画面を開いても、案件の欄・チェックは出ない。`?case=` を付けても無視する（今までどおりの画面）。 |
| AC-10 | EG が、自分に届いていない案件・下書き・無い番号・数字でない値を `?case=` に入れたら、案件を選ばなかったものとして扱う（エラーにしない。案件名も出さない）。 |
| AC-11 | 他人のスキル画面は、今までどおり開けない（EG は 403）。 |
| AC-12 | 入力の誤りで戻ったとき（`back()->withInput()`）も、`?case=` は残り、作り直した行の名前で、もう一度チェックを付け直す。 |
| AC-13 | エンジニアのマニュアル（`engineer.md`・`engineer-steps.md`）と README に、この機能が書かれている。 |

## 3. 境界の挙動

| 場合 | 挙動 |
|---|---|
| 届いた・応募した案件が 0 件 | 案件の欄は出すが、`select` の代わりに「案件が届くと、ここで選べます。」と出す（ボタンなし）。 |
| スキルが 0 件（空の行だけ） | 「合う」列とチェックボックス（外れた状態）は出す。必須が書かれていれば、全部を「スキル表に無い必須」に出す。 |
| 案件の必須・尚可がどちらも空 | チェックは全部外れた状態。「この案件には、必須・尚可のスキルが書かれていません。」と出す。 |
| 必須が空で尚可だけある | 尚可の行だけチェック。「スキル表に無い必須」は出さない。 |
| 表記ゆれ（`java` と `Java`、`ＰＨＰ` と `PHP` など） | 合う（AC-03）。2文字以下（`Go`・`C#` など）は同じ名前のときだけ合う（既存どおり。`loose_match` は使わない）。 |
| 行を足す（空の行・`template`） | 案件を選んでいるときは、外れたチェックボックスを付ける（列がずれないように）。 |
| 案件を切り替える | 「合わせる」は GET で画面を開き直すので、**保存していない入力は消える**。欄の横に「先に保存してから選んでください。」と出す。 |
| 二重に押す | GET なので何も起きない。保存は今までどおり。 |
| 仮登録中の EG | 今までどおり（EG 本人は `guardProvisional` の対象外）。 |

## 4. 仕様

### 4-1. 処理

- `app/Services/Matcher.php`
  - 一致の判定を1つの関数に出す：`public static function sameSkill(string $a, string $b): bool`（引数は `norm_skill` 済みの鍵。`$a === $b || (mb_strlen($a) >= 3 && mb_strlen($b) >= 3 && loose_match($a, $b))`）。`hasSkill` の中をこれで書き直す（動きは同じ。`loose_match($key, $k)` の引数の順を変えない）。
  - 新しい関数：`public static function rowMarks(array $names, object $c): array` → `['marks' => [行の番号 => 'required'|'preferred'], 'missing' => [必須の名前…], 'empty' => bool]`。各行の名前を `norm_skill` し、空の名前は飛ばす。必須を先に見て、合えば `required`。尚可は、まだ印が無い行だけ `preferred`。`missing` は、どの行にも合わなかった必須（元の書き方のまま）。`empty` は必須・尚可がどちらも空。
- `app/Http/Controllers/EngineerController.php`
  - `skills()`：今までの値に `pick`（選んだ案件）と `pickable`（選択肢）を足す。EG 本人のとき（`$r->user()->role === 'eg'`。`load()` で本人だと確かめ済み）だけ作る。それ以外は両方 `null`。
  - 新しい private `pickableCases(int $eid)`：`case_offers` と `applications` のどちらかでこの EG に紐づく案件（`status <> 'draft'`）を `DB::table('cases')` で読む。取る列は `id`・`title`・`status`・`required_skills`・`preferred_skills` だけ。並びは募集中が先、そのあと id の大きい順。
  - `pick`：`?case=` が数字で、`pickable` の中にあるときだけ、その行。ほかは `null`（AC-10）。
  - `saveSkills()` は変えない。

### 4-2. 画面（`skills.blade.php`）

- `$pickable !== null` のときだけ、保存の `<form>` の**外**に GET の小さなフォーム（`action` は同じ URL、`select name="case"`、先頭に「（選ばない）」、ボタン「合わせる」、注意の文）を置く。0 件なら案内の文だけ。
- `old()` で行を作り直したあとで、`$pick` があれば `Matcher::rowMarks($skills->pluck('name')->all(), $pick)` を呼ぶ（AC-12）。
- `$pick` があるときだけ、`thead` の先頭に `<th>合う</th>`、各行の先頭に `<td><input type="checkbox" class="skill-hit" …></td>`（**`name` を付けない**）。印のある行は `checked` と `<small>必須</small>`／`<small>尚可</small>`。`$row` のクロージャに行の番号と印を渡せるようにする（引数を足す。既定は印なし）。
- 選んだ案件の名前、`missing`、`empty` の文を表の上に出す。金額などは出さない（AC-08）。
- 文はやさしい日本語。例：「案件「〇〇」の必須・尚可に合うスキルにチェックを入れました。チェックは見るための印で、保存はされません。」
- 入力の誤りで戻ったとき `?case=` を保つ：`back()` は元の URL（クエリ付き）に戻るので、そのままで足りる。テストで確かめる。

### 4-3. データ・通知

- DB の変更なし。メールなし。通知なし。記録（`audit`）なし（見るだけの操作のため）。

## 5. 作業の手順

1. `Matcher::sameSkill` を作り、`hasSkill` をそれで書き直す。`php artisan test` で今のテストが全部通ることを確かめる（動きを変えていない確認）。
2. `Matcher::rowMarks` を作る。
3. `EngineerController::skills` に `pickableCases`・`pick` を足す（EG 本人だけ）。
4. `skills.blade.php` に案件の欄・「合う」列・印・`missing`／`empty` の文を足す。案件なしのときは今と同じ HTML になることを確かめる。
5. テスト `tests/Feature/SkillCaseCheckTest.php` を作る（6章）。`V030Helpers`（`as`・`engId`・`cleanEg`・`skillForm`）を使う。案件・案内は `DB::table('cases')->insertGetId(…)`、`DB::table('case_offers')->insert(…)`、`applications` で直接入れる。
6. マニュアルと README を直す（8章）。
7. `php artisan test` 全体（GENESIS は `.env` が無いので `APP_KEY` を環境変数で渡す。zip が無い既知の1件は除く）。

## 6. テスト（`tests/Feature/SkillCaseCheckTest.php`）

立場は見本の EG（`eg2@park.example`）。準備：`cleanEg` → スキルを `POST /engineers/{id}/skills` で入れる（例：`Java`・`Spring Boot`・`MySQL`・`Go`）→ 案件 A（必須 `java, spring boot`、尚可 `MySQL`、`status=open`、金額と取引先に目印の文字）を作り、`case_offers` で EG に届ける。

| テスト | 手順 | 確かめること |
|---|---|---|
| AC-01 | EG で `GET /engineers/{id}/skills` | 200、`name="case"` の `select` と案件 A の名前が出る。案件 B（届いていない）・下書き C（届いている）の名前は出ない。応募だけの案件 D は出る。 |
| AC-02・03 | EG で `?case=A` | `Java`・`Spring Boot` の行に `checked` と「必須」、`MySQL` に `checked` と「尚可」、`Go` には `checked` が無い。表記ゆれ（案件は小文字）でも合う。 |
| AC-03（2文字） | 案件の必須 `go` と、スキル `Golang` 以外 | `Go` の行は `go` と合う（同じ鍵）。3文字未満の部分一致はしない（例：必須 `C` でスキル `C#` が合わない）。 |
| AC-04・05 | `?case=A` を開いたあと、`skillForm` で `POST`（チェックの値は送らない） | `/engineers/{id}` へ戻る。`engineer_skills` は送った行のとおり。列が増えていない（`Schema::getColumnListing('engineer_skills')` が前と同じ）。 |
| AC-06 | `?case` なし・`?case=` 空 | `class="skill-hit"` も「合う」も出ない。 |
| AC-07 | 必須に `Kubernetes` を足した案件で開く | 「スキル表に無い必須：Kubernetes」が出る。全部合う案件では出ない。 |
| AC-08 | `?case=A` | 案件 A の金額・取引先の目印の文字が出ない（`assertDontSee`）。 |
| AC-09 | 管理者・SES で同じ EG の `?case=A` | 200、`name="case"` も `skill-hit` も出ない。兼任の人がいまの立場を管理者にしたときも同じ（見本に兼任がいなければ、このケースは省いて理由を書く）。 |
| AC-10 | EG で `?case=B`（届いていない）・`?case=C`（下書き）・`?case=999999`・`?case=abc` | 200、`skill-hit` が出ない、B・C の名前が出ない。 |
| AC-11 | 別の EG で `GET /engineers/{eg2}/skills?case=A` | 403。 |
| AC-12 | `?case=A` を `from()` にして、誤りのある入力（例：得意を6つ）を `POST` → リダイレクト先を開く | リダイレクト先に `case=A` が残り、作り直した行にチェックが付く。 |
| 境界 | 届いた案件 0 件／スキル 0 件／必須・尚可が空の案件 | 3章の文が出る。500 にならない。 |
| 単体 | `Matcher::rowMarks` を直接呼ぶ | 必須と尚可の両方に合う行は `required`。空の名前は印なし。`empty` が正しい。 |

## 7. 未決事項

- **P1（返事待ち・TEZZO に上げる件）**：EG に「募集中の全案件」を選ばせるか。今回は「届いた・応募した案件だけ」で作る（1章）。全件にすると、EG が見られる案件の範囲が広がる（事業・情報の出し方の判断）。広げるなら `pickableCases` に `status='open'` の全件を足すだけで、画面とテストの直しは小さい。
- 次の2つは Leo が決めた（変えるなら Leo が直す）：
  - バージョンは上げない（`config/park.php` は変えない。小さな機能の追加として v0.3.0 のまま）。
  - チェックを保存しない以上、保存したら印は消える（保存後は詳細画面へ戻る今の流れのまま）。

## 8. マニュアル・README

- `resources/manuals/engineer.md` の「### スキル」に足す：案件を選んで「合わせる」を押すと、その案件の必須・尚可に合うスキルにチェックが入ること／チェックは見るための印で、外せる・保存されないこと／選べるのは届いた案件・応募した案件だけ／切り替える前に保存すること／「スキル表に無い必須」の意味（足せるものは足す。盛らない）。
- `resources/manuals/engineer-steps.md` の「3-3」の「スキルを編集」の項の後ろに、手順を1つ足す（案件を選ぶ → 合わせる → チェックを見る → 足りない必須を確かめる → 保存）。
- 管理者・SES のマニュアルは変えない（画面が変わらないため）。
- `README.md`：「エンジニア」の機能の一文に「スキル画面で案件に合うスキルにチェック」を足し、「## EG のスキル登録（v0.3.0）」の節に1項（`Matcher::rowMarks`・`sameSkill`、保存しない、選べる案件の範囲）を足す。
- 41_Park-Project の `04_Manuals`（エンジニアの `マニュアル.md`・`操作手順書.md`・README の写し）は、Leo が写し直す。

## 9. 守ること

- 公開版への反映は、push のあと Leo が `park-deploy.sh` で行う（常時了承の範囲。migrate なし）。データの削除・メールの送信は、この作業に含まない。
- 案件・個人の情報を外部のサービスに渡さない（この機能は手元の計算だけ）。
