# PLAN：技術マスタの追加と、業務経験・業務領域のマスタ（v0.5.0。2026-11-12）

> **これが今の計画**。下の「v0.4.1」の節は前の計画（実装・公開版への反映は済み）で、消さずに残している。
> 依頼：Leo（2026-11-12）。もとは TEZZO の方針の了承（2026-11-12「その感じで進めて」）。調査の記録は 41_Park-Project の `40_動作確認/41_マッチング精度`（`2026-11-12-03`・`2026-11-12-04`）。
> 作る順：段階1 → 段階2 → 段階3（段階ごとに commit。`php artisan test` を通してから次へ）→ park-reviewer → park-tester。commit・push・公開版への反映は Leo。
> プレーヤー：管理者・SES・担当者・EG の4つ（担当者は今のアプリでは `role=ses`）。**誰が編集でき・誰が見られるかは今のまま変えない**。
> 外部のライブラリは足さない。案件メールの本文・取引先の名前はこのファイルに書かない。
> 範囲外：抜き出し（`CaseParser` の必須・尚可の読み方）への手元の AI の組み込み（手元の AI の測定のあとで別に計画する）。

## 0. コードで確かめた今の形

- **技術マスタ**：辞書は `app/Support/SkillCatalog.php` の `CATALOG`（分類 => [正式な名前 => 別名]）。`park:skills-sync`（`SkillCatalog::sync`）が `skill_terms` に入れる（同じ名前があれば別名だけ足す。名前の変更・分類の直しはしない）。`sync` は `DatabaseSeeder`・`SampleSeeder`・`DummySeed`・`SeedDummyTest` からも呼ばれる（戻り値は新しく入れた件数）。
- `SkillCatalog::find`/`map`：名前・別名 → [id, 正式な名前, 分類]。同じ言葉が2つの行にあれば、先に読んだ行が勝つ。`norm_skill` はこれで正式な名前にそろえてから比べる。
- `SkillCatalog::detect`：案件の本文から lang〜tool の分類だけを拾う（英数字の言葉は前後が英数字でないときだけ、大文字小文字は区別しない）。`domain`（業種・業務）・`method`（工程）・`role`（役割）は拾わない。この3つは `find` で正式な名前にそろえるのにだけ使われ、工程・役割は EG のスキルの `words`（役割・工程の節の項目名）と突き合わさる。`domain` は EG 側に相手が無い（業界の節は `match:false`）。
- **「Director」の誤検出**：tool の `Director` が、本文の「Director クラス」（役職）に当たる。
- 経歴の索引（`engineer_project_skills`）は `ProjectSkills::rebuildAll` が `find` で作り直す。管理者の技術マスタ画面は、追加・直し・統合のあとにこれを呼んでいる。`park-deploy.sh` は `migrate`・キャッシュだけで、`park:skills-sync` は流さない（README の手での更新手順には書いてある）。
- `loose_match`（helpers）は「片方がもう片方を含めば一致」（Java/JavaScript だけ例外）。両方3文字以上で使う（`Matcher::sameSkill`、スキルシートの `$isHit`）。**新しく足す短い名前は、ほかの名前の中に含まれて誤って当たる**（例：`word` は `wordpress` に、`erp` は `redhatenterpriselinux` に含まれる）。
- **EG のスキル**：v0.4.1 の形（`resources/data/skill_form.json` の節 → `SkillForm` → `engineer_skill_checks`。`match:false` の節は `words` が空で、照合・一覧の列・技術の「使っている人」・完成度から外れる。詳細・スキルシート・Excel には出る）。業界の節（`industry`）と同じ形で、節を足すだけで保存・表示・「今の表に無い項目」が効く。**マイグレーションは要らない**。
- **案件**：必須・尚可は `cases.required_skills`・`preferred_skills`（「、」などで区切った文。`parse_list` は `・` と `/` でも分ける）。`Workflow::saveCase` が1つずつ `SkillCatalog::ensure`（無い言葉はマスタに確認待ちで足す）と `find` を通す。だから **「進捗・課題管理」のような名前は、この欄に入れると壊れる**（`・` で分かれ、マスタにも足される）。
- 取り込み：`CaseController::import` → `CaseParser::parse`（必須・尚可の見出しの下の行を `$buckets` に1行ずつ集め、最後に「、」でつなぐ）→ 案件票の画面に入れて見せる（人が直してから登録）。
- 点数：`Matcher::score($c, $e, $idx)`。必須 55 点 × 合った数 ÷ 必須の数（経歴だけで合えば 0.8）、尚可 15 点。まとめて呼ぶところ：`Matcher::forCase`・`Workflow::distribute`・`CaseMail::send`・`SesController`（73行）・`CaseController::show`（SES の表）。1人だけ：`CaseController::show`（EG）・`recommend`・`Qa`（面談の想定問答。合う点・足りない点をそのまま使う）。
- EG の「案件チェック」：`SkillChecks::caseMarks($state, $c)`（項目の `words` と案件の必須・尚可を突き合わせて「必須」「尚可」の印、`missing`＝本人のチェックのどれにも合わない必須）。案件は `EngineerController::pickableCases`（取る列を絞っている）。
- スキルシートの `?case=`：`EngineerController::sheet` の `$reqRows`（必須ごとに ○／—）。

