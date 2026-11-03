# PLAN：PARK v0.3.0 EG のスキル登録（2026-11-03）

> **これが今の計画**。下にある v0.2.0（2026-10-31）・ダミーデータ（2026-10-30）・EG 同期（2026-10-28）は前の計画で、そのまま残している。
> 依頼者：経済大臣 Leo。決定の正本：41_Park-Project の `41_開発/v0.3.0/v0.3.0-02-課題検討及びスコープ定義書.md`（台帳 S01〜S16、6章、8章、9章）と `v0.3.0-03-テスト設計書.md`（D・K・P・A・M・R）。**この計画と台帳が食い違ったら台帳が正。** 受け入れ条件の番号はテスト設計書の番号をそのまま使う。
> プレーヤー：管理者・SES・担当者・EG の4つ（担当者を抜かさない）。
> 15章の「Leo への確認」は、推奨で仮置きして作る。違えば Leo が直す。

## 0. コードで確かめた今の形

- 表は `DB::table` だけで扱っている（Eloquent のモデルは `User` だけ）。技術マスタの関数は `App\Support\SkillCatalog`（`find`・`ensure`・`map`・`forget`・`detect`）。`ensure()` は 40 文字を超えると null を返す（`SkillCatalog.php:117`）。
- スキル表の保存は `W::saveSkills`（`Workflow.php:708`）で、**全部消して入れ直す**。`SampleSeeder:73` と `DummySeed:295` も呼ぶ。経歴の保存は `W::saveProject`（`Workflow.php:728`。id を返さない）で、画面（`EngineerController::saveProject`）と AI の下書き（`AiController::addProject`）の両方が通る。
- `editedBy()`（`Workflow.php:689`）は「いまの立場」（`ActingRole` が `$u->role` を差し替え）で判定。兼任の印は `W::selfMark`。
- 仮登録の守りは `EngineerController::loadForEdit`（上位の編集を `/people/{uid}/edit` へ回す）と `AiController::queueResume`（403）。**`AiController::addProject` には仮登録の守りが無い**（9章の「AI の下書きの取り込みも断る」に足りない。6-1 で足す）。
- `month_diff()`（`helpers.php:155`）は `2019/4` などで `explode` の2つ目が無く落ちる。`EngineerDocs::span`（41 行）・`Billing:31` が使う。
- `Matcher::score` は1人ごとに `skillIndex()`（2回の問い合わせ）。呼ぶ所：`forCase`、`CaseController:85・92・121`、`SesController:70`（案件 × 人）、`CaseMail:97`、`W::distribute:810`、`Qa`。
- 技術マスタのまとめる処理（`AdminController::mergeSkill`）は transaction が無い。「使っている人」はスキル表の行数（`used`）。
- スキル表の画面の候補（datalist）は、確認待ち（`reviewed=false`）の言葉も出している（`EngineerController::skills:245`）。経歴の画面には候補が無い。
- スキル表の画面に、S04 と違うレベルの目安の文がある（`skills.blade.php:14`）。
- バージョンは `config/park.php` の `version` の1か所（足元に出る）。`RegistrationFoundationTest:52` が `'v0.2.0'` を比べている。
- `SeedDummyTest` は `audit_log` の件数が増えないことを見ている → スキルの記録（K-07）は `W::saveSkills` の中ではなく、コントローラで書く。
- マニュアルは `resources/manuals/` の admin・ses・engineer（各 `.md` と `-steps.md`）。担当者は ses の中の節（v0.2.0 の決定 10）。

## 1. DB（マイグレーション2本。S01・S09・8章）

### 1-1. `2026_11_03_000001_skill_tables.php`（表と列）

- 新しい表 `engineer_project_skills`：`id()`、`foreignId('project_id')->constrained('engineer_projects')->cascadeOnDelete()`、`foreignId('term_id')->constrained('skill_terms')`（消すときは止める。まとめる処理で先に付け替える）、`unique(['project_id', 'term_id'])`。`engineer_id` は持たない（8-4・H4）。
- `engineer_skills` に足す：`char('last_used_ym', 7)->nullable()`、`boolean('is_primary')->default(false)`、`string('note', 200)->nullable()`、`timestamps()`（空可。今ある行は空のまま）、`index(['engineer_id', 'term_id'])`（一意にしない。S05）。
- down：新しい表を落とす → 索引を落とす → 足した列を落とす（SQLite は索引を先に落とす）。

### 1-2. `2026_11_03_000002_fill_project_skills.php`（データの移し替え）

- up：`SkillCatalog::forget()` → `ProjectSkills::rebuildAll()`（2-1）。**マスタに足さない（`ensure` を呼ばない）。今の表の行は消さない・書き換えない。** 何度流しても同じ。
- down：`engineer_project_skills` の行を消すだけ（新しい表の中だけ。元の文字列は残る）。

## 2. 共通の部品

### 2-1. `app/Services/ProjectSkills.php`（経歴の索引。S02・S11・8-4・8-5）

- `const TECH_CATS = ['lang', 'fw', 'db', 'os', 'cloud', 'infra', 'tool', 'other'];`（`method`・`domain`・`role` は入れない。S11）
- `rebuild(int $projectId): void` — **索引を作る関数はこれ1つ。経歴の保存（画面）・AI の下書きの取り込み・移し替えの3か所がこれを通る**（画面と AI は `W::saveProject` の中で呼ぶ）。経歴の行を読み、`project_techs()` で分け、`SkillCatalog::find()` で引き、分類が `TECH_CATS` の term_id だけを集める（重複は PHP の配列で1つに）。transaction で、その経歴の行を消して `insertOrIgnore` で入れ直す。経歴が無ければ行を消すだけ。マスタに無い言葉は入れない（文字列にだけ残る）。
- `rebuildAll(): void` — `engineer_projects` を id 順に `chunkById(200)` で読み、1件ずつ `rebuild` と同じ処理をする。
- `stats(array $engineerIds): array` — `[engineer_id][term_id] => ['months' => int, 'projects' => int, 'last' => ?'YYYY-MM']`。新しい表と経歴を join して **1回** で読む（500 件ずつ）。月の集まりは `ym_months()`（2-2）の和集合で、重なりは1回（8-5）。`projects` はその技術が付いた経歴の件数（期間が数えられなくても数える）。`last` は数えた月の最後。
- `statsFromRows(...)` — Matcher が読んだ行から同じ計算をする内部の関数（問い合わせを足さないため）。

### 2-2. helpers.php に足す・直す（S13）

- `ym_valid($s): bool` — `^\d{4}-(0[1-9]|1[0-2])$` だけを通す。
- `ym_months(?string $from, ?string $to): array` — 月の通し番号（年×12＋月）の配列。始まりが空・形が違う → `[]`。終わりが空 → 今月。**終わりの形が違う → `[]`**（仮定：始まりと同じ扱い）。終わりが今月より後 → 今月で打ち切り。終わりが始まりより前（打ち切りのあとも）→ `[]`。
- `fmt_months(int $n): string` — 12 未満は「◯か月」、ちょうどの年は「◯年」、ほかは「◯年◯か月」（例 15 → 1年3か月、24 → 2年）。
- `month_diff()` を直す：先頭7文字が `ym_valid` でなければ 0 を返す（落ちない）。`Billing` の正しい値の結果は変わらない。`EngineerDocs::span` は始まり・終わりが `ym_valid` でなければ空の文字を返す。

### 2-3. `app/Services/SkillTable.php`（スキル表。S04〜S07・S15）

- `const LEVELS`（S04。画面とマニュアルの文の正本）：1 学んだが業務では未経験／2 指示を受けて作れる／3 一人で作れる／4 設計・レビューができる／5 人に教え、技術を選べる。
- `mergeRows(iterable $rows): object` — 同じ技術の行を1つにする決まり（保存・表示・マスタのまとめるで共通）：`years`・`level`・`last_used_ym` は大きいほう、`is_primary` はどれかが付いていれば付ける、`note` は先の行（id の小さい順・送った順）の空でないもの。
- `dedupe($rows): Collection` — 表示用。`term_id` があればそれで、無ければ `'n:'.norm_skill(name)` でまとめる（K-12。DB は触らない）。
- `forDisplay(int $engineerId): Collection` — `dedupe` に、`ProjectSkills::stats` の月数・件数・最後の年月を付ける。`term_id` が空の行は `SkillCatalog::find(name)` で引いて付ける（K-11）。行ごとに `warn`（S03：その技術の経歴が1件以上、申告があり、`years×12 − 月数 ≥ 12`）を付ける。
- `save(int $engineerId, array $d): array` — 3章。戻り値は `['ok' => true, 'names' => [...]]` か `['bad' => '理由']`。
- `mergeTerms(int $fromId, int $toId): void` — 7章。
- `usedCounts(): array` — `term_id => 人数`（スキル表と経歴の両方。同じ人は1回）。

`W::saveSkills($id, $d)` は `SkillTable::save` を呼ぶだけの入口にして残す（`SampleSeeder`・`DummySeed` がそのまま動く。新しい欄が無くても既定値で通る）。

## 3. スキル表の保存（K-01〜K-12。S05〜S07）

送られる欄（行ごとの配列。今の4つに3つ足す）：`skill_name[]`・`skill_cat[]`・`skill_years[]`・`skill_level[]`・`skill_last[]`（`type=month`）・`skill_primary[]`（`0`/`1` の select。行の並びをずらさないため checkbox にしない）・`skill_note[]`。

1. **検査（DB に書く前に全部）**。1つでも当たれば何も保存せず、理由を出して戻す（入力は `old()` で画面に戻す）。
   - 名前が空の行は捨てる。名前が 41 文字以上 → 断る（40 文字は通す。K-04）。
   - レベル：空は 3、1〜5 以外は断る。
   - 年数：空は null。数でない・0 未満・100 以上は断る（仮定。列が decimal(4,1) のため）。
   - 最後に使った年月：空は通す。`ym_valid` でない・今月より後 → 断る（K-09）。
   - ひとこと：201 文字以上 → 断る（K-10）。
2. **transaction の中で**、行ごとに `SkillCatalog::find(name)`、無ければ `ensure(name, 分類)` して引き直す（K-03：`reviewed=false` で足され、行に term_id が入る）。それでも引けなければ断る（全部戻す）。失敗で戻したら `SkillCatalog::forget()`。
3. 同じ term_id の行を `mergeRows` で1つにする（K-05。別名・大文字小文字・全角も `find` で同じ term になる）。
4. 得意の印が 6 つ以上 → 断る（全部戻す。K-08）。
5. 今ある行と突き合わせる（S07。全部消して入れ直さない）。
   - 送られた技術 T ごとに、今ある行のうち term_id が T の行（id の小さい順）、無ければ term_id が空で名前を `find` すると T になる行を「残す行」にする。あれば値が変わったときだけ update（term_id を埋める＝K-11、`name`・`category` はマスタの正式な名前と分類、`updated_at`）。無ければ insert（`created_at`・`updated_at`）。
   - T に当たる残りの行（DB にある重複）は消す（保存のときに1つにまとめる。S05）。
   - どの T にも当たらない今ある行（画面で消した行）は消す。
6. `engineers.updated_at` を更新。

コントローラ（`EngineerController::saveSkills`）：`loadForEdit` → `W::saveSkills` → `bad` なら戻す → `W::editedBy`（今の判定のまま。S07）→ `N::audit($actor->id, 'skill-save', W::selfMark($actor, $id).'エンジニア #'.$id.'：'.技術の名前を「、」でつないだもの)`（ひとことなど中身の文は書かない。K-07）。

分類（仮置き。15章の 1）：スキル表は今のまま全部の分類を受け付ける。新しい表だけ技術の分類に限る。

## 4. 経歴の保存（P-01〜P-03・P-09。S02・S13）

- `W::saveProject` は transaction で保存し、経歴の id を返す（insert は `insertGetId`。update は `engineer_id` が合わず0件なら何もしない）。保存のあと `ProjectSkills::rebuild($pid)`。
- `EngineerController::saveProject`：保存の前に期間の形を検査する。始まり・終わりが空でなく `ym_valid` でなければ「期間は 2019-04 の形で入れてください。」で戻す（P-09）。空の始まり・空の終わりは通す（仮定：始まりの空は今も通り、数えないだけ）。
- AI の下書き（`AiController::addProject`）は `W::saveProject` を通るので索引が作られる（P-02）。期間の検査はしない（仮置き。15章の 5）。
- `EngineerController::deleteProject`：経歴を消す前に `engineer_project_skills` のその経歴の行を消す（FK の cascade に頼らない。P-03）。

## 5. 月数と注意（P-04〜P-08・P-10〜P-13。S03・S13・8-5）

- 計算は 2-1・2-2 のとおり。保存しない。表示と候補選びのときに計算する。
- 注意の文「経歴に書かれていない期間があります」は、エンジニアの立場で見ているとき（台帳の「本人に」）だけ、スキル表の編集画面と詳細画面の、その技術の行に出す。

## 6. 権限（A-01〜A-09。9章・S07・S14）

今の動きのまま（`Perms::engineer`・`loadForEdit`・`role` ミドルウェア）。

| 操作 | 管理者 | SES | 担当者 | EG |
|---|---|---|---|---|
| 自分のスキル表・経歴 | － | － | － | ○（「確認しました」はそのまま） |
| 自社の EG（参加中・停止中） | ○（全員・直属） | ○ | ○ | － |
| 仮登録中の EG（AI の取り込みを含む） | × | × | × | － |
| 他社の EG | ○ | 403 | 403 | 403 |
| マスタを確かめる・まとめる | ○ | 403 | 403 | 403 |

- 6-1：`AiController::addProject` に、`queueResume` と同じ守りを足す：エンジニアの立場でない人が、仮登録中（`users.role='eg'` かつ `registered_at` が空）の EG に取り込む → 403。
- EG 以外が直したら `self_confirmed_at` を消す（`editedBy`。今のまま）。兼任は入るときに選んだ立場で判定する。

## 7. マスタ（M-01〜M-03。S10）

`AdminController::mergeSkill` は `SkillTable::mergeTerms($t->id, $to->id)` を呼ぶ。中身は1つの transaction：

1. まとめる先 A の別名に B の名前と別名を足す（今のまま）。
2. 新しい表：`term_id=B` の行を A に付け替える（`insertOrIgnore` で `(project_id, A)` を入れる）→ `term_id=B` の行を消す。
3. スキル表：`term_id=B` の行を A（名前・分類も A）にする → B を持っていた EG ごとに、`term_id=A` の行が2つ以上なら `mergeRows` で1つにし（id の小さい行を残す）、ほかを消す。
4. マスタの B を消す。
5. transaction のあとで `SkillCatalog::forget()`、`N::audit`（今のまま）。

