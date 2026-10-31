<?php

namespace App\Services;

use App\Services\Notifier as N;
use App\Services\Workflow as W;
use App\Support\SkillCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;

// テスト用の「にぎやかしダミーデータ」を入れる手続き（2026-10-30）。
// plan() が、検査・番号・状態の割り振り・値をすべて決める（DB は読むだけ）。run() が、plan() の結果を1つの transaction で入れる。
// 消すのは SamplePurge（メールアドレスが @park.example の人・会社・エンジニアは、まとめて消せる）。
class DummySeed
{
    const SUFFIX = '@park.example';

    // 担当者・エンジニアの状態と割合（v0.2.0 の仮登録 → 本登録の状態）
    const STATE_RATIO = ['A' => 50, 'B' => 10, 'C' => 15, 'D' => 15, 'E' => 10];
    const STATE_LABEL = ['A' => '本登録済み', 'B' => '参加中（必須項目が足りない）', 'C' => '仮登録', 'D' => '仮登録（期限切れ）', 'E' => '停止中'];
    const SES_LABEL = ['pending' => '申込中', 'provisional' => '仮登録', 'active' => '参加中', 'suspended' => '停止'];
    const DUMMY_PHONE = '03-0000-0000';
    const DUMMY_POSTAL = '100-0001';
    const DUMMY_ADDRESS = '東京都千代田区（ダミー）';
    const DIRECT_RATE = 0.1;       // 直属の既定の割合（エンジニアの人数に対して）
    const UNAVAILABLE_RATE = 15;   // 本登録済みのエンジニアのうち、休止中にする割合（%）
    const MIN_SKILLS = 3;
    const MAX_SKILLS = 7;

    // 姓［漢字, ふりがな］（よくある姓。名と組み合わせるだけで、実在の人物のフルネームは並べない）
    const SEI = [
        ['佐藤', 'さとう'], ['鈴木', 'すずき'], ['高橋', 'たかはし'], ['田中', 'たなか'], ['伊藤', 'いとう'], ['渡辺', 'わたなべ'], ['山本', 'やまもと'], ['中村', 'なかむら'],
        ['小林', 'こばやし'], ['加藤', 'かとう'], ['吉田', 'よしだ'], ['山田', 'やまだ'], ['佐々木', 'ささき'], ['山口', 'やまぐち'], ['松本', 'まつもと'], ['井上', 'いのうえ'],
        ['木村', 'きむら'], ['林', 'はやし'], ['斎藤', 'さいとう'], ['清水', 'しみず'], ['山崎', 'やまざき'], ['森', 'もり'], ['池田', 'いけだ'], ['橋本', 'はしもと'],
        ['石川', 'いしかわ'], ['山下', 'やました'], ['中島', 'なかじま'], ['前田', 'まえだ'], ['藤田', 'ふじた'], ['小川', 'おがわ'], ['後藤', 'ごとう'], ['岡田', 'おかだ'],
        ['長谷川', 'はせがわ'], ['村上', 'むらかみ'], ['近藤', 'こんどう'], ['石井', 'いしい'], ['坂本', 'さかもと'], ['遠藤', 'えんどう'], ['青木', 'あおき'], ['藤井', 'ふじい'],
        ['西村', 'にしむら'], ['福田', 'ふくだ'], ['太田', 'おおた'], ['三浦', 'みうら'],
    ];
    const MEI_M = [
        ['太郎', 'たろう'], ['一郎', 'いちろう'], ['健太', 'けんた'], ['大輔', 'だいすけ'], ['翔太', 'しょうた'], ['拓也', 'たくや'], ['直樹', 'なおき'], ['和也', 'かずや'],
        ['雄太', 'ゆうた'], ['裕介', 'ゆうすけ'], ['誠', 'まこと'], ['剛', 'つよし'], ['亮', 'りょう'], ['隆', 'たかし'], ['浩二', 'こうじ'], ['俊介', 'しゅんすけ'],
        ['達也', 'たつや'], ['悠斗', 'はると'], ['蓮', 'れん'], ['颯太', 'そうた'], ['慎一', 'しんいち'], ['哲也', 'てつや'], ['修平', 'しゅうへい'], ['光一', 'こういち'],
        ['優太', 'ゆうた'], ['智也', 'ともや'], ['洋平', 'ようへい'], ['大地', 'だいち'], ['圭太', 'けいた'], ['健二', 'けんじ'],
    ];
    const MEI_F = [
        ['花子', 'はなこ'], ['陽子', 'ようこ'], ['恵美', 'めぐみ'], ['由美', 'ゆみ'], ['愛', 'あい'], ['美咲', 'みさき'], ['彩', 'あや'], ['麻衣', 'まい'],
        ['結衣', 'ゆい'], ['優子', 'ゆうこ'], ['真由美', 'まゆみ'], ['瞳', 'ひとみ'], ['裕子', 'ひろこ'], ['千尋', 'ちひろ'], ['菜々子', 'ななこ'], ['沙織', 'さおり'],
        ['香織', 'かおり'], ['理恵', 'りえ'], ['絵里', 'えり'], ['友美', 'ともみ'], ['夏美', 'なつみ'], ['遥', 'はるか'], ['葵', 'あおい'], ['咲希', 'さき'],
        ['舞', 'まい'], ['玲奈', 'れいな'], ['美穂', 'みほ'], ['里奈', 'りな'], ['紗季', 'さき'], ['杏', 'あん'],
    ];

