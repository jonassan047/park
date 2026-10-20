@props(['items'])
<div class="stats">@foreach($items as $it)@if(!empty($it[3]))<a class="stat link" href="{{ url($it[3]) }}">@else<div class="stat">@endif<span>{{ $it[0] }}</span><b>{{ $it[1] }}</b>@if(!empty($it[2]))<small>{{ $it[2] }}</small>@endif @if(!empty($it[3]))</a>@else</div>@endif @endforeach</div>