- 「使っている人」は `SkillTable::usedCounts()` にし、見出しを「使っている人（スキル表・経歴）」にする（M-03）。
- 仮置き（15章の 2）：`addSkill`・`updateSkill` で保存したあと、`ProjectSkills::rebuildAll()` を呼ぶ（あとから足された言葉・変わった分類と別名を、経歴の索引に合わせるため）。
- まとめた結果、得意の印が 5 つを超える人がいても、まとめる処理では断らない（次にその人が保存するときに 5 つに減らしてもらう。仮定）。

## 8. 候補選び（R-01・R-02。S16）

- `Matcher::skillIndexMany(array $engineerIds): array`（`engineer_id => idx`）：スキル表・経歴・新しい表を、人数によらず3回の問い合わせで読む（500 人ずつ）。idx の作り方は今のまま（スキル表の行が先、経歴の文字列の言葉はまだ無い言葉だけ `fromProject=true`）。新しい表から作った `months` を、その技術の項目に付ける（点数には使わない。次のバージョンの準備）。
- `skillIndex($id)` は `skillIndexMany([$id])[$id] ?? []`。`score($c, $e, ?array $idx = null)`：渡されなければ今のとおり読む。**点数の計算は1文字も変えない。**
- まとめて読む所：`forCase`（必須）、`CaseController:85`、`SesController::cases`（案件の外で1回）、`CaseMail` の候補の繰り返し、`W::distribute`。

## 9. 画面

- **スキル表の編集**（`engineers/skills.blade.php`、`EngineerController::skills`）：列は スキル・分類・年数（申告）・経歴から（月数と件数。見るだけ）・レベル（`SkillTable::LEVELS` の文で選ぶ）・最後に使った年月（`type=month`。空なら下に「経歴から：◯◯」）・得意（— ／ 得意）・ひとこと（`maxlength=200`）・削除。行は `SkillTable::forDisplay` から作る（重複は1行）。上の目安の文を S04 の文に替え、「得意は5つまで」「ひとことは、名前を伏せたスキルシートには出ません」を添える。候補（datalist）は `reviewed=true` の言葉だけ（S12）。入力の誤りで戻ったときは `old()` の値で行を作る。
- **詳細**（`engineers/show.blade.php`）：スキルの欄を `forDisplay` に替える（重複は1つ）。申告の年数と「経歴 ◯年◯か月」を並べ、得意の印（バッジ「得意」）、ひとこと、エンジニアの立場なら注意の文。
- **経歴**（`engineers/project.blade.php`）：使った技術・機種 OS ツール・言語・DB の4つの欄に、`reviewed=true` かつ技術の分類の言葉を候補に出す（datalist 1つ。入力のたびに「、」の前までを頭に付けた候補に作り直す数行の JS。外部のライブラリは使わない。`x-field` が `list` を渡せなければ、部品に属性を足すか、この4つだけ素の input にする）。文字列の入力はそのまま（S02）。
- **スキルシート**（`engineers/sheet.blade.php`、`EngineerController::sheet`）：スキルを `dedupe` に替える。スキルの見出しのすぐ下に「得意な技術：…」（K-14）。名前ありのときだけ、技術の後ろにひとことを小さく出す（`?anon=1` では出さない。K-13・S15）。
- **業務経歴書の Excel**（`EngineerDocs::careersheet`）：保有スキルの行を新しい `EngineerDocs::skillLines(int $id): array` で作る（`dedupe`、頭に「【得意】…」。ひとことは出さない。仮置き：15章の 4）。テストはこの関数で見る（手元の CLI に zip が無いため）。
- **技術マスタ**（`admin/skills.blade.php`）：「使っている人（スキル表・経歴）」。
- SES・管理者のエンジニアの一覧の「スキル」の文字は、名前の重複を除いて出す（`array_unique`）。

## 10. 消す処理（R-04・R-05）

- `SamplePurge::ORDER` の `engineer_projects` の前に `'engineer_project_skills' => 'id'` を足す。消す行は、消す EG の経歴の id につながる行。バックアップの JSON にも入る（ORDER の全部の表を書き出す作りなので、ORDER に足せば入る）。
- `tests/Feature/EmptyStateTest.php` の setUp の表の並びにも、`engineer_projects` の前に足す。
- 仮登録の取り消し（`Registration::cancel`）はコードを変えない。技術データが無い EG だけ engineers を消す（経歴が無いので新しい表の行も無い）。経歴がある EG は v0.2.0 のとおり技術登録を残す（15章の 3）。

## 11. バージョン・マニュアル（R-06・R-07）

- `config/park.php` を `'version' => 'v0.3.0'`。`RegistrationFoundationTest:52` の比べる値も `v0.3.0` に直す。
- マニュアル（`resources/manuals/`）
  - `engineer.md`「スキル」・`engineer-steps.md` 3-3 の 2：レベルの5つの文（`LEVELS` と同じ文）、最後に使った年月、得意（5つまで。スキルシートの頭に出る）、ひとこと（名前を伏せたスキルシートには出ない）、「経歴から数えた月数」が並んで出ること、注意の文の意味（盛らない）、同じ技術は1つにまとまること、候補は確認済みの言葉だけ。
  - `ses.md`・`ses-steps.md`（SES と担当者の節の両方）：自社の EG のスキル表・経歴を直せること、直すと EG の「確認しました」がやり直しになること、仮登録中は直せないこと、スキルシートの得意とひとこと。
  - `admin.md`（技術マスタの節）・`admin-steps.md`：「使っている人」は経歴の分も数えること、統合で経歴の索引とスキル表の重複も1つになること、確認済みにすると経歴の索引が作り直されること。スキルシートの得意とひとこと。
- 41_Park-Project の `04_Manuals` の写しは Leo が写し直す。

## 12. 受け入れ条件とテスト

- ファイル：`tests/Feature/V030DbTest.php`（D）、`V030SkillsTest.php`（K）、`V030ProjectMonthsTest.php`（P）、`V030PermsTest.php`（A）、`V030MasterTest.php`（M）、`V030RegressionTest.php`（R）。
- テストの名前：`test_<記号の小文字>_<2桁>_<短い英語>`（例 `test_k_05_merges_same_term`、`test_p_04_overlap_counts_once`）。1つの番号に複数の関数を作るときは、同じ頭に続けて書く（`test_a_05_ses_other_company_skills`・`test_a_05_ses_other_company_projects`）。各ファイルの先頭のコメントに「v0.3.0 テスト設計書 版 01」と書く。
- 月数のテストは `$this->travelTo('2026-11-15')` で今月を決める。アドレスは `@example.com` か見本の `@park.example` だけ。データの作り方は今のテスト（`ParkTest`・`DualRoleTest`・`SesToPeopleRegistrationTest`）の作法に合わせる。

### 12-1. DB と移し替え（`V030DbTest.php`）

| AC | 手順 → 期待 |
|---|---|
| D-01 | `Schema::hasTable('engineer_project_skills')`・`hasColumns`。同じ `(project_id, term_id)` を2回 insert → 例外。`engineer_skills` に同じ `(engineer_id, term_id)` を2行 insert → 通る。`Schema::getIndexes('engineer_skills')` に `[engineer_id, term_id]` の一意でない索引 |
| D-02 | 経歴に `tech='Laravel、要件定義、ZzNoMaster'`・`langs='PHP(Laravel)'` を直接 insert → 新しい表を空にする → 移し替えのクラスの `up()` を呼ぶ（`(require database_path('migrations/2026_11_03_000002_fill_project_skills.php'))->up()`）→ Laravel・PHP だけが入る。要件定義（method）・ZzNoMaster は無い。`skill_terms` の件数が同じ |
| D-03 | `up()` を2回 → 新しい表の件数と中身が同じ、`skill_terms` の件数が同じ |
| D-04 | 移し替えの前後で、`engineer_projects` の4つの文字列と `engineer_skills` の全部の行（`term_id` が空の行を含む）が同じ |
| D-05 | 2本の `down()` を新しい順に呼ぶ → 新しい表と列が無い、経歴の文字列が残る → `up()` を古い順に呼んで戻す |
| D-06 | 手動（12-7）。結果はエビデンスに書く |

### 12-2. スキル表（`V030SkillsTest.php`。立場は EG 本人。POST `/engineers/{id}/skills`）

| AC | 手順 → 期待 |
|---|---|
| K-01 | 7つの欄を入れて保存 → DB に全部、GET `/engineers/{id}`・`/skills` に出る |
| K-02 | GET `/engineers/{id}/skills` に `SkillTable::LEVELS` の5つの文 |
| K-03 | `ZzNewTech` を保存 → `skill_terms` に `reviewed=0`、行の `term_id` が入る。管理者で GET `/admin/skills` に `ZzNewTech` と「確認待ち」 |
| K-04 | 41 文字 → flash に理由、行もマスタも増えない。40 文字 → 通る |
| K-05 | `PostgreSQL`・`postgres`・全角の `ＰｏｓｔｇｒｅＳＱＬ` の3行 → 1行（値は `mergeRows` の決まり） |
| K-06 | 3行を保存 → id と `created_at` を控える → 1行のレベルを変え、1行を外して保存 → 残った2行の id・`created_at` が同じ、外した行だけ無い |
| K-07 | 保存のあと `audit_log` に `skill-save`。detail に技術の名前があり、ひとことの文が無い |
| K-08 | 得意を6行 → 断る、DB は保存の前のまま |
| K-09 | `2019/04`・来月 → 断る。空・`2020-05` → 通る |
| K-10 | ひとこと 201 文字 → 断る。200 文字 → 通る |
| K-11 | EG1・EG2 に `term_id` が空の `Laravel` の行を直接入れる → EG1 が保存 → 埋まる（行の id は同じ）。EG2 の GET 詳細・`/skills`・`/sheet` が 200、`EngineerDocs::skillLines` が落ちない、行は空のまま |
| K-12 | 経歴の無い EG に同じ term の2行を直接入れる → 詳細のスキルの欄と `skillLines` に1回だけ。DB は2行のまま |
| K-13 | ひとことのある EG で GET `/sheet?anon=1` に出ない、`/sheet` に出る |
| K-14 | 得意を付けた技術が「得意な技術：」の行に出る（`/sheet`）。`skillLines` の頭が「【得意】」 |

### 12-3. 経歴と月数（`V030ProjectMonthsTest.php`）

| AC | 手順 → 期待 |
|---|---|
| P-01 | EG が POST `/engineers/{id}/projects`（`tech='Laravel、Docker'`）→ 新しい表に2行 → 同じ経歴を `Laravel、AWS` で保存 → Docker が消え AWS が入る |
| P-02 | `ai_jobs`（kind=resume・status=done・下書きに経歴1件）を作る → POST `/ai-jobs/{id}/project/0` → 新しい表に行（下書きの形は `AiJobs::items` に合わせる） |
| P-03 | POST `/engineers/{id}/projects/{pid}/delete` → その経歴の新しい表の行が0 |
| P-04 | Laravel の経歴 2019-04〜2020-03 と 2020-01〜2020-06 → `ProjectSkills::stats` で 15 |
| P-05 | 2026-01〜空 → 11（今月まで） |
| P-06 | 始まりが空・`2019/4`・`令和1年` → 0。その EG で詳細・`/sheet`・`/skills`・`skillLines`・`Matcher::forCase` が落ちない。`month_diff('2019/4', '2020-01')` が 0 |
| P-07 | 2020-05〜2020-01 → 0 |
| P-08 | 2026-01〜2027-12 → 11（今月で打ち切り） |
| P-09 | POST で `period_from='2019/04'` → 断る、行が増えない。`period_to='abc'` → 断る。`period_to` が空 → 通る |
| P-10 | 申告 5 年、経歴 24 か月 → EG で GET `/skills` に注意の文 |
| P-11 | 申告 3 年、経歴 25 か月 → 出ない |
| P-12 | 申告 10 年、その技術の経歴0件 → 出ない |
| P-13 | GET `/skills` と詳細に「5年」と「経歴 2年」が並ぶ |
| P-14 | `reviewed=1` の `ZzReviewed`・`reviewed=0` の `ZzUnreviewed` を足す → GET `/engineers/{id}/projects/new` に前者があり後者が無い。`/skills` も同じ |

### 12-4. 権限（`V030PermsTest.php`。スキル表と経歴の両方をデータプロバイダで回す）

| AC | 手順 → 期待 |
|---|---|
| A-01 | EG が自分を直す → 通る、`self_confirmed_at` が残る |
| A-02 | 管理者が SES の EG と直属の EG を直す → 通る、`self_confirmed_at` が null |
| A-03 | SES（代表者）が自社の EG（参加中・`suspended_at` あり）を直す → 通る、null |
| A-04 | 担当者が自社の EG を直す → 通る、null |
| A-05 | SES・担当者が他社の EG に POST → 403、行は変わらない |
| A-06 | EG が他の EG に POST → 403 |
| A-07 | 仮登録中の EG に、管理者・SES・担当者が POST → `/people/{uid}/edit` に回され、行は変わらない。`ai_jobs` を作って POST `/ai-jobs/{id}/project/0` → 403、経歴が増えない |
| A-08 | 管理者を兼ねる EG・担当者を兼ねる EG：エンジニアの立場で自分を直す → `self_confirmed_at` が残る。管理者・担当者の立場で入って直す → null（立場の入り方は `DualRoleTest` に合わせる） |
| A-09 | SES・担当者・EG で GET `/admin/skills`、POST `/admin/skills/{id}/merge` → 403 |

### 12-5. マスタ（`V030MasterTest.php`）

| AC | 手順 → 期待 |
|---|---|
| M-01 | B（`ZzOld`）を使う経歴・スキル表、同じ経歴に A と B、同じ EG のスキル表に A と B を作る → 管理者で POST `/admin/skills/{B}/merge`（to=A）→ 新しい表は A だけで経歴ごとに1行、スキル表は A の1行、B がマスタに無い |
| M-02 | SQLite の trigger（`BEFORE DELETE ON skill_terms` で `RAISE(ABORT)`。`SeedDummyTest:299` と同じやり方）→ `SkillTable::mergeTerms` が例外 → 新しい表・スキル表・A の別名が元のまま |
| M-03 | スキル表だけ・経歴だけ・両方の3人が A を使う → `SkillTable::usedCounts()[A] === 3`。GET `/admin/skills` が 200 |

### 12-6. 今の機能（`V030RegressionTest.php` と今のテスト）

