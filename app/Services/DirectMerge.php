<?php

namespace App\Services;

use App\Services\Notifier as N;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

// 管理者の直属（ses_companies.kind='direct'）を、合同会社MRSY に付け替える（v0.11.0）。
// plan() が「何を付け替えるか」を出し、run() が付け替える。restore() で、バックアップから戻せる。
// ・付け替える先が見つからない（0件・2件以上・参加中でない）ときは、何も変えずに止める（会社を勝手に作らない）。
// ・対象が0件なら、何もしない（2回目も同じ。冪等）。
// ・直属の会社の行は消さない・変えない（参照が0になるだけ）。
// ・名前・メールアドレスは記録に書かない（id と数だけ）。
class DirectMerge
{
    const MRSY_NAME = '合同会社MRSY';
    const BACKUP_DIR = 'app/backups';
    const KIND = 'direct-merge'; // お知らせ・記録の種類

    // 付け替える列（表 => 列の一覧）
    const COLUMNS = [
        'engineers' => ['ses_id'],
        'users' => ['ses_id'],            // エンジニアのアカウント（role=eg）だけ
        'applications' => ['ses_id'],
        'contracts' => ['ses_id'],
        'case_threads' => ['ses_id', 'partner_ses_id'],
        'cases' => ['source_ses_id'],     // 直属のものと、受注 SES が空のもの
        'case_mails' => ['ses_id'],
        'case_searches' => ['ses_id'],
        'direct_mails' => ['from_ses_id'],
    ];

    // 付け替えの対象にしないが、直属を指していたら止める列（請求書は、直属には請求していないはず）
    const GUARDED = ['invoices.ses_id'];

    // 会社名のゆれ（全角・半角・空白）をそろえる
    private static function norm(string $s): string
    {
        if (class_exists(\Normalizer::class)) $s = \Normalizer::normalize($s, \Normalizer::FORM_KC) ?: $s;
        return (string) preg_replace('/[\s　]+/u', '', $s);
    }

    private static function directIds(): array
    {
        return DB::table('ses_companies')->where('kind', 'direct')->pluck('id')->map(fn ($i) => (int) $i)->all();
    }

    // 付け替える先の候補（kind=ses で、名前が合うもの）。[['id' => , 'status' => , 'staff' => 本登録が済んで使える担当者の数], ...]
    public static function candidates(): array
    {
        $out = [];
        $want = self::norm(self::MRSY_NAME);
        foreach (DB::table('ses_companies')->where('kind', 'ses')->get(['id', 'name', 'status']) as $s) {
            if (self::norm((string) $s->name) !== $want) continue;
            $staff = DB::table('users')->where('role', 'ses')->where('ses_id', $s->id)->where('active', 1)->whereNull('left_at')->whereNotNull('registered_at')->count();
            $out[] = ['id' => (int) $s->id, 'status' => (string) $s->status, 'staff' => $staff];
        }
        return $out;
    }

    // 対象の行。['表.列' => [id, ...]]（0件の列は入れない）
    public static function plan(): array
    {
        $d = self::directIds();
        $rows = [];
        foreach (self::COLUMNS as $table => $cols) {
            if (!Schema::hasTable($table)) continue;
            foreach ($cols as $col) {
                if (!Schema::hasColumn($table, $col)) continue;
                $q = DB::table($table);
                if ($table === 'users') $q->whereIn($col, $d)->where('role', 'eg');
                elseif ($table === 'cases') $q->where(fn ($w) => $w->whereIn($col, $d)->orWhereNull($col)); // 受注 SES が空の案件も（D5）
                else $q->whereIn($col, $d);
                $ids = $q->orderBy('id')->pluck('id')->map(fn ($i) => (int) $i)->all();
                if ($ids) $rows["{$table}.{$col}"] = $ids;
            }
        }
        return $rows;
    }

    // 付け替えの予定の件数（表.列 => 件数）と、変えない「管理者で ses_id がある行」の数。見るだけ（何も書き換えない）
    public static function summary(): array
    {
        return ['counts' => array_map('count', self::plan()), 'adminsWithSes' => self::adminsWithSes()];
    }

