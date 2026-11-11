# PLAN：スキル登録画面の全面変更（チェック式。v0.4.0。2026-11-11）

> **これが今の計画**。前の計画（スキル画面の「案件に合わせてチェック」2026-11-05）は上書きした。見るときは park で `git log --oneline -- PLAN.md` を出し、2026-11-05 の版の commit を `git show <commit>:PLAN.md` で開く。
> 依頼：TEZZO（2026-11-11）「スキル画面を全面的に変更。元は Excel（Skills.xlsx）。構造化した JSON `resources/data/skill_form.json` が項目の正本」。今の自由入力の行・レベル・最後に使った年月・得意・ひとことは**廃止**（TEZZO 決定）。
> 作る順：park-implementer → park-reviewer → park-tester。commit・push・公開版への反映は Leo。
> プレーヤー：管理者・SES・担当者・EG の4つ（担当者は今のアプリでは SES と同じ `role=ses`）。**誰が編集でき・誰が見られるかは今のまま変えない**。
> 外部のライブラリは足さない。JavaScript は最小限（その他の欄の表示・追加・削除、チェックと年数の連動、全部開く／閉じる）。

## 0. コードで確かめた今の形

- 表 `engineer_skills`（`name`・`category`・`years` decimal・`level`・`term_id`・`last_used_ym`・`is_primary`・`note`・timestamps）。保存は `SkillTable::save`（`Workflow::saveSkills` が入口）。
- `engineer_skills` を読むところ（全部、新しい表に切り替える対象）：
  - `SkillTable::forDisplay`（← `EngineerController::show`・`skills`・`sheet`、`EngineerDocs::skillLines`＝Excel の保有スキル）
  - `Matcher::skillIndexMany`（点数・`forCase`・`Workflow` の案内・スキルシートの `reqRows`）
  - `Qa::sheetCheck`（完成度の点数と指摘）
  - `AdminController::engineers`（一覧のスキル列と `q` の検索）・`SesController::engineers`（一覧のスキル列）
  - `SkillTable::usedCounts`（管理者の技術マスタ画面の「使っている人」）
  - `Registration.php:657`（技術の入力があるか `hasTech`）
  - `SamplePurge`（見本を消す表の一覧とバックアップ）
  - 書くところ：`SkillTable::save`、`SkillTable::mergeTerms`（技術マスタの統合）、`AdminController.php:642`（マスタの名前の変更で行の名前も直す）、`DummySeed.php:295`・`SampleSeeder.php:73`（`W::saveSkills`）
- 画面 `resources/views/engineers/skills.blade.php`（行の表・`old('skill_name')` で作り直し・案件チェックの欄）。詳細 `show.blade.php:44-49`（`.skillgrid`、得意・★レベル・ひとこと）。スキルシート `sheet.blade.php:15-17`（得意な技術・分類ごと・ひとこと）。
- 案件チェック（2026-11-05）：EG 本人だけ。`pickableCases`（届いた・応募した案件、下書き除く）→ `?case=` → `Matcher::rowMarks`（**行の番号**で印）。チェックに name が無く保存されない。
- 一致の決まり：`norm_skill`（技術マスタの別名で正式名にそろえ、括弧の中を外す）＋ `Matcher::sameSkill`（同じ、または両方3文字以上で `loose_match`）。
- 権限：`load()` → `Perms::engineer`（管理者＝全員、SES・担当者＝自社、EG＝本人）。`loadForEdit` は上位が仮登録中の EG を直そうとすると止める（`guardProvisional`）。上位が直すと `W::editedBy` で本人の「確認しました」がやり直し。
- JSON の形：`yearOptions`（4つ）と `sections`（7つ）。`roles`・`phases` は `items` を直に持つ。ほかは `groups`（`name`・`years`・`items`）。グループの `years` が true なら「親」（チェック＋年数）、false なら見出しだけ。項目は `name`・`years`・`other`。
- テストは sqlite（`:memory:`）、本番は MySQL。PHP の `max_input_vars` は既定 1000。

## 1. 決めたこと（Leo の決定として仮置き。変えるなら Leo が直す）

| 番号 | 決めたこと | 理由 |
|---|---|---|
| D1 | バージョンを **v0.4.0** にする（`config/park.php`） | 表の追加と移行を伴う全面変更のため |
| D2 | 新しい表 `engineer_skill_checks` に保存する。`engineer_skills` は**消さず・書かず・読まず**に残す（次のバージョンで消す。消すのは TEZZO の了承が要る） | 本番のデータを消さない安全な順序（TEZZO 指定） |
| D3 | 保存の鍵は「節／親／名前」を列で持つ（`section`・`parent`・`item`・`other_text`）。画面のフォームの name は JSON の位置から作る短い番号（`lang-3-2`）を使い、受け取ったらサーバーで「節／親／名前」に戻す | 名前に `/`・括弧・空白が入るため。DB は名前で持つので、JSON の並びが変わってもデータは崩れない |
| D4 | 親（言語など）と子（フレームワーク）のチェックは独立。子を選んでも親は自動で付けない | TEZZO 指定なし（依頼文どおりの仮置き） |
| D5 | 「そのほか（言語＋フレームワークではなく括りで管理）」のグループは、JSON では `years: true` だが**見出しだけ**（チェックなし）にする | 技術の名前ではなく括りの名前のため。中の項目と「その他」は今までどおりチェックできる |
| D6 | 年数を選んだ項目は、チェックが無くても「チェックあり」として保存する（JS が無いときの守り）。チェックが無い間は年数のプルダウンを JS で無効にする | 年数だけ選んで消える、を防ぐ |
| D7 | 「案件に合わせてチェック」は、**チェックを自動で入れず、合う項目に「必須」「尚可」の印を付ける**形に直す（9章） | 新しい画面のチェックは入力そのもの。自動で入れると、保存で経験の無いスキルが入る（盛る）おそれがあるため |
| D8 | 「経歴から数えた月数」「申告と経歴のずれの注意（warn）」は、スキル画面・詳細から外す | 年数が4段階になり、行ごとの欄も無くなったため。経歴の索引（`engineer_project_skills`）とマッチングの月数の準備はそのまま |
| D9 | 「その他」に書いた言葉は、今までどおり技術マスタに「確認待ち」で足す（`SkillCatalog::ensure`）。決まった項目の名前はマスタに足さない | 管理者が新しい言葉を確かめられる今の流れを保つ |
| D10 | JSON から消えた項目（前に保存した「節／親／名前」が今の JSON に無い）は、DB に残し、編集画面の下に「今の表に無い項目」として出す。チェックを外して保存すると消える | JSON を直しても、本人のデータが黙って消えないように |