| AC | 手順 → 期待 |
|---|---|
| R-01 | 今の Matcher のテストが通る。加えて：スキル表に PHP（3年）、経歴に Laravel の EG、必須 `Laravel、PHP`・尚可なし・年数なし・単価なし・開始日なし・働き方 any の案件 → `Matcher::score` が 82（55×1.8/2＋7.5＋8＋5＋6＋6）。`forCase` の結果の点数も 82 |
| R-02 | `DB::enableQueryLog()`。経歴のある EG 3人で `forCase` の問い合わせの数を数える → 3人足して数え直す → 同じ（先に1回呼んでマスタの読み込みを済ませる） |
| R-03 | `EngineerDetailTest`（Excel）・Q&A のテストが通る（Excel は zip の無い既知の1件を除く） |
| R-04 | 見本の EG に経歴と新しい表の行を作る → `park:purge-samples --yes` → その行が0、バックアップの JSON に `engineer_project_skills` |
| R-05 | 15章の 3 のとおり：技術データの無い仮登録の EG を取り消す → 通る。経歴のある仮登録の EG を取り消す → 通り、`engineer_project_skills` に親の無い行が0 |
| R-06 | GET `/login` に `v0.3.0`、`config('park.version') === 'v0.3.0'` |
| R-07 | GET `/manual/engineer` に `LEVELS` の文・「得意」・「ひとこと」、`/manual/ses` に「確認しました」のやり直し、`/manual/admin` に「経歴」の数え方。写しとの一致は Leo が見る |
| R-08 | `php artisan test --testsuite=Feature` が通る（zip の既知の1件を除く） |

### 12-7. D-06：手元の MariaDB で移し替え（手動。implementer か tester）

`jonassan_park` には触らない。GENESIS は `.env` を作らないので、環境変数で捨ててよいテスト用の DB を指す。

1. MariaDB が動いているか確かめる（止まっていれば `mysqld.exe --defaults-file=D:\xampp\mysql\bin\my.ini --standalone`）。
2. `CREATE DATABASE park_v030_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;`
3. Git Bash で `APP_KEY=… DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_DATABASE=park_v030_test DB_USERNAME=root /d/xampp/php/php.exe artisan migrate --seed`。
4. 同じ環境で `migrate:rollback --step=2` → 経歴に `laravel、ＬＡＲＡＶＥＬ、Laravel、TCP/IP、ZzNoMaster` などを足す（SQL か tinker）→ `migrate` → 新しい表の件数・`skill_terms` の件数を控える。
5. `migrate:rollback --step=1` → `migrate`（移し替えの2回目）→ 件数が同じ、止まらない。
6. 結果をエビデンスに書く。`park_v030_test` は消さずに残し、Leo に伝える。

## 13. 作業手順（implementer は上から順に。段階ごとに `php artisan test --testsuite=Feature`）

**段階 1：DB と部品**
1. helpers.php：`ym_valid`・`ym_months`・`fmt_months`、`month_diff` の直し。
2. マイグレーション2本（1章）。
3. `ProjectSkills`（2-1）。
4. `V030DbTest.php`（D-01〜D-05）。

**段階 2：経歴**
5. `W::saveProject`（id を返す・`rebuild`）、`EngineerController::saveProject` の期間の検査、`deleteProject`。
6. `AiController::addProject` の仮登録の守り（6-1）。
7. `EngineerDocs::span` の直し。
8. 経歴の画面の候補（9章）。
9. `V030ProjectMonthsTest.php` の P-01〜P-09・P-14。

**段階 3：スキル表**
10. `SkillTable`（`LEVELS`・`mergeRows`・`dedupe`・`forDisplay`・`save`）、`W::saveSkills` を入口に。
11. `EngineerController::saveSkills`（断り・`old()`・記録）・`skills`（候補を確認済みだけ）・`show`・`sheet`。
12. 画面：`skills`・`show`・`sheet`、`EngineerDocs::skillLines` と careersheet。
13. `V030SkillsTest.php`（K）、P-10〜P-13。

**段階 4：権限**
14. `V030PermsTest.php`（A）。足りない守りが見つかれば直す（動きは今のまま）。

**段階 5：マスタ**
15. `SkillTable::mergeTerms`・`usedCounts`、`AdminController::mergeSkill`・`skills`・`addSkill`・`updateSkill`（`rebuildAll`）、画面の見出し。
16. `V030MasterTest.php`（M）。

**段階 6：候補選び・消す処理**
17. `Matcher::skillIndexMany`・`score` の引数、8章の呼ぶ所。
18. `SamplePurge::ORDER`、`EmptyStateTest` の setUp。
19. `V030RegressionTest.php` の R-01・R-02・R-04・R-05。

**段階 7：仕上げ**
20. バージョン（`config/park.php`・`RegistrationFoundationTest`）、R-06。
21. マニュアル（11章）、R-07。
22. 全体（R-08）、D-06（12-7）。
23. park-reviewer → park-tester。commit・push・`park-deploy.sh` は Leo。

## 14. TEZZO の了承が要るもの

- 公開版の `.env` の書き換え、データの削除（`purge-samples --yes`、公開版での rollback）、メールの一斉送信。
- コードの反映（push → `park-deploy.sh`。migrate は新しい表と列を足し、新しい表に索引を入れるだけ）は常時了承の範囲。反映の前後に Leo が `park-remote-status.sh` で数を見る。
- レベルの5つの文（S04）は外の人に見せる文なので、TEZZO のレビューの項目に入れる（画面の見た目も）。

## 15. Leo への確認（推奨で仮置きして作る）
> **2026-11-03 Leo が5件とも推奨どおりに決めた**（決めた人：Leo。記録は 41_Park-Project の `41_開発/v0.3.0/v0.3.0-03-テスト設計書.md` の末尾）。R-05 はテスト設計書も読み替えた。

**1** スキル表に入れる分類：8-2 は「新しい表とスキル表に入れるのは技術の分類だけ」、S11 は新しい表だけを書いている。今のスキル表は全部の分類を受け付け、`DummySeed` の見本（公開版のダミー 80 人）にも「基本設計」（method）・「金融」（domain）の行がある。スキル表でも断ると、見本の作り直しと、その行を持つ人の保存が止まる。**推奨：S11 のとおり新しい表だけに限り、スキル表は今のまま。** 答え方：推奨でよい／スキル表も限る（そのときは新しい行だけ断り、今ある行は残す）。

**2** 経歴の索引が古くなる件：台帳は「経歴を保存するたびに作り直す」だけ。あとからマスタに言葉が足された・分類や別名が直された・確認待ちの言葉が確かめられたとき、保存しない経歴の索引は古いまま（月数・使っている人に出ない）。**推奨：管理者の「追加」「保存（確認済みにする）」のあとに、全部の経歴の索引を作り直す（`rebuildAll`。今の件数なら一瞬）。案件メールから自動で足された言葉は、管理者が確かめたときに入る。** 答え方：推奨でよい／作り直さない。

**3** 仮登録の取り消し（R-05・5章）：「新しい表の行も消え、通る」とあるが、今の取り消しは、技術データ（経歴など）がある EG の技術登録を消さずに残す（v0.2.0 の決まり）。技術データの無い EG には経歴が無いので、消える行がそもそも無い。**推奨：R-05 の期待を「取り消しが通り、新しい表に親の無い行が残らない」と読み替える（コードは変えない）。** テスト設計書の R-05 も合わせて直す。答え方：推奨でよい／経歴ごと消す（データの削除になるので TEZZO の了承が要る）。

**4** Excel の業務経歴書：K-13・K-14 は「スキルシート」、3-1 は「Excel は今のまま文字列を読む」。Excel は名前を伏せた版も、元請けに出す実物。**推奨：Excel にも得意を保有スキルの頭に出し（K-14）、ひとことは名前のあり・なしにかかわらず Excel に出さない。画面のスキルシートは K-13 のとおり。** 答え方：推奨でよい／Excel は変えない（重複のまとめ K-12 だけ）。

**5** AI の下書きの期間：S13 は「経歴の保存のときに形を検査」、理由に「AI の下書きもそのまま入る」。AI が `2019/4` と出すと、取り込みで断れば下書きが使えない。**推奨：画面の保存だけ検査し、AI の取り込みは断らずに入れる（数えないだけ。経歴の画面で直すときに検査が効く）。** 答え方：推奨でよい／AI の取り込みも断る。

---

# PLAN：PARK v0.2.0 仮登録 → 本登録（2026-10-31）

> 前の計画（v0.2.0）。下にある 2026-10-30（ダミーデータ）と 2026-10-28（EG 同期）は前の計画で、そのまま残している。2026-10-28 は S2-25・S2-26（B1）で使う。
> 依頼者：経済大臣 Leo。決定の正本：41_Park-Project の `41_開発/v0.2.0/v0.2.0-02-課題検討及びスコープ定義書.md`（台帳 K01〜K36、6章、8章）。この計画と台帳が食い違ったら台帳が正。
> 呼び名：プレーヤーは 管理者・SES（代表者）・担当者・エンジニア（EG）の4つ。

## 0. 全体の設計（3つの段階で共通。段階 1 で作る）

### 0-1. 考え方（あとで作り直さないための骨組み）

- 手続きは新しい `app/Services/Registration.php` に集める。`W::invite` は段階 1 で中身を `Registration` に渡す薄い入口にし、段階 3 の終わりに、呼ぶ所がなくなったら消す。
- 必須項目は新しい `app/Support/Requirements.php` の1か所で決める（8章の表を、4つの立場の分すべて段階 1 で入れる）。本登録の画面・足りない項目の画面（K24・K32）・編集の入口（K20）が同じものを使う。
- 「足りないか」は保存した印ではなく、**今の値と立場から毎回計算する**。これで K24（判定は立場ごと）と K32（足りない項目はあとで取る）が1つの仕組みになり、`profile_completed_at`（1人に1つ）で判定しなくて済む。
- 本登録の画面は段階 1 で4つの立場すべてに対応させる。段階 2・3 で作るのは、上位が登録する入口と権限だけ。古い入口から出たリンクも新しい本登録の画面に入るので、途中の段階でも流れは壊れない（K26）。

### 0-2. データ（K35。マイグレーション `2026_10_31_000001_registration_columns.php`）

| 表.列 | 型 | 意味 |
|---|---|---|
| `users.registered_at` | dateTime null | 本登録の完了の日時（K17・K35）。null＝仮登録 |
| `users.registered_by` | unsignedBigInteger null | 登録した人（K25・K35）。外部キーは張らない（見本を消すとき順番の問題が出るため） |
| `users.token_kind` | string(10) null | リンクの種類 `register`／`reset`（K28） |
| `users.reg_declined_at` | dateTime null | 「心当たりがない」を押した日時（K27） |
| `users.secrecy_agreed_at` | dateTime null | 担当者本人の守秘義務の同意（8章。今の `agreed_at` は会社に1つなので足りない） |
| `ses_companies.rep_user_id` | unsignedBigInteger null, index | 代表者（K21・K35）。外部キーなし |
| `ses_companies.next_rep_user_id` | unsignedBigInteger null | 担当者0人の交代で、本登録を待っている次の代表者（K21） |
| `engineers.registered_at` | dateTime null, index | 候補などから外す条件（K17）。兼任の人は role='eg' の行が無いので users から判定できない |

- `ses_companies.status` は string(20) なので列を変えず、値 `provisional`（仮登録）を足す。`PARK_LABELS['sesStatus']` にも足す。`pending`（公開の申込中）は別に残す（K17）。
- `profile_completed_at` は消さない。判定には使わなくなるが、完了のときは今までどおり入れる（画面・コマンドが読むため）。列を消すのは次のバージョン以降。

### 0-3. 状態の決まり

| 画面の言葉 | users の条件（上から順に判定） |
|---|---|
| 退会 | `left_at` あり |
| 停止 | `active=0` |
| 仮登録（心当たりなし） | `registered_at` なし・`reg_declined_at` あり |
| 仮登録（期限切れ） | `registered_at` なし・`token_expires` が過去または token なし |
| 仮登録 | `registered_at` なし |
| 参加中 | `registered_at` あり |

- `user_state()`（helpers.php）をこの表に直す。「使用中」「招待中」の言葉を比べている所（`inviteRows`、`ses/company`、`admin/ses-show` ほか）も直す。「期限切れ」は別の状態ではなく、仮登録の中の表示（K17）。
- 仮登録の間はパスワードが入らないので、ログインできない（K06）。

### 0-4. 仮登録を外す条件の共有（K17）

`Registration` に次を置き、同じ条件を各所に書かない。

- `usableEngineers($query, $alias = 'engineers')`：`registered_at` あり・`suspended_at` なし・status が left でない。
- 使う所：`Matcher::forCase`、`W::distribute`、`CaseMail` の候補（`CaseMail.php:94` の周り）、`CaseController` の 83・101・113・135 行、`SesController::cases`（59 行）、`AdminController` の `broadcast`（437・443 行）と `engAvail`（88 行）、`Notifier::egUsers`、`Todo` の 67 行。
- SES：`status='active'` で絞っている所はそのまま外れる。`W::distribute`（`find` で取っている）・`Billing`・`buildInvoices`・`Notifier::sesUsers`（`registered_at` ありも足す）を確かめて足す。
- `Notifier::admins` にも `registered_at` ありを足す（仮登録の管理者に知らせを送らない）。

### 0-5. 本登録の手順（K18・K27 の両立）

1. `GET /invite/{token}`：`Registration::userByRegisterToken`（種類 register・期限内・active=1・K31 の会社の確認）。使えないときは、誰のものかを出さず同じ文（「このリンクは使えません。登録した方に送り直しをご依頼ください。」）。
2. `POST /invite/{token}`：検査する。**この時点で DB には書かない。** パスワードはハッシュにし、入力と token を `Crypt::encryptString` してセッションの `reg_pending` に置く（`.env.example` は `SESSION_ENCRYPT=false` で、セッションは DB に残るため）。今のログインを外してセッションを作り直し、`Auth::loginUsingId`、`TwoFactor::send($r, 'invite')`、`/two-factor/challenge` へ。
3. 確認コードを通ったら（`TwoFactor::pass` の中で `Registration::finishPending($r)` を呼ぶ。確認コードを省く環境の道も `pass` を通るので、ここ1か所でよい）：トランザクションと `lockForUpdate` で、`registered_at` が null・token が同じ・`active=1`・K31 の会社の確認が通ることを確かめ、全部を保存し、token を消し、K25 の相手に知らせる。**会社の停止は token を消さない（`sesSuspend`）ので、会社の確認をここでもう一度行う。**
4. 確かめが通らなかった（途中で送り直された・「心当たりがない」が押された・取り消された・会社が止まった）ら、何も保存せず、ログアウトして「リンクが新しくなりました」の文を出す（行が消えた・会社が止まったときは 1 の共通の文）。`pass` は、ログアウトしたら `SESSION_KEY` を入れずに戻る（今の `pass` は `$r->user()->id` を読むので、そのままだと落ちる）。`TwoFactorController::challenge` は、`pass` のあとに `$r->user()` が無ければ `/login` へ返す（`two_factor_confirmed_at` は入れない）。確認コードを省く道では `send` の中で `pass` が走るので、`PublicController::invite`・`challengeForm`（16・36 行）・`resend` も、`send` のあとに `$r->user()` が無いときを同じように扱う。

