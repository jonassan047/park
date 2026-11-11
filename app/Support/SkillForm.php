<?php

namespace App\Support;

// スキル登録画面の項目表。正本は resources/data/skill_form.json（元は Excel の Skills.xlsx）。
// JSON を読んで、画面・保存・マッチング・移行が同じ「項目の一覧」を使えるようにする。
class SkillForm
{
    // 年数の選択肢と、DB に入れる値（years_min）。JSON の yearOptions と同じ並び（食い違えば例外）
    const YEARS = ['1年未満' => 0, '1年以上' => 1, '3年以上' => 3, '5年以上' => 5];
    const OTHER = 'その他';              // 「その他」の項目の名前
    const OTHER_MAX = 40;               // その他に書く名前の長さ
    const OTHER_COUNT_MAX = 10;         // 1つの「その他」に入れられる名前の数
    const SPLIT_SLASH = ['HTML/CSS'];   // 「/」でも分けて言葉にする名前（ふつうは前後に空白のある「 / 」だけで分ける）
    const SKIP_WORDS = ['実行環境'];     // 括弧の中にあっても、技術の言葉として使わないもの
    const HEADING_PREFIX = 'そのほか';   // この名前で始まるグループは、見出しだけにする（チェックなし）

    // 表示用の短い節の名前
    const SHORT = [
        'roles' => '役割', 'phases' => '工程', 'lang' => '言語・フレームワーク', 'db' => 'データベース', 'os' => 'OS',
        'pm' => 'プロジェクト管理ツール', 'dev' => '開発・運用ツール',
        'industry' => '業界', 'pmpl' => 'PM/PL の経験',
    ];

    // 今のスキルを新しい表に置くときの「その他」の置き場所（分類 => [節, 親]）。OS と、下に無い分類は place() で決める
    const PLACES = [
        'role' => ['roles', ''], 'method' => ['phases', ''], 'db' => ['db', 'RDB'],
        'cloud' => ['dev', 'クラウド'], 'infra' => ['dev', 'Webサーバー・AP'], 'tool' => ['dev', 'IDE・エディタ'],
    ];

    private static ?array $data = null;

    // JSON を1回だけ読んで、項目の一覧（nodes）・木（tree）・道筋の索引を作る
    private static function load(): array
    {
        if (self::$data !== null) return self::$data;
        $file = resource_path('data/skill_form.json');
        $json = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (!is_array($json)) throw new \RuntimeException('skill_form.json を読めませんでした。');
        self::validate($json);
        return self::$data = self::build($json);
    }

    // JSON の検査。誤りは \RuntimeException（テストが別の JSON を渡して確かめられるよう、load から分けてある）
    public static function validate(array $json): void
    {
        if (!is_array($json['sections'] ?? null) || !is_array($json['yearOptions'] ?? null)) {
            throw new \RuntimeException('skill_form.json を読めませんでした。');
        }
        if (array_values($json['yearOptions']) !== array_keys(self::YEARS)) {
            throw new \RuntimeException('skill_form.json の yearOptions が、SkillForm::YEARS と違います。');
        }
        self::build($json); // 項目の重なり・none の置き場所・showIf などは、組み立てながら検査する（結果は捨てる）
    }