    // 会社名の素（前半＋後半。末尾に「（ダミー）」を付ける）
    const CO_HEAD = ['さくら', 'あおば', 'みなも', 'こはる', 'ひなた', 'あかり', 'つばさ', 'はるか', 'ことは', 'かえで', 'むつみ', 'しらべ', 'そよかぜ', 'ひだまり'];
    const CO_TAIL = ['システム', 'テック', 'ソリューションズ', 'ラボ', 'ワークス', 'ネットワークス', 'デザイン', 'ソフト'];
    const CO_STRENGTHS = ['業務系システムの開発に強い', 'Web サービスの開発・運用に強い', 'インフラ・クラウドの構築に強い', 'テスト・品質保証に強い', 'スマートフォンアプリの開発に強い', '金融系の保守・開発に強い'];
    const CO_SUFFIX = '（ダミー）';

    const STATIONS = ['新宿', '渋谷', '池袋', '東京', '品川', '上野', '秋葉原', '新橋', '大宮', '横浜', '川崎', '立川', '吉祥寺', '町田', '船橋', '柏', '千葉', '浦和', '北千住', '錦糸町',
        '蒲田', '大崎', '五反田', '目黒', '三鷹', '府中', '海老名', '溝の口', '武蔵小杉', '赤羽'];
    const WORK_STYLES = ['onsite', 'hybrid', 'remote', 'any'];

