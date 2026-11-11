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
| A3 | 業界の節の後ろに、見出し「PM/PLご経験者の方へ」の節が出る。中は3問で、それぞれ単一選択（ラジオボタン）。選択肢は原文どおり |
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