## 1. 受け入れ条件

| 番号 | 受け入れ条件 |
|---|---|
| **段階1** | **技術マスタ** |
| C1 | 技術マスタに、3章の表の技術が分類・別名つきで入る |
| C2 | 本文に「Director クラス」「Director」とあっても、`detect` は何も拾わない。「Adobe Director」「Macromedia Director」は拾う。案件の必須の欄・経歴に「Director」と単独で書いた言葉は、今までどおり技術（Adobe Director）としてそろう |
| C3 | 新しく足す短い名前（Word・ERP・SSO・dbt）は、ほかの名前の中に含まれていても一致にしない（Word の案件が WordPress の人に当たらない） |
| C4 | 既存の DB に `park:skills-sync` を流すと：足りない言葉が入る／`Director` の行が `Adobe Director` に名前が変わる（id は同じ）／案件から自動で足された確認待ちの行で、名前が辞書と同じものは、辞書の分類になり確認済みになる／辞書の別名が、ほかの行の名前・別名とぶつかるものは画面に一覧で出す（勝手に統合・削除しない）／経歴の索引を作り直す／何度流しても結果は同じ |
| C5 | 「振り分けの前に消す言い方」（3章）を、`detect` と段階3の拾い出しの両方で、言葉を探す前に消す |
| **段階2** | **EG のスキル画面** |
| W1 | スキル画面の開発・運用ツールの節の後ろに節「業務経験」（15項目）、業界の節の後ろに節「業務領域」（8項目）が出る。チェックだけ（年数なし・その他なし）。名前と並びは3章のとおり |
| W2 | チェックは `engineer_skill_checks` に保存され、詳細・スキルシート（名前を伏せた版も）・Excel に節として出る。一覧の「スキル」列・技術の「使っている人」・技術の照合（words）・完成度の「5つ以上」には入らない |
| W3 | 作りは v0.4.1 の業界と同じ（JSON に節を足す。表の追加・マイグレーション無し）。既存の節・データの保存は変わらない |
| **段階3** | **案件側と突き合わせ** |
| K1 | 案件メールの取り込みで、必須・尚可の見出しの下の各行から、辞書（3章の「案件での言い方」）で業務経験・業務領域を拾い、案件票の画面に「必須」「尚可」が選ばれた状態で出る。同じ項目が両方にあれば必須。取り込みの知らせに、拾った名前が出る |
| K2 | 英字の言い方（AI・BI・ITIL など）は、前後が英数字でないときだけ当たる（「AIX」で AI 活用にならない）。消す言い方は先に消す（「5営業日」で営業・見積にならない） |
| K3 | 案件の登録・編集の画面で、業務経験・業務領域の項目ごとに「—／必須／尚可」を選べ、保存される（`cases.required_exp`・`preferred_exp`）。今までの必須・尚可スキルの欄は変わらない |
| K4 | 案件の詳細に「必須の業務」「尚可の業務」が出る（どちらかがあるときだけ）。案件を見られる全員（管理者・SES・担当者・EG） |
| K5 | 点数：案件の必須の業務は必須の数に、尚可の業務は尚可の数に入る。EG がその項目にチェックしていれば合う点「必須 ◯◯」、無ければ足りない点「必須 ◯◯」。**業務の指定が無い案件の点数は今と同じ** |
| K6 | EG の案件チェック：業務経験・業務領域の項目にも「必須」「尚可」の印が付き、本人がチェックしていない必須の業務は「あなたのスキルに無い必須」に出る |
| K7 | 案件に合わせたスキルシート（`?case=`）の必須の表に、必須の業務が ○ 経験あり／— で出る |
| K8 | まとめて点数を出すところで、問い合わせの数が人数で増えない |
| K9 | JSON から業務の項目が消えたら、案件に保存した名前は無視される（点数・表示・印に出ない） |
| K10 | 権限は今のまま：案件を直せるのは管理者・自社の案件の SES と担当者。EG は見るだけ。通知・メールは足さない |
| V1 | バージョンを v0.5.0 にする。README・マニュアル（`resources/manuals` と 04_Manuals の写し）を直す |

## 2. 決めたこと（Leo の決定として仮置き。違えば7章で直す）