    // スキルの「型」。skills は SkillCatalog::CATALOG にある正式な名前だけ（無い言葉は入れる前に外す）
    const TYPES = [
        ['skills' => ['Java', 'Spring Boot', 'MyBatis', 'Oracle', 'SQL', 'Linux', 'Git', '基本設計', '詳細設計', '単体テスト', '結合テスト', '金融'],
            'summary' => ['業務系システムの設計から結合テストまでを経験。仕様を整理して進めるのが得意です。', '金融系の業務システムで、保守・改修を長く担当してきました。'],
            'strengths' => ['設計書の読み書き', '品質を保った保守・改修']],
        ['skills' => ['PHP', 'Laravel', 'MySQL', 'JavaScript', 'jQuery', 'HTML', 'CSS', 'Git', 'Linux', 'AWS', '実装', 'EC'],
            'summary' => ['Web サービスの開発を、設計から実装・運用まで幅広く担当。小さなチームで動くのが得意です。', 'EC サイトの機能追加と改善を中心に開発してきました。'],
            'strengths' => ['素早い実装と改善提案', '少人数チームでの開発']],
        ['skills' => ['JavaScript', 'TypeScript', 'React', 'Vue.js', 'Next.js', 'HTML', 'CSS', 'Git', 'Figma', 'REST API'],
            'summary' => ['フロントエンドの開発を中心に、画面設計から実装まで担当してきました。', '使いやすい画面づくりと、デザイナーとの橋渡しが得意です。'],
            'strengths' => ['画面の使いやすさへの配慮', 'デザイナーとの連携']],
        ['skills' => ['Linux', 'AWS', 'Docker', 'Kubernetes', 'Terraform', 'Ansible', 'Zabbix', 'ネットワーク', 'Shell', 'Jenkins', '運用・保守'],
            'summary' => ['サーバー・クラウドの構築と運用を担当。手順を整えて自動化するのが得意です。', '監視・障害対応から、構築の自動化まで経験があります。'],
            'strengths' => ['手順書づくりと自動化', '落ち着いた障害対応']],
        ['skills' => ['C#', '.NET', 'VB.NET', 'SQL Server', 'Windows Server', 'SQL', 'Visual Studio', '詳細設計', '結合テスト'],
            'summary' => ['業務アプリの開発・改修を、詳細設計からテストまで担当してきました。', '社内向けシステムの保守と機能追加を長く担当しています。'],
            'strengths' => ['業務の流れを読み取る力', '丁寧なテスト']],
        ['skills' => ['Kotlin', 'Swift', 'Android', 'iOS', 'Firebase', 'Flutter', 'Git', 'REST API'],
            'summary' => ['スマートフォンアプリの開発を、企画の相談からリリースまで経験しています。', 'Android・iOS の両方で、アプリの改善を続けてきました。'],
            'strengths' => ['アプリの動きへのこだわり', 'ストア公開までの経験']],
        ['skills' => ['Python', 'Django', 'PostgreSQL', 'Docker', 'AWS', 'Git', 'SQL', 'REST API', 'Linux'],
            'summary' => ['データを扱う Web アプリの開発を担当。集計や API づくりが得意です。', '小さな機能をすばやく形にして、改善を重ねるのが得意です。'],
            'strengths' => ['データの扱いと集計', '素早い試作']],
    ];

