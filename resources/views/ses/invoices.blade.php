@extends('layouts.app', ['title' => '請求'])
@section('content')
<x-card :title="'今月（'.this_month().'）の見込み'"><x-table :head="['内容', '基準額', '率', '金額']" :count="count($est['items'])" empty="今月の成功報酬はありません。">
@foreach($est['items'] as $it)<tr><td>{{ $it['label'] }}</td><td>{{ yen($it['base']) }}</td><td>{{ round($it['rate'] * 1000) / 10 }}%</td><td>{{ yen($it['amount']) }}</td></tr>@endforeach
</x-table><p>小計 {{ yen($est['subtotal']) }}／税込 {{ yen($est['total']) }}（確定は請求書で）</p></x-card>
<x-card title="請求書"><x-table :head="['月', '合計（税込）', '状態', '']" :count="$list->count()">
@foreach($list as $i)<tr><td>{{ $i->period }}</td><td>{{ yen($i->total) }}</td><td>{{ badge('invoiceStatus', $i->status) }}</td><td><a class="btn small" href="{{ url('/invoices/'.$i->id) }}">開く</a></td></tr>@endforeach
</x-table></x-card>
@endsection