| 番号 | 決めたこと | 理由 |
|---|---|---|
| D1 | Director：正式な名前を `Adobe Director`、別名 `Director`・`Macromedia Director`。別名 `Director` は **`detect`（文章から探す）では使わない**（`SkillCatalog::NO_DETECT`）。`find`（言葉そのもの）では今までどおり当たる | 経歴・必須の欄に単独で書いた「Director」は技術のことなので、そろえる働きは残す。誤りは文章から探すときだけ |
| D2 | 名前の変更は `SkillCatalog::RENAMES = ['Director' => 'Adobe Director']` を `sync` の最初に行う（古い名前の行があり、新しい名前の行が無いときだけ `name` を変える。両方あれば何もせず、ぶつかりとして知らせる） | id が変わらないので、経歴の索引の付け替えが要らない |
| D3 | 案件から自動で足された行（`source=auto`・`reviewed=false`）の名前が辞書の名前と同じなら、`sync` が分類を辞書のものにし、`reviewed=true`・`source=master` にする | 確認待ちのまま「その他」の分類で残るのを防ぐ。削除はしない |
| D4 | 辞書の別名が、別の行の名前・別名として DB にもうあるときは、`sync` は足すだけでぶつかりを報告する（コマンドの画面に出す）。統合は管理者が技術マスタの画面で行う（統合は行を消すので、TEZZO の了承を取ってから） | データの削除を自動でしない |
| D5 | 「完全に同じときだけ一致」の言葉 `PARK_EXACT_ONLY = ['word', 'erp', 'sso', 'dbt']`（`norm_skill_raw` の形）を helpers に置き、`loose_match` で片方がこれなら `===` だけにする | C3。今ある言葉の動きは変えない |
| D6 | 技術マスタの「そのほか」の LLM・RAG・生成 AI・ChatGPT は、技術マスタに足さず、業務経験「AI 活用」の言い方に寄せる（AI の分類は作らない） | EG のスキル画面に相手の項目が無く、技術として拾っても誰にも当たらない。案件側の要件は「AI を使った経験」の意味がほとんど |
| D7 | 消す言い方は `SkillCatalog::STRIP`（PHP の定数。1か所）に置き、`SkillCatalog::strip($text)` で、全角英数を半角にした文から1つずつ空白に置き換える。`detect` と段階3の拾い出しが使う | C5。正本1か所 |
| D8 | EG 側の項目と、案件で拾う言い方は `skill_form.json` に置く。節の新しい鍵 `caseMatch: true`（案件の業務と名前で突き合わせる節）、項目の新しい鍵 `caseWords: [...]`（案件での言い方）。`caseMatch` の節は `match: false`・`items` だけ・年数なし・その他なし。`caseWords` は `caseMatch` の節だけで、どの項目にも1つ以上 | 「項目の正本は JSON」を保つ。名前を2か所に書かない |
| D9 | 節の key は `work`（業務経験）・`area`（業務領域）。並びは `… dev, work, industry, area, pmpl`。短い名前 `SHORT`：`work => 業務経験`・`area => 業務領域` | 業務経験は役割・工程・技術の続き、業務領域は業界の隣。PM/PL の3問（出たり消えたりする節）は一番下のまま |
| D10 | 案件の保存先は `cases` に列を2つ足す：`required_exp`・`preferred_exp`（text・null 可。項目の名前を改行でつなぐ）。`required_skills` には入れない | `parse_list` が `・` で名前を分けてしまう。`ensure` で技術マスタに混ざるのも防ぐ |
| D11 | `work` と `area` の項目名は、2つの節を通して重ならない（JSON の検査）。案件の列は名前だけで持つ | どちらの節の項目かは JSON から引ける |
| D12 | 点数は、必須の業務を必須の数に、尚可の業務を尚可の数に足す（1項目＝技術1つと同じ重み）。EG の行があれば 1（経歴からは数えない）。`WEIGHTS` は変えない | K5。業務の無い案件は今と同じ点になる |
| D13 | 取り込みでは、`required_skills`・`preferred_skills` の中身は今のまま（業務だけの行も残す）。業務は別に拾って `required_exp`・`preferred_exp` に入れる | 今の抜き出しの動きを変えない。業務だけの行の扱いは7章 Q3 |
| D14 | 案件の画面の入力は `exp[項目の id]` = `''`／`required`／`preferred`（セレクト）＋隠し欄 `_exp=1`。`_exp` が無い保存では2列を触らない（新しい案件は null）。知らない id・知らない値は黙って無視 | 案件の画面を通らない保存（見本など）で消さない |
| D15 | 案件の業務を読むときは、今の JSON にある名前だけを使う（`CaseExp::split`）。消えた項目は黙って外す | K9 |
| D16 | EG の業務のチェックは `SkillChecks::expMany($ids)`（[engineer_id => [名前 => true]]。500人ごとに1回）で読む。`Matcher::score` に5つ目でなく **4つ目の引数 `?array $exp = null`** を足し、null なら1人分を読む。まとめて呼ぶところは全部まとめて渡す | K8。`skillIndexMany` の形（技術の索引）に混ぜると、`hasSkill` のゆるい一致で技術と業務が当たってしまう |
| D17 | 案内メールの本文（`{必須}`）・面談の想定問答の「必須ごとの質問」・案件の一覧の「必須」列には業務を足さない（面談の「合う点・足りない点」には点数から自動で入る） | 今回は突き合わせまで。文面を変えるのは別の依頼で（7章 Q8） |
| D18 | バージョンは **v0.5.0**（`config/park.php`） | 表の列の追加（マイグレーション）と新しい機能のため |
| D19 | 業務経験・業務領域を `DummySeed`・`SampleSeeder` に入れない | v0.4.1 の業界と同じ |

## 3. 足すもの

### 3-1. 技術マスタ（段階1。`SkillCatalog::CATALOG`）

