<?php

// 画面とロジックで使う小さな道具（表示用の言葉・金額・日付・スキル名の正規化）
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;

const PARK_LABELS = [
    'role' => ['admin' => '管理者', 'ses' => 'SES', 'eg' => 'エンジニア'],
    'sesStatus' => ['pending' => '申込中', 'active' => '参加中', 'suspended' => '停止', 'left' => '退会済'],
    'engStatus' => ['available' => '待機中', 'proposing' => '提案中', 'working' => '稼働中', 'unavailable' => '休止', 'left' => '退会済'],
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
    'pending' => 'warn', 'active' => 'ok', 'suspended' => 'mute', 'left' => 'mute', 'available' => 'ok', 'proposing' => 'info', 'working' => 'strong', 'unavailable' => 'mute',
    'draft' => 'mute', 'open' => 'ok', 'filled' => 'strong', 'closed' => 'mute', 'sent' => 'info', 'viewed' => 'info', 'declined' => 'mute',
    'applied' => 'info', 'submitted' => 'info', 'doc_passed' => 'ok', 'interview' => 'warn', 'interviewed' => 'warn', 'won' => 'strong', 'rejected' => 'mute', 'withdrawn' => 'mute',
    'pass' => 'ok', 'fail' => 'mute', 'ended' => 'mute', 'issued' => 'warn', 'paid' => 'ok', 'queued' => 'warn', 'failed' => 'bad', 'saved' => 'mute',
];

// 設定（管理者の「設定」画面で変えられる。率は仮）
const PARK_DEFAULT_SETTINGS = [
    'app_name' => 'PARK',
    'fee_initial_rate' => '7',
    'fee_monthly_rate' => '3',
    'monthly_from' => '2',
    'tax_rate' => '10',
    'match_threshold' => '40',
    'operator_name' => 'PARK 管理者',
];

function lbl(string $map, ?string $key): string
{
    return PARK_LABELS[$map][$key] ?? (string) $key;
}

// 管理者の企業名（メールの差出人・署名に使う）。管理者でない・企業名が空なら null
function admin_sender(?object $u): ?string
{
    if (!$u || ($u->role ?? '') !== 'admin') return null;
    $c = trim((string) ($u->company_name ?? ''));
    return $c === '' ? null : $c;
}

// メールの宛名・差出人・画面の名前表示用：「SES名 氏名」（管理者は企業名 + 氏名。企業名がなければ運営の名前 + 氏名）
function user_label(?object $u): string
{
    if (!$u) return '';
    if ($u->role === 'admin') return trim((admin_sender($u) ?? setting('operator_name')).' '.$u->name);
    $s = DB::table('ses_companies')->where('id', $u->ses_id)->first(['name', 'kind']);
    if ($u->role === 'eg' && ($s->kind ?? '') === 'direct') return (string) $u->name; // 管理者の直属は、会社名をつけない
    return trim(($s->name ?? '').' '.$u->name);
}

// 担当者・本人のアカウントの状態：退会済・停止・使用中・招待中（1か所で決める）
function user_state(?object $u): string
{
    if (!$u) return 'なし';
    if (!empty($u->left_at)) return '退会済';
    if (!$u->active) return '停止';
    return $u->password ? '使用中' : '招待中';
}

function badge(string $map, ?string $key): HtmlString
{
    return new HtmlString('<span class="badge '.(PARK_TONE[$key] ?? '').'">'.e(lbl($map, $key)).'</span>');
}