## 2. 受け入れ条件（AC）

| 番号 | 受け入れ条件 |
|---|---|
| AC-01 | スキル画面（`GET /engineers/{id}/skills`）は `skill_form.json` から作る。7つの節が JSON の順・題で出る。チェックが出るのは、`roles`・`phases` の項目、`groups` の `years: true` のグループ（親。D5 の「そのほか」は除く）とすべての項目。節の題・`years: false` のグループの名前・「そのほか」の名前にはチェックが無い。 |
| AC-02 | `years: true` の項目・親には、年数のプルダウンが付く。選択肢は「（年数）」（空）と「1年未満」「1年以上」「3年以上」「5年以上」の5つで、この順。`years: false` の項目にはプルダウンが無い。 |
| AC-03 | 「その他」（`other: true`）はチェックすると名前の入力欄（40文字まで）が出る。「＋」で入力欄を足せ、「×」で外せる。年数付きの節の「その他」は、入力欄ごとに年数のプルダウンも付く。 |
| AC-04 | 項目は CSS グリッドで複数列に並び、画面幅で列数が変わる（`repeat(auto-fill, minmax(…, 1fr))`）。節ごとに開閉できる（`details`）。 |
| AC-05 | 「保存する」で、チェックした項目・年数・その他が `engineer_skill_checks` に入り、`/engineers/{id}` へ戻って「スキルを保存しました。」が出る。`engineer_skills` は変わらない。 |
| AC-06 | チェックして年数を選ばないと、年数なし（`years_min` が null）で保存され、表示は「経験あり」。 |
| AC-07 | その他：複数の名前を保存できる。空の名前は保存しない。同じ親の同じ名前（`norm_skill` で同じ）は1つにまとめ、年数は大きいほう。41文字以上・1つの「その他」に11個以上は保存せず、理由を出す（何も変えない）。 |
| AC-08 | 親と子は独立（D4）。子だけチェックすると子の行だけ入る。 |
| AC-09 | 同じ名前が別の親にあれば（React が JavaScript と TypeScript の下など）、別の行で持つ。マッチングと一覧では同じ技術として1つにまとめ、年数は大きいほう。 |
| AC-10 | 年数に4つ以外の値が来たら、何も保存せず「年数は選択肢から選んでください。」を出して戻す。知らない番号の項目は無視する。 |
| AC-11 | 入力の誤りで戻ったとき、チェック・年数・その他の入力は送ったとおりに出る。 |
| AC-12 | 詳細・スキルシート・Excel（業務経歴書の保有スキル）は新しい表から出す。節ごとに、親は「PHP（5年以上）：Laravel（3年以上）、CakePHP」の形。レベル・得意・ひとこと・最後に使った年月は、どの画面・Excel にも出ない。 |
| AC-13 | 管理者・SES のエンジニア一覧の「スキル」列は新しい表から出す（同じ技術は1回）。管理者一覧の `q` でスキル名を探せる。 |
| AC-14 | マッチング（`Matcher::score`・`forCase`・案件の案内・スキルシートの「案件の必須要件との対応」）は新しい表を読む。理由の文は「必須 Java（3年以上）」、年数なしは「必須 Java」。対応表は「○ 3年以上」／「○ 経験あり」／「—」。 |
| AC-15 | EG 本人がスキル画面で案件を選ぶと、その案件の必須・尚可に合う項目に「必須」「尚可」の印が付き、印のある節は開いた状態で出る。チェックは自動で変わらない（D7）。本人がチェックしていない必須を「あなたのスキルに無い必須：A、B」と出す。選べる案件・無効な `?case=` の扱い・金額などを出さないことは前と同じ。 |
| AC-16 | 権限は今のまま（7章）。 |
| AC-17 | 移行：今の `engineer_skills` の行が、11章の決まりで新しい表に移る。何度流しても同じ。古い表は変わらない。 |
| AC-18 | ダミー（`park:seed-dummy`）・見本（`SampleSeeder`）は新しい表に入れる。`park:purge-samples` は新しい表も消してバックアップに入れる。 |
| AC-19 | 完成度（`Qa::sheetCheck`）は新しい表で数える（5件以上で15点、年数付きの項目が全部年数ありで5点。文は12章）。 |
| AC-20 | `Registration` の「技術の入力がある」は新しい表も見る。 |
| AC-21 | その他の名前は技術マスタに「確認待ち」で足される（D9）。管理者の技術マスタ画面の「使っている人」に、新しい表の人も数えられる。 |
| AC-22 | 画面のフォームの入力の数（name の付いた要素）は、全部チェックしても 900 未満（`max_input_vars` 1000 に収まる）。 |
| AC-23 | マニュアル（エンジニア・SES・管理者）と README が新しい画面に合っている。 |