| 分類 | 正式な名前 => 別名 |
|---|---|
| db | `Redshift` => `Amazon Redshift`、`dbt` => '' |
| cloud | `Amazon Athena` => `Athena`、`Google Workspace` => '' 、`Microsoft 365` => `M365`、`ServiceNow` => ''、`Dify` => ''、`LINE Messaging API` => `LIFF` |
| tool | `Tableau`、`Power BI` => `PowerBI`、`Looker Studio`、`QlikView`、`PowerPoint`、`Word`、`Jest`、`Genesys Cloud`、`JUST.DB`、`Dataiku`、`Streamlit`、`ERP`（別名なし） |
| lang | `Google Apps Script` => `GAS` |
| fw | `Doctrine ORM` => `Doctrine` |
| os | `メインフレーム` => `汎用機` |
| infra | `SSO` => `シングルサインオン` |
| tool（直す） | `Director` => '' を `Adobe Director` => `Director、Macromedia Director` に（D1・D2） |

- EG のスキル画面には、Redshift・Streamlit・Doctrine・メインフレーム・Google Workspace・Microsoft 365・Jest がもうある（その人に当たるようになる）。Tableau などは EG の「その他」か経歴で当たる。スキル画面に項目を足すのは今回の範囲外。
- 消す言い方 `STRIP`（D7）：`電気小売`、`製造業`、`PMワークベンチ`、`プロジェクトマネージャ試験`、`可視化`、`営業日`（調査の v3 の5つ＋「営業日」。`Director（D）` は D1 で要らなくなる）。

### 3-2. 業務経験（段階2。`skill_form.json` の節 `work`）

```json
{ "key": "work", "title": "システム開発プロジェクトで経験のある業務は？", "match": false, "caseMatch": true,
  "items": [ {"name": "進捗・課題管理", "years": false, "other": false, "caseWords": ["進捗", "課題管理", "WBS", "タスク管理", "リスク", "バックログ"]}, … ] }
```

| # | 名前（この並び・この文字） | caseWords |
|---|---|---|
| 1 | 進捗・課題管理 | 進捗、課題管理、WBS、タスク管理、リスク、バックログ |
| 2 | 折衝・調整 | 折衝、調整、合意形成、ステークホルダー、ヒアリング |
| 3 | ベンダー管理 | ベンダーコントロール、ベンダー管理 |
| 4 | 資料作成 | 資料、ドキュメント、設計書、報告書、議事録、マニュアル |
| 5 | 移行 | 移行、データ移行 |
| 6 | 導入・刷新 | 導入、刷新、更改、バージョンアップ、リプレース |
| 7 | 業務設計・運用設計 | 業務フロー、運用設計、業務整理、標準化、業務改善 |
| 8 | 品質管理 | 品質、品質指標 |
| 9 | データ分析・BI | 分析、BI、データアーキテクチャ |
| 10 | 障害・問い合わせ対応（ITIL） | 障害、不具合、問い合わせ、インシデント、ITIL、変更管理 |
| 11 | 英語・海外 | 英語、通訳、海外、グローバル |
| 12 | AI 活用 | AI、生成AI、AX、LLM、RAG、ChatGPT（後ろ3つは D6） |
| 13 | 難しいプロジェクト（大規模・複数・立て直し） | 大規模、複数案件、複数プロジェクト、横断、炎上、遅延、立て直し |
| 14 | 教育・ナレッジ | 教育、指導、ナレッジ、属人化解消 |
| 15 | 提案・事業化 | 提案、プリセールス、外販、新規事業 |

### 3-3. 業務領域（段階2。節 `area`。業界の節の後ろ）

`{ "key": "area", "title": "システム開発で関わった業務の領域は？", "match": false, "caseMatch": true, "items": [...] }`

| # | 名前 | caseWords（Leo の案。調査の辞書を土台に、ほかの言葉と取り違えやすいものは外した） |
|---|---|---|
| 1 | 営業・見積 | 営業、見積、SFA、販売管理 |
| 2 | 人事・給与 | 人事、給与、勤怠、労務 |
| 3 | 経理・財務 | 経理、会計、財務、債権、債務、固定資産、決算 |
| 4 | 総務・バックオフィス | 総務、バックオフィス、庶務、稟議 |
| 5 | 受発注・在庫 | 受発注、受注、発注、在庫、購買、調達 |
| 6 | 生産管理 | 生産管理、生産計画、MES |
| 7 | 規制対応（GxP など） | GxP、GMP、薬事、規制対応 |
| 8 | 顧客管理（CRM・コンタクトセンター） | CRM、顧客管理、コンタクトセンター、コールセンター |

- 外したもの：`CSV`（ファイルの形式と取り違える）、`GCP`（Google Cloud と取り違える）、`工程管理`（技術マスタで「マネジメント」の別名）。

### 3-4. 案件（段階3。マイグレーション `2026_11_12_000001_case_exp_columns.php`）

- `cases` に `required_exp` text null・`preferred_exp` text null を足す（`after('preferred_skills')`）。down は2列を消す。既存の行は null のまま（後から埋めない。7章 Q4）。

## 4. プレーヤーごとに変わること

