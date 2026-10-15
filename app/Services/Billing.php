<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

// 成功報酬の計算。基準は案件の金額（SES が受け取る月額）。成約時に初期費用、契約が続く間は月額費用。率は「設定」で変えられる（仮）。
class Billing
{
    public static function rates(): array
    {
        return [
            'initial' => (float) setting('fee_initial_rate') / 100,
            'monthly' => (float) setting('fee_monthly_rate') / 100,
            'monthlyFrom' => (int) setting('monthly_from') ?: 2,
            'tax' => (float) setting('tax_rate') / 100,
        ];
    }

    // ある月（YYYY-MM）に、その SES に請求する明細
    public static function items(int $sesId, string $period): array
    {
        $r = self::rates();
        $items = [];
        $contracts = DB::table('contracts as k')->join('cases as c', 'c.id', '=', 'k.case_id')->join('engineers as e', 'e.id', '=', 'k.engineer_id')
            ->where('k.ses_id', $sesId)->select('k.*', 'c.title as case_title', 'e.name as eg_name')->get();
        foreach ($contracts as $k) {
            $startYm = substr($k->start_date, 0, 7);
            $endYm = $k->end_date ? substr($k->end_date, 0, 7) : null;
            $base = (int) round($k->price * 10000);
            $n = month_diff($startYm, $period) + 1; // 稼働の何か月目か
            if ($n === 1) $items[] = ['contract_id' => $k->id, 'type' => 'initial', 'label' => "初期費用 {$k->case_title}（{$k->eg_name}）", 'base' => $base, 'rate' => $r['initial'], 'amount' => (int) round($base * $r['initial'])];
            $active = $n >= $r['monthlyFrom'] && (!$endYm || $period <= $endYm);
            if ($n >= 1 && $active) $items[] = ['contract_id' => $k->id, 'type' => 'monthly', 'label' => "月額費用 {$k->case_title}（{$k->eg_name}・{$n}か月目）", 'base' => $base, 'rate' => $r['monthly'], 'amount' => (int) round($base * $r['monthly'])];
        }
        $subtotal = array_sum(array_column($items, 'amount'));
        $tax = (int) floor($subtotal * $r['tax']);
        return ['items' => $items, 'subtotal' => $subtotal, 'tax' => $tax, 'total' => $subtotal + $tax];
    }

    // 下書きの請求書を作る・作り直す（発行済みは変えない）
    public static function build(int $sesId, string $period): ?object
    {
        $ex = DB::table('invoices')->where('ses_id', $sesId)->where('period', $period)->first();
        if ($ex && $ex->status !== 'draft') return $ex;
        $inv = self::items($sesId, $period);
        if (!$inv['items']) { if ($ex) DB::table('invoices')->where('id', $ex->id)->delete(); return null; }
        $row = ['items_json' => json_encode($inv['items'], JSON_UNESCAPED_UNICODE), 'subtotal' => $inv['subtotal'], 'tax' => $inv['tax'], 'total' => $inv['total']];
        if ($ex) DB::table('invoices')->where('id', $ex->id)->update($row);
        else DB::table('invoices')->insert($row + ['ses_id' => $sesId, 'period' => $period, 'status' => 'draft', 'created_at' => now_str()]);
        return DB::table('invoices')->where('ses_id', $sesId)->where('period', $period)->first();
    }

    public static function monthTotal(string $period): int
    {
        $sum = 0;
        foreach (DB::table('ses_companies')->pluck('id') as $id) $sum += self::items($id, $period)['subtotal'];
        return $sum;
    }
}