## 3. 保存先（DB）

### 3-1. 新しい表 `engineer_skill_checks`（マイグレーション `2026_11_11_000001_create_engineer_skill_checks.php`）

| 列 | 型 | 意味 |
|---|---|---|
| `id` | id | |
| `engineer_id` | foreignId → engineers、cascadeOnDelete | |
| `section` | string(10) | JSON の節の `key`（`roles`・`phases`・`lang`・`db`・`os`・`pm`・`dev`） |
| `parent` | string(80)、既定 `''` | グループの名前。`roles`・`phases` の項目と、親そのものの行は `''` |
| `item` | string(120) | 項目の名前。親の行はグループの名前。その他は `その他` |
| `other_text` | string(40)、既定 `''` | その他に書いた名前。その他以外は `''` |
| `years_min` | unsignedTinyInteger、nullable | 0＝1年未満・1＝1年以上・3＝3年以上・5＝5年以上・null＝年数なし（経験あり） |
| `source` | string(10)、既定 `form` | `form`（画面）・`migrated`（移行）・`seed`（ダミー・見本） |
| timestamps | | |

- 一意：`unique(['engineer_id', 'section', 'parent', 'item', 'other_text'], 'esc_unique')`（MySQL utf8mb4 で 1000 バイト。3072 以内）。
- 検索用：`index(['section', 'item'], 'esc_item')`（「この技術を持つ人」を SQL で引く準備）。`engineer_id` で引くのは一意の索引の先頭で足りる。
- 例：PHP を5年以上 → (`lang`,`''`,`PHP`,`''`,5)。Laravel 3年以上 → (`lang`,`PHP`,`Laravel`,`''`,3)。PHP のその他「Haxe」→ (`lang`,`PHP`,`その他`,`Haxe`,null)。役割のその他「SE」→ (`roles`,`''`,`その他`,`SE`,…)。
- `down()`：`Schema::dropIfExists('engineer_skill_checks')`。

### 3-2. 年数の値

- `SkillForm::YEARS = ['1年未満' => 0, '1年以上' => 1, '3年以上' => 3, '5年以上' => 5]`。JSON の `yearOptions` と同じ並び（テストで確かめる。食い違えば例外）。
- 大きさの比べ方：null ＜ 0 ＜ 1 ＜ 3 ＜ 5（null は「経験はあるが年数不明」で一番小さい扱い）。

## 4. 処理（app）

### 4-1. `app/Support/SkillForm.php`（新規。JSON の読み手）

- `load()`：`resource_path('data/skill_form.json')` を1回だけ読んで覚える。読めない・形が違えば例外（画面は 500。テストで正本の形を守る）。
- `nodes()`：平らな一覧。1つの要素は `id`（`roles-4`・`lang-3`・`lang-3-2`。節の key と JSON の位置の番号）・`section`・`parent`・`item`・`years`（bool）・`other`（bool）・`checkable`（bool）・`words`（照合用の言葉）。
  - `checkable`：`roles`/`phases` の項目、`years: true` のグループ（D5 の名前が「そのほか」で始まるものは除く）、グループの中の全項目。
- `node(string $id)`・`byPath(section, parent, item)`。「節／親／名前」が JSON の中で重複したら例外（テストで確かめる）。
- `words(string $name): array`（照合用。決まり）：
  1. 名前そのもの。
  2. 括弧の外の部分（基）を ` / `（前後に空白のある `/`）で分けたそれぞれ。`HTML/CSS` だけは `/` でも分ける（定数 `SPLIT_SLASH`）。
  3. 括弧の中を `・`・`、`・`,` で分けたそれぞれ。中に `：` があれば `：` より後ろだけ使う。
  4. 数字だけの言葉と、`実行環境` は外す。空は外す。
  - 例（テストで確かめる）：`Node.js（実行環境）`→ Node.js（＋名前そのもの）／`HTML/CSS（Sass・Bootstrap・Tailwind CSS・Material UI）`→ HTML, CSS, Sass, Bootstrap, Tailwind CSS, Material UI／`SQL（PL/SQL・T-SQL）`→ SQL, PL/SQL, T-SQL／`Pandas / NumPy`→ Pandas, NumPy／`VB / VBA`→ VB, VBA／`ABAP（SAP）`→ ABAP, SAP／`（メインフレーム系ミドルウェア：CICS・IMS）`→ CICS, IMS／`IBM i（AS/400）`→ IBM i, AS/400／`2012 / 2016 / 2019 / 2022`→ 名前そのものだけ／`プロジェクトマネージャー（PM)`→ プロジェクトマネージャー, PM。
- `label(?int)`・`value(string)`：年数の値と文の行き来。
- `shortTitle(section)`：表示用の短い名前（役割・工程・言語・フレームワーク・データベース・OS・プロジェクト管理ツール・開発・運用ツール）。編集画面の節の題は JSON の `title`。
- `place(string $name, string $category, ?float $years): array`：今の行1つを新しい表のどこに置くか（移行・ダミー・見本で共通）。11章。

### 4-2. `app/Services/SkillChecks.php`（新規。`SkillTable` の役目を引き継ぐ）

