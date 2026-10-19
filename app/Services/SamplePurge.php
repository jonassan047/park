<?php

namespace App\Services;

use App\Services\Notifier as N;
use App\Services\Workflow as W;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

// 見本データ（メールアドレスが @park.example の人・会社・エンジニア・案件と、それにつながるもの）を消す手続き。
// plan() が「何を消し、何を残すか」と守りの判定を出し、run() が消す。名前・メールアドレスは外に出さない（id と数だけ）。
class SamplePurge
{
    const SUFFIX = '@park.example';
    const BACKUP_DIR = 'app/backups';

    // 消す順番（外部キーのため、子の表から先に）。表 => 行を決める列
    const ORDER = [
        'ai_jobs' => 'id', 'follow_ups' => 'id', 'contracts' => 'id', 'invoices' => 'id', 'application_events' => 'id', 'interviews' => 'id',
        'applications' => 'id', 'case_offers' => 'id', 'case_mails' => 'id', 'messages' => 'id',
        'engineer_skills' => 'id', 'engineer_projects' => 'id', 'engineer_histories' => 'id', 'engineer_certs' => 'id', 'engineer_files' => 'id',
        'engineers' => 'id', 'cases' => 'id', 'app_notifications' => 'id', 'users' => 'id', 'sessions' => 'id', 'password_reset_tokens' => 'email',
        'ses_companies' => 'id', 'mail_outbox' => 'id', 'audit_log' => 'id',
    ];

