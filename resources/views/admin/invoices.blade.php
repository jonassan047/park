@extends('layouts.app', ['title' => '請求'])
@section('content')
<x-card title="請求書を作る"><form method="post" action="{{ url('/admin/invoices/build') }}">@csrf<div class="grid2">
<x-field label="対象の月" name="period" type="month" :value="$period" required /><div><button class="btn">この月の請求書（下書き）を作る</button></div></div>
<p class="muted">稼働中・その月に始まった契約から、初期費用 {{ setting('fee_initial_rate') }}%（稼働開始の月）と月額 {{ setting('fee_monthly_rate') }}%（{{ setting('monthly_from') }}か月目から）を計算します。発行済みのものは変えません。</p></form></x-card>
<x-table :head="['月', 'SES', '小計', '合計（税込）', '状態', '']" :count="$list->count()">
@foreach($list as $i)<tr><td>{{ $i->period }}</td><td>{{ $i->ses_name }}</td><td>{{ yen($i->subtotal) }}</td><td>{{ yen($i->total) }}</td><td>{{ badge('invoiceStatus', $i->status) }}</td><td><a class="btn small" href="{{ url('/invoices/'.$i->id) }}">開く</a></td></tr>@endforeach
</x-table>
@endsection