| プレーヤー | 段階1（技術マスタ） | 段階2（EG のスキル） | 段階3（案件） |
|---|---|---|---|
| 管理者 | 技術マスタの画面に新しい言葉が出る。ぶつかりがあれば統合の判断（了承を取ってから） | 全 EG の業務経験・業務領域を直せる（仮登録中は今どおり直せない） | 全案件で業務を選べる。取り込みで拾われる。点数・案内メールの候補の順・配信の点数に入る |
| SES | 案件の取り込みで新しい技術が拾われる | 自社の EG の分を直せる | 自社が持ち込んだ案件で業務を選べる。「貴社のエンジニアとの相性」の点数・足りない点に入る |
| 担当者 | SES と同じ（`role=ses`） | SES と同じ | SES と同じ |
| EG | — | 本人の分を直せる。詳細・スキルシートに出る | 案件の詳細で業務が見える。「あなたとの相性」・案件チェックの印・「あなたのスキルに無い必須」に入る |

- 通知・メールは足さない。上位が EG のスキルを直すと、今どおり `W::editedBy` で本人の「確認しました」がやり直しになる。
- 0件：業務を選んでいない案件は、詳細に行を出さず、点数も今と同じ。業務にチェックの無い EG は、業務のある案件で足りない点が増える（意図どおり）。

## 5. 作業の手順（implementer）

### 段階1：技術マスタ

1. `SkillCatalog::CATALOG` に3-1の表を足す（Director は直す）。
2. 定数を足す：`NO_DETECT = ['Director']`、`RENAMES = ['Director' => 'Adobe Director']`、`STRIP`（3-1）。関数 `strip(string $text): string`（全角英数を半角にした文から、STRIP の言葉（同じく半角にしたもの）を空白に置き換える）。
3. `detect`：最初に `strip` を通す。`NO_DETECT` にある言葉は飛ばす。
4. `sync` を `syncDetail(): array`（`['added' => n, 'renamed' => [旧 => 新], 'fixed' => [名前…], 'conflicts' => ['言葉 → 既にある行の名前'…]]`）に分け、`sync(): int` は `syncDetail()['added']` を返す（今の呼び出し元はそのまま）。順番：RENAMES → 各言葉（あれば別名を足す。D3 の auto 行は分類・確認済みを直す。無ければ入れる）→ 別名ごとに、同じ `norm_skill_raw` の言葉がほかの行にあれば conflicts に足す → `forget`。
5. `SkillsSync` コマンド：`syncDetail` の結果を出す（足した数・名前を変えた行・直した行・ぶつかり）。最後に `ProjectSkills::rebuildAll()`。ぶつかりがあれば「技術マスタの画面で統合してください（統合は行を消します）」と出す。
6. helpers：`PARK_EXACT_ONLY` を足し、`loose_match` の最初で、どちらかがこれに入っていれば `$a === $b` を返す（D5）。
7. `php artisan test`。手元の DB で `park:skills-sync` を2回流し、2回目は added 0・renamed 空になることを確かめる。

### 段階2：EG のスキル画面

1. `skill_form.json`：3-2 の `work` を `dev` の後ろに、3-3 の `area` を `industry` の後ろに足す（D9）。
2. `SkillForm::build`：節の `caseMatch`（既定 false）を `tree` の節に持たせ、項目の node に `caseWords`（無ければ `[]`）を持たせる。検査（`\RuntimeException`）：`caseMatch` の節が `match:false` でない／`groups` がある／`single` である／項目に `years:true`・`other:true` がある／`caseWords` が空・文字の配列でない、`caseMatch` でない節に `caseWords` がある、`work`・`area` を通して名前が重なる。
3. `SkillForm::SHORT` に `work`・`area` を足す。関数 `caseItems(): array`（`caseMatch` の節の項目を JSON の並びで `[名前 => ['id', 'section', 'words' => caseWords]]`）。
4. 画面 `engineers/skills.blade.php`：今の描き方（業界と同じ items の節）で出ることを確かめる。説明文の「業界は当てはまるものすべて」の文を「業務経験・業界・業務領域は当てはまるものすべて」に直す。
5. `SkillChecks`：変えない（`match:false` で一覧・照合・使っている人・完成度から外れる）。確かめるだけ。
6. `php artisan test`（`SkillFormJsonTest`・`SkillQuestionsTest` の節の一覧を直す）。

### 段階3：案件

1. マイグレーション（3-4）。
2. `app/Support/CaseExp.php`（新しく）：
   - `detectLine(string $line): array`（名前の配列）：`SkillCatalog::strip` を通した行に、`caseItems()` の各 caseWords を当てる。英数字だけの言葉は `detect` と同じ前後の決まり（大文字小文字を区別しない）、それ以外は含むかどうか。
   - `fromLines(array $req, array $pref): array`（`['required' => [...], 'preferred' => [...]]`。JSON の並び。両方にあれば required だけ）。
   - `split(?string $v): array`（改行で分け、`caseItems()` にある名前だけ。D15）、`join(array $names): ?string`（JSON の並びに直して改行でつなぐ。空なら null）。
