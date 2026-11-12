<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 技術マスタ。案件メールの言葉とエンジニアのスキルを、同じ「正式な名前」にそろえて突き合わせる。
// 最初の辞書はここに書く（php artisan park:skills-sync で DB に入れる）。案件から見つかった新しい技術は DB に自動で足す。
class SkillCatalog
{
    // 分類 => [正式な名前 => 別名（「、」区切り）]
    const CATALOG = [
        'lang' => [
            'Java' => '', 'PHP' => '', 'JavaScript' => 'JS、ECMAScript', 'TypeScript' => 'TS', 'Python' => 'Python3', 'Ruby' => '', 'Go' => 'Golang',
            'C' => 'C言語', 'C++' => 'CPP', 'C#' => 'CSharp、C＃', 'VB.NET' => 'VB.net', 'Visual Basic' => 'VB、VB6', 'VBA' => 'Excel VBA', 'Kotlin' => '', 'Swift' => '',
            'Objective-C' => 'ObjC', 'Scala' => '', 'Rust' => '', 'Perl' => '', 'COBOL' => '', 'PL/SQL' => 'PLSQL', 'SQL' => '', 'HTML' => 'HTML5、XHTML',
            'CSS' => 'CSS3', 'Sass' => 'SCSS', 'Shell' => 'Bash、シェルスクリプト、sh', 'PowerShell' => '', 'R' => 'R言語', 'Dart' => '', 'ABAP' => '', 'XML' => '', 'RPG' => '',
            'Google Apps Script' => 'GAS',
        ],
        'fw' => [
            'Laravel' => '', 'CakePHP' => '', 'CodeIgniter' => 'Codeigniter', 'Symfony' => '', 'Zend Framework' => 'ZendFramework、ZendFramework2、Zend', 'FuelPHP' => '',
            'Ethna' => '', 'Slim' => '', 'Spring' => 'Spring Framework', 'Spring Boot' => 'SpringBoot', 'Struts' => 'Struts2', 'Seasar2' => '', 'MyBatis' => 'iBATIS',
            'Hibernate' => '', 'JSF' => '', 'Ruby on Rails' => 'Rails、RoR', 'Django' => '', 'Flask' => '', 'FastAPI' => '', 'Express' => 'Express.js',
            'NestJS' => 'Nest.js', 'Next.js' => 'Next', 'Nuxt.js' => 'Nuxt', 'React' => 'React.js、ReactJS', 'Vue.js' => 'Vue、Vue3、VueJS', 'Angular' => 'AngularJS',
            'jQuery' => '', 'Svelte' => '', '.NET' => 'ASP.NET、.NET Framework、.NET Core', 'Unity' => '', 'Flutter' => '', 'React Native' => '',
            'Drupal' => '', 'WordPress' => 'WP', 'EC-CUBE' => 'ECCUBE', 'Bootstrap' => '', 'Tailwind CSS' => 'Tailwind', 'Node.js' => 'Node、NodeJS', 'Electron' => '',
            'Doctrine ORM' => 'Doctrine',
        ],
        'db' => [
            'MySQL' => 'MySql', 'MariaDB' => '', 'PostgreSQL' => 'Postgres、psql', 'Oracle' => 'Oracle DB、Oracle Database', 'SQL Server' => 'SQLServer、MSSQL',
            'SQLite' => '', 'DB2' => '', 'MongoDB' => '', 'Redis' => '', 'DynamoDB' => '', 'Elasticsearch' => '', 'Access' => 'MS Access', 'FileMaker' => '', 'BigQuery' => '', 'Snowflake' => '',
            'Redshift' => 'Amazon Redshift', 'dbt' => '',
        ],
        'os' => [
            'Linux' => '', 'Windows' => '', 'Windows Server' => '', 'macOS' => 'Mac', 'UNIX' => 'Unix', 'Solaris' => '', 'AIX' => '', 'CentOS' => '',
            'Red Hat Enterprise Linux' => 'RHEL、Red Hat', 'Ubuntu' => '', 'Android' => '', 'iOS' => '',
            'メインフレーム' => '汎用機',
        ],
        'cloud' => [
            'AWS' => 'Amazon Web Services', 'Azure' => 'Microsoft Azure', 'GCP' => 'Google Cloud、Google Cloud Platform', 'Firebase' => '', 'Heroku' => '', 'Salesforce' => '',
            'Amazon EC2' => 'EC2', 'Amazon S3' => 'S3', 'AWS Lambda' => 'Lambda',
            'Amazon Athena' => 'Athena', 'Google Workspace' => '', 'Microsoft 365' => 'M365', 'ServiceNow' => '', 'Dify' => '', 'LINE Messaging API' => 'LIFF',
        ],
        'infra' => [
            'Docker' => '', 'Kubernetes' => 'k8s', 'Terraform' => '', 'Ansible' => '', 'Apache' => 'Apache HTTP Server', 'Nginx' => '', 'Tomcat' => '', 'IIS' => '',
            'Jenkins' => '', 'CircleCI' => '', 'GitHub Actions' => '', 'Zabbix' => '', 'VMware' => '', 'Cisco' => '', 'ネットワーク' => 'TCP/IP', 'JP1' => '',
            'SSO' => 'シングルサインオン',
        ],
        'tool' => [
            'Git' => '', 'GitHub' => '', 'GitLab' => '', 'Subversion' => 'SVN', 'Backlog' => '', 'Redmine' => '', 'Jira' => 'JIRA', 'Confluence' => '', 'Slack' => '',
            'Excel' => '', 'Photoshop' => '', 'Illustrator' => '', 'Dreamweaver' => '', 'Flash' => '', 'Adobe Director' => 'Director、Macromedia Director', 'ToolBook' => '', 'AgileWorks' => '', 'Figma' => '',
            'Eclipse' => '', 'Visual Studio' => '', 'Visual Studio Code' => 'VS Code、VSCode', 'IntelliJ IDEA' => 'IntelliJ', 'Selenium' => '', 'JUnit' => '', 'PHPUnit' => '', 'Postman' => '',
            'Tableau' => '', 'Power BI' => 'PowerBI', 'Looker Studio' => '', 'QlikView' => '', 'PowerPoint' => '', 'Word' => '', 'Jest' => '', 'Genesys Cloud' => '',
            'JUST.DB' => '', 'Dataiku' => '', 'Streamlit' => '', 'ERP' => '',
        ],
        'method' => [
            '要件定義' => '', '基本設計' => '外部設計', '詳細設計' => '内部設計', '実装' => '製造、プログラミング', '単体テスト' => 'UT', '結合テスト' => 'IT、結合試験',
            '総合テスト' => 'システムテスト、ST', '運用・保守' => '運用、保守', 'アジャイル' => 'スクラム、Scrum', 'ウォーターフォール' => '', 'SPA' => 'Single Page Application',
            'REST API' => 'REST、API開発、WebAPI', 'オフショア開発' => 'オフショア', 'マネジメント' => '工程管理、進捗管理、プロジェクト管理', '設計書レビュー' => 'レビュー',
        ],
        'domain' => [
            '金融' => '', '証券' => '', '保険' => '生保、損保', '銀行' => '', '物流' => '倉庫', '小売' => '流通', 'EC' => 'ECサイト、通販', '建設' => '', '製造業' => 'メーカー',
            '医療' => '', '官公庁' => '中央省庁、自治体', '不動産' => '賃貸', '飲食' => '', '通信' => 'キャリア', 'エネルギー' => 'ガス、電力', '教育' => 'eラーニング',
            'POS' => '', '予約システム' => '予約', '決済' => '', '顧客管理' => 'CRM、会員管理',
        ],
        'role' => [
            'PM' => 'プロジェクトマネージャー', 'PL' => 'プロジェクトリーダー、リーダー', 'SE' => 'システムエンジニア', 'PG' => 'プログラマー', 'PMO' => '',
            'アーキテクト' => '', 'テックリード' => '', 'インフラエンジニア' => '',
        ],
    ];

