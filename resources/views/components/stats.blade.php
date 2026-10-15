@props(['items'])
<div class="stats">@foreach($items as $it)<div class="stat"><span>{{ $it[0] }}</span><b>{{ $it[1] }}</b>@if(!empty($it[2]))<small>{{ $it[2] }}</small>@endif</div>@endforeach</div>