これで「途中でやめたら何も残らず、リンクは期限まで使える」（K18）と「完了は確認コードを通ったとき」（K27）が両方成り立つ。

**完了のときに入れるもの**：`users`（姓・名・name・任意の項目・password・`registered_at`・`profile_completed_at`・token 消し）。立場ごとに次も入れる。

| 立場 | 追加で入れるもの |
|---|---|
| SES（代表者） | `ses_companies` の name・phone・postal_code・address・`agreed_at`・`self_confirmed_at`、status=active、`joined_at`、rep の写し。`users.secrecy_agreed_at` も |
| 担当者 | `users.phone`・`secrecy_agreed_at` |
| EG | `engineers` の姓名・かな・phone・postal_code・address・`consent_at`・`self_confirmed_at`・`registered_at`（K18 の確認を兼ねる）と `users` の同じ項目 |
| 管理者 | `users.company_name`・phone ほか |

### 0-6. 足りない項目の画面（K24・K32・B6）

- `GET/POST /register/complete`（`account/complete.blade.php`）。本登録と同じ部品（`partials/register-fields.blade.php`）で、足りない項目と同意だけを入力欄にする。パスワードは聞かない。
- `Role` ミドルウェアの `profile_completed_at` の判定を `Requirements::missing($row, $u->role)` に替える。足りなければ `/register/complete` へ。通すのはこの画面だけ（立場を選ぶ `/login/role` はその前に通る）。**`account`・`account/*` の例外をなくす（B6）。**
- `/welcome`・`/eg/consent` はこの画面へ転送する。`AccountController::chooseRole` の `/eg/consent` への分岐は消す（ミドルウェアが回す）。
- 判定はいまの立場（`$u->role`）ごと。EG の立場は `engineers` を、代表者は `ses_companies` と `users` を、担当者・管理者は `users` を見る。EG の立場で完了したとき、`engineers.registered_at` が空なら入れる（兼任の人はここで候補に出る）。

### 0-7. 必須項目（`Requirements`）

8章の表をそのままコードにする。`fields(role, isRep)` は 項目 → ラベル・必須／任意・入れる場所（users／company／engineer）・本人が直せるか（確／見）を返す。「見」の項目は POST されても無視する（改ざんに備える）。

| 立場 | 本人が必須 | 確（入った状態で出す・必須） | 見 | 任意 | 同意 |
|---|---|---|---|---|---|
| 管理者 | 電話 | 企業名（`users.company_name`）・姓・名 | メール | ふりがな・郵便番号・住所・所属／役職 | なし |
| SES（代表者） | 会社の電話・郵便番号・住所 | 企業正式名称・姓・名 | メール | ふりがな・所属／役職 | 守秘義務（会社として） |
| 担当者 | 電話 | 姓・名 | 企業名・メール | ふりがな・所属／役職 | 守秘義務 |
| EG | ふりがな・電話・郵便番号・住所 | 姓・名 | 企業名・メール | なし | 個人情報の取り扱い（内容の確認を兼ねる） |

- `isRep`：`rep_user_id` または `next_rep_user_id` がその人。
- K20：`Requirements::checkNotEmpty(role, isRep, 入力)` が、必須の欄を空にする保存を断る。

### 0-8. 文面（J：TEZZO が案のまま承認。2026-10-31）

- `app/Support/RegistrationText.php` に、仮登録メールの件名・本文、立場の言葉、立場ごとの必須項目の言い方、同意3つ、知らせのメール、断りの文をまとめる。要件定義書の付録の文で実装する。**文面はこのファイルだけを直せばよい形にする**（Blade・Workflow に文を書かない）。
- テストは文全体を比べず、大事な部分（リンク・7日以内・立場の言葉）だけを見る。文を直してもテストが落ちないようにするため。

### 0-9. リンクの種類（K28・B7）

- `issueToken($id, $days, $kind)`。URL：本登録は `/invite/{token}`（今のリンクがそのまま動く）、再設定は新しい `/reset/{token}`（`public/reset.blade.php`。パスワードだけ）。
- 種類が違う token で開いたら、正しいほうへ転送する（再設定の token で `/invite/` を開いたら `/reset/` へ）。

### 0-10. 権限の1か所（K13・K21・K29・K34）

`Registration::canManage($actor, $target)` と `W::isRep($u)` に集める。

| 操作 | 管理者 | SES（代表者） | 担当者 |
|---|---|---|---|
| 登録・送り直し・仮登録中の直し：管理者／SES（代表者） | ○ | × | × |
| 登録・送り直し・仮登録中の直し：担当者 | ○ | 自社 | × |
| 登録・送り直し・仮登録中の直し：EG | ○ | 自社 | 自社 |
| 担当者の停止・再開・退会 | ○ | 自社（本人を除く） | × |
| EG の停止・再開・退会 | ○ | 自社 | 自社 |
| 企業名・代表者の欄・会社情報の編集 | ○ | 自社 | ×（見るだけ） |
| 代表者の交代 | ○ | × | × |

### 0-11. バージョン

`config/park.php`（`'version' => 'v0.2.0'`）を正本にし、`layouts/app.blade.php` の足元（83 行の `<footer class="foot">`）に `{{ config('park.version') }}` を出す。印刷の画面（`$print`）には足元が無いので出ない。Git のタグ `v0.2.0` は全段階の完了後（Leo）。

### 0-12. 今のテストへの影響

- `SampleSeeder` を新しい形に直す（各社の代表者・本登録の日時・必須項目）。`W::createUser` は既定で `registered_at` を入れる（パスワードを持って作るのは `/setup`・見本・テストだけのため）。
- テストが `engineers` を作って候補を確かめる所は、`engineers.registered_at` を入れないと 0-4 で外れる。`tests/TestCase.php` の作り方（SampleSeeder を使う部分を含む）を先に直す。
- `/invite`・`/welcome`・`/eg/consent` を使うテスト（`ProfileAndDirectTest`・`NameSplitTest`・`DualRoleTest`・`EmptyStateTest`・`UiUnifyTest`・`PostalAddressTest`・`AccountProfileTest`・`AdminAccountTest`・`ParkTest`・`SuspendLeaveTest`・`SeedDummyTest`・`PurgeSamplesTest` ほか）は新しい流れに書き直す。**確かめる中身を消さない。** 消すのは、決定で要らなくなった動きのテストだけで、消したものと理由を完了報告に書く。
- 確認コードの取り方：`mail_outbox` の最新の件名（`確認コード 123456`）から読む。

---

## 1. 台帳との対応表

| 番号 | 段階とAC | 番号 | 段階とAC |
|---|---|---|---|
| K01 | S1-06・S1-07 | K22 | S1-26・S1-37 |
| K02 | S1-11・S2-01・S2-02・S3-01（K15 で変更） | K23 | S2-06・S2-31 |
| K03 | S1-14・S1-32（K14 で変更） | K24 | S1-31・S2-24・S3-08 |
| K04 | S1-32・S2-09 | K25 | S1-16・S2-11・S3-03 |
| K05 | S1-32・S3-01・S3-02 | K26 | S1〜S3 の分け方・S3-10 |
| K06 | S1-19・S1-29 | K27 | S1-15・S1-22・S2-17・S3-05 |
| K07 | S1-20・S2-14・S3-05 | K28 | S1-27・S1-28 |
| K08 | S1-08・S1-39・S2-13・S3-13 | K29 | S1-20・S1-23・S2-14・S2-15・S3-04・S1-36・S2-30・S3-11（取り消し。L を承認） |
| K09 | S1-26（画面は触らない） | K30 | S1-12・S1-31・S2-18・S3-08 |
| K10 | S1-16・S2-11・S3-03 | K31 | S1-25・S1-37・S1-38・S1-39・S2-19 |
| K11 | S1-16 | K32 | S1-03・S1-04・S1-05・S1-33 |
| K12 | S2-01 | K33 | S1-29・S3-06・S3-07 |
| K13 | S2-03・S2-04・S2-20 | K34 | S1-30・S2-28 |
| K14 | S1-14・S1-32 | K35 | S1-02 |
| K15 | S1-11・S2-01〜S2-07・S3-01 | K36 | 下の B1〜B8 のとおり |
| K16 | S1-18・S2-10・S3-02 | B1 | S2-25・S2-26 |
| K17 | S1-08・S1-09・S1-10・S1-39・S2-12 | B2 | S1-32・S2-09 |
| K18 | S1-13〜S1-15・S1-24・S2-09・S2-16 | B3 | S1-30・S2-13 |
| K19 | S1-14・S1-32・S2-08・S3-02 | B4 | S1-30・S2-22 |
| K20 | S1-29・S2-27・S3-09 | B5 | S1-23・S2-15・S3-04 |
| K21 | S2-20〜S2-23 | B6 | S1-29 |
| | | B7 | S1-27・S1-28 |
| | | B8 | S1-06 |

6章の完了条件は S3-10（通し）と S3-12（エビデンス）で確かめる。スコープ 2章の R01・R06 のつながりは S3-13。

---

## 2. 段階 1：管理者 → SES ＋共通の土台

### 2-1. 画面と操作

- **管理者**
  - `/admin/ses/new`：企業正式名称・代表者の姓・名・メールの4つだけ（`admin/ses-form` を新しい形と編集の形に分ける）。ボタンは「仮登録してメールを送る」。
  - `/admin/ses`：列の「担当」を「代表者」に替える。状態に「仮登録」「仮登録（期限切れ）」など。仮登録の行に「送り直し」「詳細」。
  - `/admin/ses/{id}`：仮登録の間は「送り直し」「仮登録の内容を直す」「退会」。「停止」は出さない。「代表者」は `rep_*` を出し、「担当」の欄はなくす。
  - `/admin/ses/{id}/edit`：仮登録の間は4つの項目だけ。本登録のあとは今の編集から担当者の欄を除く。メールは出すだけ（B5）。
  - 申込中の SES の承認：`GET /admin/ses/{id}/approve` で同じ入力画面に申し込みの内容を入れて出す（代表者姓名は `rep_*`、無ければ `contact_*`）。`POST` で送る。**申し込みの画面（`/apply`）は触らない（K09）。**
  - `/admin`：SES の数・「はじめの手順」・招待の状況に、仮登録の会社も数える（今は `active`・`pending` だけを見ている）。
- **SES（代表者）**：メール → `/invite/{token}` → 1画面で入力 → 確認コード → `/ses`。`/ses/company/edit` は代表者だけ（欄は企業正式名称・代表者姓名・電話・郵便番号・住所・人数・得意な分野。担当者の欄は出さない）。
- **担当者**：この段階では登録の入口は作らない。今いる担当者は、足りない項目の画面（電話・守秘義務）と、会社情報を見るだけの制限を受ける。
- **EG**：この段階では登録の入口を変えない。今の入口から出たリンクは新しい本登録の画面に入る。

### 2-2. 通知・メール

| いつ | 誰から | 誰へ | 手段 |
|---|---|---|---|
| 仮登録・送り直し | 登録した人の名前 | 本人 | メール（K01） |
| 取り消し | PARK | なし（知らせを送らない。古いリンクは行が消えるので使えなくなる） | — |
| 申込の承認 | 管理者 | 申し込んだメールアドレス | メール。代表者と同じアドレスなら、仮登録メールに承認の1文を足して1通にする（仮定） |
| 本登録の完了（確認コードのあと） | PARK | K25 の相手 | メールとベル。修正があれば前後を本文に入れる |
| 心当たりがない | PARK | K25 と同じ決め方の相手 | メールとベル |
| 本登録が済んでいない人の「忘れた」 | PARK | 本人 | 「上位に送り直しを頼んでください」のメール（同じアドレスへは1時間に1通まで） |

K25 の相手の決め方は `Registration::recipientsFor($target)`。登録した人が使える（active・本登録済み・SES の人なら会社が参加中）ならその人。SES の中の人なら代表者も。管理者の本登録と SES の本登録は管理者全員も。誰もいなければ管理者全員。user id で重複を除く。本人は除く。

### 2-3. 受け入れ条件

**土台**

- **S1-01** 全画面の足元に `v0.2.0` が出る。テスト：未ログインで `/login`、管理者で `/admin` を開き、`config('park.version')` の文字が出る。
- **S1-02** 0-2 の列が足される。テスト：`Schema::hasColumn` で全部を確かめる。SQLite（テスト）で通す。MariaDB は手元の `php artisan migrate` で確かめる。
- **S1-03** K32 の users：`2026_10_31_000002_migrate_existing_registration.php`。パスワードのある人は `registered_at`＝`two_factor_confirmed_at`・`profile_completed_at`・`last_login`・`created_at` の最初にあるもの。パスワードの無い人は null。**既にある列の値は変えない。メールも通知も出さない。** 2回流しても同じ。テスト：マイグレーションの前の形の行を入れ、データ移行のクラスを直接呼び、列を確かめる（管理者・停止中・退会済を含む）。`mail_outbox`・`app_notifications` が増えない。もう一度呼んでも変わらない。
- **S1-04** K32 の代表者ほか：各社の `rep_user_id`＝使える（active・left_at なし）本登録済みの担当者で id が最小の人。いなければ使える仮登録の人で最小。いなければ null。`rep_*` には書かない（5章の 1）。`engineers.registered_at` は、つながる users にパスワードがあればその日時、無ければ null。`token_kind` は token があってパスワードありなら reset、なしなら register。テスト：複数の担当者の会社で最古が選ばれる、最古が停止中なら次の人、兼任の行も engineers に効く、ログインの無いエンジニアは null。
- **S1-05** 今いる人が締め出されない。テスト：移行済みの管理者（電話なし、`two_factor_confirmed_at` あり）でログイン → 確認コードなし → `/register/complete` に回る → 電話を入れて保存 → `/admin` が開く。同じく移行済みの代表者（会社の住所なし）と EG（郵便番号・同意なし）も通す。

**メールと文面**