- `save(int $engineerId, array $in): array` → `['ok' => true, 'count' => n, 'names' => [...], 'skipped' => n]` か `['bad' => '理由']`。
  - 入力：`chk[<id>]=1`、`yr[<id>]=<年数の文>`、`oth[<id>][]=<名前>`、`othyr[<id>][]=<年数の文>`、`keep[]=<行の id>`（D10）。
  - 1. 検査（DB に書く前に全部。1つでも当たれば何も書かない）：年数の文が4つ以外（空は可）→ AC-10 の文／その他の名前が40文字超→「その他の名前は40文字までにしてください（…）。」／1つのその他に11個以上→「1つの『その他』に入れられるのは10個までです。」。
  - 2. 行を作る：`checkable` の項目で `chk` があるか `yr` が空でない（D6）もの。年数は `years: true` のときだけ。その他は `chk` があるときだけ、空でない名前ごとに1行（名前は `clean_name` で整える）。同じ親の同じ名前（`norm_skill`）は1つにまとめ、年数は大きいほう。知らない id は無視。チェックありで名前が空のその他は数えて `skipped` に。
  - 3. transaction の中で：今の行のうち「今の JSON にある道筋」の行を `source` を問わず消し、作った行を入れる（`source=form`）。JSON に無い道筋の行（D10）は、`keep[]` にあれば残し、無ければ消す。`engineers.updated_at` を今にする。その他の名前は `SkillCatalog::ensure($name, 分類)`（分類は節で決める：roles→role、phases→method、lang の親→lang・子とその他→fw、db→db、os→os、pm・dev→tool）。
- `saveRows(int $engineerId, array $rows, string $source)`：移行・ダミー・見本から、行の配列（section・parent・item・other_text・years_min）をそのまま入れる（一意の重複は大きい年数で1つ）。
- `forDisplay(int $engineerId): array`：節の順に、`[shortTitle, entries]`。entries は JSON の順。グループの節は親ごとに `['name' => 親, 'years' => 文|null, 'checked' => 親にチェックがあるか, 'children' => [...]]`。その他は `other_text` を名前として出す。D10 の行は最後に「今の表に無い項目」として別に返す。
- `lines(int $engineerId): array`：表示の文の行（`役割：PM（3年以上）、メンバー（5年以上）`／`言語・フレームワーク：PHP（5年以上）：Laravel（3年以上）、CakePHP ／ Java：Spring Boot` の形）。詳細・スキルシート・Excel で共通に使う。
- `summaryMany(array $engineerIds): array` → `[id => 'PHP、Laravel、MySQL…']`（同じ技術は `norm_skill` で1回。一覧の列と検索用）。問い合わせは1回。
- `indexMany(array $engineerIds): array`：マッチング用。`[engineer_id => [norm_skill(言葉) => ['name' => 表示名, 'years' => 文|null, 'rank' => 値, 'fromProject' => false]]]`。1行の言葉は `SkillForm::words`（その他は名前1つ）。同じ鍵は rank の大きいほう（AC-09）。
- `caseMarks(array $state, object $c): array`（9章）。

### 4-3. 直すところ

| ファイル | 直すこと |
|---|---|
| `Workflow::saveSkills` | `SkillChecks::save` を呼ぶ |
| `EngineerController::skills` | `skills` の代わりに `form`（`SkillForm::nodes` を節ごとにまとめたもの）・`state`（保存済み：`[id => years|null]` と その他の名前と年数の並び）・`gone`（D10）を渡す。`terms`（datalist）は渡さない。案件の部分は 9章 |
| `EngineerController::saveSkills` | 戻り値の形に合わせる。完了の文：「スキルを保存しました。」＋`skipped` があれば「名前が空のその他は保存しませんでした。」。記録（audit）は「エンジニア #id：チェック N 件（PHP、Laravel、…先頭20件）」 |
| `EngineerController::show` | `skills` を `SkillChecks::lines` にする |
| `EngineerController::sheet` | `byCat` の代わりに `SkillChecks::forDisplay`。`isHit` は項目の `words` のどれかが当たれば印。`reqRows` の文を AC-14 に |
| `Matcher::skillIndexMany` | `engineer_skills` を読む部分を `SkillChecks::indexMany` に置き換える（経歴の文字列・経歴の索引の月数の部分はそのまま。`termKeys` は無くなるので名前で引く） |
| `Matcher::score` | 理由の文を AC-14 に（`$s['years']` は文）。`rowMarks` は消し、`sameSkill` は残す |
| `EngineerDocs::skillLines` | `SkillChecks::lines` を使う。「【得意】」の行は無くす |
| `Qa::sheetCheck` | 12章 |
| `AdminController::engineers`・`SesController::engineers` | スキル列を `SkillChecks::summaryMany` に |
| `SkillTable` | `save`・`write`・`changed`・`forDisplay`・`LEVELS`・`MAX_PRIMARY`・`NOTE_MAX`・`WARN_GAP_MONTHS` を消す。`mergeTerms`・`dedupe`・`mergeRows` は古い表の整合のため残す（次のバージョンで古い表と一緒に消す）。`usedCounts` は、古い表の代わりに新しい表を数える（行の言葉を `SkillCatalog::find` で引いて term_id にする）＋経歴の索引 |
| `AdminController.php:642` | そのまま（古い表の名前を直すだけ。新しい表は JSON の名前なので関係なし） |
| `Registration.php:657` | 見る表に `engineer_skill_checks` を足す（`engineer_skills` も残す） |
| `SamplePurge` | 表の一覧とバックアップに `engineer_skill_checks`（`engineer_id` で）を足す。消す順は `engineers` より前 |
| `DummySeed`・`SampleSeeder` | 今の名前の並びを `SkillForm::place` で置き場所に変え、`SkillChecks::saveRows(…, 'seed')` で入れる。年数は 11章と同じく丸める。`skill_level` などは渡さない |
| `config/park.php` | `version` を `v0.4.0` |
| `public/js/park.js` | 10章の小さな関数を足す（`addRow` は残す。ほかで使っていなければ消してよい。Grep で確かめる） |
| `public/css/park.css` | 5章のクラスを足す |

## 5. 画面（`resources/views/engineers/skills.blade.php` を作り直す）