3. `CaseParser::parse`：`$buckets` を「、」でつなぐ前に `CaseExp::fromLines($buckets['required_skills'] ?? [], $buckets['preferred_skills'] ?? [])` を呼び、`$out['required_exp']`・`$out['preferred_exp']` を `join` した文で入れる。ほかの出力は変えない（D13）。
4. `CaseController::import`：知らせの文に「本文の必須・尚可から見つかった業務：◯◯（必須）、◯◯（尚可）」を足す（無ければ出さない）。
5. `cases/form.blade.php`：「尚可スキル」の欄の下に、`<details open>`「業務経験・業務領域（必須・尚可）」。節ごと（業務経験・業務領域）に、項目ごとの行：名前＋`<select name="exp[項目の id]">`（`—`／`必須`／`尚可`。`$c->required_exp`・`preferred_exp` を `CaseExp::split` して選ぶ）。隠し欄 `_exp=1`。説明「取り込みでは、必須・尚可の文から拾って選んでおきます。確かめて直してください。」
6. `Workflow::saveCase`：`CASE_FIELDS` には足さない。`array_key_exists('_exp', $d)` のとき、`exp` の配列から名前の一覧を作り（`caseItems` にある id・`required`／`preferred` の値だけ）、`required_exp`・`preferred_exp` を `CaseExp::join` で入れる。`_exp` が無いときは、新規なら `$d['required_exp']`・`$d['preferred_exp']`（文）を `split`→`join` して入れ（見本・テスト用）、更新なら2列を触らない（D14）。`ensure` は通さない。
7. `cases/show.blade.php`：「尚可」の行の後ろに、`dt`「必須の業務」「尚可の業務」（中身があるときだけ。`x-chips`）。
8. `SkillChecks::expMany(array $ids): array`（`caseMatch` の節の行で、今の JSON でチェックできるもの。500人ごとに1回）。
9. `Matcher::score`：4つ目の引数 `?array $exp = null`（null なら `SkillChecks::expMany([$e->id])[$e->id]`）。必須：`$req` の技術に加え、`CaseExp::split($c->required_exp ?? null)` の名前ごとに、`isset($exp[$name])` なら hit +1・合う点「必須 名前」、無ければ足りない点「必須 名前」。分母は技術と業務の合計。尚可も同じ。両方が空のときの 0.5 の扱いは今と同じ（技術も業務も無いとき）。
10. まとめて呼ぶところで `expMany` を1回読んで渡す：`Matcher::forCase`、`Workflow::distribute`、`CaseMail::send`、`SesController`（73行）、`CaseController::show`（SES の表）。`Qa`・EG の表示・`recommend` は1人分なので引数なしでよい。
11. `SkillChecks::caseMarks`：`caseItems()` の項目ごとに、案件の required_exp にあれば `required`、preferred_exp にあれば `preferred` の印。必須の業務で本人が `isOn` でないものを `missing` に足す（名前のまま）。`empty` は技術・業務の4つとも空のとき。`EngineerController::pickableCases` の取る列に `required_exp`・`preferred_exp` を足す。
12. `EngineerController::sheet`：`$reqRows` の後ろに、必須の業務ごとに `[名前, ○ 経験あり／—]`（`expMany` で本人の分）。
13. `config/park.php` を `v0.5.0`。版を見ているテスト（`RegistrationFoundationTest`・`SkillQuestionsTest`・`V030RegressionTest`）の `v0.4.1` を直す。
14. README：「## スキルのチェック式」に v0.5.0 の段落（`work`・`area`、`caseMatch`・`caseWords`、案件の2列、点数への入り方、`CaseExp`）。技術マスタの段落に `NO_DETECT`・`RENAMES`・`STRIP`・`PARK_EXACT_ONLY`、`park:skills-sync` が名前の変更・確認待ちの直し・ぶつかりの報告・経歴の索引の作り直しまですること。
15. マニュアル（`resources/manuals`）：
    - `engineer.md`「### スキル」：業務経験・業務領域の節（当てはまるものすべて。年数なし）。業界と違い、**案件の点数と「案件に合わせた印」に使う**こと。「案件を見る」（97行あたり）：案件票に「必須の業務」「尚可の業務」が出ること。
    - `engineer-steps.md` 3-3 の2：手順に「業務経験・業務領域にチェック」を足す。
    - `ses.md` 82行・`ses-steps.md` 59行・`admin.md` 120行：節の説明に業務経験・業務領域を足す（点数に使う）。
    - `admin.md` 168〜183行・`ses-steps.md` 76行：取り込みで業務も拾うこと、案件票の「業務経験・業務領域」の選び方、点数の説明に「必須・尚可の業務」を足す。
    - 04_Manuals の写しは、park を push したあと Leo（または park-manager）が写し直す。implementer は park の中だけ直す。
16. `php artisan test` が全部通ることを確かめる（zip の既知の1件を除く）。

## 6. テスト（新しく `tests/Feature/V050CatalogTest.php`・`V050WorkExpTest.php`）

立場は今のテストのやり方（管理者・SES・担当者・EG のユーザーを作ってログイン）に合わせる。取り込みは `POST /cases/import`（`raw_text`）、登録は `POST /cases`、編集は `POST /cases/{id}`、詳細は `GET /cases/{id}`、スキルは `GET/POST /engineers/{id}/skills`（`_sf=1`・`_sf_end=1`）、シートは `GET /engineers/{id}/sheet?case=`。本文はテストの中で作った架空の文だけを使う。