    // 検査ずみの JSON から、項目の一覧・木・索引を組み立てる（誤りは \RuntimeException）
    private static function build(array $json): array
    {
        $nodes = []; $byId = []; $byPath = []; $tree = []; $heading = null; $showIf = [];
        $add = function (array $n) use (&$nodes, &$byId, &$byPath) {
            $n['pos'] = count($nodes);
            $path = $n['section']."\0".$n['parent']."\0".$n['item'];
            if (isset($byPath[$path]) || isset($byId[$n['id']])) {
                throw new \RuntimeException("skill_form.json の項目が重なっています：{$n['section']}／{$n['parent']}／{$n['item']}");
            }
            $nodes[] = $n;
            $byId[$n['id']] = $n['pos'];
            $byPath[$path] = $n['pos'];
            return $n;
        };
        foreach ($json['sections'] as $s) {
            $key = (string) ($s['key'] ?? '');
            // match（既定 true）：false の節は、案件とのマッチングに使わない。single：グループごとに1つ選ぶ。showIf：出す条件（検査のあと、項目の id にする）
            $match = ($s['match'] ?? true) !== false;
            $single = !empty($s['single']);
            $sec = ['key' => $key, 'title' => (string) ($s['title'] ?? ''), 'short' => self::SHORT[$key] ?? (string) ($s['title'] ?? ''), 'items' => [], 'groups' => [],
                'match' => $match, 'single' => $single, 'showIf' => []];
            if ($single && !empty($s['items'])) throw new \RuntimeException("skill_form.json の「{$key}」は、1つ選ぶ節なので、グループだけにしてください。");
            foreach ($s['items'] ?? [] as $i => $it) {
                if (!empty($it['none'])) throw new \RuntimeException("skill_form.json の none は、1つ選ぶ節のグループの中だけで使えます：$key");
                $sec['items'][] = $add(self::make($key, "$key-$i", '', $it, true, false, $match));
            }
            foreach ($s['groups'] ?? [] as $g => $grp) {
                $gname = (string) $grp['name'];
                if ($single && !empty($grp['years'])) throw new \RuntimeException("skill_form.json の1つ選ぶ節のグループに、年数は付けられません：{$key}／{$gname}");
                $isHeading = !($grp['years'] ?? false) || str_starts_with($gname, self::HEADING_PREFIX);
                $parent = $add(self::make($key, "$key-$g", '', ['name' => $gname, 'years' => $grp['years'] ?? false, 'other' => false], !$isHeading, true, $match));
                $parent['short'] = (string) ($grp['short'] ?? $gname);
                if ($key === 'lang' && $isHeading && str_starts_with($gname, self::HEADING_PREFIX)) $heading = $gname;
                $children = [];
                foreach ($grp['items'] ?? [] as $i => $it) {
                    if (!empty($it['none']) && !$single) throw new \RuntimeException("skill_form.json の none は、1つ選ぶ節のグループの中だけで使えます：{$key}／{$gname}");
                    if ($single && !empty($it['other'])) throw new \RuntimeException("skill_form.json の1つ選ぶ節に、「その他」は置けません：{$key}／{$gname}");
                    $children[] = $add(self::make($key, "$key-$g-$i", $gname, $it, true, false, $match));
                }
                $sec['groups'][] = ['node' => $parent, 'items' => $children];
            }
            $tree[] = $sec;
            if (!empty($s['showIf'])) $showIf[$key] = $s['showIf'];
        }
        // 出す条件の項目は、チェックできる項目として見つかること
        foreach ($showIf as $key => $cond) {
            $ids = [];
            foreach ((array) ($cond['items'] ?? []) as $name) {
                $n = isset($byPath[((string) ($cond['section'] ?? ''))."\0\0".$name]) ? $nodes[$byPath[((string) $cond['section'])."\0\0".$name]] : null;
                if ($n === null || !$n['checkable']) throw new \RuntimeException("skill_form.json の showIf の項目が見つかりません：{$key}／{$name}");
                $ids[] = $n['id'];
            }
            if (!$ids) throw new \RuntimeException("skill_form.json の showIf に項目がありません：$key");
            foreach ($tree as $i => $sec) if ($sec['key'] === $key) $tree[$i]['showIf'] = $ids;
        }
        return ['nodes' => $nodes, 'byId' => $byId, 'byPath' => $byPath, 'tree' => $tree, 'heading' => $heading];
    }

    // 項目1つ。checkable は、チェックを付けられるか（見出しだけのグループは false）
    // match が false の節の項目は、照合に使う言葉（words）を空にする（業界などをマッチングに混ぜない）。none は「選んでも行を作らない」印
    private static function make(string $section, string $id, string $parent, array $it, bool $checkable, bool $isGroup, bool $match = true): array
    {
        $name = (string) $it['name'];
        $other = !$isGroup && !empty($it['other']);
        return ['id' => $id, 'section' => $section, 'parent' => $parent, 'item' => $name, 'group' => $isGroup,
            'years' => $checkable && !empty($it['years']), 'other' => $other, 'checkable' => $checkable,
            'none' => !$isGroup && !empty($it['none']),
            'words' => $other || !$match ? [] : self::words($name)];
    }

    // その節の行を、案件とのマッチング・技術の「使っている人」・一覧のスキル列に使ってよいか（JSON に無い節は true）
    public static function matchable(string $section): bool
    {
        foreach (self::tree() as $sec) if ($sec['key'] === $section) return $sec['match'];
        return true;
    }

    // 節の「出す条件」の項目の id（条件が無ければ空）
    public static function showIfIds(string $sectionKey): array
    {
        foreach (self::tree() as $sec) if ($sec['key'] === $sectionKey) return $sec['showIf'];
        return [];
    }

    // 1つ選ぶ節のグループ：[グループの id => [選択肢の id…]]
    public static function singleGroups(): array
    {
        $out = [];
        foreach (self::tree() as $sec) {
            if (!$sec['single']) continue;
            foreach ($sec['groups'] as $g) $out[$g['node']['id']] = array_column($g['items'], 'id');
        }
        return $out;
    }

    // 項目の平らな一覧（JSON の並び。親はその子より前）
    public static function nodes(): array { return self::load()['nodes']; }

    // 節ごとの木：[['key', 'title'（JSON の題）, 'short', 'items'（役割・工程）, 'groups'（['node' => 親, 'items' => [子…]]）]…]
    public static function tree(): array { return self::load()['tree']; }