    // ---------- 予定を作る（DB は読むだけ） ----------
    // $opt：ses・staff・eg・direct・seed（文字・数字・null）。戻り値：errors（あれば止める）、seed、env、counts、ranges、spec（run が使う中身）
    public static function plan(array $opt): array
    {
        $env = app()->environment();
        $int = function ($v, int $min, int $max, string $label, array &$errors, ?int $default = null): ?int {
            if ($v === null || $v === '') return $default;
            $n = filter_var($v, FILTER_VALIDATE_INT);
            if ($n === false || $n < $min || $n > $max) { $errors[] = "{$label} は {$min}〜{$max} の整数で指定してください。"; return null; }
            return $n;
        };
        $errors = [];
        $ses = $int($opt['ses'] ?? 8, 1, 50, '--ses', $errors);
        $staff = $int($opt['staff'] ?? 15, 0, 200, '--staff', $errors);
        $eg = $int($opt['eg'] ?? 80, 0, 500, '--eg', $errors);
        $direct = $eg === null ? null : $int($opt['direct'] ?? null, 0, $eg, '--direct', $errors, (int) round($eg * self::DIRECT_RATE));
        $seed = $int($opt['seed'] ?? null, 0, 2147483647, '--seed', $errors, 0);
        if ($errors) return ['errors' => $errors, 'env' => $env];
        if (($opt['seed'] ?? null) === null || $opt['seed'] === '') $seed = random_int(1, 2147483647);

        // 会社の状態の件数（申込中は ses≥3、停止は ses≥4、仮登録は ses≥5 のときだけ。残りは参加中）
        $nPending = $ses >= 3 ? max(1, (int) round($ses * 0.15)) : 0;
        $nSuspended = $ses >= 4 ? max(1, (int) round($ses * 0.10)) : 0;
        $nProvisional = $ses >= 5 ? 1 : 0; // 仮登録の会社は、代表者（仮登録）が1人だけ。エンジニアはいない
        $nOpen = $ses - $nPending - $nProvisional; // 担当者・エンジニアがいる会社（参加中・停止）
        if ($staff < $nOpen) return ['errors' => ["担当者は最低 {$nOpen} 人です（参加中・停止の SES 1社に1人ずつ）。"], 'env' => $env];

        $rng = new Randomizer(new Mt19937($seed));
        $pick = fn (array $a) => $a[$rng->getInt(0, count($a) - 1)];
        $person = function () use ($rng, $pick): array {
            $sei = $pick(self::SEI);
            $mei = $pick($rng->getInt(0, 1) ? self::MEI_M : self::MEI_F);
            return ['sei' => $sei[0], 'mei' => $mei[0], 'kana_sei' => $sei[1], 'kana_mei' => $mei[1]];
        };

        // 始まりの番号（既にあるダミーの次から）
        $s0 = self::maxNo('/^ses-d(\d+)(?:-\d+)?'.preg_quote(self::SUFFIX, '/').'$/i') + 1;
        $e0 = self::maxNo('/^eg-d(\d+)'.preg_quote(self::SUFFIX, '/').'$/i') + 1;

        // SES の会社
        $sesStates = array_merge(array_fill(0, $nPending, 'pending'), array_fill(0, $nProvisional, 'provisional'), array_fill(0, $nSuspended, 'suspended'), array_fill(0, $ses - $nPending - $nProvisional - $nSuspended, 'active'));
        $sesStates = $rng->shuffleArray($sesStates);
        $combos = [];
        foreach (self::CO_HEAD as $h) foreach (self::CO_TAIL as $t) $combos[] = $h.$t;
        $combos = $rng->shuffleArray($combos);
        $companies = [];
        foreach ($sesStates as $i => $st) {
            $companies[$i] = ['no' => $s0 + $i, 'email' => sprintf('ses-d%02d', $s0 + $i).self::SUFFIX, 'name' => $combos[$i % count($combos)].self::CO_SUFFIX, 'status' => $st,
                'rep' => $person(), 'contact' => $person(), 'employees' => $rng->getInt(3, 80), 'strengths' => $pick(self::CO_STRENGTHS)];
        }
        $open = array_keys(array_filter($companies, fn ($c) => in_array($c['status'], ['active', 'suspended'], true)));

        // 担当者：参加中・停止の各社に1人目（会社の email と同じ。会社の代表者。停止中にしない）。残りは各社にランダムに配る
        $st = self::stateList($rng, $staff);
        for ($k = 0; $k < $nOpen; $k++) {
            if ($st[$k] !== 'E') continue;
            $j = null;
            for ($x = $nOpen; $x < $staff; $x++) if ($st[$x] !== 'E') { $j = $x; break; }
            if ($j === null) { $st[$k] = 'A'; continue; }
            [$st[$k], $st[$j]] = [$st[$j], $st[$k]];
        }
        $people = []; $seq = [];
        for ($k = 0; $k < $staff; $k++) {
            if ($k < $nOpen) { $ci = $open[$k]; $seq[$ci] = 1; $p = $companies[$ci]['rep']; }
            else { $ci = $pick($open); $seq[$ci] = ($seq[$ci] ?? 1) + 1; $p = $person(); }
            $no = $companies[$ci]['no'];
            $people[] = $p + ['ci' => $ci, 'state' => $st[$k], 'days' => $rng->getInt(1, 30), 'expired' => $rng->getInt(1, 20), 'first' => $seq[$ci] === 1,
                'email' => $seq[$ci] === 1 ? sprintf('ses-d%02d', $no).self::SUFFIX : sprintf('ses-d%02d-%d', $no, $seq[$ci]).self::SUFFIX];
        }
        // 仮登録の会社の代表者（仮登録のまま。上の担当者の人数には数えない）
        foreach ($companies as $ci => $c) {
            if ($c['status'] !== 'provisional') continue;
            $people[] = $c['rep'] + ['ci' => $ci, 'state' => 'C', 'days' => 1, 'expired' => 1, 'first' => true, 'email' => sprintf('ses-d%02d', $c['no']).self::SUFFIX];
        }

        // エンジニア：直属 $direct 人、残りは申込中ではない SES に（会社ごとの重み 1〜3）
        $pool = [];
        foreach ($open as $ci) for ($w = $rng->getInt(1, 3); $w > 0; $w--) $pool[] = $ci;
        $dStates = self::stateList($rng, $direct);
        $sStates = self::stateList($rng, $eg - $direct);
        $year = (int) date('Y');
        $engineers = [];
        for ($j = 0; $j < $eg; $j++) {
            $isDirect = $j < $direct;
            $state = $isDirect ? $dStates[$j] : $sStates[$j - $direct];
            $p = $person();
            $birth = $rng->getInt(1965, 2002);
            $years = $rng->getInt(1, max(1, min(30, $year - $birth - 22)));
            $desired = $rng->getInt(40, 90);
            $type = self::TYPES[$rng->getInt(0, count(self::TYPES) - 1)];
            $cand = $rng->shuffleArray($type['skills']);
            $skills = [];
            foreach (array_slice($cand, 0, min(count($cand), $rng->getInt(self::MIN_SKILLS, self::MAX_SKILLS))) as $name) {
                $skills[] = [$name, $rng->getInt(1, max(1, $years)), $rng->getInt(2, 5)];
            }
            $engineers[] = $p + ['no' => $e0 + $j, 'email' => sprintf('eg-d%03d', $e0 + $j).self::SUFFIX, 'direct' => $isDirect, 'ci' => $isDirect ? null : $pick($pool), 'state' => $state,
                'unavailable' => $state === 'A' && $rng->getInt(1, 100) <= self::UNAVAILABLE_RATE,
                'birth_year' => $birth, 'years_exp' => $years, 'desired_price' => $desired, 'min_price' => $desired - $rng->getInt(0, 10),
                'station' => $pick(self::STATIONS), 'avail_month' => $rng->getInt(0, 3), 'work_style' => $pick(self::WORK_STYLES),
                'days_per_week' => $rng->getInt(1, 10) === 1 ? $rng->getInt(3, 4) : 5,
                'summary' => $pick($type['summary']), 'strengths' => $pick($type['strengths']), 'skills' => $skills,
                'days' => $rng->getInt(1, 30), 'expired' => $rng->getInt(1, 20)];
        }

        // 件数の表
        $zero = array_fill_keys(array_keys(self::STATE_LABEL), 0);
        $counts = ['ses' => array_fill_keys(array_keys(self::SES_LABEL), 0), 'staff' => $zero, 'eg_ses' => $zero, 'eg_direct' => $zero];
        foreach ($companies as $c) $counts['ses'][$c['status']]++;
        foreach ($people as $p) $counts['staff'][$p['state']]++;
        foreach ($engineers as $e) $counts[$e['direct'] ? 'eg_direct' : 'eg_ses'][$e['state']]++;
        $ranges = [
            'ses' => sprintf('ses-d%02d〜ses-d%02d', $s0, $s0 + $ses - 1),
            'eg' => $eg > 0 ? sprintf('eg-d%03d〜eg-d%03d', $e0, $e0 + $eg - 1) : '（なし）',
        ];

        // これから使うメールアドレスが既にあれば止める
        $emails = array_merge(array_column($companies, 'email'), array_column($people, 'email'), array_column($engineers, 'email'));
        foreach (['users', 'ses_companies', 'engineers'] as $t) {
            foreach (array_chunk($emails, 200) as $chunk) {
                if (DB::table($t)->whereIn('email', $chunk)->exists()) return ['errors' => ['これから使うメールアドレスが、既に登録されています。何も入れずに止めました。'], 'env' => $env];
            }
        }

        return ['errors' => [], 'seed' => $seed, 'env' => $env, 'counts' => $counts, 'ranges' => $ranges, 'total' => ['ses' => $ses, 'staff' => $staff, 'eg' => $eg],
            'spec' => ['companies' => $companies, 'people' => $people, 'engineers' => $engineers]];
    }