    // $moveKept が true のときは、見本の SES に入っている見本ではない人を、消さずに管理者の直属へ移す前提で数える
    public static function plan(bool $moveKept = false): array
    {
        $like = '%'.self::SUFFIX;
        $u = DB::table('users')->where('email', 'like', $like)->pluck('id')->all();
        $s = DB::table('ses_companies')->where('kind', 'ses')->where('email', 'like', $like)->pluck('id')->all();
        $e = DB::table('engineers')->where(fn ($q) => $q->where('email', 'like', $like)
            ->orWhere(fn ($q) => $q->where(fn ($q) => $q->whereNull('email')->orWhere('email', ''))->whereIn('ses_id', $s)))->pluck('id')->all();
        $c = DB::table('cases')->whereIn('registered_by', $u)->pluck('id')->all();

        // 見本の SES に所属しているが、見本ではない人（残す人）
        $keptUsers = DB::table('users')->whereIn('ses_id', $s)->whereNotIn('id', $u)->where('role', 'eg')->pluck('id')->all(); // 直属へ移してよいのはエンジニアだけ
        $keptSesUsers = DB::table('users')->whereIn('ses_id', $s)->whereNotIn('id', $u)->where('role', '!=', 'eg')->count(); // SES の担当者などは移さず、止める
        $keptByEng = DB::table('users')->whereIn('engineer_id', $e)->whereNotIn('id', $u)->count(); // 見本のエンジニアにつながる、見本ではないユーザー
        $keptEngs = DB::table('engineers')->whereIn('ses_id', $s)->whereNotIn('id', $e)->pluck('id')->all();

        // SES で決まる行は、残す人のものを除く
        $bySes = fn ($q) => $q->whereIn('ses_id', $s)->whereNotIn('engineer_id', $keptEngs);
        $a = DB::table('applications')->where(fn ($q) => $q->whereIn('engineer_id', $e)->orWhereIn('case_id', $c)->orWhere($bySes))->pluck('id')->all();
        $iv = DB::table('interviews')->whereIn('application_id', $a)->pluck('id')->all();
        $k = DB::table('contracts')->where(fn ($q) => $q->whereIn('engineer_id', $e)->orWhereIn('case_id', $c)->orWhereIn('application_id', $a)->orWhere($bySes))->pluck('id')->all();
        $inv = DB::table('invoices')->whereIn('ses_id', $s)->pluck('id')->all();
        $files = DB::table('engineer_files')->whereIn('engineer_id', $e)->get(['id', 'path']);
        $cs = fn ($t) => DB::table($t)->where(fn ($q) => $q->whereIn('engineer_id', $e)->orWhereIn('case_id', $c))->pluck('id')->all();
        $byEng = fn ($t) => DB::table($t)->whereIn('engineer_id', $e)->pluck('id')->all();

        $del = [
            'ai_jobs' => DB::table('ai_jobs')->where(fn ($q) => $q->where(fn ($q) => $q->where('ref_type', 'interview')->whereIn('ref_id', $iv))
                ->orWhere(fn ($q) => $q->where('ref_type', 'engineer_file')->whereIn('ref_id', $files->pluck('id')->all())))->pluck('id')->all(),
            'follow_ups' => DB::table('follow_ups')->whereIn('contract_id', $k)->pluck('id')->all(),
            'contracts' => $k, 'invoices' => $inv,
            'application_events' => DB::table('application_events')->whereIn('application_id', $a)->pluck('id')->all(),
            'interviews' => $iv, 'applications' => $a,
            'case_offers' => $cs('case_offers'), 'case_mails' => $cs('case_mails'), 'messages' => $byEng('messages'),
            'engineer_skills' => $byEng('engineer_skills'), 'engineer_projects' => $byEng('engineer_projects'), 'engineer_histories' => $byEng('engineer_histories'),
            'engineer_certs' => $byEng('engineer_certs'), 'engineer_files' => $files->pluck('id')->all(),
            'engineers' => $e, 'cases' => $c,
            'users' => $u,
            'sessions' => DB::table('sessions')->whereIn('user_id', $u)->pluck('id')->all(),
            'password_reset_tokens' => DB::table('password_reset_tokens')->whereIn('email', DB::table('users')->whereIn('id', $u)->select('email'))->pluck('email')->all(),
            'ses_companies' => $s,
            'mail_outbox' => DB::table('mail_outbox')->where('to_email', 'like', $like)->pluck('id')->all(),
            'audit_log' => DB::table('audit_log')->whereIn('user_id', $u)->pluck('id')->all(),
        ];
        // 通知：消す人の分と、残る人の通知のうちリンクが消すものを指しているもの
        $targets = ['engineers' => $e, 'cases' => $c, 'applications' => $a, 'interviews' => $iv, 'contracts' => $k, 'invoices' => $inv, 'admin/ses' => $s];
        $notif = DB::table('app_notifications')->whereIn('user_id', $u)->pluck('id')->all();
        foreach (DB::table('app_notifications')->whereNotIn('user_id', $u)->whereNotNull('link')->get(['id', 'link']) as $n) {
            if (preg_match('#^/(engineers|cases|applications|interviews|contracts|invoices|admin/ses)/(\d+)(?:[/?\#]|$)#', $n->link, $m) && in_array((int) $m[2], $targets[$m[1]], true)) $notif[] = $n->id;
        }
        $del['app_notifications'] = $notif;

        $counts = [];
        $total = 0;
        foreach (self::ORDER as $t => $key) {
            $n = count($del[$t]);
            $total += $n;
            $counts[$t] = [$n, DB::table($t)->count() - $n];
        }

        // 守り：どれかに当たれば、何も消さずに止める
        $guards = [];
        if (!DB::table('users')->where('role', 'admin')->where('active', 1)->whereNotNull('password')->whereNotIn('id', $u)->exists()) {
            $guards[] = '見本の人のほかに、使える管理者がいません（最初の管理者を残すため、止めました）。';
        }
        if (($keptUsers || $keptEngs) && !$moveKept) {
            $guards[] = '見本の SES に、見本ではない人が入っています（ユーザー '.count($keptUsers).'人・エンジニア '.count($keptEngs).'人）。消す前に tezzo に確かめてください。'
                .'管理者の直属へ移して続けるときだけ --move-kept-to-direct を付けます。';
        }
        if ($keptSesUsers) {
            $guards[] = "見本の SES に、見本ではない SES の担当者などが {$keptSesUsers}人入っています。オプションを付けても移せないので、止めました。tezzo に確かめてください。";
        }
        if ($keptByEng) {
            $guards[] = "見本のエンジニアにつながる、見本ではないユーザーが {$keptByEng}人います。止めました。tezzo に確かめてください。";
        }
        // 見本ではない案件・エンジニアが混ざった応募・契約（見本の SES の行も含む。残す人は直属へ移すので除く）
        $mixed = fn ($t) => DB::table($t)->where(fn ($q) => $q->where(fn ($q) => $q->whereIn('engineer_id', $e)->whereNotIn('case_id', $c))
            ->orWhere(fn ($q) => $q->whereIn('ses_id', $s)->whereNotIn('engineer_id', $keptEngs)->where(fn ($q) => $q->whereNotIn('engineer_id', $e)->orWhereNotIn('case_id', $c))))->count();
        $appsOther = $mixed('applications');
        $consOther = $mixed('contracts');
        if ($appsOther || $consOther) {
            $guards[] = "見本ではない案件・エンジニアに関わる応募が {$appsOther}件・契約が {$consOther}件あります。消す前に tezzo に確かめてください。";
        }

        return ['del' => $del, 'counts' => $counts, 'total' => $total, 'guards' => $guards, 'sets' => ['users' => $u, 'ses' => $s, 'engineers' => $e, 'cases' => $c],
            'moveUsers' => $keptUsers, 'moveEngs' => $keptEngs, 'files' => $files->pluck('path')->all(), 'caseSrc' => DB::table('cases')->whereIn('source_ses_id', $s)->whereNotIn('id', $c)->pluck('id')->all()];
    }

