@php $u = auth()->user(); @endphp
@extends('layouts.app', ['title' => '請求書 '.$inv->period.' '.$inv->ses_name, 'print' => true, 'noTitle' => true])
@section('content')
<div class="noprint toolbar"><a href="{{ url($u->role === 'admin' ? '/admin/invoices' : '/ses/invoices') }}">← 戻る</a> <button class="btn small" onclick="print()">印刷・PDF に保存</button>
@if($u->role === 'admin' && $inv->status === 'draft')<x-post :action="'/admin/invoices/'.$inv->id.'/issue'" label="発行して SES に知らせる" cls="small" />@endif
@if($u->role === 'admin' && $inv->status === 'issued')<x-post :action="'/admin/invoices/'.$inv->id.'/paid'" label="入金を記録" cls="small" />@endif
{{ badge('invoiceStatus', $inv->status) }}</div>
<article class="sheet invoice"><header><h1>ご請求書</h1><div>{{ $inv->period }} 分　発行日 {{ $inv->issued_at ? fmt_date($inv->issued_at) : today_str() }}</div></header>
<p class="to">{{ $inv->ses_name }} 御中</p><p>下記のとおりご請求申し上げます。</p>
<p class="total">ご請求金額　{{ yen($inv->total) }}（税込）</p>
<table class="grid"><thead><tr><th>内容</th><th>基準額</th><th>率</th><th>金額</th></tr></thead><tbody>
@foreach($items as $it)<tr><td>{{ $it['label'] }}</td><td class="r">{{ yen($it['base']) }}</td><td class="r">{{ round($it['rate'] * 1000) / 10 }}%</td><td class="r">{{ yen($it['amount']) }}</td></tr>@endforeach
<tr><td colspan="3" class="r">小計</td><td class="r">{{ yen($inv->subtotal) }}</td></tr>
<tr><td colspan="3" class="r">消費税（{{ setting('tax_rate') }}%）</td><td class="r">{{ yen($inv->tax) }}</td></tr>
<tr><td colspan="3" class="r"><b>合計</b></td><td class="r"><b>{{ yen($inv->total) }}</b></td></tr></tbody></table>
<p class="muted">成功報酬（成約したときだけ）：初期費用は稼働開始の月、月額費用は契約が続く間。基準は案件の金額（月額）です。</p>
<footer>{{ setting('operator_name') }}</footer></article>
@endsection
