<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

// エンジニアごとのデータ領域：最新の履歴書・業務経歴書を Excel で書き出して保存する。アップロードした書類もここに置く。
// 置き場所は storage/app/private/engineers/{id}/（公開されない）。ダウンロードは画面から、本人・所属 SES・管理者だけ。
class EngineerDocs
{
    const KINDS = ['resume' => '履歴書', 'careersheet' => '業務経歴書', 'other' => 'その他の書類'];
    const DISK = 'local';

    public static function dir(int $engineerId): string { return "engineers/{$engineerId}"; }

    private static function age($e): ?int
    {
        if ($e->birth_date) return (int) date_diff(date_create($e->birth_date), date_create('today'))->y;
        return $e->birth_year ? (int) date('Y') - (int) $e->birth_year : null;
    }

    private static function ymText(?string $ym): string
    {
        if (!$ym) return '';
        [$y, $m] = array_pad(explode('-', $ym), 2, '');
        return $m ? $y.'年'.(int) $m.'月' : "{$y}年";
    }

    // 期間（YYYY-MM〜YYYY-MM）の長さ「1年5月」
    private static function span(?string $from, ?string $to): string
    {
        if (!$from) return '';
        $n = month_diff($from, $to ?: date('Y-m')) + 1;
        return intdiv($n, 12).'年'.($n % 12).'月';
    }

    private static function box(Worksheet $ws, string $range, bool $head = false): void
    {
        $st = $ws->getStyle($range);
        $st->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('8FB9A0');
        $st->getAlignment()->setVertical(Alignment::VERTICAL_TOP)->setWrapText(true);
        if ($head) {
            $st->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E1F0E8');
            $st->getFont()->setBold(true);
            $st->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        }
    }

    private static function book(string $title): array
    {
        $book = new Spreadsheet();
        $book->getDefaultStyle()->getFont()->setName('メイリオ')->setSize(9);
        $book->getProperties()->setCreator('PARK')->setTitle($title);
        $ws = $book->getActiveSheet();
        $ws->setTitle($title);
        $ws->getPageSetup()->setPaperSize(PageSetup::PAPERSIZE_A4)->setFitToWidth(1)->setFitToHeight(0);
        $ws->getPageMargins()->setLeft(0.4)->setRight(0.4)->setTop(0.5)->setBottom(0.5);
        return [$book, $ws];
    }