    // 文章から探すときは使わない言葉（言葉そのものを調べる find では、今までどおり使う）。例：「Director クラス」は役職のこと
    const NO_DETECT = ['Director'];

    // 名前を変える行（古い名前 => 新しい名前）。sync の最初に行う。id は変わらない
    const RENAMES = ['Director' => 'Adobe Director'];

    // 振り分けの前に文章から消す言い方（別の言葉に当たってしまうもの）。detect と案件の業務の拾い出し（CaseExp）が使う
    const STRIP = ['電気小売', '製造業', 'PMワークベンチ', 'プロジェクトマネージャ試験', '可視化', '営業日'];

    // 消す言い方を、空白に置き換える（全角の英数字は半角にそろえてから）
    public static function strip(string $text): string
    {
        $text = mb_convert_kana($text, 'as');
        foreach (self::STRIP as $w) $text = str_replace(mb_convert_kana($w, 'as'), ' ', $text);
        return $text;
    }

    // 辞書を DB に入れる（すでにある名前は、別名だけ足す）。新しく入れた件数を返す
    public static function sync(): int
    {
        return self::syncDetail()['added'];
    }

    // sync の詳しい結果：['added' => 件数, 'renamed' => [旧 => 新], 'fixed' => [名前…], 'conflicts' => ['言葉 → 既にある行の名前'…]]
    // 行は消さない。別名が別の行とぶつかるものは、知らせるだけ（統合は管理者が画面で行う）
    public static function syncDetail(): array
    {
        $added = 0; $renamed = []; $fixed = []; $conflicts = []; $idOf = [];
        // 1. 名前の変更（古い名前の行があり、新しい名前の行が無いときだけ）
        foreach (self::RENAMES as $old => $new) {
            $o = DB::table('skill_terms')->where('name', $old)->first();
            if (!$o) continue;
            if (DB::table('skill_terms')->where('name', $new)->exists()) {
                $conflicts[] = "{$old} → {$new}";
                continue;
            }
            DB::table('skill_terms')->where('id', $o->id)->update(['name' => $new, 'updated_at' => now()]);
            $renamed[$old] = $new;
        }
        // 2. 辞書の言葉を入れる
        foreach (self::CATALOG as $cat => $terms) {
            foreach ($terms as $name => $aliases) {
                $ex = DB::table('skill_terms')->where('name', $name)->first();
                if ($ex) {
                    $merged = implode('、', array_unique(array_merge(parse_list($ex->aliases), parse_list($aliases))));
                    $upd = ['aliases' => $merged ?: null];
                    // 案件から自動で足されて確認待ちのままの行は、辞書の分類にして確認済みにする
                    if ($ex->source === 'auto' && !$ex->reviewed) {
                        $upd += ['category' => $cat, 'source' => 'master', 'reviewed' => true, 'updated_at' => now()];
                        $fixed[] = $name;
                    }
                    DB::table('skill_terms')->where('id', $ex->id)->update($upd);
                    $idOf[$name] = (int) $ex->id;
                } else {
                    $idOf[$name] = (int) DB::table('skill_terms')->insertGetId(['name' => $name, 'category' => $cat, 'aliases' => $aliases ?: null, 'source' => 'master', 'reviewed' => true, 'created_at' => now(), 'updated_at' => now()]);
                    $added++;
                }
            }
        }
        // 3. 辞書の別名が、ほかの行の名前・別名とぶつかっていないか調べる
        $owners = []; // 正規化した言葉 => [[行の id, 行の名前]…]
        $rows = DB::table('skill_terms')->get();
        foreach ($rows as $r) {
            foreach (array_merge([$r->name], parse_list($r->aliases)) as $w) {
                $k = norm_skill_raw($w);
                if ($k !== '') $owners[$k][$r->id] = $r->name;
            }
        }
        foreach (self::CATALOG as $terms) {
            foreach ($terms as $name => $aliases) {
                $id = $idOf[$name] ?? null; // 2. で覚えた行の id（名前の大文字小文字だけ違う行と取り違えない）
                foreach (parse_list($aliases) as $a) {
                    foreach ($owners[norm_skill_raw($a)] ?? [] as $oid => $oname) {
                        if ((int) $oid !== $id) $conflicts[] = "{$a} → {$oname}";
                    }
                }
            }
        }
        self::forget();
        return ['added' => $added, 'renamed' => $renamed, 'fixed' => $fixed, 'conflicts' => array_values(array_unique($conflicts))];
    }