- 上：案件の欄（EG 本人だけ。9章）。
- 説明の文（やさしい日本語）：「経験のある項目にチェックを入れ、年数を選んでください。年数がわからないときは選ばなくてかまいません（『経験あり』と出ます）。一覧に無いものは『その他』にチェックして名前を書きます。盛らずに、本当に経験のあるものだけを選んでください。」
- 「すべて開く」「すべて閉じる」のボタン（JS）。
- 節ごとに `<details class="sf-sec">`。`<summary>` は JSON の `title` と「（N 件チェック）」（表示したときの数。サーバーで数える）。**開いた状態で出す節**：`roles`・`phases`、チェックが1つでもある節、案件の印がある節、入力の誤りで戻ったときの全節。ほかは閉じて出す。
- 項目を直に持つ節（`roles`・`phases`）：`<div class="sf-items">` の中に項目を並べる。`grid-template-columns: repeat(auto-fill, minmax(220px, 1fr))`。
- グループの節：`<div class="sf-groups">`（`repeat(auto-fill, minmax(260px, 1fr))`、`align-items: start`）の中に、グループごとの箱 `<div class="sf-group">`。箱の頭は、親ならチェック＋名前（太字）＋年数、見出しだけなら名前（太字）。箱の中の項目は1列で縦に並べる。目安：横 1200px で 4 列、スマホで 1 列。
- 1つの項目：`<label class="sf-item"><input type="checkbox" name="chk[lang-3-2]" value="1"> Laravel</label>` ＋ 年数があれば `<select name="yr[lang-3-2]">`（空「（年数）」＋4つ）。チェックが無い間は `select` を `disabled`（JS）。年数を選ぶとチェックが入る（JS）。
- その他：チェック `chk[<id>]` ＋ 入れ物 `<div class="sf-other" hidden>`。中に `<input name="oth[<id>][]" maxlength="40">`（＋年数があれば `othyr[<id>][]`）と「×」、下に「＋」。保存済み・入力の誤りで戻ったときは、その数だけ欄を出し、`hidden` を外す。足す欄は `<template>` から作る（id ごとに1つではなく、年数あり／なしの2つの `template` を使い回し、JS で name を差し替える）。
- **name を付けるのは、チェック・年数・その他の欄だけ**。項目ごとの隠し欄は作らない（AC-22）。
- D10 の「今の表に無い項目」：あれば節の後ろに1つのカードで、行ごとに `keep[]` のチェック（最初は入っている）と「名前（年数）」。
- 入力の誤りで戻ったとき：`old('_sf')`（隠し欄1つ）があれば、`old('chk')`・`old('yr')`・`old('oth')`・`old('othyr')`・`old('keep')` から状態を作る。無ければ DB の `state`。
- 下：「戻る」「保存する」。
- 詳細 `show.blade.php` のスキルのカードは `SkillChecks::lines` を節ごとの行で出す（`.skillgrid`・★・得意・ひとこと・経歴の月数・注意は消す）。0件なら「まだ登録がありません。」。
- スキルシート `sheet.blade.php`：「得意な技術」を消し、表は節ごと（左に短い名前、右に `lines` の中身。当たる名前に `hit`）。ひとことの表示を消す。

## 6. 境界の挙動

| 場合 | 挙動 |
|---|---|
| 何もチェックせずに保存 | 保存できる（新しい表の行は0）。詳細は「まだ登録がありません。」 |
| チェックして年数なし | 経験あり（AC-06） |
| 年数を選んだがチェックなし（JS が無い） | チェックありで保存（D6） |
| 年数のない項目に `yr` が届く | 無視 |
| 年数が4つ以外（書き換えた送信） | 何も保存しない。AC-10 の文で戻る |
| 知らない id・チェックできない id（見出し） | 無視 |
| その他にチェック、名前は空 | その行は保存しない。完了の文に一言 |
| その他の名前の前後の空白・改行 | `clean_name` で取る。取った結果が空なら空として扱う |
| その他に同じ名前を2回 | 1行にまとめ、年数は大きいほう |
| その他の名前が決まった項目と同じ（PHP のその他に「Laravel」） | その他のまま保存する（自動で付け替えない） |
| 子だけチェック | 子の行だけ（D4） |
| 同じ名前が2か所（React） | 別の行。マッチング・一覧は1つ（AC-09） |
| 二重に押す | 同じ内容で上書きになるだけ（結果は同じ） |
| JSON から項目が消えた | D10 |
| 仮登録中の EG を上位が開く | 今までどおり `guardProvisional` で止まる。EG 本人は通る |
| スキル0件の EG のスキルシート・Excel | スキルの表は空（500 にしない） |
| 移行前の一瞬（コードの反映から migrate まで） | 新しい表が無い／空の間、スキルは空に見える。反映は続けて migrate するので数秒 |

## 7. 権限（今のまま。変えない）

| 立場 | スキル画面（見る・保存） | 詳細・スキルシート・Excel | 案件の印（9章） |
|---|---|---|---|
| 管理者 | 全員のを開けて保存できる（仮登録中の EG は止まる） | 全員 | 出ない（`?case=` は無視） |
| SES | 自社のエンジニアだけ（他社は 403。仮登録中は止まる） | 自社だけ | 出ない |
| 担当者（今は `role=ses`） | SES と同じ | SES と同じ | 出ない |
| EG | 本人だけ（他人は 403） | 本人だけ | 出る |
| 兼任（いまの立場で決まる） | いまの立場のとおり | 同じ | いまの立場が EG のときだけ |

- 上位が保存すると、本人の「確認しました」がやり直し（`W::editedBy`。今のまま）。メール・通知は送らない。記録（audit）は 4-3 のとおり。

