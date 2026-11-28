@props(['kind', 'eg' => false])
{{-- 状況の言葉の欄外の説明（参加状況＝ses／登録状況＝account／稼働状況＝work）。値は <b> で出し、一覧の行の札（.badge）とは見分ける --}}
@php
    $legend = PARK_STATUS_LEGEND[$kind] ?? null;
    $rows = $legend ? $legend[1] : [];
    if ($kind === 'account' && $eg) $rows = array_merge($rows, PARK_STATUS_LEGEND['account_eg'][1]);
@endphp
@if($legend)
<div class="status-legend noprint"><p class="muted"><b>{{ $legend[0] }}</b>とは</p><ul>@foreach($rows as [$v, $d])<li><b>{{ $v }}</b>：{{ $d }}</li>@endforeach</ul></div>
@endif