    // ---------- 入れる ----------
    // plan() の結果を、1つの transaction で入れる。途中で失敗したら何も残らない。メールは出さず、ベルの通知・登録の記録は消す
    public static function run(array $opt): array
    {
        $plan = self::plan($opt);
        if ($plan['errors']) throw new RuntimeException(implode(' ', $plan['errors']));
        $spec = $plan['spec'];

        // 万一メールを出す処理に当たっても送られないよう、実行の間だけ「送らず保存だけ」にする
        $mailer = config('mail.default');
        config(['mail.default' => 'array']);
        try {
            self::insertAll($spec, $plan);
        } finally {
            config(['mail.default' => $mailer]);
        }
        return $plan;
    }

    private static function insertAll(array $spec, array $plan): void
    {
        $hash = Hash::make(Str::random(40)); // ダミーのパスワード（誰も知らない。全員で同じ値を使い回す）
        DB::transaction(function () use ($spec, $plan, $hash) {
            $maxNotice = (int) DB::table('app_notifications')->max('id');
            $maxAudit = (int) DB::table('audit_log')->max('id');
            $mails = DB::table('mail_outbox')->count();
            if (DB::table('skill_terms')->count() === 0) SkillCatalog::sync(); else SkillCatalog::forget();
            $directId = $spec['engineers'] && collect($spec['engineers'])->contains('direct', true) ? W::directSesId() : null;

            // SES の会社
            $sesIds = [];
            foreach ($spec['companies'] as $i => $c) {
                $pending = $c['status'] === 'pending';
                $prov = $c['status'] === 'provisional';
                $sesIds[$i] = DB::table('ses_companies')->insertGetId([
                    'name' => $c['name'], 'kind' => 'ses',
                    ...W::sesPeopleCols(['rep_sei' => $c['rep']['sei'], 'rep_mei' => $c['rep']['mei'], 'contact_sei' => $c['contact']['sei'], 'contact_mei' => $c['contact']['mei'],
                        'contact_kana_sei' => $c['contact']['kana_sei'], 'contact_kana_mei' => $c['contact']['kana_mei']]),
                    'email' => $c['email'], 'employees' => $c['employees'], 'strengths' => $c['strengths'],
                    'message' => $pending ? 'テスト用のダミーの申し込みです。' : null, 'status' => $c['status'],
                    // 仮登録の会社は、代表者の本登録で同意・参加日が入る
                    'agreed_at' => $prov ? null : now_str(), 'self_confirmed_at' => ($pending || $prov) ? null : now_str(), 'joined_at' => ($pending || $prov) ? null : today_str(),
                    'created_at' => now_str(), 'updated_at' => now_str(),
                ]);
            }

            // SES の担当者（各社の1人目は代表者。代表者が本登録済み・停止なら、会社の電話・住所も入れる）
            $repState = [];
            foreach ($spec['people'] as $p) {
                $uid = self::createUser($hash, $p, 'ses', $sesIds[$p['ci']], null);
                self::applyUserState($uid, $p, 'ses');
                if (!empty($p['first'])) {
                    $repState[$p['ci']] = $p['state'];
                    DB::table('ses_companies')->where('id', $sesIds[$p['ci']])->update(['rep_user_id' => $uid]);
                }
            }
            foreach ($repState as $ci => $state) {
                if ($state === 'B' || $spec['companies'][$ci]['status'] === 'provisional') continue; // 必須項目が足りない代表者の会社は、会社の項目も空のまま
                DB::table('ses_companies')->where('id', $sesIds[$ci])->update(['phone' => self::DUMMY_PHONE, 'postal_code' => self::DUMMY_POSTAL, 'address' => self::DUMMY_ADDRESS]);
            }

            // エンジニア（アカウントつき）
            $engIds = [];
            foreach ($spec['engineers'] as $e) {
                $sesId = $e['direct'] ? $directId : $sesIds[$e['ci']];
                $done = in_array($e['state'], ['A', 'E']); // 本登録済み・停止中は、必須項目がそろっている
                $d = ['name_sei' => $e['sei'], 'name_mei' => $e['mei'], 'kana_sei' => $e['kana_sei'], 'kana_mei' => $e['kana_mei'], 'email' => $e['email'],
                    'phone' => $done ? self::DUMMY_PHONE : null, 'postal_code' => $done ? self::DUMMY_POSTAL : null, 'address' => $done ? self::DUMMY_ADDRESS : null,
                    'birth_year' => $e['birth_year'], 'years_exp' => $e['years_exp'], 'desired_price' => $e['desired_price'], 'min_price' => $e['min_price'],
                    'nearest_station' => $e['station'], 'available_from' => $e['avail_month'] === 0 ? today_str() : date('Y-m-01', mktime(0, 0, 0, (int) date('n') + $e['avail_month'], 1, (int) date('Y'))),
                    'work_style' => $e['work_style'], 'days_per_week' => $e['days_per_week'], 'summary' => $e['summary'], 'strengths' => $e['strengths'],
                    'status' => $e['unavailable'] ? 'unavailable' : 'available'];
                $id = W::createEngineer($sesId, $d, null, false);
                $engIds[] = $id;

                // マスタにある言葉だけを渡す（技術マスタを増やさない）
                $rows = array_values(array_filter($e['skills'], fn ($s) => SkillCatalog::find($s[0])));
                W::saveSkills($id, ['skill_name' => array_column($rows, 0), 'skill_cat' => array_fill(0, count($rows), 'other'), 'skill_years' => array_column($rows, 1), 'skill_level' => array_column($rows, 2)]);

                $now = now_str();
                $consent = in_array($e['state'], ['A', 'B', 'E']);
                DB::table('engineers')->where('id', $id)->update([
                    'consent_at' => $consent ? $now : null,
                    'self_confirmed_at' => in_array($e['state'], ['A', 'E']) ? $now : null,
                    'suspended_at' => $e['state'] === 'E' ? $now : null,
                    'registered_at' => in_array($e['state'], ['A', 'B', 'E']) ? $now : null, // 仮登録（C・D）は候補に出ない
                ]);
                $uid = self::createUser($hash, $e, 'eg', $sesId, $id);
                self::applyUserState($uid, $e, 'eg');
            }

            // createEngineer が残した、ベルの通知と登録の記録（ダミーの氏名が入る）を消す
            foreach (array_chunk($engIds, 200) as $chunk) {
                DB::table('app_notifications')->where('id', '>', $maxNotice)->where('title', 'エンジニアが登録されました')
                    ->whereIn('link', array_map(fn ($i) => "/engineers/$i", $chunk))->delete();
            }
            // 記録の詳細は「氏名（SES 番号）」で終わる。今回の SES・直属の番号のものだけを消す
            $audit = DB::table('audit_log')->where('id', '>', $maxAudit)->where('action', 'engineer-create')->whereNull('user_id')
                ->where(function ($q) use ($sesIds, $directId) {
                    foreach (array_filter([...$sesIds, $directId]) as $sid) $q->orWhere('detail', 'like', "%（SES {$sid}）");
                });
            $audit->delete();
            N::audit(null, 'seed-dummy', json_encode($plan['total'] + ['seed' => $plan['seed']]));

            if (DB::table('mail_outbox')->count() !== $mails) throw new RuntimeException('メールが予約されたため、何も入れずに止めました。');
        });
    }