## 8. ルートと入力

- ルートは変えない（`GET/POST /engineers/{id}/skills`）。
- フォームの入力：`_token`・`_sf=1`・`chk[]`・`yr[]`・`oth[][]`・`othyr[][]`・`keep[]`。

## 9. 案件に合わせた印（前の機能を新しい画面に合わせる）

- 前は「行の番号」で印を付け、チェックを入れていた。新しい画面では**項目の id（節／親／名前）**で印を付け、チェックは変えない（D7）。
- `EngineerController::skills`：`pickableCases`・`pick` は今のまま（EG 本人だけ、届いた・応募した案件、下書き除く、`?case=` が数字で範囲内のときだけ）。
- `SkillChecks::caseMarks(array $state, object $c)` → `['marks' => [id => 'required'|'preferred'], 'missing' => [...], 'empty' => bool]`。
  - `marks`：チェックできる全項目について、`words` のどれかが案件の必須の言葉と `Matcher::sameSkill`（`norm_skill` 済み）で合えば `required`、まだ印が無く尚可と合えば `preferred`。その他の欄は、本人が書いた名前で同じように判定し、その他の id に印。
  - `missing`：必須のうち、**本人がチェックしている項目（と書いたその他の名前）**のどれにも合わないもの（元の書き方のまま）。
  - `empty`：必須・尚可がどちらも空。
  - `state` は表示している状態（入力の誤りで戻ったときは送った値）で数える。
- 画面：印のある項目の横に `<small class="sf-mark">必須</small>`／`尚可`、ラベルに `hit` のクラス。印のある節は開く。文：「案件「〇〇」の必須・尚可に当たる項目に印を付けました。経験のある項目だけチェックして保存してください。印は保存されません。」。`missing` は「あなたのスキルに無い必須：A、B（経験があればチェックを入れてください。盛らないでください）」。`empty` は前と同じ文。
- 消すもの：`Matcher::rowMarks`、「合う」の列、`skill-hit` のクラス。
- 注意（前と同じ）：案件を選び直すと、保存していない入力は消える。欄の横に「先に保存してから選んでください。」。
- `loose_match` のため、`SQL` の必須で `MySQL`・`NoSQL` などにも印が付く（今のマッチングと同じゆるさ。変えない）。

## 10. JavaScript（`public/js/park.js` に足す。最小限）

- `sfInit()`：ページを開いたとき、チェックの無い項目の年数 `select` を `disabled` にする。
- チェックを変えたら、同じ項目の年数の `disabled` を切り替える。その他なら入れ物の `hidden` を切り替え、開いたとき欄が0なら1つ足す。
- 年数を選んだら、その項目のチェックを入れる。
- 「＋」：`template` を写して name を `oth[<id>][]`・`othyr[<id>][]` にして足す（1つのその他に10個まで。それより多いとボタンを押せなくする）。「×」：その欄を外す。
- 「すべて開く／閉じる」：`.sf-sec` の `open` を切り替える。

## 11. 移行（今のスキルを新しい表へ）

- マイグレーション2本：`2026_11_11_000001_create_engineer_skill_checks.php`（3-1）と `2026_11_11_000002_fill_engineer_skill_checks.php`（中身は `SkillChecks::migrateFromOld()` を呼ぶだけ。最初に `SkillCatalog::forget()`）。
- `migrateFromOld()`：エンジニアごと（transaction はエンジニア1人ずつ）。**すでに新しい表に1行でもある人は飛ばす**（冪等。移行済み・画面で保存済みの人を上書きしない）。古い行ごとに `SkillForm::place(name, category, years)` で置き場所を決め、`saveRows(…, 'migrated')`。
- `place` の決まり：
  1. 名前の `norm_skill` が、チェックできる項目（親・子・項目。その他を除く）の `words` のどれかの `norm_skill` と**同じ**なら、JSON の順で最初の項目に置く（React は JavaScript の下）。
  2. 合わなければ「その他」に置き、`other_text` は元の名前（40文字で切る）。親は分類で決める：

| 分類（`category`） | 置き場所 |
|---|---|
| `role` | 役割（`roles`）のその他 |
| `method` | 工程（`phases`）のその他（年数なし） |
| `db` | データベース「RDB」のその他 |
| `os` | 名前に windows を含めば OS「Windows Server」のその他、ほかは「UNIX」のその他 |
| `cloud` | 開発・運用ツール「クラウド」のその他（年数なし） |
| `infra` | 開発・運用ツール「Webサーバー・AP」のその他（年数なし） |
| `tool` | 開発・運用ツール「IDE・エディタ」のその他（年数なし） |
| `lang`・`fw`・`domain`・`other`・そのほか | 開発言語とフレームワーク「そのほか（…）」のその他 |

  3. 年数：置き場所が年数付きなら丸める（<1→1年未満、1以上3未満→1年以上、3以上5未満→3年以上、5以上→5年以上、null→null）。年数なしの置き場所は null。
  4. 同じ置き場所（同じその他の名前も）に2つ来たら、年数の大きいほう。
- 置き場所の表の親・その他が JSON にあることをテストで確かめる（無ければ例外）。
- `down()`：fill は `source = 'migrated'` の行だけ消す（画面で保存した行は残る）。create は表を消す（画面で保存した内容も消える。古い表は元のまま残っているので、コードを戻せば前の画面に戻る）。**戻せない部分**：新しい画面で保存した内容は古い表に書き戻さない。
- 移るときに落ちる情報（古い表には残る）：レベル・最後に使った年月・得意・ひとこと、年数の細かい値（2.5年 → 1年以上など）、工程・ツールの年数、その他になった名前の分類。

