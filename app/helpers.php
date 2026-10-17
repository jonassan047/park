<?php

// 画面とロジックで使う小さな道具（表示用の言葉・金額・日付・スキル名の正規化）
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;

const PARK_LABELS = [
    'role' => ['admin' => '運営', 'ses' => 'SES', 'eg' => 'エンジニア'],
    'sesStatus' => ['pending' => '申込中', 'active' => '参加中', 'suspended' => '停止'],
    'engStatus' => ['available' => '待機中', 'proposing' => '提案中', 'working' => '稼働中', 'unavailable' => '休止'],
    'caseStatus' => ['draft' => '下書き', 'open' => '募集中', 'filled' => '充足', 'closed' => '終了'],
    'offerStatus' => ['sent' => '配信済み', 'viewed' => '既読', 'declined' => '見送り', 'applied' => '応募'],
    'appStatus' => ['applied' => '応募', 'submitted' => '書類提出', 'doc_passed' => '書類通過', 'interview' => '面談調整', 'interviewed' => '面談済み', 'won' => '成約', 'rejected' => '見送り', 'withdrawn' => '辞退', 'note' => 'メモ'],
    'workStyle' => ['any' => 'どちらでも', 'remote' => 'リモート', 'hybrid' => '一部リモート', 'onsite' => '出社（常駐）'],
    'ivResult' => ['pending' => '結果待ち', 'pass' => '通過', 'fail' => '見送り'],
    'contractStatus' => ['active' => '稼働中', 'ended' => '終了'],
    'invoiceStatus' => ['draft' => '下書き', 'issued' => '発行済み', 'paid' => '入金済み'],
    'mailStatus' => ['queued' => '送信待ち', 'sent' => '送信済み', 'failed' => '失敗', 'saved' => '保存のみ'],
    'skillCat' => ['lang' => '言語', 'fw' => 'フレームワーク', 'db' => 'DB', 'os' => 'OS', 'cloud' => 'クラウド', 'infra' => 'インフラ・ミドルウェア', 'tool' => 'ツール',
        'method' => '工程・開発手法', 'domain' => '業種・業務', 'role' => '役割', 'other' => 'その他'],
];
const PARK_REJECT_REASONS = ['スキル・経験の不足', '経験年数', '単価が合わない', '開始日・稼働が合わない', '勤務地・働き方', '面談の受け答え', '他の候補者で決定', '案件の中止・延期', 'その他'];
const PARK_FUNNEL = ['applied', 'submitted', 'doc_passed', 'interview', 'interviewed', 'won'];
const PARK_PHASES = ['要件定義', '基本設計', '詳細設計', '実装', '単体テスト', '結合テスト', '総合テスト', '運用・保守', 'マネジメント'];
const PARK_TONE = [
    'pending' => 'warn', 'active' => 'ok', 'suspended' => 'mute', 'available' => 'ok', 'proposing' => 'info', 'working' => 'strong', 'unavailable' => 'mute',
    'draft' => 'mute', 'open' => 'ok', 'filled' => 'strong', 'closed' => 'mute', 'sent' => 'info', 'viewed' => 'info', 'declined' => 'mute',
    'applied' => 'info', 'submitted' => 'info', 'doc_passed' => 'ok', 'interview' => 'warn', 'interviewed' => 'warn', 'won' => 'strong', 'rejected' => 'mute', 'withdrawn' => 'mute',
    'pass' => 'ok', 'fail' => 'mute', 'ended' => 'mute', 'issued' => 'warn', 'paid' => 'ok', 'queued' => 'warn', 'failed' => 'bad', 'saved' => 'mute',
];

// 設定（運営の「設定」画面で変えられる。率は仮）
const PARK_DEFAULT_SETTINGS = [
    'app_name' => 'PARKS',
    'fee_initial_rate' => '7',
    'fee_monthly_rate' => '3',
    'monthly_from' => '2',
    'tax_rate' => '10',
    'match_threshold' => '40',
    'operator_name' => 'PARKS 運営事務局',
];

function lbl(string $map, ?string $key): string
{
    return PARK_LABELS[$map][$key] ?? (string) $key;
}

function badge(string $map, ?string $key): HtmlString
{
    return new HtmlString('<span class="badge '.(PARK_TONE[$key] ?? '').'">'.e(lbl($map, $key)).'</span>');
}

function setting(string $key): string
{
    static $cache = null;
    if ($cache === null || app()->runningUnitTests()) {
        $cache = DB::table('settings')->pluck('value', 'key')->all();
    }
    return (string) ($cache[$key] ?? PARK_DEFAULT_SETTINGS[$key] ?? '');
}

function set_setting(string $key, $value): void
{
    DB::table('settings')->updateOrInsert(['key' => $key], ['value' => (string) $value]);
}