| 番号 | 条件 | 手順 | 確かめること |
|---|---|---|---|
| T1 | C1 | `SkillCatalog::sync()` のあと `find` | 3-1 の名前・別名（`Athena`・`M365`・`GAS`・`Doctrine`・`LIFF`・`汎用機`）が正しい正式な名前と分類を返す |
| T2 | C2 | `detect("Director クラスの方")`／`detect("Director")`／`detect("Adobe Director の経験")` | 前の2つは空、3つ目は `Adobe Director`。`find('Director')[1]` は `Adobe Director` |
| T3 | C3 | 「Word」が必須の案件と、WordPress だけにチェックした EG で `Matcher::score`／`loose_match('word','wordpress')`／`loose_match('erp','redhatenterpriselinux')` | 必須 Word は足りない点。loose_match は false。`loose_match('laravel','laravel5')` など今のものは true のまま |
| T4 | C4 | `skill_terms` に `Director`（tool）と、auto・未確認の `Tableau`（other）と、auto の `M365` を入れてから `syncDetail()` を2回 | 1回目：`Director` の行が同じ id で `Adobe Director`、`Tableau` は tool・確認済み、conflicts に `M365` が出る（行は消えない）。2回目：added 0・renamed 空 |
| T5 | C4 | `php artisan park:skills-sync`（`$this->artisan`） | 終了 0。経歴に「Tableau」と書いた EG の `engineer_project_skills` に Tableau の行ができる |
| T6 | C5 | `SkillCatalog::strip('5営業日以内')`／`CaseExp::detectLine('5営業日以内に報告書を作成')` | 「営業日」が消える。拾うのは `資料作成` だけ（`営業・見積` は無い） |
| T7 | W1 | `SkillForm::tree()`／EG で画面を開く | 節の key が `roles, phases, lang, db, os, pm, dev, work, industry, area, pmpl`。work 15件・area 8件が3章の名前と並びで、`years=false`・`other=false`。画面に「進捗・課題管理」「経理・財務」が出て、その行に年数のプルダウンが無い |
| T8 | W2 | EG が work 2つ・area 1つを `chk` で保存 → 詳細・シート（`?anon=1` も）・`SkillChecks::lines` | `engineer_skill_checks` に `section=work` 2行・`area` 1行（`years_min` null）。「業務経験」「業務領域」の節が出る |
| T9 | W2 | 同じ EG で `indexMany`・`summaryMany`・`usedPeople`・`normIndex`・`Qa::sheetCheck`（技術3つ＋業務5つ） | 業務の行が索引・一覧の列・使っている人・normIndex に無い。「5つ以上」の指摘が出る |
| T10 | W3 | JSON の検査（`SkillForm::validate` に一時の配列を渡す）：caseMatch に `years:true`／`match` が無い caseMatch／caseWords が空／caseMatch でない節に caseWords／work と area に同じ名前 | どれも `\RuntimeException` |
| T11 | K1 | 管理者で、必須の見出しの下に「進捗管理の経験」「AIX の運用」、尚可の下に「英語での会話」「進捗の報告」の行がある本文を `POST /cases/import` | 画面の `exp[...]` で `進捗・課題管理` が必須、`英語・海外` が尚可に選ばれている。`AI 活用` は選ばれていない（K2）。知らせに「見つかった業務」が出る。`required_skills` の欄の中身は今と同じ |
| T12 | K3 | 管理者・SES・担当者がそれぞれ `exp` を付けて `POST /cases`（`_exp=1`）→ 編集で `_exp=1` と空の `exp` で更新／`_exp` なしで更新 | 列に名前が改行で入る（JSON の並び）。空で更新すると null。`_exp` なしの更新では列が変わらない。知らない id・値は無視。技術マスタに業務の名前が足されない |
| T13 | K10 | EG が `POST /cases/{id}`／他社の SES が自社でない案件を編集 | 今どおり拒否（403） |
| T14 | K4 | 業務のある案件を、管理者・SES・担当者・EG で `GET /cases/{id}`／業務の無い案件 | 前は「必須の業務」と名前が出る。後は「必須の業務」の行が出ない |
| T15 | K5 | 必須 `Java` と必須の業務 `進捗・課題管理`、尚可の業務 `英語・海外` の案件。EG-A（Java と進捗・課題管理にチェック）・EG-B（Java だけ）で `Matcher::score` | A は必須2つ合い、合う点に「必須 進捗・課題管理」。B は足りない点に「必須 進捗・課題管理」で、A より低い |
| T16 | K5 | 業務の列が null の案件で、`V030RegressionTest` と同じ人・案件 | 点数が今と同じ（82 点） |
| T17 | K6 | EG-B で、その案件を選んでスキル画面を開く（`?case=`） | `進捗・課題管理` の行に「必須」、`英語・海外` に「尚可」の印。「あなたのスキルに無い必須」に `進捗・課題管理` |
| T18 | K7 | 管理者で EG-A のシートを `?case=` で開く | 必須の表に「進捗・課題管理」「○ 経験あり」 |
| T19 | K8 | エンジニア 3人と 30人で `Matcher::forCase` の問い合わせ数を数える（`V030RegressionTest` と同じやり方） | 同じ回数 |
| T20 | K9 | 案件の `required_exp` に JSON に無い名前を直接入れる | 点数・詳細・印に出ない |
| T21 | V1 | `/login` | `v0.5.0` が出る |
| T22 | 既存 | 既存のテスト一式 | 版の文字と節の一覧を直したほかは、変えずに通る |