## 12. 完成度（`Qa::sheetCheck`）

- 新しい表の行が5件以上で 15 点。足りなければ「スキルは、経験のある項目に5つ以上チェックを入れましょう。」。
- 年数付きの項目（`years: true` の項目・親・その他）が全部年数ありで 5 点。ほかは「年数を選んでいない項目があります（『経験あり』と出ます）。わかる範囲で選びましょう。」。
- 合計の点の配分（100点）は変えない。

## 13. テスト

- 立場は見本の EG（`eg2@park.example`）と、管理者・その SES・他社の SES・他の EG。準備は `V030Helpers` の `as`・`engId`・`cleanEg`。`cleanEg` は新しい表も消すように直す。新しい助け `checkForm(array $checks, array $others = [])`（`['lang/PHP' => '5年以上', 'lang/PHP/Laravel' => '', …]` の道筋から `chk`・`yr` を作る。id は `SkillForm::byPath` で引く）を足す。`skillForm` は消す。
- 新規 `tests/Feature/SkillFormTest.php`：

| AC | 手順 | 確かめること |
|---|---|---|
| AC-01 | EG で `GET /engineers/{id}/skills` | 200。7つの題が JSON の順。`type="checkbox"` の数＝`checkable` の数。「課題・タスク管理」「そのほか（…）」の名前の前にチェックが無い |
| AC-02 | 同じ | `yr[lang-…]` の select の option が5つでこの順。`phases` の項目に `yr[` が無い |
| AC-03 | 同じ | その他ごとに `sf-other` と `template` がある。保存済みのその他2つなら欄が2つ、`hidden` が無い |
| AC-04 | 同じ | `sf-sec`・`sf-groups`・`sf-items` のクラスがある（見た目は 16章で人が確かめる） |
| AC-05・06 | `checkForm` で PHP（5年以上）・Laravel（空）を POST | `/engineers/{id}` へ。行が2つ、`years_min` が 5 と null。`engineer_skills` の行数が前と同じ |
| AC-07 | PHP のその他に「Haxe」「 haxe 」「」と年数、41文字、11個 | 1行（大きい年数）。41文字・11個は bad の文で、行が前のまま |
| AC-08 | Laravel だけ | 行は Laravel だけ |
| AC-09 | React を JS（1年以上）と TS（3年以上）で | 行2つ。`SkillChecks::indexMany` の react は 3年以上。一覧の列に React が1回 |
| AC-10 | `yr[…]=2年以上`、知らない id `chk[zzz-9]` | 前者は bad で何も変わらない。後者は無視して保存 |
| AC-11 | 誤りのある送信を `from()` で → 戻り先を開く | 送ったチェック・年数・その他が出る |
| AC-12 | 保存後に詳細・スキルシート・Excel（`skillLines`） | 「PHP（5年以上）：Laravel」の形。「得意」「★」「ひとこと」「最後に使った年月」「レベル」が出ない |
| AC-13 | 管理者 `/admin/engineers?q=laravel`、SES `/ses/engineers` | その EG が出て、列に Laravel |
| AC-14 | 案件（必須 `java`、尚可 `MySQL`）で `Matcher::score`・スキルシート `?case=` | 理由「必須 java（3年以上）」の形、対応表「○ 3年以上」。年数なしは「○ 経験あり」 |
| AC-16 | 管理者・自社 SES で保存できる。他社 SES・他の EG は GET/POST とも 403。仮登録中の EG を SES が開くと止まる | 今と同じ |
| AC-19 | 5件・年数あり／なし | 点と指摘の文 |
| AC-20 | 新しい表だけに行のある人 | `hasTech` が真になる流れ（今のテストの形に合わせる） |
| AC-21 | その他「ZzzLang」で保存 | `skill_terms` に `reviewed=false` で入る。`SkillTable::usedCounts` に新しい表の人が数えられる |
| AC-22 | GET の HTML の `name="` を数える（全部チェックした状態も） | 900 未満 |
| D10 | 新しい表に JSON に無い道筋の行を直接入れて GET → `keep[]` なしで POST | 画面に「今の表に無い項目」。POST 後に消える。`keep[]` ありなら残る |
| 境界 | 0件で保存、二重 POST | 0件で詳細「まだ登録がありません。」。二重でも行は同じ |

- 新規 `tests/Feature/SkillFormJsonTest.php`（単体）：`yearOptions` が `SkillForm::YEARS` と同じ／道筋の重複なし／11章の置き場所が全部ある／`words` の例（4-1）／`place` の例（`Java`→`lang//Java`、`Spring`→`lang/Java/Spring Framework`、`React`→JavaScript の下、`Node.js`→親 `Node.js（実行環境）`、`PM`→役割の PM、`HTML`・`CSS`→`HTML/CSS（…）`、`Windows`→Windows Server のその他、`金融`→そのほかのその他、`2.5`→1年以上、`0`→1年未満、`5`→5年以上、null→null）。
- 新規 `tests/Feature/SkillMigrateTest.php`：古い表に行（合う名前・合わない名前・同じ技術2行・年数 null）を入れて `migrateFromOld()` → 新しい表が 11章どおり、`source=migrated`。もう一度流しても行が増えない。新しい表に行がある人は変わらない。古い表は変わらない。fill の `down` 相当で `migrated` だけ消える。
- 直す `tests/Feature/SkillCaseCheckTest.php`（AC-15）：前の AC を新しい形に。印（`sf-mark`）が Java・Spring Boot・MySQL の項目に付く／チェックの状態は変わらない（保存済みでない項目に `checked` が付かない）／`missing` の文／管理者・SES・`?case=` の不正値・他人の 403・金額を出さない、は前と同じ。
- 古いテストの整理（`engineer_skills`・`skill_name`・`is_primary`・`SkillTable` を Grep。15 ファイル）：
  - 消えた機能（レベル・得意・ひとこと・最後に使った年月・経歴との注意・`SkillTable::save` の行の付き合わせ）だけを試すテストは消す。
  - スキルを入れる準備に `skillForm` を使っているだけのテストは `checkForm` に置き換え、確かめることは変えない。
  - 技術マスタの統合（`mergeTerms`）のテストは、古い表のまま残す（関数を残すため）。`usedCounts` のテストは新しい表で数えるように直す。
  - ダミー・見本・purge のテスト（`SeedDummyTest`・`PurgeSamplesTest`）は新しい表を数えるように直す。
  - 消した・書き換えたテストは、ファイルと理由を reviewer への引き継ぎに一覧で書く。