// 困ったときの相談先
function contact_email(): string
{
    return 'park@jona.jp';
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

// 企業名・SES名の整え：文字列でなければ空、改行などの制御文字を取り、前後の空白（全角も）を取る。空なら null
function clean_name($v): ?string
{
    if (!is_string($v)) return null;
    $v = preg_replace('/^[\s　]+|[\s　]+$/u', '', preg_replace('/\p{Cc}/u', '', $v) ?? '') ?? '';
    return $v === '' ? null : $v;
}

// 氏名を最初の空白（半角・全角。続く空白は1つ）で姓と名に分ける。空白が無ければ全体が姓。空なら [null, null]
function split_name(?string $full): array
{
    $s = clean_name($full);
    if ($s === null) return [null, null];
    $p = preg_split('/[\s　]+/u', $s, 2);
    return [$p[0], isset($p[1]) && $p[1] !== '' ? $p[1] : null];
}

// 姓と名を半角の空白でつなぐ。片方だけならそれ、両方なければ null
function join_name(?string $sei, ?string $mei): ?string
{
    $sei = clean_name($sei);
    $mei = clean_name($mei);
    if ($sei !== null && $mei !== null) return $sei.' '.$mei;
    return $sei ?? $mei;
}

// 氏名の入力から [氏名, 姓, 名] を返す。姓・名の2欄（{$base}_sei・{$base}_mei）のどちらかがあればそれを使い、
// 無ければ1欄 $full（古い呼び方）を最初の空白で分ける。文字数は切らない（呼ぶ側で調べる）。どちらも空なら [null, null, null]
function name_parts(array $in, string $base, string $full): array
{
    if (isset($in[$base.'_sei']) || isset($in[$base.'_mei'])) {
        [$sei, $mei] = [clean_name($in[$base.'_sei'] ?? null), clean_name($in[$base.'_mei'] ?? null)];
    } else {
        [$sei, $mei] = split_name(is_string($in[$full] ?? null) ? $in[$full] : null);
    }
    return [join_name($sei, $mei), $sei, $mei];
}

// 郵便番号をそろえる。空なら null、7桁の数字（ハイフン・〒・全角・空白は許す）なら「123-4567」、それ以外は false
function postal_norm($v)
{
    if ($v === null) return null;
    $s = mb_convert_kana((string) $v, 'n'); // 全角の数字を半角に
    $s = str_replace(['－', 'ー', '‐', '−', '―'], '-', $s);
    $s = preg_replace('/[\s　]+/u', '', $s) ?? '';
    $s = preg_replace('/^〒/u', '', $s) ?? '';
    if ($s === '') return null;
    if (preg_match('/^(\d{3})-?(\d{4})$/', $s, $m)) return $m[1].'-'.$m[2];
    return false;
}

// 郵便番号と住所を「〒123-4567 住所」の形で見せる。どちらも無ければ「—」。そろえられない古い郵便番号は入っているとおりに出す
function postal_address(?string $postal, ?string $address): string
{
    $p = trim((string) $postal);
    if ($p !== '') {
        $n = postal_norm($p);
        $p = '〒'.($n ? $n : $p);
    }
    $a = trim((string) $address);
    $out = trim($p.' '.$a);
    return $out === '' ? '—' : $out;
}

function int_or_null($v): ?int { return is_numeric($v) ? (int) $v : null; }
function num_or_null($v): ?float { return is_numeric($v) ? (float) $v : null; }
function str_in($v): ?string
{
    if (is_array($v)) $v = implode(',', $v);
    $v = trim((string) $v);
    return $v === '' ? null : $v;
}

// 兼任（エンジニアと、SES 担当者・管理者を兼ねる）の人か。ActingRole ミドルウェアが、この要求の間だけ覚えておく
function is_dual(): bool
{
    return (bool) request()->attributes->get('park_dual', false);
}

// 兼任の人の、DB の本来の役割（いまの立場ではなく users.role）。兼任でなければ null
function base_role(): ?string
{
    return is_dual() ? request()->attributes->get('park_base_role') : null;
}

// 詳細画面の「一覧に戻る」の行き先（役割ごと）
function list_url(string $kind): string
{
    $role = auth()->user()->role ?? 'admin';
    $map = [
        'engineers' => ['admin' => '/admin/engineers', 'ses' => '/ses/engineers', 'eg' => '/eg'],
        'cases' => ['admin' => '/admin/cases', 'ses' => '/ses/cases', 'eg' => '/eg/offers'],
        'applications' => ['admin' => '/admin/applications', 'ses' => '/ses/applications', 'eg' => '/eg/applications'],
        'contracts' => ['admin' => '/admin/contracts', 'ses' => '/ses/contracts', 'eg' => '/eg'],
    ];
    return url($map[$kind][$role] ?? '/');
}