- **S1-06** K01・B8：管理者が SES を登録すると `mail_outbox` に1通。宛先は代表者のメール、件名に「仮登録のご案内」、本文に企業名・姓名・登録した人・立場「SES の代表者」・`/invite/` のリンク・「7日以内」・必須項目・「心当たりがない」。「ご招待」「パスワードを設定」の文がない。`token_expires` が7日後、`token_kind`=register。テスト：POST `/admin/ses` のあと DB を見る。
- **S1-07** 文面が1か所にある。テスト：`RegistrationText` の文を設定で差し替えられなくてもよいので、メール本文と同意の文が `RegistrationText` の返す文と同じことを比べる。

**状態**

- **S1-08** K08・K17：一覧と詳細に状態が出る。テスト：`/admin/ses` で、仮登録の会社に「仮登録」と「送り直し」、期限を過去にすると「仮登録（期限切れ）」、本登録のあとは「参加中」で送り直しなし。
- **S1-09** K17：仮登録の EG は候補・配信・応募・一斉メールに出ない。テスト：`engineers.registered_at` が null の EG と入っている EG を作る → `Matcher::forCase`、`W::distribute`、配信（POST `/cases/{id}/distribute`）、推薦（POST `/cases/{id}/recommend`）、SES からの応募（POST `/cases/{id}/apply`）、一斉メール（POST `/admin/mail/broadcast`）で、null の人が入らない・断られる。
- **S1-10** K17：仮登録の SES は配信・請求・選ぶ一覧から外れる。テスト：status=provisional の会社で `/cases/new`（管理者）の選択肢に出ない、請求の作成で行ができない、`N::toSes` が仮登録の人に届かない。

**管理者 → SES の流れ**

- **S1-11** K02・K15：管理者が4項目で登録すると、会社は `provisional`、`rep_user_id`＝新しい行、その行は role=ses・`registered_by`＝管理者・`registered_at` null・password null。`joined_at` は空、電話・住所は空。テスト：POST `/admin/ses`。
- **S1-12** K30（管理者側）：企業名・姓・名・メールのどれかが空、形が違うメールは断り、何も作らない。同じメールの人がいたら理由を出す（使用中／仮登録中／停止中／退会済を別の文で）。テスト：項目ごとに POST して flash と件数を見る。
- **S1-13** K18：リンクを開くと企業名・姓・名が入力欄に入っており、メールは表示だけ。テスト：GET `/invite/{token}` の内容を見る。
- **S1-14** K03・K14・K19：代表者姓・名・企業正式名称・電話・郵便番号・住所・パスワード・同意のどれが欠けても進めない。郵便番号の形も検査する。担当者の姓名は聞かない。テスト：項目ごとに欠かして POST → エラー、`registered_at` null、確認コードのメールが出ない。
- **S1-15** K18・K27：そろえて POST すると確認コードの画面へ。**DB はまだ何も変わらない**（会社は provisional、password null）。上位に知らせは出ない。やめて別のセッションで開くと、リンクはまだ使える。テスト：POST のあと DB と `app_notifications` を確かめ、新しいセッションで GET → 200。
- **S1-16** K10・K11・K25：確認コードを通ると、`registered_at` が入り、会社は `active`、`joined_at` が今日、`agreed_at` が入り、token が消える。`/ses` に入れる。管理者全員にメールとベルが1通ずつ（登録した管理者にも重ねない）。テスト：`mail_outbox` の件名からコードを読んで POST `/two-factor/challenge`。管理者2人で確かめる。
- **S1-17** 確認コードを省く環境でも完了する。テスト：`config(['app.skip_2fa_domain' => 'skip.example'])` で、そのドメインの人が POST したら同じ結果になる。
- **S1-18** K16：企業名・姓・名を直して本登録すると、新しい値が入り、知らせに修正の前後が出る。同じ名前の会社があれば注意の文を出す（止めない）。`W::renameSes` のベルと重ねない。テスト：名前を直して通し、知らせの本文と件数を見る。
- **S1-19** K06：仮登録の人はログインできず、ほかの画面も開けない。テスト：POST `/login`（どんなパスワードでも）失敗。確認コードの途中で `/ses`・`/account` を開くと `/two-factor/challenge` へ回る。
- **S1-20** K07・K29：期限が切れたリンクは使えず、誰のものかを出さない。上位の「送り直し」で新しいリンクが7日間使え、前のリンクは無効。テスト：`token_expires` を過去にして GET → 共通の文。POST `/people/{id}/resend` → 古い token で GET → 共通の文、新しい token で GET → 200。
- **S1-21** 二重の操作：完了のあとリンクは使えない。確認コードの途中で送り直しがあったら、前の入力は保存しない。テスト：コードの前に別のアクターが resend → コードを通す → `registered_at` null、ログアウトしている、新しいリンクは使える。
- **S1-22** K27：「心当たりがない」を押すとリンクが無効になり、`reg_declined_at` が入り、登録した人に知らせが届く。送り直すと `reg_declined_at` が消える。テスト：POST `/invite/{token}/decline` → DB・知らせ・GET で共通の文 → resend → 状態が「仮登録」。
- **S1-23** K29・B5：仮登録中は管理者が企業名・姓・名・メールを直せる。同じ行のまま。メールを変えると新しいリンクが新しいアドレスへ届き、前のリンクは無効。`users.email` と `ses_companies.email` がそろう。使われているアドレスには変えられない。テスト：POST `/admin/ses/{id}` のあと、users の行数が同じ、email 2か所、mail_outbox の宛先、古い token。
- **S1-24** K18：仮登録中の会社の編集は4項目だけで、電話・住所などを POST しても入らない。テスト：`phone` を混ぜて POST → null のまま。
- **S1-25** K31：会社が停止中・退会済なら、本登録・再設定・送り直しを通さない。仮登録の会社は停止できない。テスト：status=suspended で GET `/invite/` → 共通の文、POST `/forgot` → リンクなし、resend → 断り。provisional で POST `/admin/ses/{id}/suspend` → 断り。
- **S1-26** K09・K22：`/apply` の画面と入力は変わらない。申込中の承認は入力画面を通り、送ると `provisional`、`agreed_at` は null（前の日時は audit に残す）、代表者の本登録で必ず同意を取り直す。申し込んだアドレスに承認の知らせが届く。テスト：POST `/apply` → GET `/admin/ses/{id}/approve` に内容が出る → 別のアドレスで POST → 2通 → 同意なしの本登録は断り。同じアドレスのときは1通で、本文に承認の文。

**再設定**

- **S1-27** K28・B7：本登録が済んでいない人の `/forgot`：token と期限は変わらず、再設定のリンクも出ず、「上位に送り直しを」のメールが届く。画面の文は誰でも同じ。テスト：POST `/forgot` の前後で token を比べる。登録のないアドレスでも同じ画面の文、メールなし。
- **S1-28** K28：本登録済みの人の `/forgot`：`/reset/{token}` のリンク（24時間）。画面はパスワードだけで、必須項目・同意を聞かない。古い `/invite/{再設定の token}` は転送する。テスト：POST `/forgot` → GET・POST `/reset/{token}` → パスワードが変わり、`registered_at` は変わらない。

**必須を保つ・権限**

- **S1-29** K20・K33・B6：足りない項目があると `/account` も開けず保存できない。必須の欄は本人も上位も空にできない。テスト：足りない人で GET/POST `/account` → `/register/complete` に回り DB は変わらない。SES：POST `/ses/company`（電話を空）・POST `/admin/ses/{id}`（住所を空）・POST `/account`（担当者が電話を空、姓を空）→ 断り、値は残る。
- **S1-30** K34・B3・B4：企業名・代表者の欄は代表者と管理者だけが変えられる。担当者は `/ses/company` を見るだけ（編集のボタンなし、GET `/ses/company/edit` と POST `/ses/company` は 403）。`/account`・`/register/complete` から `ses_name` を POST しても企業名は変わらない。代表者が姓を直すと `ses_companies.rep_*` が同じになる（`/account`・`/ses/company`・`/admin/ses/{id}` の3つの入口それぞれ）。一覧・詳細に `contact_*` を出さない。テスト：各立場で POST して確かめる。
- **S1-31** K24・K30（土台）：判定は立場ごと。役割を足せるのは本登録済みの人だけ。テスト：兼任の人（担当者の項目はそろう・EG の住所なし）で、SES の立場なら入れて、EG の立場を選ぶと `/register/complete` に回る。`W::egShareTarget`・`sesShareTarget`・`adminShareTarget` は `registered_at` null の人に null を返す。
- **S1-32** K03〜K05・K14・K19・B2：`Requirements` が8章の表と同じ。テスト：4つの立場の表で、`fields` の必須・任意・確・見と `missing` を1項目ずつ確かめる（データプロバイダ）。EG に郵便番号・住所があり、担当者に住所が無い。

**道具**

- **S1-33** K32：`park:seed-dummy` が新しい状態で作る（下の 2-4）。`park:purge-samples` で全部消え、守りも効く。テスト：`SeedDummyTest` の T1〜T11 を新しい状態で通す。見本ではない SES の代表者が見本の人なら purge が止まる。残る人の `registered_by` が消す人を指していたら null になる。
- **S1-34** `park:status` に、状態ごとの人数、代表者のいない会社の数、「パスワードがあるのに `registered_at` が無い人」の数（0 のはず）を足す。名前・アドレスは出さない。テスト：`artisan('park:status')` の出力を見る。
- **S1-35** マニュアル（下の 2-5）。テスト：GET `/manual/admin`・`/manual/ses` に「仮登録」「本登録」が出る。
- **S1-36** K29・L（2026-10-31 TEZZO 承認）：`POST /people/{id}/cancel` で仮登録を取り消せる（条件と記録は 4-4）。管理者が SES の代表者を取り消すと、会社に他の人がいなければ会社の行（status=provisional）も消え、アドレスがまた使える。テスト：取り消して行が消える・audit が残り名前とアドレスが入っていない・同じアドレスで仮登録できる。パスワードまたは `registered_at` のある行、参加中の会社は断る。権限のない人は 403。

**本登録を飛ばす道をふさぐ**

- **S1-37** K22・K31：承認は申込中の会社だけ。今の `sesApprove`・`W::approveSes` は状態を見ないので、POST すると仮登録・停止中・退会済の会社も参加中になってしまう。テスト：`provisional`・`suspended`・`left`・`active` の会社に GET・POST `/admin/ses/{id}/approve` → 断り、status は変わらない、メールなし。
- **S1-38** K31：確認コードの途中で会社が止まったら、本登録は完了しない。テスト：参加中の会社の担当者の行を仮登録で作る → POST `/invite/{token}` → 管理者が POST `/admin/ses/{id}/suspend` → 正しいコードを入れる → `registered_at` null、ログアウトしている、知らせなし。確認コードを省く環境で、取り消された行の POST でも 500 にならない（0-5 の 4）。
- **S1-39** K08・K17・K31：仮登録の会社の退会は通り、リンクも使えなくなる。再開は押せない。管理者のホームの数に仮登録が入る。0件でも開く。テスト：provisional で POST `/admin/ses/{id}/delete` → status=left、古い token で GET → 共通の文。POST `/admin/ses/{id}/resume` → status は変わらない。GET `/admin` に仮登録の会社の行が出る。会社が1つも無くても 200。

### 2-4. 作業の手順

1. `config/park.php` と足元のバージョン（S1-01）。
2. マイグレーション2つ（列・今いる行）。データ移行は `Registration::migrateExisting()` に置き、SQLite でも動くよう `DB::table` と chunk で書く。
3. `Requirements`・`RegistrationText`・`Registration`（`issueToken` の種類、`sendLink`、`finishPending`、`recipientsFor`、`canManage`、`usableEngineers`、`companyAllows`）。
4. `user_state()`・`PARK_LABELS`・`W::isRep`・`W::createUser`（`registered_at` を入れる）。
5. `SampleSeeder`・`tests/TestCase.php` を直し、今のテストを動かす状態にする。
6. `Role` ミドルウェアと `/register/complete`、`/welcome`・`/eg/consent` の転送。`saveProfile` から `ses_name` を外す。
7. `PublicController`：`inviteForm`・`invite`（0-5）、`decline`、`forgot`、`resetForm`・`reset`。`TwoFactor::pass`・`TwoFactorController::challenge`・`challengeForm`・`resend`（0-5 の 4）。
8. `W::invite` の中身を `Registration` へ（メールは新しい文、`registered_by` を入れる）。`*ShareTarget` に本登録済みの条件。
9. `AdminController`：`sesStore`・`sesUpdate`・`sesApprove`（GET を足す。申込中だけ）・`sesSuspend`・`dashboard`・`inviteRows`。`POST /people/{id}/resend`（`canManage`）。`SesController::saveCompany`・`companyEdit`。`renameSes` の知らせをまとめられるようにする。
10. 画面：`public/invite`（作り直し）、`public/reset`、`partials/register-fields`、`account/complete`、`admin/ses-*`、`ses/company*`、`admin/dashboard`（文の「招待」）。
11. 0-4 の各所に `usableEngineers` などを入れる。
12. `DummySeed`：SES に `provisional` を足す（代表者1人だけ・状態 C、エンジニアなし）。人の状態 B を「参加中（必須項目が足りない）」に替える。`rep_user_id`（各社の1人目）・`registered_at`・`registered_by`（null）・`token_kind`・`engineers.registered_at` を入れる。`SamplePurge`：`registered_by` を null にする処理と、代表者の守りを足す。
13. `Status.php`。
14. テスト `tests/Feature/RegistrationFoundationTest.php`・`AdminToSesRegistrationTest.php` を足し、0-12 の今のテストを直す。`php artisan test`。
15. マニュアル：`admin.md`・`admin-steps.md`（SES の登録・承認・状態・送り直し・代表者の項）、`ses.md`・`ses-steps.md`（「初めてのログイン」を本登録に、会社情報は代表者だけ）。段階 1 では SES まわりだけを直す。

### 2-5. 公開版で流す手順（K32）

マイグレーションは `park-deploy.sh` の `migrate --force` で自動で流れる。流れたときの中身を確かめてから反映する。

1. **手元**：`php artisan test` を通す。公開版と同じ形の手元の DB（管理者・SES・EG・ダミー）で `php artisan migrate` を流し、`park:status` を見る。
2. **反映の前**：Leo が `.claude/scripts/park-remote-status.sh` で今の数を控える（見るだけ）。
3. **反映**：Leo が push のあと `bash .claude/scripts/park-deploy.sh`。**この移行がすることは、新しい列を埋めるだけ**（既にある値を変えない・行を消さない・メールを出さない）。
4. **反映のあと**：`park-remote-status.sh` で次を確かめる。
   - パスワードがあるのに `registered_at` が無い人：0。
   - ダミー：SES 8社に代表者がいる（申込中の会社は除く）。担当者15人・EG 80人は、パスワードのある人が本登録済み、無い人が仮登録に分かれている。
   - 管理者 TEZZO・テスト用の SES・テスト用の EG が本登録済み。