    // ---------- 部品 ----------
    // 担当者・エンジニアのアカウントの行（パスワードは使い回しの乱数のハッシュ）
    private static function createUser(string $hash, array $p, string $role, int $sesId, ?int $engineerId): int
    {
        return DB::table('users')->insertGetId(['email' => $p['email']] + W::nameCols(join_name($p['sei'], $p['mei']), $p['sei'], $p['mei'])
            + ['role' => $role, 'password' => $hash, 'ses_id' => $sesId, 'engineer_id' => $engineerId, 'created_at' => now_str(), 'updated_at' => now_str()]);
    }

    // 状態ごとの列（担当者・エンジニアのアカウント）。A＝本登録済み（必須項目がそろっている）／B＝参加中だが必須項目が足りない／
    // C＝仮登録（リンクは7日）／D＝仮登録の期限切れ／E＝停止中。本登録の日時（registered_at）が、参加中かどうかの印
    private static function applyUserState(int $uid, array $p, string $role): void
    {
        $now = now_str();
        $filled = $role === 'eg' ? ['phone' => self::DUMMY_PHONE] : ['phone' => self::DUMMY_PHONE, 'secrecy_agreed_at' => $now];
        $cols = match ($p['state']) {
            'A' => ['active' => 1, 'registered_at' => $now, 'profile_completed_at' => $now, 'two_factor_confirmed_at' => $now, 'last_login' => date('Y-m-d H:i:s', time() - $p['days'] * 86400)] + $filled,
            'B' => ['active' => 1, 'registered_at' => $now, 'profile_completed_at' => null, 'two_factor_confirmed_at' => null],
            'C' => ['password' => null, 'registered_at' => null],
            'D' => ['password' => null, 'registered_at' => null, 'token' => Str::random(40), 'token_kind' => 'register', 'token_expires' => date('Y-m-d H:i:s', time() - $p['expired'] * 86400)],
            'E' => ['active' => 0, 'registered_at' => $now, 'profile_completed_at' => $now, 'token' => null, 'token_expires' => null] + $filled,
        };
        DB::table('users')->where('id', $uid)->update($cols + W::kanaCols(['kana_sei' => $p['kana_sei'], 'kana_mei' => $p['kana_mei']]) + ['updated_at' => $now]);
        if ($p['state'] === 'C') W::issueToken($uid);
    }