    public static function node(string $id): ?array
    {
        $d = self::load();
        return isset($d['byId'][$id]) ? $d['nodes'][$d['byId'][$id]] : null;
    }

    public static function byPath(string $section, string $parent, string $item): ?array
    {
        $d = self::load();
        $p = $d['byPath'][$section."\0".$parent."\0".$item] ?? null;
        return $p === null ? null : $d['nodes'][$p];
    }

    public static function shortTitle(string $section): string { return self::SHORT[$section] ?? $section; }

    // 見出しだけの「そのほか（…）」グループの名前（言語の節）
    public static function headingGroupName(): string
    {
        $h = self::load()['heading'];
        if ($h === null) throw new \RuntimeException('skill_form.json に「'.self::HEADING_PREFIX.'」のグループがありません。');
        return $h;
    }

    // 照合に使う言葉。決まり：
    //  1. 名前そのもの  2. 括弧の外を「 / 」（HTML/CSS だけ「/」も）で分けたそれぞれ
    //  3. 括弧の中を「・」「、」「,」で分けたそれぞれ（中に「：」があれば、それより後ろだけ）  4. 数字だけの言葉・「実行環境」・空は外す
    public static function words(string $name): array
    {
        $out = [$name];
        preg_match_all('/[（(](.*?)[)）]/u', $name, $m);
        $base = trim((string) preg_replace('/[（(].*?[)）]/u', '', $name));
        if ($base !== '') {
            $sep = in_array($base, self::SPLIT_SLASH, true) ? '/' : ' / ';
            foreach (explode($sep, $base) as $w) $out[] = $w;
        }
        foreach ($m[1] as $inner) {
            if (($p = mb_strpos($inner, '：')) !== false) $inner = mb_substr($inner, $p + 1);
            foreach (preg_split('/[・、,]/u', $inner) as $w) $out[] = $w;
        }
        $words = [];
        foreach ($out as $w) {
            $w = trim($w);
            if ($w === '' || preg_match('/^\d+$/', $w) || in_array($w, self::SKIP_WORDS, true)) continue;
            $words[$w] = true;
        }
        return array_map('strval', array_keys($words));
    }

    // 年数の値 → 文（null は null）
    public static function label(?int $v): ?string
    {
        if ($v === null) return null;
        $k = array_search($v, self::YEARS, true);
        return $k === false ? null : (string) $k;
    }

    // 年数の文 → 値（4つ以外は null）
    public static function value(string $label): ?int { return self::YEARS[$label] ?? null; }

    // 大きさの比べ方に使う数。年数なし（経験あり）は一番小さい
    public static function rank(?int $v): int { return $v === null ? -1 : $v; }

    // 昔の年数（小数）を4つの値に丸める
    public static function roundYears(?float $y): ?int
    {
        if ($y === null) return null;
        if ($y < 1) return 0;
        if ($y < 3) return 1;
        if ($y < 5) return 3;
        return 5;
    }

    // チェックできる項目（その他を除く）の言葉 => 最初の項目の id。place() が使う
    public static function normIndex(): array
    {
        $idx = [];
        foreach (self::nodes() as $n) {
            if (!$n['checkable'] || $n['other']) continue;
            foreach ($n['words'] as $w) {
                $k = norm_skill($w);
                if ($k !== '' && !isset($idx[$k])) $idx[$k] = $n['id'];
            }
        }
        return $idx;
    }

    // 今の行（名前・分類・年数）1つを、新しい表のどこに置くか。移行・ダミー・見本で共通。
    // 戻り値：['section', 'parent', 'item', 'other_text', 'years_min']
    public static function place(string $name, string $category, ?float $years, ?array $index = null): array
    {
        $index ??= self::normIndex();
        $k = norm_skill($name);
        $isOther = true; $text = '';
        if ($k !== '' && isset($index[$k])) {
            $n = self::node($index[$k]);
            $isOther = false;
        } else {
            if ($category === 'os') [$section, $parent] = ['os', mb_stripos($name, 'windows') !== false ? 'Windows Server' : 'UNIX'];
            elseif (isset(self::PLACES[$category])) [$section, $parent] = self::PLACES[$category];
            else [$section, $parent] = ['lang', self::headingGroupName()];
            $n = self::byPath($section, $parent, self::OTHER);
            if ($n === null) throw new \RuntimeException("skill_form.json に置き場所がありません：{$section}／{$parent}");
            $text = mb_substr((string) (clean_name($name) ?? trim($name)), 0, self::OTHER_MAX);
        }
        return ['section' => $n['section'], 'parent' => $n['parent'], 'item' => $n['item'], 'other_text' => $isOther ? $text : '',
            'years_min' => $n['years'] ? self::roundYears($years) : null];
    }
}