    // ---------- 業務経歴書（スキルシート） ----------
    public static function careersheet(int $engineerId, bool $anon = false): Spreadsheet
    {
        $e = DB::table('engineers')->find($engineerId);
        $skills = DB::table('engineer_skills')->where('engineer_id', $engineerId)->orderBy('category')->orderByDesc('years')->get()->groupBy('category');
        $certs = DB::table('engineer_certs')->where('engineer_id', $engineerId)->orderBy('sort')->get();
        $projects = DB::table('engineer_projects')->where('engineer_id', $engineerId)->orderByRaw('sort is null, sort')->orderByDesc('period_from')->get();
        [$book, $ws] = self::book('業務経歴書');
        foreach (['A' => 5, 'B' => 18, 'C' => 46, 'D' => 14, 'E' => 13, 'F' => 18, 'G' => 14, 'H' => 14] as $c => $w) $ws->getColumnDimension($c)->setWidth($w);

        $ws->setCellValue('A1', '業 務 経 歴 書')->mergeCells('A1:F1');
        $ws->getStyle('A1')->getFont()->setSize(16)->setBold(true);
        $ws->setCellValue('G1', date('Y年n月j日').' 現在')->mergeCells('G1:H1');
        $ws->getStyle('G1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

        $name = $anon ? ($e->initials ?: 'イニシャル未設定') : $e->name;
        $age = self::age($e);
        $station = trim(($e->nearest_line ? $e->nearest_line.' ' : '').($e->nearest_station ? $e->nearest_station.'駅' : '').($e->walk_minutes ? '（徒歩'.$e->walk_minutes.'分）' : ''));
        $info = [
            ['フリガナ', $anon ? '' : mb_convert_kana((string) $e->kana, 'KC'), '性別', $e->gender],
            ['氏名', $name, '年齢', $age !== null ? $age.'歳' : ''],
            ['最寄り駅', $station, '経験年数', $e->years_exp ? num_disp($e->years_exp).'年' : ''],
            ['最終学歴', $e->education, '卒業年月', self::ymText($e->graduated_ym)],
        ];
        if (!$anon) $info[] = ['現住所', $e->address, '稼働開始', $e->available_from ? fmt_date($e->available_from) : ''];
        $r = 3;
        foreach ($info as [$k1, $v1, $k2, $v2]) {
            $ws->setCellValue("A$r", $k1)->mergeCells("A$r:B$r");
            $ws->setCellValue("C$r", (string) $v1)->mergeCells("C$r:E$r");
            $ws->setCellValue("F$r", $k2);
            $ws->setCellValue("G$r", (string) $v2)->mergeCells("G$r:H$r");
            self::box($ws, "A$r:H$r");
            foreach (["A$r", "F$r"] as $h) self::box($ws, $h, true);
            $r++;
        }
        $skillText = [];
        foreach ($skills as $cat => $list) $skillText[] = '【'.lbl('skillCat', $cat).'】'.implode('、', $list->map(fn ($s) => $s->name.($s->years ? '（'.num_disp($s->years).'年）' : ''))->all());
        $blocks = [
            ['職務概要', $e->career_summary ?: $e->summary],
            ['保有スキル', implode("\n", $skillText)],
            ['希望業務', $e->desired_roles],
            ['資格', implode("\n", $certs->map(fn ($c) => trim($c->name.'　'.self::ymText($c->ym)))->all())],
        ];
        if ($e->english_level) $blocks[] = ['語学', $e->english_level];
        foreach ($blocks as [$k, $v]) {
            $ws->setCellValue("A$r", $k)->mergeCells("A$r:B$r");
            $ws->setCellValue("C$r", (string) $v)->mergeCells("C$r:H$r");
            self::box($ws, "A$r:H$r");
            self::box($ws, "A$r", true);
            $lines = max(1, substr_count((string) $v, "\n") + 1, (int) ceil(mb_strwidth((string) $v) / 110));
            $ws->getRowDimension($r)->setRowHeight(14 * $lines + 4);
            $r++;
        }

        $r++;
        $heads = ['No.', '期間', '作業内容と担当分野', '工程', '役割／規模', '機種・OS・ツール', '言語', 'DB'];
        foreach ($heads as $i => $h) $ws->setCellValue(chr(65 + $i).$r, $h);
        self::box($ws, "A$r:H$r", true);
        $ws->getStyle("A$r:H$r")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $r++;
        foreach ($projects as $i => $p) {
            $period = self::ymText($p->period_from).'〜'.($p->period_to ? self::ymText($p->period_to) : '現在')."\n（".self::span($p->period_from, $p->period_to).'）';
            $content = $p->title.($p->industry ? "（{$p->industry}）" : '')."\n".trim((string) $p->description).($p->achievements ? "\n【成果】".$p->achievements : '');
            $role = trim(($p->role ?: '').($p->team_size ? "\n{$p->team_size}名" : ''));
            $row = [$i + 1, $period, $content, str_replace('、', "\n", (string) $p->phases), $role, str_replace('、', "\n", (string) ($p->os_tools ?: $p->tech)),
                str_replace('、', "\n", (string) $p->langs), str_replace('、', "\n", (string) $p->dbs)];
            foreach ($row as $j => $v) $ws->setCellValue(chr(65 + $j).$r, (string) $v);
            self::box($ws, "A$r:H$r");
            $lines = max(3, substr_count($content, "\n") + 1 + (int) floor(mb_strwidth($content) / 90));
            $ws->getRowDimension($r)->setRowHeight(13 * $lines + 6);
            $r++;
        }
        $ws->setCellValue("A$r", '以上')->mergeCells("A$r:H$r");
        $ws->getStyle("A$r")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        return $book;
    }

    // ---------- 履歴書 ----------
    public static function resume(int $engineerId): Spreadsheet
    {
        $e = DB::table('engineers')->find($engineerId);
        $hist = DB::table('engineer_histories')->where('engineer_id', $engineerId)->orderBy('sort')->get();
        $certs = DB::table('engineer_certs')->where('engineer_id', $engineerId)->orderBy('sort')->get();
        [$book, $ws] = self::book('履歴書');
        foreach (['A' => 9, 'B' => 6, 'C' => 40, 'D' => 14, 'E' => 22] as $c => $w) $ws->getColumnDimension($c)->setWidth($w);

        $ws->setCellValue('A1', '履 歴 書')->mergeCells('A1:C1');
        $ws->getStyle('A1')->getFont()->setSize(18)->setBold(true);
        $ws->setCellValue('D1', date('Y年n月j日').' 現在')->mergeCells('D1:E1');
        $ws->getStyle('D1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $age = self::age($e);
        $birth = $e->birth_date ? date('Y年n月j日', strtotime($e->birth_date)).'生' : ($e->birth_year ? $e->birth_year.'年生' : '');
        $rows = [
            ['ふりがな', mb_convert_kana((string) $e->kana, 'c'), '性別', $e->gender],
            ['氏名', $e->name, '生年月日', trim($birth.($age !== null ? "（満{$age}歳）" : ''))],
            ['ふりがな', (string) $e->address_kana, '電話', $e->phone],
            ['現住所', trim(($e->postal_code ? '〒'.$e->postal_code."\n" : '').$e->address), 'E-mail', $e->email],
        ];
        $r = 3;
        foreach ($rows as [$k1, $v1, $k2, $v2]) {
            $ws->setCellValue("A$r", $k1)->mergeCells("A$r:B$r");
            $ws->setCellValue("C$r", (string) $v1);
            $ws->setCellValue("D$r", $k2);
            $ws->setCellValue("E$r", (string) $v2);
            self::box($ws, "A$r:E$r");
            foreach (["A$r", "D$r"] as $h) self::box($ws, $h, true);
            if ($k1 === '氏名') $ws->getStyle("C$r")->getFont()->setSize(14)->setBold(true);
            if ($k1 === '現住所') $ws->getRowDimension($r)->setRowHeight(32);
            $r++;
        }

        $section = function (string $title, $items) use ($ws, &$r) {
            $r++;
            $ws->setCellValue("A$r", '年')->setCellValue("B$r", '月')->setCellValue("C$r", $title)->mergeCells("C$r:E$r");
            self::box($ws, "A$r:E$r", true);
            $ws->getStyle("A$r:E$r")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $r++;
            foreach ($items as $it) {
                [$y, $m] = array_pad(explode('-', (string) ($it[0] ?? '')), 2, '');
                $ws->setCellValue("A$r", $y)->setCellValue("B$r", $m ? (int) $m : '')->setCellValue("C$r", (string) $it[1])->mergeCells("C$r:E$r");
                self::box($ws, "A$r:E$r");
                if (!empty($it[2])) $ws->getStyle("C$r")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $ws->getRowDimension($r)->setRowHeight(max(16, 14 * (1 + (int) floor(mb_strwidth((string) $it[1]) / 95))));
                $r++;
            }
        };
        $items = [['', '学歴', true]];
        foreach ($hist->where('kind', 'edu') as $h) $items[] = [$h->ym, $h->text];
        $items[] = ['', '職歴', true];
        foreach ($hist->where('kind', 'work') as $h) $items[] = [$h->ym, $h->text];
        $items[] = ['', '以上', true];
        $section('学歴・職歴', $items);
        $section('資格・免許', $certs->map(fn ($c) => [$c->ym, $c->name])->all() ?: [['', '']]);

        foreach ([['志望の動機、特技、好きな学科、アピールポイントなど', $e->appeal ?: $e->summary], ['本人希望記入欄', $e->wishes]] as [$k, $v]) {
            $r++;
            $ws->setCellValue("A$r", $k)->mergeCells("A$r:E$r");
            self::box($ws, "A$r:E$r", true);
            $r++;
            $ws->setCellValue("A$r", (string) $v)->mergeCells("A$r:E$r");
            self::box($ws, "A$r:E$r");
            $ws->getRowDimension($r)->setRowHeight(max(48, 14 * (substr_count((string) $v, "\n") + 2 + (int) floor(mb_strwidth((string) $v) / 100))));
            $r++;
        }
        return $book;
    }

    // 書き出して、データ領域に保存する（版ごとに残す）
    public static function generate(int $engineerId, string $kind, $actor, bool $anon = false): int
    {
        $e = DB::table('engineers')->find($engineerId);
        $book = $kind === 'resume' ? self::resume($engineerId) : self::careersheet($engineerId, $anon);
        $label = self::KINDS[$kind].($anon ? '（名前を伏せた版）' : '');
        $who = $anon ? ($e->initials ?: 'anon') : $e->name;
        $filename = date('Ymd').'_'.$label.'_'.$who.'.xlsx';
        $path = self::dir($engineerId).'/'.date('Ymd_His').'_'.$kind.($anon ? '_anon' : '').'.xlsx';
        $tmp = tempnam(sys_get_temp_dir(), 'park');
        (new Xlsx($book))->save($tmp);
        Storage::disk(self::DISK)->put($path, file_get_contents($tmp));
        $size = filesize($tmp);
        @unlink($tmp);
        $id = DB::table('engineer_files')->insertGetId(['engineer_id' => $engineerId, 'kind' => $kind, 'title' => $label.'（PARK で書き出し）', 'filename' => $filename,
            'path' => $path, 'size' => $size, 'generated' => true, 'uploaded_by' => $actor->id ?? null, 'created_at' => now_str()]);
        Notifier::audit($actor->id ?? null, 'doc-generate', "{$e->name}：{$label}");
        return $id;
    }

    public static function store(int $engineerId, \Illuminate\Http\UploadedFile $file, string $kind, string $title, $actor): int
    {
        $ext = strtolower($file->getClientOriginalExtension());
        $path = $file->storeAs(self::dir($engineerId), date('Ymd_His').'_upload_'.bin2hex(random_bytes(4)).'.'.$ext, self::DISK);
        $id = DB::table('engineer_files')->insertGetId(['engineer_id' => $engineerId, 'kind' => isset(self::KINDS[$kind]) ? $kind : 'other',
            'title' => $title ?: $file->getClientOriginalName(), 'filename' => $file->getClientOriginalName(), 'path' => $path, 'size' => $file->getSize(),
            'generated' => false, 'uploaded_by' => $actor->id ?? null, 'created_at' => now_str()]);
        Notifier::audit($actor->id ?? null, 'doc-upload', $file->getClientOriginalName());
        return $id;
    }
}
