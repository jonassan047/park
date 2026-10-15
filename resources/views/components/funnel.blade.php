@props(['rows'])
@php $max = max(1, $rows[0][1] ?? 1); @endphp
<div class="funnel">@foreach($rows as [$label, $n, $rate])<div class="frow"><span>{{ $label }}</span><div class="fbar"><i style="width:{{ $n / $max * 100 }}%"></i></div><b>{{ $n }}</b><small>{{ $rate }}</small></div>@endforeach</div>