5. **TEZZO が次にログインするとき**：確認コードは聞かれない。足りない項目の画面が出る（管理者は電話など。テスト用の代表者は会社の住所など。テスト用の EG は郵便番号・住所・ふりがな・同意が足りなければ）。入れれば今までの画面に入れる。**先に TEZZO に伝える。**
6. 戻し方：`php artisan migrate:rollback --step=2`（新しい列が消える。`provisional` の会社は `pending` に戻す処理を `down` に書く）。データの削除にあたるので、流す前に TEZZO の了承を取る。

**TEZZO の了承が要るもの**（この段階）：公開版の `.env` の書き換え、データの削除（`purge-samples --yes`、上の戻し方）、一斉メール、ダミーの入れ直し（`seed-dummy --yes`）。今いるダミーは移行で新しい状態になるので、入れ直さなくてよい。ダミーには「送り直し」を押さない（届かないメールが出る）。

---

## 3. 段階 2：SES → 担当者・EG ／ 管理者 → 担当者・EG

### 3-1. 画面の骨子

- 入口（どちらも 企業名（表示または選ぶ）・姓・名・メールの4つだけ。`Registration::provisionalPerson` を通す）
  - 担当者：管理者は `/admin/ses/{id}/users/new`（今ある）、代表者は新しい `/ses/company/users/new`。
  - EG：`/engineers/new` を4項目の画面に替える（`engineers/form` の新規の分岐をなくす）。管理者は `/engineers/new?ses=` で参加中の SES から選ぶ。SES の人は自社に固定。
- `/ses/company`：担当者の一覧に「代表者」の印、状態、送り直し。担当者を登録・停止・退会のボタンは代表者だけ。`/ses/engineers`：状態と送り直し。
- 代表者の交代：`/admin/ses/{id}` に、本登録済みの担当者から選ぶ欄と、担当者が0人のときの「次の代表者を仮登録する」。
- 仮登録中の EG：`/engineers/{id}/edit`・`/profile`・`/skills`・`/projects`・書類・AI は上位が使えない（K18）。4項目の直しの画面へ案内する。`EngineerController::load` の近くに1か所で守りを置く。
- 帯の文：代表者は「SES（◯◯）の代表者として」、担当者は「ご担当者として」。

### 3-2. 受け入れ条件

- **S2-01** K02・K12・K15：代表者が担当者を仮登録できる。テスト：代表者で POST → role=ses・同じ ses_id・`registered_by`＝代表者・仮登録・メール1通。
- **S2-02** K15：代表者が EG を仮登録できる。テスト：POST `/engineers`（姓・名・メール）→ `engineers` と `users` が1行ずつ、どちらも仮登録、メール1通。
- **S2-03** K13：担当者が EG を仮登録できる。テスト：担当者で POST `/engineers`。`registered_by`＝担当者。
- **S2-04** K13：担当者は担当者を登録できない。テスト：担当者で GET/POST `/ses/company/users/new` → 403。
- **S2-05** K15：管理者が担当者を登録できる。選べるのは参加中の SES だけ。テスト：provisional・suspended・pending の会社で GET・POST → 断り、active → 通る。所属 SES の代表者にメールとベル。
- **S2-06** K15・K23：管理者が EG を登録するとその場でメールが届く（「招待は SES から」をやめる）。選べるのは参加中の SES だけ。直属は選べない。代表者にメールとベル。テスト：POST `/engineers`（ses_id）→ 本人あて1通、代表者に知らせ。ses_id が direct → 断り。
- **S2-07** SES の人が他社に登録できない。テスト：代表者・担当者で `ses_id` に他社を混ぜて POST → 自社に入る。`/admin/ses/{他社}/users` → 403。
- **S2-08** 8章：担当者の本登録。姓・名・電話・パスワード・守秘義務がそろわないと進めない。企業名は見るだけ。テスト：項目ごとに欠かして POST。通すと `secrecy_agreed_at`・`phone`・`registered_at`。
- **S2-09** K04・K18・B2：EG の本登録。姓・名・ふりがな・電話・郵便番号・住所・パスワード・同意。通すと `engineers` と `users` の両方に入り、`consent_at`・`self_confirmed_at`・`registered_at` が入る。「確認しました」を求められず `/eg` に入れる。テスト：通して GET `/eg` → 200、`/engineers/{id}/edit` にふりがな・住所。
- **S2-10** K16：担当者・EG は企業名を直せない。テスト：`ses_name`・`company_name` を混ぜて本登録 → 企業名は変わらない。姓を直すと知らせに前後が出る。
- **S2-11** K10・K25：担当者が登録 → 担当者と代表者に1通ずつ。代表者が登録 → 代表者に1通（重ねない）。管理者が登録 → 管理者と代表者（管理者全員ではない）。登録した人が停止中・退会済 → 代表者へ。代表者も使えない → 管理者全員へ。テスト：5つの組み合わせで `app_notifications` と `mail_outbox` を数える。
- **S2-12** K17：仮登録の EG は候補に出ず、本登録のあと出る。テスト：`Matcher::forCase` の前後。
- **S2-13** K08・B3：`/ses/company`・`/ses/engineers`・`/admin/engineers`・`/admin/ses/{id}`・`/admin/ses/{id}/users/{uid}` に状態が出る。担当者の姓名は users から出す。0件のときも画面が開く。テスト：各立場で GET して文字を見る。
- **S2-14** K07・K29：送り直しの権限。管理者は全部、代表者は自社の担当者・EG、担当者は自社の EG。テスト：組み合わせごとに POST `/people/{id}/resend` → 通る／403。他社 → 404。
- **S2-15** K29・B5：仮登録中の直し。同じ行のまま。EG は `users` と `engineers` の両方のメールがそろい、1人に2行にならない。**所属（企業名）を変えられるのは管理者だけで、参加中の SES にだけ。`users.ses_id` と `engineers.ses_id` がそろう（7章の 1）。** テスト：メールを直して users の行数、email 2か所、旧 token、新しいアドレスの1通。管理者が所属を直す → 2か所が変わる。代表者・担当者が `ses_id` を混ぜて POST → 変わらない。
- **S2-16** K18：仮登録中の EG の技術・住所などを上位が入れられない。テスト：POST `/engineers/{id}`・`/profile`・`/skills`・`/projects` → 断り、値は変わらない。本登録のあとは通り、今までどおり `self_confirmed_at` が消える（2026-10-16）。本人が直したときは消えない。
- **S2-17** K27：担当者・EG の「心当たりがない」。テスト：押す → 登録した人に知らせ、リンク無効。
- **S2-18** K30：SES の人には理由によらず同じ文（「このアドレスでは登録できません」）。回数制限（`throttle`）と記録（`N::audit` の `reg-refused`。アドレスは伏せる）。仮登録中のアドレスに役割を足さない。停止中・退会済のアドレスは断る。テスト：他社の使用中・仮登録中・停止中・退会済で POST → 文が同じ、audit が1行ずつ、制限を超えると 429。同じ SES の本登録済みの人なら兼任になる。
- **S2-19** K31：担当者・EG の本登録・再設定・送り直しは、会社が参加中のときだけ。テスト：会社を停止して GET `/invite/`・POST `/forgot`・resend → 通らない。再開すると元のリンクが期限内なら使える。
- **S2-20** K13・K21：担当者の停止・再開・退会は代表者と管理者だけ。担当者は押せない（ボタンなし、POST は 403）。EG の操作は担当者もできる。テスト：`/admin/ses/{id}/users/{uid}/suspend` を各立場で POST。
- **S2-21** K21：代表者の停止・退会は交代のあと。会社ごとの停止・退会は今までどおり。テスト：代表者に suspend・delete → 断り。交代のあと → 通る。`/admin/ses/{id}/suspend` は通る。
- **S2-22** K21・B4：交代（担当者から選ぶ）。選べるのは本登録済みの担当者だけ。前の代表者は担当者になり、`rep_*` が新しい人の姓名になる。代表者の権限が入れ替わる。テスト：POST `/admin/ses/{id}/rep` → DB、前の人が会社情報を編集できない、新しい人はできる。仮登録・他社の人を指定 → 断り。SES の人が POST → 403。
- **S2-23** K21：担当者0人の交代。`next_rep_user_id` に仮登録し、本登録の完了で `rep_user_id` が入れ替わる。その間、代表者は前の人のまま。テスト：POST → 仮登録 → `rep_user_id` は変わらない → 本登録を通す → 入れ替わり、`next_rep_user_id` は null。代表者の項目（会社の欄）が確で出る。
- **S2-24** K24：兼任の役割を足すと通知が届き、次のログインでその立場に入る前に足りない項目と同意を取る。完了で `engineers.registered_at` が入り候補に出る。テスト：本登録済みの担当者のアドレスで EG を登録 → ベル → EG の立場を選ぶ → `/register/complete` → 通す → `Matcher::forCase` に出る。
- **S2-25** B1：下の 2026-10-28 の計画の AC1〜AC7・AC9。**読み替え**：welcome は `/invite`（本登録）と `/register/complete` に。テスト：同じ計画の T1〜T8・T10（`EgProfileSyncTest.php`）。兼任は対象外、正の決め方も計画のまま（K36）。
- **S2-26** B1：`park:sync-eg-names`（AC8・T9）。既定は見るだけ、`--apply` で書き換え。**公開版で `--apply` を流すのは TEZZO の了承を取ってから。** 先に引数なしの件数を見せる。
- **S2-27** K20：EG の必須を空にできない。テスト：本人・代表者・管理者が POST `/engineers/{id}`・`/profile`・`/account` で姓・名・ふりがな・電話・郵便番号・住所を空 → 断り。`EngineerController::update` の「名は空でもよい」をやめる（5章の 3）。
- **S2-28** K34：担当者の見え方の仕上げ。テスト：担当者で `/ses/company` に編集・担当者の登録・停止のボタンが無く、代表者には出る。
- **S2-30** K29・L：担当者・EG の取り消し。権限は 0-10 の「登録・送り直し」と同じ（代表者は自社の担当者・EG、担当者は自社の EG、管理者は全部）。EG は応募・配信などの行があれば断る。`engineers` と `users` の両方が消える。テスト：組み合わせごとに POST → 通る／403／他社は 404。
- **S2-31** K23：今いる管理者の直属の EG。新しくは作れないが、今いる人は同じ決まりを通る。テスト：直属で仮登録の EG に管理者が resend → 本登録の画面で企業名を出さず（`user_label` と同じ）、通すと参加中、知らせは管理者全員（代表者がいないため）。移行済みの直属の EG は `/register/complete` を通せる。
- **S2-29** マニュアル：`ses.md`・`ses-steps.md`（担当者・エンジニアの登録、代表者と担当者の違い、交代は管理者へ。担当者向けの節を同じファイルに足す）、`engineer.md`・`engineer-steps.md`（本登録、「確認しました」は本登録で済む、氏名は両方に反映）、`admin.md`・`admin-steps.md`（担当者・エンジニアの登録、交代）。テスト：GET `/manual/ses`・`/manual/engineer` に「本登録」が出る。

### 3-3. 作業の骨子

1. `Registration::provisionalPerson`（断りの文を立場で分ける。K30）と `canManage` の入口を作る。
2. `EngineerController`：`create`・`store` を4項目に、`invite` を resend に、仮登録の守り。
3. `AdminController::sesUsers` と `SesController` の担当者の登録。`sesUserDo` に権限と代表者の守り。
4. 交代（`Registration::changeRep`、`finishPending` の入れ替え）。
5. B1：下の計画の手順 1〜8。
6. 画面、テスト（`SesToPeopleRegistrationTest.php`・`AdminToPeopleRegistrationTest.php`・`RepresentativeTest.php`・`EgProfileSyncTest.php`）、マニュアル。
7. 反映のあと、Leo が `park:sync-eg-names`（見るだけ）の件数を確かめ、TEZZO に `--apply` の了承を取る。

---

## 4. 段階 3：管理者 → 管理者と仕上げ

### 4-1. 画面の骨子

- `/admin/admins/new`：企業名・姓・名・メールの4つだけ（`AdminController::admins` の任意の項目は本登録へ）。
- `/admin/admins`：状態と送り直し。仮登録中の直しは `W::updateAdmin`（N03 はそのまま）。
- `/setup`：企業名・姓・名・電話・メール・パスワードを1画面で（K33）。
- `park:invite-admin`：引数を `{email} {sei} {mei} {--company=}` にする。

### 4-2. 受け入れ条件