- 画面の見た目（案件票のセレクトの並び、スキル画面の新しい2節）は自動テストが無いので、reviewer がコードを読み、Leo が画面を開いて確かめる。

## 7. 確認したいこと（Leo が決める。答えが来るまで推奨で作る）

> **2026-11-12 Leo：Q1〜Q10 はすべて推奨どおり。** Q1 まとめて v0.5.0 で1回反映／Q2 業務1項目＝技術1つの重み／Q3 業務だけの行も必須の欄から外さない／Q4 既存案件の拾い直しコマンドは作らない／Q5 AI 系は業務経験「AI 活用」に寄せる／Q6 業界の突き合わせは今回しない／Q7 `park-deploy.sh` への `park:skills-sync` の追加は計画に書くだけ（スクリプトは 41_Park-Project 側にあるので Leo が直す）／Q8 案内メールには出さない／Q9 並びは計画どおり／Q10 業務領域の辞書は計画のまま（外れが出たら JSON を直し、必要なら `STRIP` に「教育業界」を足す）。

- **Q1 反映の単位**：段階ごとに公開版へ出すか、3段階をまとめて v0.5.0 で出すか。**推奨：まとめて1回**（段階2だけだと、EG にチェックさせても点数に効かず、入れる理由が伝わらない）。
- **Q2 業務の重み**：業務1項目を技術1つと同じ重みで必須・尚可の数に入れる（D12）。**推奨：このまま**。案件によって業務の数が多いと、技術の比重が下がる。気になれば、次の版で必須の中を技術と業務で分ける。
- **Q3 業務だけの行**：取り込みで、業務だけに当たって技術を含まない行（例「進捗管理の経験」）を必須スキルの欄から外すか。**推奨：今回は外さない**（D13）。外すと、同じ行の役割・工程（PMO など）も消える。手元の AI の抜き出しを入れるときに見直す。それまでは、登録の前に人が欄を直す。
- **Q4 既存の案件**：保存済みの案件の `raw_text` から業務を拾い直すコマンドを作るか。**推奨：作らない**（点数が一斉に変わる。必要な案件は編集で選ぶ）。
- **Q5 AI の分類**：LLM・RAG・生成 AI・ChatGPT を「AI 活用」に寄せた（D6）。**推奨：このまま**。
- **Q6 業界との突き合わせ**：v0.4.1 の業界（26件）と、技術マスタの `domain` を案件と突き合わせるか。**推奨：今回はしない**（業界の名前と `domain` の名前がそろっておらず、別の整理が要る）。
- **Q7 `park-deploy.sh` に `park:skills-sync` を足すか**（41_Park-Project の `.claude/scripts`）。**推奨：足す**（何度流しても同じで、データを消さない。今は README の手での手順にしかなく、公開版の辞書が古いままになる）。足さない場合は、今回の反映のあとに Leo が公開版で1回流す。ぶつかりが出たら、統合（行を消す）は TEZZO の了承を取ってから。
- **Q8 案内メールの本文**：`{必須}` に業務も並べるか（D17）。**推奨：今回はしない**。
- **Q9 節の並び**：`dev → work → industry → area → pmpl`（D9）。**推奨：このまま**。項目名と並びは画面で TEZZO に見てもらう（見た目の確認は TEZZO）。
- **Q10 業務領域の言い方**：3-3 の caseWords は Leo の案（調査の辞書が無かったので足した）。**推奨：このまま入れ、外れを見て JSON を直す**。また「教育」は業界の「教育」と取り違えるおそれがある（例：教育業界向けの案件）。**推奨：今回は消す言い方に足さず、外れが出たら `STRIP` に「教育業界」を足す**。

## 8. 了承が要ること

- 公開版への反映（`park-deploy.sh`。マイグレーション1本＝列の追加だけ）は常時了承の範囲。データの削除・メールの送信は無い。
- `park:skills-sync` を公開版で流すと、`Director` の行の名前が変わり、確認待ちの行の一部が確認済みになる（行は消さない）。README の更新手順にあるコマンドなので、Leo が流してよい（Q7）。
- ぶつかり（D4）の統合は行を消すので、**TEZZO の了承を取ってから**管理者の画面で行う。

---

> 以下は前の計画（v0.4.1。2026-11-11）。実装・公開版への反映は済み。消さずに残す。

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
| A3 | 業界の節の後ろに、見出し「PM/PLご経験者の方へ」の節が出る。中は3問で、それぞれ単一選択（v0.4.1 の修正でラジオからプルダウンに変更。TEZZO の指示）。選択肢は原文どおり |
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