    // 消す。守りに当たったら例外（何も消さない）。戻り値は [表ごとの件数, バックアップの場所]。消すものが無ければバックアップも作らない
    public static function run(bool $moveKept = false): array
    {
        $p = self::plan($moveKept);
        if ($p['guards']) throw new RuntimeException(implode("\n", $p['guards']));
        if ($p['total'] === 0 && !$p['moveUsers'] && !$p['moveEngs']) return ['deleted' => [], 'backup' => null, 'total' => 0];

        // 1. 消す行を、全部の列ごと JSON に書き出す（書き出せなければ止まる）
        $dir = storage_path(self::BACKUP_DIR);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) throw new RuntimeException('バックアップの置き場所を作れませんでした。何も消していません。');
        $backup = ['作成' => now_str(), '表' => [], '変える行' => ['cases.source_ses_id を空にする' => $p['caseSrc'], '直属へ移す users' => $p['moveUsers'], '直属へ移す engineers' => $p['moveEngs']]];
        foreach (self::ORDER as $t => $key) {
            $rows = [];
            foreach (array_chunk($p['del'][$t], 500) as $ids) $rows = array_merge($rows, DB::table($t)->whereIn($key, $ids)->get()->all());
            $backup['表'][$t] = $rows;
        }
        $json = json_encode($backup, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRETTY_PRINT);
        $path = $dir.DIRECTORY_SEPARATOR.'purge-samples-'.date('Ymd-His').'.json';
        if ($json === false || file_put_contents($path, $json) === false) throw new RuntimeException('バックアップを書き出せませんでした。何も消していません。');

        // 2. 1つの transaction で消す
        DB::transaction(function () use ($p, $moveKept) {
            if ($moveKept && ($p['moveUsers'] || $p['moveEngs'])) {
                $direct = W::directSesId();
                $from = $p['sets']['ses'];
                DB::table('users')->whereIn('id', $p['moveUsers'])->update(['ses_id' => $direct]);
                DB::table('engineers')->whereIn('id', $p['moveEngs'])->update(['ses_id' => $direct]);
                foreach (['applications', 'contracts', 'case_mails'] as $t) DB::table($t)->whereIn('engineer_id', $p['moveEngs'])->whereIn('ses_id', $from)->update(['ses_id' => $direct]);
            }
            foreach (self::ORDER as $t => $key) {
                foreach (array_chunk($p['del'][$t], 500) as $ids) DB::table($t)->whereIn($key, $ids)->delete();
                if ($t === 'cases' && $p['caseSrc']) DB::table('cases')->whereIn('id', $p['caseSrc'])->update(['source_ses_id' => null]);
            }
        });

        // 3. 書類の実物を消す（transaction が通ったあと）
        foreach ($p['files'] as $f) Storage::disk(EngineerDocs::DISK)->delete($f);

        // 4. 記録（件数だけ。名前は書かない）
        $deleted = array_filter(array_map(fn ($c) => $c[0], $p['counts']));
        N::audit(null, 'purge-samples', json_encode($deleted, JSON_UNESCAPED_UNICODE));
        return ['deleted' => $deleted, 'backup' => $path, 'total' => $p['total']];
    }
}
