<?php

namespace App\Console\Commands;

use App\Services\Workflow as W;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

// エンジニアの氏名・ふりがな・電話を、アカウント（users）と技術登録（engineers）でそろえる（今ある食い違いを一度だけ直す。B1・AC8）。
// 既定は見るだけ（件数と id だけを出す。氏名・ふりがな・電話の中身は出さない）。書き換えは --apply を付けたときだけ。
// 公開版で --apply を流すのは、TEZZO の了承を取ってから。マイグレーションにしないのは、反映のとき了承なしに書き換わるのを避けるため。
// 対象は users の role='eg' で engineer_id がある組だけ（兼任の人は触らない）。
//   a. 片方だけ値がある → 値のある方で、空の方を埋める
//   b. 両方に値があって違う → 本人が情報を登録済み（users.profile_completed_at あり）なら users を、まだなら engineers を正とする
// 使い方：php artisan park:sync-eg-names [--apply]
class SyncEgNames extends Command
{
    protected $signature = 'park:sync-eg-names {--apply : 食い違いを書き換える（付けないときは見るだけ）}';
    protected $description = 'エンジニアの氏名・ふりがな・電話を、アカウントと技術登録でそろえる（既定は見るだけ）';

    private const FIELDS = ['name_sei', 'name_mei', 'kana_sei', 'kana_mei', 'phone'];

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $fill = []; $conflict = []; // 組の [users.id, engineers.id]
        $pairs = DB::table('users as u')->join('engineers as e', 'e.id', '=', 'u.engineer_id')->where('u.role', 'eg')
            ->select('u.id as uid', 'u.profile_completed_at', 'e.id as eid')->orderBy('u.id')->get();
        foreach ($pairs as $p) {
            $u = DB::table('users')->find($p->uid);
            $e = DB::table('engineers')->find($p->eid);
            $kind = null; $resolved = [];
            foreach (self::FIELDS as $f) {
                $uv = self::norm($u->$f);
                $ev = self::norm($e->$f);
                if ($uv === $ev) { $resolved[$f] = $uv; continue; }
                if ($uv === null || $ev === null) { $resolved[$f] = $uv ?? $ev; $kind = $kind ?? 'fill'; continue; }
                $resolved[$f] = $p->profile_completed_at ? $uv : $ev; // 両方に値があって違う
                $kind = 'conflict';
            }
            if (!$kind) continue;
            if ($kind === 'fill') $fill[] = [$p->uid, $p->eid]; else $conflict[] = [$p->uid, $p->eid];
            if ($apply) $this->write((int) $p->uid, (int) $p->eid, $resolved);
        }

        $this->info('食い違い（アカウントと技術登録）');
        $this->table(['種類', '件数', 'users.id／engineers.id'], [
            ['片方だけ値がある（空の方を埋める）', count($fill), implode(', ', array_map(fn ($x) => "{$x[0]}／{$x[1]}", $fill)) ?: '—'],
            ['両方に値があって違う（登録済みなら users、まだなら engineers が正）', count($conflict), implode(', ', array_map(fn ($x) => "{$x[0]}／{$x[1]}", $conflict)) ?: '—'],
        ]);
        $this->line($apply ? '書き換えました。' : '見るだけです（何も変えていません）。書き換えるときは --apply を付けます。');
        return 0;
    }

    private static function norm($v): ?string
    {
        $v = is_string($v) ? trim($v) : null;
        return $v === '' ? null : $v;
    }

    // そろえた値を、両方に書く。氏名の姓・名がどちらも空なら、つないだ氏名は今のまま
    private function write(int $uid, int $eid, array $v): void
    {
        $name = join_name($v['name_sei'], $v['name_mei']);
        $kana = join_name($v['kana_sei'], $v['kana_mei']);
        $common = ['name_sei' => $v['name_sei'], 'name_mei' => $v['name_mei'], 'kana_sei' => $v['kana_sei'], 'kana_mei' => $v['kana_mei'], 'kana' => $kana, 'phone' => $v['phone'], 'updated_at' => now_str()]
            + ($name !== null ? ['name' => $name] : []);
        DB::transaction(function () use ($uid, $eid, $common, $kana) {
            DB::table('users')->where('id', $uid)->update($common);
            $e = DB::table('engineers')->find($eid);
            DB::table('engineers')->where('id', $eid)->update($common + (empty($e->initials) && $kana ? ['initials' => W::initialsFromKana($kana)] : []));
        });
    }
}