    // 直属を指している行の数（受注 SES が空の案件は含めない）。付け替えのあとは 0 のはず
    public static function references(): int
    {
        $d = self::directIds();
        if (!$d) return 0;
        $n = 0;
        foreach (self::COLUMNS as $table => $cols) {
            if (!Schema::hasTable($table)) continue;
            foreach ($cols as $col) if (Schema::hasColumn($table, $col)) $n += DB::table($table)->whereIn($col, $d)->count();
        }
        foreach (self::GUARDED as $tc) {
            [$table, $col] = explode('.', $tc);
            if (Schema::hasTable($table) && Schema::hasColumn($table, $col)) $n += DB::table($table)->whereIn($col, $d)->count();
        }
        return $n;
    }

    // role=admin で ses_id がある行の数（担当者を兼ねている管理者。v0.11.0 から、ses_id がある管理者は担当者を兼ねる扱いになる）。
    // 古いデータにこの形の行があると、意図せず兼任になるので、数えて知らせる。データは書き換えない
    public static function adminsWithSes(): int
    {
        return DB::table('users')->where('role', 'admin')->whereNotNull('ses_id')->count();
    }

    // 付け替える先の会社の id。見つからないときは RuntimeException
    private static function target(): int
    {
        $c = self::candidates();
        if (count($c) !== 1 || $c[0]['status'] !== 'active') {
            throw new RuntimeException(self::MRSY_NAME.' が見つからない（または複数・参加中でない）ため、直属の付け替えを止めました。');
        }
        return $c[0]['id'];
    }

