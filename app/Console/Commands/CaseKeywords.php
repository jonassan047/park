<?php

namespace App\Console\Commands;

use App\Services\Notifier as N;
use App\Services\Workflow as W;
use App\Support\CaseKeywords as Keywords;
use App\Support\SkillCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

// 今までの案件の必須・尚可を、新しい形（名前だけ＋「EGに確認」）に作り直す（v0.14.0）。
// 本文（raw_text）からは読み直さず、今の欄を元の行とみなす（人が直した欄・AI の結果を壊さないため）。
// --apply が無いときは、DB に書かずに変わる内容だけを出す。公開版の --apply は TEZZO の了承を取ってから
class CaseKeywords extends Command
{
    protected $signature = 'park:case-keywords {--id= : 案件の id（行があっても、この1件を作り直す）} {--apply : 書き換える（付けないときは、変わる内容を出すだけ）}';
    protected $description = '今までの案件の必須・尚可を、技術マスタの名前だけにして、拾えない文を「EGに確認」へ移す（--apply を付けたときだけ書き換える）';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $q = DB::table('cases')->orderBy('id');
        if ($this->option('id')) $q->where('id', (int) $this->option('id'));
        else $q->whereNull('required_lines');
        $n = 0; $changed = 0; $newWords = [];
        foreach ($q->get() as $c) {
            $n++;
            $r = W::keywordColumns(['required_skills' => $c->required_skills, 'preferred_skills' => $c->preferred_skills, 'confirm_items' => $c->confirm_items]);
            $v = $r['v'];
            $reqLines = Keywords::cleanAll(Keywords::items($c->required_skills));
            $prefLines = Keywords::cleanAll(Keywords::items($c->preferred_skills));
            $new = [
                'required_skills' => $v['required_skills'], 'preferred_skills' => $v['preferred_skills'], 'confirm_items' => $v['confirm_items'],
                // 行が空の案件は空文字にして、次から対象にしない（null のままだと、毎回対象に入る）
                'required_lines' => $reqLines ? implode("\n", $reqLines) : '', 'preferred_lines' => $prefLines ? implode("\n", $prefLines) : '',
            ];
            $same = $new['required_skills'] === $c->required_skills && $new['preferred_skills'] === $c->preferred_skills && $new['confirm_items'] === $c->confirm_items;
            if (!$same) $changed++;
            $this->line(($c->code ?: '#'.$c->id).' '.$c->title.($same ? '（名前のまま。元の行だけ入れます）' : ''));
            if (!$same) {
                $this->line('  必須　　：'.($c->required_skills ?: '—').' → '.($new['required_skills'] ?: '—'));
                $this->line('  尚可　　：'.($c->preferred_skills ?: '—').' → '.($new['preferred_skills'] ?: '—'));
                $this->line('  EGに確認：'.($c->confirm_items ? str_replace("\n", ' / ', $c->confirm_items) : '—').' → '.($new['confirm_items'] ? str_replace("\n", ' / ', $new['confirm_items']) : '—'));
            }
            if (!$apply) continue;
            // マスタに足すのは、空白の無い英数字1語だけ（文は足さない）
            foreach ($r['new'] as $w) if (SkillCatalog::ensure($w)) $newWords[] = $w;
            DB::table('cases')->where('id', $c->id)->update($new + ['updated_at' => now_str()]);
            N::audit(null, 'case-keywords', (string) ($c->code ?: $c->id));
        }
        $this->info("対象 {$n} 件（変わるもの {$changed} 件）。".($apply ? '書き換えました。' : '書き換えていません（--apply を付けると書き換えます）。'));
        // 管理者へのマスタのお知らせは、まとめて1件
        if ($apply && $newWords) {
            N::toAdmins('案件の読み直しで、新しい技術を技術マスタに追加しました', 'マスタに無い技術がありました：'.implode('、', array_unique($newWords))."\n分類（言語・フレームワーク・DB など）と別名を確かめてください。", '/admin/skills', false);
        }
        return 0;
    }
}