- **S3-01** K02・K05・K15：管理者が管理者を仮登録できる。テスト：POST `/admin/admins`（4項目）→ `registered_by`、仮登録、メール「管理者」。電話などを混ぜても入らない。項目が欠けると断り。
- **S3-02** K05・K16・K19：本登録。企業名・姓・名（確）、電話、パスワードが必須。同意なし。企業名を直せる。テスト：項目ごとに欠かして POST。通すと `/admin` に入れる。
- **S3-03** K10・K25：管理者全員に知らせ。登録した人に重ねない。登録した人が停止中でも届く。テスト：管理者3人で件数を数える。
- **S3-04** K29・B5・N03：仮登録中は直せる（同じ行、メールを変えると新しいリンク）。使用中の人のメールは変えられない。送り直しは管理者だけ。テスト：`/admin/admins/{id}` に POST、`reinvite`（`resend` に統合）。自分のメール・使用中のメールの変更は断り。
- **S3-05** K07・K27：管理者の期限切れと「心当たりがない」。テスト：S1-20・S1-22 と同じ手順を管理者で。
- **S3-06** K33：`/setup` で必須項目がそろわないと作れない。通すと本登録済みで、足りない項目の画面が出ない。管理者がいると開けない。テスト：項目ごとに欠かして POST、通して GET `/admin` → 200。確認コードの扱いは今のまま（5章の 4）。
- **S3-07** K33：`park:invite-admin` で作った人も、同じ本登録を通る。別の立場の仮登録の行があれば断る。テスト：`artisan` → 行ができる・`token_kind`=register・出力のリンクを開いて S3-02 を通す。役割 eg の仮登録の行 → 終了コード 1。
- **S3-08** K24・K30：管理者の役割を足せるのは本登録済みの EG だけ。理由を出す。`addAdminRole` は空の値で既にある値を消さない。次のログインで管理者の立場の足りない項目を取る。テスト：仮登録の EG → 断り。本登録済み → 兼任、電話の入っていた行が空で消えない、立場を選ぶと `/register/complete`。
- **S3-09** K20：管理者の必須（企業名・姓・名・電話）を空にできない。`W::updateAdmin`・`/account`・`/register/complete`。テスト：項目ごとに空で POST → 断り、値は残る。
- **S3-10** K26・6章：通しのテスト。管理者 → 管理者・SES・担当者・EG、代表者 → 担当者・EG、担当者 → EG の7本で、仮登録メール → 本登録 → 確認コード → 参加中 → 知らせ → 一覧の状態を1本ずつたどる。テスト：`RegistrationFlowsTest.php`（データプロバイダ）。
- **S3-11** K29・L：管理者の取り消しと画面。テスト：仮登録の管理者を別の管理者が取り消せる。一覧・詳細の仮登録の行に「取り消し」ボタン（確認ダイアログ付き）が権限のある人にだけ出て、本登録済みの行には出ない。
- **S3-12** エビデンス：Leo のテスト設計書の項目ごとに、公開版の画面と届いたメールの記録を取る（AC の対応表を渡す。テストのアドレスだけを使う）。`resources/manuals` と README に「招待」の古い言い方が残っていない。テスト：GET `/manual/*` に「ご招待」「パスワードを設定してください」が出ない。
- **S3-13** R01・R06・K08：今ある動きが壊れていない。テスト：参加中の人のログイン → 確認コード → ホーム。一度通した人には聞かない。兼任の人は立場を選ぶ。一覧の 停止・再開・退会・詳細 が、管理者・代表者それぞれで今のまま通る（`SuspendLeaveTest`・`ListButtonsTest`・`DualRoleTest`）。自分自身と最後の使える管理者は不可のまま。

### 4-3. 作業の骨子

1. `AdminController::admins`・`reinviteAdmin`・`updateAdmin`。
2. `addAdminRole`。
3. `PublicController::setup`・`public/setup`、`InviteAdmin.php`。
4. `W::invite` と古い入口を片付ける（呼ぶ所が無いことを grep で確かめる）。`admin/dashboard` の `inviteRows` の言葉。
5. 通しのテスト、マニュアルの残り（`admin.md`・`admin-steps.md`）、README。41_Park-Project の `04_Manuals` の写しは Leo。
6. 全部の完了後、Leo がタグ `v0.2.0`。

### 4-4. 仮登録の取り消し（L：2026-10-31 TEZZO 承認。段階 1 から作る）

- 操作：`POST /people/{id}/cancel`（`Registration::cancel`）。`canManage` と同じ権限。
- 条件：`password` が空かつ `registered_at` が空の行だけ。エンジニアは、応募・案件の配信などの行が無いことも確かめる。SES の代表者なら、会社に他の人がいないときだけ会社の行も消す（status が provisional の会社に限る）。
- 確認：ボタンは確認ダイアログを通す。本人が使い始めた行は消せない。
- 記録：audit に残す（`reg-cancel`）。名前・アドレスは伏せる。
- 公開版の既存の行は自動で消さない。人が押したときだけ消える。ダミーの行に押してもよいが、片付けは `park:purge-samples` を使う。

### 4-5. TEZZO の了承が要るもの（全段階）

- 公開版の `.env` の書き換え。
- データの削除（`park:purge-samples --yes`、ロールバック）。
- 一斉メールの送信。
- `park:sync-eg-names --apply` を公開版で流すこと。
- `park:seed-dummy --yes` を公開版で流すこと。

コードの反映（push → `park-deploy.sh`）は常時了承の範囲。テストのメールはテスト用のアドレスにだけ送る。

---

## 5. Leo の決定（2026-10-31。決めた人：Leo）

1. **K32**：データ移行はマイグレーションで行う。埋めるのは**今回足した新しい列だけ**。既にある列（空の `rep_*` を含む）には書かない（常時了承の「列の追加」を超えないため）。S1-04 の「`rep_*` の写しを空のとき埋める」は取りやめ、画面は `rep_*` が空なら `rep_user_id` の人の姓名を出す。行を消さない・メールを出さない。
2. **足した列**：`engineers.registered_at`、`ses_companies.next_rep_user_id`、`users.secrecy_agreed_at`、`token_kind`、`reg_declined_at` を認める。
3. **名の必須**：台帳のとおり必須。名の空の人は足りない項目の画面で止まる。
4. **`/setup`**：確認コードなしのまま（誰もいない最初の1人の例外）。
5. **ログインの無いエンジニア**：仮登録扱い。送り直しで `users` の行を作る。メールが無ければ先に入れるよう断る。
6. **参加中の会社の代表者が仮登録**：K32 のとおり会社の状態は変えない。
7. **件名**：`Notifier` が `[PARK]` を付けるので、本文の案の `【PARK】` は除く（J の承認の範囲の技術的な調整。TEZZO の最終確認の項目に入れる）。
8. **`mail_opt_in`**：本人の設定を優先し、ベルは届く。
9. **確認コードを省く設定**：K27 の例外（テスト用）として残す。
10. **担当者のマニュアル**：SES のファイルの中の節にする。
11. **SES の `self_confirmed_at`**：本登録で入れる。

## 6. 実行の決まり（GENESIS）

- テスト：`.env` は作らない。Git Bash で `APP_KEY=base64:… /d/xampp/php/php.exe artisan test --testsuite=Feature`（`tests/Unit` が無いので `--testsuite=Feature` を付ける）。手元の開始時点は 401 件中 1 件失敗（CLI の PHP に zip 拡張が無いことによる `EngineerDetailTest` の Excel）。
- MariaDB でのマイグレーションの確認は、捨ててよいテスト用の DB で行い、`jonassan_park` に触らない。

## 7. Leo への確認

勝手に変えず、次の形で計画した。違えば Leo が直す。

**決定（2026-11-01。決めた人：Leo）**：3件とも計画のとおりにする。1＝所属 SES を直せるのは管理者だけ、参加中の SES にだけ（S2-15）。2＝`rep_*` は書き換えない（代表者が姓名を保存するか交代するまで残す）。3＝計画の番号は変えず、テスト設計書に「計画の AC」の列を足して対応づける。

1. **仮登録中に所属 SES を直せるか**：K29 は「上位が企業名を直せる」、8章は担当者・EG の企業名が「所属 SES を選ぶ」。K15 では SES の人は自社に固定なので、計画は「管理者だけが、参加中の SES に直せる」と読んだ（S2-15）。
2. **移行で `rep_*` と代表者本人の名前が違うとき**：B4 は「会社の代表者の欄に同じものを出す」。5章の 1 で既にある列に書かないので、`rep_*` が入っていて本人の姓名と違う会社は、代表者が姓名を保存するか交代するまで、そのまま残る。計画は書き換えない。
3. **AC とテスト設計書の番号がぶつかる**：`v0.2.0-03-テスト設計書.md`（版 01）も `S1-01` などの番号を使っていて、この計画の AC とは中身が違う（例：設計書の S1-16 はバージョン、計画の S1-16 は本登録の完了）。計画の番号は変えなかった。設計書に「計画の AC」の列を足すか、片方に接頭辞を付けるのを勧める。

---

# PLAN：テスト用のにぎやかしダミーデータを入れる `park:seed-dummy`（2026-10-30）

> この節が今の計画。下の「エンジニアの氏名・ふりがな・電話をそろえる（2026-10-28）」は前の計画で、そのまま残している。

## 背景

PARK v0.2.x（仮登録 → 本登録）のテストのため、公開版（https://www.jona.jp/park/）の一覧に、いろいろな状態の SES・担当者・エンジニアを大量に並べたい（TEZZO が公開版に入れると決めた）。消すときは今ある `park:purge-samples` でまとめて消す。

## 前提（コードで確かめたこと）

- v0.2.0 の「仮登録・本登録」の状態の列は**まだ無い**（v0.2.0 は②の段階。K04〜K11 未決）。今ある列だけで状態を表す（下の対応表）。v0.2.0 で状態の列が足されたら、このコマンドも合わせて直す（別の計画）。
- 今ある状態の値
  - SES の会社 `ses_companies.status`：`pending`（申込中）・`active`（参加中）・`suspended`（停止）・`left`（退会済）。`kind` は `ses`／`direct`（管理者の直属）。
  - アカウント `users`：`user_state()`（helpers.php）＝ `left_at` あり→退会済、`active=0`→停止、`password` あり→使用中、なし→招待中。ほかに `profile_completed_at`（最初の情報登録）・`two_factor_confirmed_at`・`token`/`token_expires`（招待リンク）。
  - エンジニア `engineers`：`status`（available 待機中・proposing・working・unavailable 休止・left）、`suspended_at`（停止）、`consent_at`（同意）、`self_confirmed_at`（本人の確認）。
- `SamplePurge::plan()` が消すもの：`users.email LIKE '%@park.example'`、`ses_companies` の `kind='ses'` かつ email が `@park.example`、`engineers.email` が `@park.example`（または email 空で見本の SES 所属）、それらにつながる skills・通知・sessions・mail_outbox（宛先が @park.example）・audit_log（user_id が見本の人）など。
- `W::createEngineer()` は、招待を切っても（`$invite=false`）**管理者全員にベルの通知**（「エンジニアが登録されました」、メールなし）と **audit_log（`engineer-create`、user_id は null、ダミーの氏名入り）**を書く。後者は purge では消えない（user_id が null のため）→ このコマンドの中で消す（下の仕様）。
- `W::saveSkills()` は、技術マスタに無い言葉を `skill_terms` に足す（purge では消えない）→ マスタにある言葉だけを渡す。
- `W::createUser()` は必ずパスワードを入れる（招待中にするときは、あとで `password=null` にする）。ふりがなは受け取らない（あとで `W::kanaCols()` で更新）。
- `SampleSeeder` は公開版では動かない作り。今回は別のコマンドにし、`SampleSeeder` は変えない。

## 受け入れ条件

- **AC1** `php artisan park:seed-dummy` に `--ses`（既定 8）・`--staff`（既定 15）・`--eg`（既定 80）・`--direct`（既定は eg の 10%を四捨五入）・`--seed`・`--yes` がある。
- **AC2** `--yes` が無いときは、作る件数の予定（種類 × 状態）・使う番号の範囲・seed を出すだけで、DB を一切変えない（直属の入れ物も作らない）。
- **AC3** `--yes` で、SES の会社・SES の担当者（role ses）・エンジニア（SES 所属と直属。role eg のアカウントつき）を、指定の件数だけ作る。全部を1つの transaction で入れ、途中で失敗したら何も残らない。
- **AC4** 状態が混ざる：SES は申込中・参加中・停止、担当者とエンジニアは 本登録済み・本登録の途中・招待中・仮登録のまま（リンク期限切れ）・停止中（下の対応表）。件数が足りるとき（状態の数以上）は、どの状態も1件以上ある。
- **AC5** メールアドレスはすべて `@park.example`（`ses-d01@park.example`・`ses-d01-2@park.example`・`eg-d001@park.example`）。
- **AC6** 氏名・ふりがなは姓・名に分かれて入り（`name_sei/name_mei/kana_sei/kana_mei`、つないだ `name/kana` も）、スキル・経験年数・単価・最寄り駅・生年などは、ありそうな値の組み合わせ。実在の人物の情報は使わない。
- **AC7** `--seed` が同じで、始まりの番号が同じなら、同じ名前・状態・値になる。`--seed` が無いときは乱数で決め、使った seed を出す。
- **AC8** メールを一切送らない。`mail_outbox` に1行も増えない。管理者のベル（`app_notifications`）も増えない。
- **AC9** 二重に実行したら、番号は続きから振る（既存のダミーと重ならない）。
- **AC10** 公開版（`APP_ENV=production`）でも動く。
- **AC11** `php artisan park:purge-samples --yes` で、作ったダミーが全部消える（守りに当たらない）。
- **AC12** 出力に名前・メールアドレスを出さない（件数・番号の範囲・seed だけ）。パスワード・招待のトークンも出さない。
- **AC13** README と管理者マニュアルに書き足す。

## 仕様

### コマンド

```
php artisan park:seed-dummy                         # 予定を見るだけ（既定の件数）
php artisan park:seed-dummy --ses=8 --staff=15 --eg=80 --seed=1        # 予定を見るだけ
php artisan park:seed-dummy --ses=8 --staff=15 --eg=80 --seed=1 --yes  # 入れる
```

- ファイル：`app/Console/Commands/SeedDummy.php`（入出力だけ）、`app/Services/DummySeed.php`（`plan(array $opt): array` と `run(array $opt): array`。名前の素・駅・スキルの組などの表もここに `const` で持つ）。
- signature：`park:seed-dummy {--ses=8} {--staff=15} {--eg=80} {--direct=} {--seed=} {--yes : 実際に入れる}`
- 入力の検査（当たったら何もせず、理由を出して終了コード 1）
  - `--ses` は 1〜50、`--staff` は 0〜200、`--eg` は 0〜500、`--direct` は 0〜eg。すべて整数。
  - `--staff` は「申込中でない SES の数」以上（各社に1人目の担当者が要る）。足りなければ「担当者は最低 N 人です」と出して止める。
  - これから使うメールアドレスが1つでも既にあれば止める（番号は続きからなので通常は起きない）。
- 出力（`--yes` の有無で同じ表。purge-samples と同じ作法）
  - 表：SES（申込中・参加中・停止）、担当者（状態ごと）、エンジニア SES 所属（状態ごと）、エンジニア 直属（状態ごと）の件数。
  - 番号の範囲（例 `ses-d01〜ses-d08`・`eg-d001〜eg-d080`。ドメインは書かない）、seed、`APP_ENV`。
  - `--yes` なし：「見るだけです（何も変えていません）。入れるときは --yes を付けます。」終了コード 0。
  - `--yes` あり：「入れました（SES n 社・担当者 n 人・エンジニア n 人）。消すときは php artisan park:purge-samples」。終了コード 0。

### 状態の対応表（v0.2.0 の言葉 ⇔ 今の列）

仮定：v0.2.0 の列ができるまで、次のように今の列で表す。画面の `user_state()` では B は「使用中」、C・D はどちらも「招待中」と出る（期限切れを区別する表示は今は無い）。