    private static ?array $map = null;

    public static function forget(): void { self::$map = null; }

    // 正規化した言葉 => [term id, 正式な名前, 分類]
    public static function map(): array
    {
        if (self::$map !== null) return self::$map;
        self::$map = [];
        try {
            if (!Schema::hasTable('skill_terms')) return self::$map;
            foreach (DB::table('skill_terms')->get() as $t) {
                foreach (array_merge([$t->name], parse_list($t->aliases)) as $w) {
                    $k = norm_skill_raw($w);
                    if ($k !== '' && !isset(self::$map[$k])) self::$map[$k] = [$t->id, $t->name, $t->category];
                }
            }
        } catch (\Throwable $e) {
            self::$map = [];
        }
        return self::$map;
    }

    public static function find(string $word): ?array
    {
        return self::map()[norm_skill_raw($word)] ?? null;
    }

    // マスタに無い言葉なら、自動で足す（管理者が分類を確かめるまで「未確認」）。足したら名前を返す
    public static function ensure(string $word, string $category = 'other'): ?string
    {
        $word = trim($word);
        if ($word === '' || mb_strlen($word) > 40 || self::find($word)) return null;
        DB::table('skill_terms')->insertOrIgnore(['name' => $word, 'category' => $category, 'source' => 'auto', 'reviewed' => false, 'created_at' => now(), 'updated_at' => now()]);
        self::forget();
        return $word;
    }

    // 文章の中から、マスタにある技術を探す（英数字の言葉は、前後が英数字でないときだけ一致にする）
    public static function detect(?string $text): array
    {
        $text = self::strip((string) $text);
        $found = [];
        foreach (DB::table('skill_terms')->whereIn('category', ['lang', 'fw', 'db', 'os', 'cloud', 'infra', 'tool'])->get() as $t) {
            foreach (array_merge([$t->name], parse_list($t->aliases)) as $w0) {
                if (in_array($w0, self::NO_DETECT, true)) continue;
                $w = mb_convert_kana($w0, 'as'); // 文章と同じく、全角の英数字は半角にそろえてから比べる
                if (mb_strlen($w) < 2 && !preg_match('/^[A-Z]$/', $w)) continue;
                if (preg_match('/^[\x20-\x7e]+$/', $w)) {
                    if (strlen($w) <= 1) continue; // 「C」「R」のような1文字は文章からは拾わない
                    if (preg_match('/(?<![A-Za-z0-9#+.])'.preg_quote($w, '/').'(?![A-Za-z0-9#+])/i', $text)) { $found[$t->name] = true; break; }
                } elseif (mb_strpos($text, $w) !== false) { $found[$t->name] = true; break; }
            }
        }
        return array_keys($found);
    }
}