    // 状態の並び（$n 人分）。割合は最大剰余法で整数にし、$n が状態の数以上なら各状態を最低1にして、並びをシャッフルする
    private static function stateList(Randomizer $rng, int $n): array
    {
        $ratio = self::STATE_RATIO; $keys = array_keys($ratio); $total = array_sum($ratio);
        $out = []; $rem = [];
        foreach ($ratio as $k => $r) { $x = $n * $r / $total; $out[$k] = (int) floor($x); $rem[$k] = $x - $out[$k]; }
        $left = $n - array_sum($out);
        arsort($rem);
        foreach (array_keys($rem) as $k) { if ($left <= 0) break; $out[$k]++; $left--; }
        if ($n >= count($keys)) {
            foreach ($keys as $k) {
                if ($out[$k] > 0) continue;
                $c = $out; arsort($c);
                $out[array_key_first($c)]--; $out[$k] = 1;
            }
        }
        $list = [];
        foreach ($keys as $k) for ($i = 0; $i < $out[$k]; $i++) $list[] = $k;
        return $rng->shuffleArray($list);
    }

    // 既にあるダミーのメールアドレスのうち、一番大きい番号（無ければ 0）
    private static function maxNo(string $regex): int
    {
        $max = 0;
        foreach (['users', 'ses_companies', 'engineers'] as $t) {
            foreach (DB::table($t)->where('email', 'like', '%-d%'.self::SUFFIX)->pluck('email') as $email) {
                if (preg_match($regex, (string) $email, $m)) $max = max($max, (int) $m[1]);
            }
        }
        return $max;
    }
}
