<?php

namespace Database\Seeders;

use App\Services\Workflow as W;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

// 動きを試すための見本データ（すべて架空）。パスワードはどれも park1234。
// 入れ直すとき：php artisan migrate:fresh --seed
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        \App\Support\SkillCatalog::sync(); // 技術マスタの辞書
        $pw = 'park1234';
        $adminId = W::createUser('admin@park.example', '運営 太郎', 'admin', $pw);
        $admin = DB::table('users')->find($adminId);

        $ses = [];
        foreach ([['みどり情報システム', '緑川 一郎', 'ses1@park.example', 'Java の業務系、テスト'], ['あおぞらテック', '青木 花子', 'ses2@park.example', 'Web 系（PHP・JavaScript）'],
            ['ひだまりソリューションズ', '日向 健', 'ses3@park.example', 'インフラ・クラウド']] as [$name, $contact, $email, $str]) {
            $id = DB::table('ses_companies')->insertGetId(['name' => $name, 'rep_name' => $contact, 'contact_name' => $contact, 'email' => $email, 'status' => 'active',
                'strengths' => $str, 'agreed_at' => now_str(), 'joined_at' => today_str(), 'created_at' => now_str(), 'updated_at' => now_str()]);
            W::createUser($email, $contact, 'ses', $pw, ['ses_id' => $id]);
            $ses[] = $id;
        }
        DB::table('ses_companies')->insert(['name' => 'こもれび開発', 'contact_name' => '木下 さくら', 'email' => 'ses4@park.example', 'status' => 'pending',
            'message' => '少人数ですが参加したいです。', 'agreed_at' => now_str(), 'created_at' => now_str(), 'updated_at' => now_str()]);

        $engs = [
            [$ses[0], ['name' => '山田 太郎', 'kana' => 'やまだ たろう', 'email' => 'eg1@park.example', 'birth_year' => 1984, 'nearest_station' => '川崎', 'years_exp' => 15, 'desired_price' => 65, 'min_price' => 60,
                'available_from' => today_str(), 'work_style' => 'hybrid', 'strengths' => '業務系 Java の設計〜テストを1人で回せる',
                'summary' => '金融・保険の業務システムを中心に15年。要件の聞き取りから設計・実装・テストまで一通り担当してきました。仕様の不明点を早めに洗い出し、手戻りを減らすのが得意です。'],
                [['Java', 'lang', 12, 5], ['Spring Boot', 'fw', 6, 4], ['Oracle', 'db', 10, 4], ['SQL', 'db', 12, 5], ['JUnit', 'tool', 6, 4], ['AWS', 'infra', 2, 2]],
                [['title' => '損害保険会社 契約管理システムの刷新', 'industry' => '保険', 'period_from' => '2023-04', 'role' => 'SE（サブリーダー）', 'team_size' => 12, 'tech' => 'Java、Spring Boot、Oracle、JUnit',
                    'phases' => ['基本設計', '詳細設計', '実装', '結合テスト'], 'description' => '旧システムからの移行に伴う契約変更機能の設計と実装を担当。3名の PG のレビューも行った。', 'achievements' => '移行後の障害を前年比で40%削減。夜間バッチを4時間から50分に短縮。'],
                 ['title' => '銀行向け 融資審査ワークフローの開発', 'industry' => '金融', 'period_from' => '2019-10', 'period_to' => '2023-03', 'role' => 'PG→SE', 'team_size' => 20, 'tech' => 'Java、Struts、Oracle、SQL',
                    'phases' => ['詳細設計', '実装', '単体テスト', '結合テスト'], 'description' => '審査ステータスの遷移と帳票出力の機能を担当。性能試験でボトルネックの SQL を改善した。', 'achievements' => '帳票出力の処理時間を70%短縮。']]],
            [$ses[1], ['name' => '佐藤 美咲', 'kana' => 'さとう みさき', 'email' => 'eg2@park.example', 'birth_year' => 1993, 'nearest_station' => '渋谷', 'years_exp' => 7, 'desired_price' => 60, 'min_price' => 55,
                'available_from' => add_months(today_str(), 1), 'work_style' => 'remote', 'strengths' => 'React と Laravel で画面からAPIまで作れる',
                'summary' => 'EC・予約サービスの Web 開発を7年。React の画面と Laravel の API を両方担当でき、デザイナー・企画と直接やり取りしながら進めるのが得意です。'],
                [['PHP', 'lang', 7, 4], ['Laravel', 'fw', 5, 4], ['JavaScript', 'lang', 7, 4], ['TypeScript', 'lang', 3, 3], ['React', 'fw', 4, 4], ['MySQL', 'db', 7, 4], ['Docker', 'infra', 3, 3]],
                [['title' => '予約サービスのフロント刷新', 'industry' => 'サービス', 'period_from' => '2022-06', 'role' => 'フロントエンド担当', 'team_size' => 6, 'tech' => 'React、TypeScript、Laravel、MySQL',
                    'phases' => ['基本設計', '詳細設計', '実装', '単体テスト'], 'description' => 'jQuery の画面を React に置き換え。予約カレンダーと決済画面を担当した。', 'achievements' => '予約完了率を12%改善。']]],
            [$ses[2], ['name' => '鈴木 健一', 'kana' => 'すずき けんいち', 'email' => 'eg3@park.example', 'birth_year' => 1988, 'nearest_station' => '大宮', 'years_exp' => 10, 'desired_price' => 70,
                'available_from' => today_str(), 'work_style' => 'any', 'strengths' => 'AWS の設計・構築と運用の自動化',
                'summary' => 'オンプレからクラウドへの移行を中心に10年。AWS の設計・構築、Terraform による自動化、監視の整備を担当してきました。'],
                [['AWS', 'infra', 6, 5], ['Terraform', 'infra', 4, 4], ['Linux', 'infra', 10, 5], ['Python', 'lang', 4, 3], ['Docker', 'infra', 4, 4], ['Kubernetes', 'infra', 2, 3]],
                [['title' => '物流会社 基幹システムの AWS 移行', 'industry' => '物流', 'period_from' => '2021-01', 'role' => 'インフラリーダー', 'team_size' => 8, 'tech' => 'AWS、Terraform、Linux、Python',
                    'phases' => ['要件定義', '基本設計', '詳細設計', '実装', '運用・保守'], 'description' => 'オンプレの基幹システムを AWS に移行。ネットワーク設計と Terraform での構築を担当した。', 'achievements' => 'インフラ費用を年間25%削減。構築時間を2週間から1日に短縮。']]],
            [$ses[0], ['name' => '高橋 陽介', 'kana' => 'たかはし ようすけ', 'birth_year' => 1996, 'nearest_station' => '横浜', 'years_exp' => 4, 'desired_price' => 50,
                'available_from' => today_str(), 'work_style' => 'onsite', 'summary' => 'Java でのテストと保守を中心に4年。'],
                [['Java', 'lang', 4, 3], ['SQL', 'db', 4, 3]], []],
        ];
        $engIds = [];
        foreach ($engs as [$sesId, $data, $skills, $projects]) {
            $id = W::createEngineer($sesId, $data, $admin, false);
            DB::table('engineers')->where('id', $id)->update(['consent_at' => now_str()]);
            W::saveSkills($id, ['skill_name' => array_column($skills, 0), 'skill_cat' => array_column($skills, 1), 'skill_years' => array_column($skills, 2), 'skill_level' => array_column($skills, 3)]);
            foreach ($projects as $p) W::saveProject($id, $p);
            if (!empty($data['email'])) W::createUser($data['email'], $data['name'], 'eg', $pw, ['ses_id' => $sesId, 'engineer_id' => $id]);
            $engIds[] = $id;
        }
        $ses1 = DB::table('users')->where('email', 'ses1@park.example')->first();
        $c1 = W::saveCase(['title' => '生命保険会社 保全システムの追加開発', 'client_label' => 'SH-A 社', 'price' => 68, 'required_skills' => 'Java、Spring Boot、Oracle', 'preferred_skills' => 'AWS、保険業務',
            'min_years' => 5, 'description' => '保全（契約内容の変更）機能の追加開発。詳細設計から結合テストまで。', 'location' => '東京都千代田区（最寄り：大手町）', 'work_style' => 'hybrid',
            'start_date' => add_months(today_str(), 1), 'period' => '3か月〜長期', 'days_per_week' => 5, 'interviews_count' => 1, 'headcount' => 1, 'status' => 'open'], $ses1);
        $c2 = W::saveCase(['title' => 'EC サイトのフロントエンド改善', 'client_label' => 'SH-B 社', 'price' => 62, 'required_skills' => 'React、TypeScript', 'preferred_skills' => 'Next.js、Laravel', 'min_years' => 3,
            'description' => 'EC サイトの商品一覧・カートの改善。', 'location' => 'フルリモート', 'work_style' => 'remote', 'start_date' => add_months(today_str(), 1), 'period' => '6か月', 'interviews_count' => 2, 'headcount' => 1, 'status' => 'open'], $admin);
        $c3 = W::saveCase(['title' => '製造業 社内システムのクラウド基盤構築', 'client_label' => 'SH-C 社', 'price' => 75, 'required_skills' => 'AWS、Terraform', 'preferred_skills' => 'Kubernetes', 'min_years' => 5,
            'description' => 'AWS 上に社内システムの基盤を構築。', 'location' => '埼玉県さいたま市', 'work_style' => 'hybrid', 'start_date' => today_str(), 'period' => '長期', 'interviews_count' => 1, 'headcount' => 1, 'status' => 'open'], $admin);

        // 流れの見本：配信 → 応募 → 書類 → 面談（Q&A）→ 成約
        W::distribute($c1, [$engIds[0], $engIds[3]], $admin, '経験がよく合う案件です。');
        W::distribute($c2, [$engIds[1]], $admin);
        W::distribute($c3, [$engIds[2]], $admin);
        $eg = fn ($i) => DB::table('users')->where('engineer_id', $engIds[$i])->first();
        $a1 = W::apply($c1, $engIds[0], ['start_ok' => 1, 'price_ok' => 1, 'style_ok' => 1], $eg(0));
        W::setStatus($a1, 'submitted', $admin);
        W::setStatus($a1, 'doc_passed', $admin);
        W::scheduleInterview($a1, ['scheduled_at' => date('Y-m-d', strtotime('+3 days')).' 15:00', 'place' => 'オンライン'], $admin);
        $a3 = W::apply($c3, $engIds[2], ['start_ok' => 1, 'price_ok' => 1, 'style_ok' => 1], $eg(2));
        W::setStatus($a3, 'submitted', $admin);
        W::setStatus($a3, 'doc_passed', $admin);
        $iv3 = W::scheduleInterview($a3, ['scheduled_at' => today_str().' 10:00', 'place' => 'オンライン'], $admin);
        W::interviewResult($iv3, 'pass', '設計の説明がわかりやすかったとのこと。', $admin);
        W::makeContract($a3, ['price' => 75, 'start_date' => date('Y-m-01')], $admin);
        DB::table('messages')->insert(['engineer_id' => $engIds[1], 'from_user' => $eg(1)->id, 'body' => "【スキルシートのレビュー依頼】\n予約サービスの成果の書き方を見てほしいです。",
            'created_at' => now_str(), 'read_by_eg' => true, 'read_by_admin' => false]);
    }
}
