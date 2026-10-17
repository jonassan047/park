<?php

namespace App\Services;

use App\Services\Notifier as N;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

// AI の仕事の箱（2026-10-16）。
// PARKS は仕事（AI への指示と材料）を箱に入れるだけ。手元の PC の AI（tools/ai-worker）が取りに来て、結果を返す。
// 結果は下書きとして見せ、採用するかどうかは人が決める（AI は確定しない）。
class AiJobs
{
    const KINDS = ['script' => '面談スクリプトの言い回し', 'resume' => '経歴書から経験票の下書き'];
    const STATUS = ['queued' => '順番待ち', 'running' => 'AI が作業中', 'done' => '下書きができた', 'failed' => '失敗', 'applied' => '採用した', 'dropped' => '捨てた'];

    public static function latest(string $refType, int $refId, string $kind)
    {
        return DB::table('ai_jobs')->where('ref_type', $refType)->where('ref_id', $refId)->where('kind', $kind)->orderByDesc('id')->first();
    }

    private static function queue(string $kind, string $refType, int $refId, array $input, $actor): int
    {
        $id = DB::table('ai_jobs')->insertGetId(['kind' => $kind, 'ref_type' => $refType, 'ref_id' => $refId, 'input' => json_encode($input, JSON_UNESCAPED_UNICODE),
            'status' => 'queued', 'created_by' => $actor->id ?? null, 'created_at' => now_str()]);
        N::audit($actor->id ?? null, 'ai-queue', self::KINDS[$kind]." #$id");
        return $id;
    }

    // ---------- 面談スクリプトの言い回し ----------
    public static function queueScript(int $interviewId, $actor): int
    {
        $iv = DB::table('interviews')->find($interviewId);
        $a = Workflow::loadApp($iv->application_id);
        $c = DB::table('cases')->find($a->case_id);
        $n = count(json_decode($iv->qa_json ?: '[]', true));
        $prompt = "あなたは、IT エンジニアの面談の準備を手伝う担当者です。\n"
            ."次の「想定質問と答え方のヒント」の言い回しを、面談の前に本人が読みやすい、自然でわかりやすい日本語に整えてください。\n"
            ."守ること：\n- 事実を足さない。経験や数字を作らない（盛らない）。\n- 項目の数・順番・category は変えない。\n- 案件名・技術名・期間などの固有の言葉はそのまま残す。\n"
            ."- 全部の項目（{$n}個）を、同じ順番で返す。1つも省かない。\n"
            ."- 出力は JSON だけ。形：{\"items\": [{\"category\": \"...\", \"q\": \"...\", \"hint\": \"...\"}, ...]}\n\n"
            ."案件：{$c->title}\n必須：{$c->required_skills}\n\n想定質問と答え方のヒント（JSON）：\n".$iv->qa_json;
        return self::queue('script', 'interview', $interviewId, ['prompt' => $prompt, 'count' => $n], $actor);
    }

    // ---------- 経歴書から経験票の下書き ----------
    public static function fileText(object $f): ?string
    {
        $path = Storage::disk(EngineerDocs::DISK)->path($f->path);
        $ext = strtolower(pathinfo($f->filename, PATHINFO_EXTENSION));
        if (in_array($ext, ['txt', 'csv'])) return mb_convert_encoding((string) file_get_contents($path), 'UTF-8', 'UTF-8,SJIS-win');
        if ($ext === 'docx') {
            $zip = new \ZipArchive();
            if ($zip->open($path) !== true) return null;
            $xml = (string) $zip->getFromName('word/document.xml');
            $zip->close();
            return trim(html_entity_decode(strip_tags(str_replace(['</w:p>', '<w:tab/>'], ["\n", "\t"], $xml))));
        }
        if (in_array($ext, ['xlsx', 'xls'])) {
            $book = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
            $lines = [];
            foreach ($book->getAllSheets() as $ws) {
                foreach ($ws->toArray(null, true, false, false) as $row) {
                    $cells = array_filter(array_map(fn ($v) => trim((string) $v), $row), fn ($v) => $v !== '');
                    if ($cells) $lines[] = implode("\t", $cells);
                }
            }
            return implode("\n", $lines);
        }
        return null; // PDF・画像は、まだ読めない
    }