    // 止める条件。何か1つでも当たれば、理由の文を返す（無ければ null）
    private static function guard(array $d, int $m): ?string
    {
        if (DB::table('users')->whereIn('ses_id', $d)->where('role', '!=', 'eg')->exists()) return '直属に、エンジニア以外のアカウントが入っているため、止めました。';
        if (Schema::hasTable('invoices') && DB::table('invoices')->whereIn('ses_id', $d)->exists()) return '直属あての請求書があるため、止めました。';
        // 付け替えると、案件×エンジニアの1本が重なる
        if (Schema::hasTable('case_threads') && DB::table('case_threads as a')->whereIn('a.ses_id', $d)
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('case_threads as b')->whereColumn('b.case_id', 'a.case_id')->whereColumn('b.engineer_id', 'a.engineer_id')->where('b.ses_id', $m))->exists()) {
            return '案件のやり取りの1本が、直属と '.self::MRSY_NAME.' の両方にあるため、止めました。';
        }
        // 直属が複数あり、同じ案件×エンジニアの1本が、直属どうしで両方にある（MRSY に付け替えると重なる）
        if (Schema::hasTable('case_threads') && DB::table('case_threads')->whereIn('ses_id', $d)->groupBy('case_id', 'engineer_id')->havingRaw('count(distinct ses_id) > 1')->exists()) {
            return '案件のやり取りの1本が、複数の直属の行にまたがっているため（付け替えると重なる）、止めました。';
        }
        // 表が増えたときの取りこぼし防止：上の表に無い「…ses_id」の列が直属を指していたら止める
        $known = array_merge(self::GUARDED, ...array_map(fn ($t, $cols) => array_map(fn ($c) => "{$t}.{$c}", $cols), array_keys(self::COLUMNS), self::COLUMNS));
        $dbName = DB::getDatabaseName();
        foreach (Schema::getTables() as $t) {
            $table = (string) ($t['name'] ?? '');
            if ($table === '' || $table === 'ses_companies') continue;
            // 今つながっているデータベースの表だけを見る（ほかのデータベースの表を数えない）
            if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true) && isset($t['schema']) && $t['schema'] !== null && $t['schema'] !== $dbName) continue;
            foreach (Schema::getColumnListing($table) as $col) {
                if (!str_ends_with($col, 'ses_id') || in_array("{$table}.{$col}", $known, true)) continue;
                if (DB::table($table)->whereIn($col, $d)->exists()) return "表 {$table} の {$col} が直属を指しています（この付け替えの対象に無い列）。止めました。";
            }
        }
        return null;
    }

    // 付け替える。戻り値：['changed' => bool, 'counts' => ['表.列' => 件数], 'backup' => パスか null, 'mrsy' => id か null]
    public static function run(): array
    {
        $plan = self::plan();
        if (!$plan) return ['changed' => false, 'counts' => [], 'backup' => null, 'mrsy' => null]; // 対象が0件なら、探さずに終わる
        $m = self::target();
        $d = self::directIds();
        if ($why = self::guard($d, $m)) throw new RuntimeException($why);

        // 1. 元の値を、列ごと・行ごとに JSON に書き出す（書き出せなければ止まる）
        $dir = storage_path(self::BACKUP_DIR);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) throw new RuntimeException('バックアップの置き場所を作れませんでした。何も変えていません。');
        $old = [];
        foreach ($plan as $key => $ids) {
            [$table, $col] = explode('.', $key);
            $old[$key] = [];
            foreach (array_chunk($ids, 500) as $chunk) foreach (DB::table($table)->whereIn('id', $chunk)->pluck($col, 'id') as $id => $v) $old[$key][$id] = $v === null ? null : (int) $v;
        }
        $json = json_encode(['作成' => now_str(), '直属' => $d, '付け替え先' => $m, '元の値' => $old], JSON_PRETTY_PRINT);
        $path = $dir.DIRECTORY_SEPARATOR.'merge-direct-'.date('Ymd-His').'.json';
        if ($json === false || file_put_contents($path, $json) === false) throw new RuntimeException('バックアップを書き出せませんでした。何も変えていません。');

        // 2. 1つの transaction で付け替える
        DB::transaction(function () use ($plan, $m) {
            foreach ($plan as $key => $ids) {
                [$table, $col] = explode('.', $key);
                foreach (array_chunk($ids, 500) as $chunk) DB::table($table)->whereIn('id', $chunk)->update([$col => $m]);
            }
        });

        // 3. お知らせ（画面だけ。メールは送らない。D15）
        $engIds = $plan['engineers.ses_id'] ?? [];
        foreach ($engIds as $eid) {
            N::toEg($eid, '所属の会社が「'.self::MRSY_NAME.'」になりました', '所属の会社が「'.self::MRSY_NAME.'」になりました。連絡は '.self::MRSY_NAME.' のご担当者へ。', '/eg', false, self::KIND);
        }
        if ($engIds) {
            N::toSes($m, '管理者の直属だった '.count($engIds).' 名のエンジニアが、貴社の所属になりました', '管理者の直属だった '.count($engIds).' 名のエンジニアが、貴社の所属になりました。エンジニアの一覧からご確認ください。', '/ses/engineers', false, self::KIND);
        }

        // 4. 記録（件数とバックアップのファイル名だけ。名前は書かない）
        $counts = array_map('count', $plan);
        $admins = self::adminsWithSes(); // 変えない。数だけ記録する
        N::audit(null, self::KIND, json_encode($counts, JSON_UNESCAPED_UNICODE).' 管理者で ses_id あり（変えない）：'.$admins.' '.basename($path));
        return ['changed' => true, 'counts' => $counts, 'backup' => $path, 'mrsy' => $m, 'adminsWithSes' => $admins];
    }

    // いちばん新しいバックアップから、記録した行だけ元の値に戻す。バックアップが無ければ何もしない（false）。お知らせは消さない
    public static function restore(): bool
    {
        $files = glob(storage_path(self::BACKUP_DIR).DIRECTORY_SEPARATOR.'merge-direct-*.json') ?: [];
        if (!$files) return false;
        sort($files);
        $b = json_decode((string) file_get_contents(end($files)), true);
        if (!is_array($b) || !isset($b['元の値'])) return false;
        DB::transaction(function () use ($b) {
            foreach ($b['元の値'] as $key => $rows) {
                [$table, $col] = explode('.', $key);
                if (!Schema::hasTable($table) || !Schema::hasColumn($table, $col)) continue;
                $byValue = [];
                foreach ($rows as $id => $v) $byValue[$v === null ? '' : (string) $v][] = (int) $id;
                foreach ($byValue as $v => $ids) {
                    foreach (array_chunk($ids, 500) as $chunk) DB::table($table)->whereIn('id', $chunk)->update([$col => $v === '' ? null : (int) $v]);
                }
            }
        });
        N::audit(null, self::KIND.'-restore', basename(end($files)));
        return true;
    }
}