function now_str(): string { return date('Y-m-d H:i:s'); }
function today_str(): string { return date('Y-m-d'); }
function this_month(): string { return date('Y-m'); }
function fmt_date($s): string { return $s ? substr((string) $s, 0, 10) : '—'; }
function fmt_dt($s): string { return $s ? substr((string) $s, 0, 16) : '—'; }
function add_months(string $date, int $n): string { return date('Y-m-d', strtotime(substr($date, 0, 10)." +$n months")); }
// その月から n か月後の月末（month_end('2026-11-15', 2) → '2027-01-31'）
function month_end(string $date, int $n = 0): string
{
    [$y, $m] = array_map('intval', explode('-', substr($date, 0, 7)));
    return date('Y-m-d', mktime(0, 0, 0, $m + $n + 1, 0, $y));
}
function month_diff(string $fromYm, string $toYm): int
{
    [$y1, $m1] = array_map('intval', explode('-', $fromYm));
    [$y2, $m2] = array_map('intval', explode('-', $toYm));
    return ($y2 - $y1) * 12 + ($m2 - $m1);
}
function days_between(string $a, string $b): int { return (int) round((strtotime(substr($b, 0, 10)) - strtotime(substr($a, 0, 10))) / 86400); }

function yen($n): string { return '¥'.number_format(round((float) $n)); }
function man($n): string
{
    if ($n === null || $n === '') return '—';
    $v = (float) $n;
    return (floor($v) == $v ? number_format($v) : rtrim(rtrim(number_format($v, 1), '0'), '.')).'万円';
}
// 10.0 → 10、6.5 → 6.5（小数点がある数だけ、末尾の 0 を消す）
function num_disp($n): string
{
    if ($n === null || $n === '') return '';
    $s = (string) $n;
    return str_contains($s, '.') ? rtrim(rtrim($s, '0'), '.') : $s;
}
function pct($a, $b): string { return $b ? (round($a / $b * 1000) / 10).'%' : '—'; }
function age_text($y): string { return $y ? (date('Y') - $y).'歳前後' : ''; }

// 「Java、Spring Boot / AWS」をリストにする
function parse_list($s): array
{
    return array_values(array_filter(array_map('trim', preg_split('/[,、，\/／\n・;；|]+/u', (string) $s)), fn ($x) => $x !== ''));
}

const PARK_SKILL_ALIASES = [
    'js' => 'javascript', 'ecmascript' => 'javascript', 'ts' => 'typescript', 'postgres' => 'postgresql', 'psql' => 'postgresql',
    'golang' => 'go', 'k8s' => 'kubernetes', 'reactjs' => 'react', 'vuejs' => 'vue', 'vue3' => 'vue', 'node' => 'nodejs',
    'amazonwebservices' => 'aws', 'googlecloud' => 'gcp', 'googlecloudplatform' => 'gcp', 'microsoftazure' => 'azure',
    'csharp' => 'c#', 'cplusplus' => 'c++', 'springframework' => 'spring', 'mssql' => 'sqlserver', 'oracledb' => 'oracle', 'oracledatabase' => 'oracle',
    'nextjs' => 'next', 'nuxtjs' => 'nuxt', 'html5' => 'html', 'css3' => 'css', 'python3' => 'python', 'php8' => 'php', 'php7' => 'php',
    'java8' => 'java', 'java11' => 'java', 'java17' => 'java', 'mysql8' => 'mysql',
];
// 技術マスタにある言葉は、正式な名前にそろえてから比べる（Postgres と PostgreSQL を同じにする）
function norm_skill($s): string
{
    $t = \App\Support\SkillCatalog::find((string) $s);
    return $t ? norm_skill_raw($t[1]) : norm_skill_raw($s);
}

// 文字だけの正規化（小文字・全角→半角・空白や記号を外す）
function norm_skill_raw($s): string
{
    $k = mb_strtolower(mb_convert_kana((string) $s, 'as')); // 全角の英数字・空白を半角に
    $k = preg_replace('/[（(].*?[)）]/u', '', $k);
    $k = preg_replace('/[\s.\-_]/u', '', $k);
    return PARK_SKILL_ALIASES[$k] ?? $k;
}
// 業務経歴の1件で使った技術（技術・OS／ツール・言語・DB の欄をまとめ、「PHP(Laravel)」は PHP と Laravel に分ける）
function project_techs($p): array
{
    $all = [];
    foreach (['tech', 'os_tools', 'langs', 'dbs'] as $f) {
        foreach (parse_list($p->$f ?? '') as $w) {
            if (preg_match('/^(.+?)[（(](.+)[)）]$/u', $w, $m)) {
                $all[] = trim($m[1]);
                foreach (preg_split('/[,、他]+/u', $m[2]) as $x) if (trim($x) !== '') $all[] = trim($x);
            } else $all[] = $w;
        }
    }
    return array_values(array_unique($all));
}

// 名前が似ていても別の技術（Java と JavaScript など）は一致にしない
function loose_match(string $a, string $b): bool
{
    if (!str_contains($a, $b) && !str_contains($b, $a)) return false;
    foreach ([['java', 'javascript']] as [$x, $y]) {
        if ((str_starts_with($a, $x) && str_starts_with($b, $y) && !str_starts_with($a, $y))
            || (str_starts_with($b, $x) && str_starts_with($a, $y) && !str_starts_with($b, $y))) return false;
    }
    return true;
}

function int_or_null($v): ?int { return is_numeric($v) ? (int) $v : null; }
function num_or_null($v): ?float { return is_numeric($v) ? (float) $v : null; }
function str_in($v): ?string
{
    if (is_array($v)) $v = implode(',', $v);
    $v = trim((string) $v);
    return $v === '' ? null : $v;
}