    public static function queueResume(int $engineerId, int $fileId, $actor): int
    {
        $f = DB::table('engineer_files')->where('id', $fileId)->where('engineer_id', $engineerId)->first();
        $text = $f ? self::fileText($f) : null;
        if (!$text) throw new \RuntimeException('このファイルからは文字を取り出せません（いまは Excel・Word・テキストに対応。PDF・画像はまだ）。');
        $prompt = "次は、IT エンジニアの経歴書（業務経歴書）から取り出した文字です。\n"
            ."これを案件（プロジェクト）ごとに分けて、次の JSON で出してください。\n"
            ."守ること：\n- 書かれていないことは空（\"\"）にする。推測で足さない。盛らない。\n- 期間は YYYY-MM の形。終わっていなければ period_to は空。\n"
            ."- phases は、要件定義・基本設計・詳細設計・実装・単体テスト・結合テスト・総合テスト・運用・保守 のうち、書かれているものだけ。\n"
            ."- 出力は JSON だけ。形：{\"projects\": [{\"title\": \"\", \"industry\": \"\", \"period_from\": \"\", \"period_to\": \"\", \"role\": \"\", \"team_size\": \"\", \"tech\": \"\", \"os_tools\": \"\", \"langs\": \"\", \"dbs\": \"\", \"phases\": [], \"description\": \"\", \"achievements\": \"\"}]}\n\n"
            ."経歴書の文字：\n".mb_substr($text, 0, 20000);
        return self::queue('resume', 'engineer_file', $fileId, ['prompt' => $prompt, 'engineer_id' => $engineerId], $actor);
    }

    // AI の答えから JSON を取り出す（前後に文が付いていても読む）
    public static function json(?string $out)
    {
        if (!$out) return null;
        $out = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', trim($out));
        $d = json_decode($out, true);
        if ($d !== null) return $d;
        if (preg_match('/(\[.*\]|\{.*\})/s', $out, $m)) return json_decode($m[1], true);
        return null;
    }

    // 下書きの中身（面談スクリプトは項目の並び、経歴書は案件の並び）。形が違えば null
    public static function items(object $j): ?array
    {
        $d = self::json($j->output);
        if (!is_array($d)) return null;
        if ($j->kind === 'script') {
            $list = $d['items'] ?? (array_is_list($d) ? $d : null);
            if (!is_array($list)) return null;
            $list = array_values(array_filter($list, fn ($x) => is_array($x) && !empty($x['q'])));
            $want = json_decode($j->input, true)['count'] ?? null;
            return $list && (!$want || count($list) === (int) $want) ? $list : null;
        }
        $list = $d['projects'] ?? null;
        return is_array($list) ? array_values(array_filter($list, fn ($p) => is_array($p) && !empty($p['title']))) : null;
    }

    // ---------- 手元の PC の AI（作業する側）とのやり取り ----------
    public static function next(): ?object
    {
        // 30分たっても返ってこない仕事は、もう一度順番待ちに戻す
        DB::table('ai_jobs')->where('status', 'running')->where('picked_at', '<', date('Y-m-d H:i:s', strtotime('-30 minutes')))->update(['status' => 'queued']);
        $j = DB::table('ai_jobs')->where('status', 'queued')->orderBy('id')->first();
        if (!$j) return null;
        DB::table('ai_jobs')->where('id', $j->id)->update(['status' => 'running', 'picked_at' => now_str()]);
        return $j;
    }

    public static function finish(int $id, ?string $output, ?string $error, ?string $engine): void
    {
        $j = DB::table('ai_jobs')->find($id);
        if (!$j || $j->status !== 'running') return;
        $ok = $output !== null && $error === null && self::items((object) ['kind' => $j->kind, 'input' => $j->input, 'output' => $output]) !== null;
        DB::table('ai_jobs')->where('id', $id)->update(['status' => $ok ? 'done' : 'failed', 'output' => $output, 'engine' => $engine, 'done_at' => now_str(),
            'error' => $ok ? null : ($error ?: ($j->kind === 'script' ? 'AI の答えの形が違うか、項目の数が合いませんでした。もう一度頼んでください' : 'AI の答えの形が違いました。もう一度頼んでください'))]);
        if ($ok && $j->created_by) {
            $link = $j->ref_type === 'interview' ? "/interviews/{$j->ref_id}" : '/engineers/'.(json_decode($j->input, true)['engineer_id'] ?? '').'#docs';
            N::notify((int) $j->created_by, 'AI の下書きができました：'.self::KINDS[$j->kind], '中身を確かめて、採用するか捨ててください。', $link, false, 'ai');
        }
    }
}
