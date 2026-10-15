<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

// 面談対策の Q&A と、スキルシートの完成度。
// 案件の要件と本人の経験票から作る（外部の AI には渡さない）。文は運営が画面で直せる。
class Qa
{
    private static function projectsUsing($projects, string $skill): array
    {
        $k = norm_skill($skill);
        return array_values(array_filter($projects, function ($p) use ($k) {
            foreach (project_techs($p) as $t) {
                $n = norm_skill($t);
                if ($n === $k || (mb_strlen($k) >= 3 && mb_strlen($n) >= 3 && loose_match($n, $k))) return true;
            }
            return false;
        }));
    }

    private static function projLine($p): string
    {
        return '「'.$p->title.'」（'.($p->period_from ?: '?').'〜'.($p->period_to ?: '現在').'、'.($p->role ?: '担当').($p->team_size ? "、{$p->team_size}名体制" : '').'）';
    }

    public static function generate(int $caseId, int $engineerId): array
    {
        $c = DB::table('cases')->find($caseId);
        $e = DB::table('engineers')->find($engineerId);
        if (!$c || !$e) return [];
        $projects = DB::table('engineer_projects')->where('engineer_id', $engineerId)->orderByDesc('period_from')->get()->all();
        $idx = Matcher::skillIndex($engineerId);
        $qa = [];
        $add = function ($category, $q, $hint) use (&$qa) { $qa[] = ['category' => $category, 'q' => $q, 'hint' => $hint]; };

        $latest = $projects[0] ?? null;
        $add('自己紹介', '簡単に自己紹介と、これまでのご経歴をお願いします。',
            '1分程度で。経験'.(num_disp($e->years_exp) ?: '?').'年、得意分野、直近の案件の順に話す。'
            .($latest ? '直近は'.self::projLine($latest).'。' : '').($e->strengths ? '強み：'.$e->strengths : ''));
        if ($latest) {
            $add('直近の案件', '直近の案件で、どのような役割・作業を担当しましたか。',
                self::projLine($latest).'。担当工程：'.($latest->phases ?: '（経験票に工程を書いておく）').'。'
                .($latest->achievements ? '成果は数字で：'.$latest->achievements : '成果を数字で1つ言えるようにしておく（件数・時間・割合など）。'));
        }
        foreach (parse_list($c->required_skills) as $r) {
            $s = Matcher::hasSkill($idx, $r);
            $ps = self::projectsUsing($projects, $r);
            if ($s) {
                $add("必須：$r", "$r の経験について、年数と担当した範囲を教えてください。",
                    ($s['years'] ? num_disp($s['years']).'年。' : '').($ps ? '使った案件：'.implode('、', array_map([self::class, 'projLine'], array_slice($ps, 0, 2))).'。' : '')
                    .'「何を作ったか」「どこまで1人でできるか」「困ったことと解決」を1つずつ。');
            } else {
                $add("必須：{$r}（要準備）", "$r のご経験はありますか。",
                    '経験が無い・浅い場合は、正直に答える。近い技術の経験と、自分で学んでいること（期間・内容）を具体的に言う。盛らない。');
            }
        }
        foreach (parse_list($c->preferred_skills) as $r) {
            if (Matcher::hasSkill($idx, $r)) {
                $ps = self::projectsUsing($projects, $r);
                $add("尚可：$r", "$r はどの程度触っていますか。", '経験した範囲を短く。'.($ps ? self::projLine($ps[0]) : ''));
            }
        }
        $add('進め方', 'チームでの開発で、気をつけていることは何ですか。', '報告・連絡・相談のタイミング、レビュー、ドキュメントなど、具体的な習慣を1つ例に出す。');
        $add('トラブル', 'これまでに困った・失敗した経験と、どう対応したかを教えてください。', '状況 → 自分がしたこと → 結果 → 学んだこと の順で。他人のせいにしない。');
        if (in_array($c->work_style, ['onsite', 'hybrid'])) {
            $add('条件', '勤務地（'.($c->location ?: '現場').'）への通勤や、出社の頻度は問題ありませんか。', '最寄り駅からの時間を事前に調べておく。条件が合わない点は応募前に確認済みのことを伝える。');
        }
        $add('条件', '開始日（'.($c->start_date ? fmt_date($c->start_date) : '相談').'）と、週'.($c->days_per_week ?: 5).'日の稼働は可能ですか。', '可能なら「可能です」とはっきり。難しい場合は、いつからなら可能かを具体的に。');
        $add('意欲', 'この案件に興味を持った理由を教えてください。', '案件の内容（'.mb_substr($c->description ?: $c->title, 0, 40).'…）と、自分の経験・伸ばしたい方向を結びつける。');
        $add('逆質問', '最後に、何か質問はありますか。', '必ず1〜2個用意する。候補：チームの体制と、参画後に最初に担当する作業を教えてください。 / 開発の進め方（レビュー・テスト・使っているツール）を教えてください。 / 今いちばん困っていること・期待されていることは何ですか。 / 評価されるのはどのような働き方ですか。');
        return $qa;
    }

    // 経験票の完成度と、スキルシートを良くするための指摘
    public static function sheetCheck(int $engineerId): array
    {
        $e = DB::table('engineers')->find($engineerId);
        $skills = DB::table('engineer_skills')->where('engineer_id', $engineerId)->get();
        $projects = DB::table('engineer_projects')->where('engineer_id', $engineerId)->get();
        $tips = []; $pts = 0;
        if (mb_strlen((string) $e->summary) >= 60) $pts += 15; else $tips[] = '自己PR（60字以上）を書きましょう。得意分野と、どんな現場で力を出せるかを短く。';
        if ($e->strengths) $pts += 5; else $tips[] = '強み（ひとこと）を書きましょう。';
        if ($skills->count() >= 5) $pts += 15; else $tips[] = 'スキルは5つ以上、年数とレベルを入れましょう。';
        if ($skills->every(fn ($s) => $s->years)) $pts += 5; else $tips[] = 'スキルの年数が空のものがあります。';
        if ($projects->count() >= 2) $pts += 20; else $tips[] = '経験したプロジェクトを2件以上入れましょう。';
        $noAch = $projects->filter(fn ($p) => !$p->achievements || !preg_match('/\d/', $p->achievements))->count();
        if ($projects->count() && !$noAch) $pts += 15; elseif ($noAch) $tips[] = "成果が数字で書かれていない案件が {$noAch} 件あります（例：処理時間を30%短縮、月200件の問い合わせを削減）。";
        $noPhase = $projects->filter(fn ($p) => !$p->phases)->count();
        if ($projects->count() && !$noPhase) $pts += 10; elseif ($noPhase) $tips[] = "担当工程が空の案件が {$noPhase} 件あります。";
        if ($projects->count() && $projects->every(fn ($p) => mb_strlen((string) $p->description) >= 40)) $pts += 10; elseif ($projects->count()) $tips[] = '案件の作業内容は40字以上で、何を・どこまで担当したかを具体的に。';
        if ($e->desired_price && $e->available_from) $pts += 5; else $tips[] = '希望単価と稼働開始日を入れると、合う案件が届きやすくなります。';
        $tips[] = '事実と違うこと（盛った内容）は書かないでください。面談や配属後のトラブルの元になります。';
        return ['score' => min($pts, 100), 'tips' => $tips];
    }
}