| 記号 | 状態（この計画の呼び名） | users | engineers（エンジニアだけ） | 割合 |
|---|---|---|---|---|
| A | 本登録済み | password あり・active=1・profile_completed_at あり・two_factor_confirmed_at あり・last_login（1〜30日前） | consent_at・self_confirmed_at あり、status は available（うち 15% は unavailable） | 50% |
| B | 本登録の途中（パスワードは設定、最初の情報登録が未了） | password あり・active=1・profile_completed_at なし・two_factor_confirmed_at なし | consent_at あり・self_confirmed_at なし | 10% |
| C | 招待中（仮登録・リンク有効） | password なし・`W::issueToken($id)`（7日） | consent_at・self_confirmed_at なし | 15% |
| D | 仮登録のまま（リンク期限切れ） | password なし・token あり・token_expires は 1〜20日前 | 同上 | 15% |
| E | 停止中 | password あり・profile_completed_at あり・active=0・token なし | suspended_at あり（consent・self_confirmed あり） | 10% |

SES の会社：

| 状態 | ses_companies | 担当者 | 件数 |
|---|---|---|---|
| 申込中 | status=pending、agreed_at・message あり、joined_at なし | 作らない（今の申し込みと同じ。承認まで users は無い） | ses≥3 のとき max(1, round(ses×0.15))、それ以外 0 |
| 停止 | status=suspended、agreed_at・self_confirmed_at・joined_at あり | 作る | ses≥4 のとき max(1, round(ses×0.10))、それ以外 0 |
| 参加中 | status=active、同上 | 作る | 残り（1社以上） |

- 退会済（left）は今回は作らない（要求の状態に無いため。要るなら足す。未決 2）。
- 既定（8社）なら 申込中1・停止1・参加中6。

### 件数の割り振り（seed で再現）

- 乱数は `new \Random\Randomizer(new \Random\Engine\Mt19937($seed))` を使う（PHP 全体の乱数に影響しない）。`--seed` が無ければ `random_int(1, 2147483647)` で決めて出力する。
- 状態ごとの件数：割合 × 人数を最大剰余法で整数にする。人数が状態の数（5）以上なら、各状態を最低1にする（多い状態から1ずつ回す）。並びは Randomizer の `shuffleArray`。
- 担当者：申込中でない各社に1人目（会社の email と同じアドレス）を置き、残りを各社にランダムに配る。**1人目は E（停止中）にしない**（会社に使える担当者が1人もいない形を避ける。今の画面の「最後の1人は停止できない」と合わせる）。E が1人目にしか置けないときは A に回す。
- エンジニア：`--direct` 人を直属（`W::directSesId()`。無ければ作る）、残りを申込中でない SES にランダムに配る（会社ごとの重み 1〜3）。状態は SES 所属と直属それぞれで上の割合。

### 番号とメールアドレス（AC5・AC9）

- SES：`ses-d%02d@park.example`（会社の email と1人目の担当者）。2人目以降の担当者：`ses-d%02d-%d@park.example`（`-2` から）。
- エンジニア（SES 所属・直属とも同じ通し番号）：`eg-d%03d@park.example`。users と engineers に同じアドレス。
- 始まりの番号：`users`・`ses_companies`・`engineers` の email から `ses-d(\d+)@park.example`・`eg-d(\d+)@park.example` に合う最大の番号を探し、その次から（無ければ 1）。桁があふれたら（100社目など）そのまま3桁になる。
- 二重実行の扱い：**続きから振る**（決定）。同じアドレスがあったら飛ばすのではなく、全体を止める（上の検査）。

### 値の作り方（AC6）

- 名前の素は `DummySeed` の `const` に持つ。姓（漢字・かな）40個前後、名（漢字・かな）男女それぞれ30個前後の、よくある組み合わせ。実在の人物のフルネームを並べない（姓と名をランダムに組み合わせるだけ）。
- SES の会社名：「さくら・あおば・みなも・こはる…」＋「システム・テック・ソリューションズ・ラボ…」を組み合わせ、末尾に「（ダミー）」を付ける（実在の会社と取り違えないため。仮定。未決 1）。代表者・担当者は姓・名（担当者はふりがなも）を `W::sesPeopleCols()` の形で入れる。従業員数 3〜80、強み（定型文から1つ）。電話・住所は入れない（実在の番号・住所になるのを避ける）。
- エンジニア（`W::createEngineer($sesId, $data, null, false)` に渡す）：`name_sei/name_mei/kana_sei/kana_mei`、`email`、`birth_year` 1965〜2002、`years_exp` は 1〜min(30, 年齢−22)、`desired_price` 40〜90（万円）、`min_price` は desired − 0〜10、`nearest_station`（首都圏のよくある駅 30個前後から）、`available_from` は今日〜3か月先の月初、`work_style` は onsite/hybrid/remote/any、`days_per_week` 5（1割は 3〜4）、`summary`・`strengths` は「型」ごとの定型文。initials は `engValues` が kana から作る。電話・住所・生年月日は入れない。
- スキル：「型」（業務系 Java・Web 系 PHP・フロント・インフラ・.NET・モバイル など6つ前後）を1つ選び、型ごとの候補から 3〜7 個（年数 1〜経験年数、レベル 2〜5）。候補は `SkillCatalog::CATALOG` にある正式な名前だけ。渡す前に `SkillCatalog::find()` で確かめ、見つからない言葉は渡さない（`skill_terms` を増やさない）。`skill_terms` が空のときだけ `SkillCatalog::sync()` を呼ぶ（マスタ。purge 後も残ってよい）。
- 担当者・エンジニアのアカウント：`W::createUser($email, $name, 'ses'|'eg', $pw, ['ses_id'=>…, 'engineer_id'=>…, 'sei'=>…, 'mei'=>…])` のあと、`W::kanaCols()` のふりがなと、対応表の状態の列を update。`$pw` は実行ごとに `Str::random(40)`（出力しない・どこにも書かない。ダミーでログインはできない。未決 3）。

### メールを出さない・跡を残さない（AC8）

- `W::invite()`・`W::applySes()`・`W::approveSes()`・`N::notify()` 系は呼ばない。`createEngineer` は `$invite=false`、`$actor=null`。
- transaction の中で、始める前の `max(id)` を覚えておき、終わりに次を消す：
  - `app_notifications`：id が始める前より大きく、title が「エンジニアが登録されました」で link が今回の `/engineers/{id}` のもの。
  - `audit_log`：id が始める前より大きく、action が `engineer-create` のもの（ダミーの氏名が残るため）。
- 最後に `N::audit(null, 'seed-dummy', 件数だけの JSON)` を1行（名前・アドレスは書かない）。
- 安全のため、transaction の最後で `mail_outbox` の件数が始める前と同じかを確かめ、違えば例外で全部戻す。

### purge-samples で消える根拠（AC11）

| 作るもの | purge の判定 | 消えるか |
|---|---|---|
| SES の会社（申込中・参加中・停止） | `kind='ses'` かつ email が `@park.example` | 消える（全社に email を入れる） |
| 担当者・エンジニアの users | email が `@park.example` | 消える |
| engineers（SES 所属・直属） | email が `@park.example` | 消える（直属も email で拾う） |
| engineer_skills | 消す engineers の id | 消える |
| 招待トークン・sessions・password_reset_tokens | 消す users | 消える |
| ベルの通知・engineer-create の記録 | （purge では消えない） | このコマンドの中で消しておく |
| 直属の入れ物（kind=direct） | 対象外 | 残る（システムの入れ物。今までどおり） |
| skill_terms（空のとき入れたマスタ） | 対象外 | 残る（マスタ） |
| audit_log の `seed-dummy` 1行 | 対象外 | 残る（件数だけ） |

守りの判定に当たらない理由：ダミーに管理者は作らない（TEZZO の管理者が残る）、ダミーの SES にダミー以外の人はいない、案件・応募・契約は作らない。
**注意（テスト中の運用）**：テストで、ダミーの SES に本物（@park.example 以外）の担当者・エンジニアを登録したり、ダミーのエンジニアで案件・応募を作ったりすると、purge は守りで止まる。そのときは `--move-kept-to-direct`（エンジニアだけ直属へ移す）を使うか、TEZZO に確かめる。README とマニュアルに書く。

### 公開版で動かすときの注意

- `park:status` と朝の点検の「見本データ（@park.example）」の数が増える（想定どおり）。
- 一覧の招待中・申込中のダミーに「招待を送り直す」「承認して招待を送る」を押すと、`@park.example` あてのメールが出る（届かない予約済みのドメイン。mail_outbox に残るが purge で消える）。押さないよう、マニュアルに書く。
- ダミーがいる間は、一斉メールを使わない。案件の配信でダミーのエンジニア・SES を選ばない（応募まで進むと purge が守りで止まる）。マニュアルと README に書く。

## 作業手順

1. `app/Services/DummySeed.php` を作る：表（姓・名・会社名の素・駅・型とスキル・定型文）、`plan()`（検査・番号・割り振り・件数の表を返す。DB は読むだけ）、`run()`（`plan()` の結果を1つの transaction で入れる。上の「跡を残さない」まで）。
2. `app/Console/Commands/SeedDummy.php` を作る（`PurgeSamples.php` と同じ形。予定の表 → `--yes` なしなら終わり → `run()`）。
3. 入れた直後に手元（GENESIS／FOCUS の手元の DB）で、既定の件数で `--yes` なし → `--yes` → 管理者の一覧・SES の一覧・エンジニアの一覧で状態の混ざり方を見る → `park:purge-samples` で消えることを確かめる。
4. テスト `tests/Feature/SeedDummyTest.php` を足し、`php artisan test` を通す。
5. README と管理者マニュアルを直す（下）。41_Park-Project の `04_Manuals` の写しも写し直す（JOE／Leo）。

## テスト（tests/Feature/SeedDummyTest.php）

`PurgeSamplesTest` の作り方に合わせる（RefreshDatabase。最初に @park.example でない管理者を1人、password つきで作る。`Mail::fake()`）。件数は小さめ（例 `--ses=5 --staff=8 --eg=12 --direct=5 --seed=1`）でよいが、T2 は既定の件数でも1回流す。

- **T1（AC2）** `--yes` なしで流す → 終了コード 0、出力に「見るだけ」。users・ses_companies・engineers・engineer_skills・app_notifications・audit_log・mail_outbox・skill_terms の件数が変わらない。kind=direct の行も増えない。
- **T2（AC1・AC3）** `--ses=8 --staff=15 --eg=80 --seed=1 --yes` → @park.example の ses_companies が 8、role ses の users が 15、engineers が 80（うち直属 8）、role eg の users が 80 で engineer_id がそれぞれの engineers を指す。どのエンジニアにも skills が 3件以上。
- **T3（AC4）** T2 のあと：SES に pending・active・suspended が各1以上、pending の SES に users が 0。担当者とエンジニアで A〜E（対応表の条件）が各1以上。1人目の担当者（会社の email と同じ）に active=0 がいない。E のエンジニアは suspended_at あり・users.active=0。
- **T4（AC5・AC6）** 作った users・engineers・ses_companies の email がすべて `@park.example` で終わり、形が `ses-dNN`・`ses-dNN-M`・`eg-dNNN`。name_sei・name_mei・kana_sei・kana_mei が空でなく、name がつないだもの。engineers.initials が入っている。skill_terms の件数が増えていない（実行前に sync 済みの状態で比べる）。
- **T5（AC8）** `--yes` のあと、mail_outbox が 0 件増、`Mail::assertNothingOutgoing()`。本物の管理者の app_notifications が増えていない。audit_log の増えは `seed-dummy` の1行だけで、detail に `@` が無い。
- **T6（AC11）** 入れたあと `park:purge-samples --yes` → 終了コード 0。@park.example の users・ses_companies・engineers が 0、それらの engineer_skills も 0。本物の管理者は残る。users・engineers・ses_companies（kind=ses）の件数が入れる前と同じ。
- **T7（AC10）** `app()['env'] = 'production'` にして `--yes` → 作られる。`--yes` なしなら変わらない。
- **T8（AC9）** 同じ指定で2回 `--yes` → 2回目は `ses-d06`・`eg-d013` から（上の小さい件数の例）。メールアドレスの重複なし、件数は2倍。
- **T9（AC7）** `--seed=42 --yes` → (email, name, kana, 状態) の一覧を取る → `park:purge-samples --yes` → もう一度 `--seed=42 --yes` → 同じ一覧になる。
- **T10（入力の検査）** `--ses=0`・`--eg=501`・`--staff` が申込中でない SES の数より少ない・`--direct` が eg より多い → どれも終了コード 1、DB は変わらない。
- **T11（AC12）** 出力に `@park.example` と、作った人の姓が1つも出ない。

## README・マニュアルに書き足すこと

- `README.md`：「見本データを消すコマンド」の段落の前に、`park:seed-dummy` の段落を足す。オプションと既定値、`--yes` なしは見るだけ、公開版でも動く（SampleSeeder とは別）、メールは出さない、状態の対応表の要約、番号は続きから、消すのは `park:purge-samples`、ダミーの SES に本物の人を入れると purge が止まること。
- `resources/manuals/admin.md`：「2. はじめに」の「メールについて」の後に「### テスト用のダミーデータ」を足す（3〜5行）：テストの間、一覧に `@park.example` の SES・担当者・エンジニアが並ぶ／ダミーには招待の送り直し・承認を押さない（届かないメールが出る）／ダミーの SES に本物の人を登録しない／片付けは管理者（TEZZO）がコマンドで行う。`admin-steps.md` は変えない。
- SES・エンジニアのマニュアルは変えない（ダミーを見るのは管理者の画面だけ。SES のダミーはログインできない）。

## TEZZO の了承が要る手順

- 公開版で `park:seed-dummy --yes` を流すこと（データを足す）。先に `--yes` なしの予定の表を TEZZO に見せる。TEZZO が入れると決めているが、件数と seed は流す前に確かめる。GENESIS にはサーバーの鍵が無いので、FOCUS の Leo（ssh）か TEZZO が流す。
- 公開版で `park:purge-samples --yes` を流すこと（データの削除）。先に `--yes` なしで件数を見せる。
- コードの反映（push → `park-deploy.sh`）は常時了承の範囲。マイグレーションは無い。

## 未決（返事待ちに載せる件）

TEZZO が 2026-10-30 に決定した（すべて仮定のとおり）。

1. 決定：SES の会社名の末尾に「（ダミー）」を付ける。人の氏名には付けない。
2. 決定：退会済は混ぜない。
3. 決定：ダミーのアカウントではログインしない（パスワードは乱数のまま）。
4. 決定：既定の件数は SES 8社・担当者15人・エンジニア80人、直属はエンジニアの1割。
5. 決定：v0.2.0 で仮登録・本登録の列ができたら、対応表を差し替える（今回は対象外。別の計画）。

---

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