- 全体：`php artisan test`（GENESIS は `.env` が無いので `APP_KEY` を環境変数で渡す。zip が無い既知の1件は除く）。

## 14. マニュアル・README（`resources/manuals/` と README）

- `engineer.md`「### スキル」を書き直す：チェックで選ぶこと、節は開閉できること、年数は4つ（選ばなければ「経験あり」）、言語とフレームワークは別々にチェック（子だけでもよい）、一覧に無いものは「その他」で名前を書き「＋」で足せる、盛らない。レベルの表・得意・ひとことの項は消す。「### スキルシート」の得意・ひとことの文を消す。案件の印の文を D7 の形に直す（印は保存されない／チェックは自分で入れる）。
- `engineer-steps.md` 3-3 の 2 を新しい手順に（開く → 節を開く → チェックと年数 → その他 → 保存）。案件の印の手順も直す。
- `ses.md:82`・`ses-steps.md:59`：レベル・得意・ひとことの文を消し、「スキルはチェック式。年数は4段階」に。編集できる範囲と「確認しました」がやり直しになることは今のまま。
- `admin.md:120`：同じく直す。`admin.md:157`（統合）：「スキルのチェックは技術マスタの統合の影響を受けません。その他に書かれた言葉は確認待ちで足されます」に直す。`admin-steps.md:96`：得意・ひとことの文を消す。
- `README.md`：48行（エンジニアの機能の文）、81行（技術マスタ：スキルのその他だけを足す）、「## EG のスキル登録（v0.3.0）」の後ろに「## スキルのチェック式（v0.4.0。2026-11-11）」の節（正本は `skill_form.json`、`SkillForm`・`SkillChecks`、表と一意の鍵、年数の値、移行の決まり、古い表は次のバージョンで消す、案件の印）、ファイル一覧（`SkillForm.php`・`SkillChecks.php`・`skill_form.json` を足し、`SkillTable.php` の説明を「技術マスタの統合と古い表」に）。150行（案件チェック）の文を D7 に。
- 41_Park-Project の `04_Manuals`（エンジニア・SES・管理者の `マニュアル.md`・`操作手順書.md`、README の写し）は Leo が写し直す。

## 15. 実装の作業順

1. `SkillForm`（JSON の読み・`nodes`・`words`・`place`・年数）と `SkillFormJsonTest`。ここで JSON の形を固める。
2. マイグレーション2本と `SkillChecks::saveRows`・`migrateFromOld`、`SkillMigrateTest`。
3. `SkillChecks::save`・`forDisplay`・`lines`・`summaryMany`・`indexMany`。
4. 画面 `skills.blade.php` を作り直し、CSS・JS を足す。`EngineerController::skills`・`saveSkills`・`Workflow::saveSkills`。
5. 詳細・スキルシート・Excel・一覧（管理者・SES）・`Qa`・`Registration`・`usedCounts`。
6. `Matcher`（`skillIndexMany`・`score` の文、`rowMarks` を消す）と案件の印（9章）。
7. `DummySeed`・`SampleSeeder`・`SamplePurge`。`SkillTable` の要らない部分を消す。`config/park.php` を v0.4.0。
8. テストの新規・直し・整理（13章）。`php artisan test` 全体。
9. マニュアル・README（14章）。
10. 手元（MariaDB）で `php artisan migrate` を流し、古い表に行のある人数＝新しい表に行のある人数かを確かめ、画面を開いて見る（16章）。

## 16. 本番（公開版）への反映（Leo）

1. reviewer・tester を通したあと push。
2. 反映の前に、公開版の DB のバックアップが取れるなら取る（消す操作ではない。安全チェックで止まれば飛ばしてよい。古い表を消さない設計のため）。
3. `bash .claude/scripts/park-deploy.sh`（コード → migrate 2本 → キャッシュ。常時了承の範囲）。
4. `park-remote-status.sh` で、エラーが無いことと、古い表に行のある人数と新しい表に行のある人数が同じことを見る。合わなければ、手元で同じデータで流して原因を探す（ロールバックは `migrate:rollback` で新しい表が消えるだけ。古い表は元のまま）。
5. 画面（スキル画面・詳細・スキルシート）を開いて 200 を確かめる。見た目は TEZZO が公開版で確かめる（列数・開閉・その他の＋）。
- **TEZZO の了承が要るもの（この計画では行わない）**：古い表 `engineer_skills` と古い列を消すこと（次のバージョン）、データの削除、メールの送信、`.env` の書き換え。
- 案件・個人の情報を外部のサービスに渡さない（この機能は手元の計算だけ）。

## 17. 返事待ち（TEZZO に上げる件）

- なし。迷った点は 1章の D1〜D10 で Leo の決定として仮置きした（特に D5 の「そのほか」を見出しにする件、D7 の案件の印を「チェックを入れない」形にする件）。違えば Leo が直す。
