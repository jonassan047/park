@props(['list' => [], 'hit' => null])
<span class="chips">@foreach($list as $x)<span class="chip {{ $hit && $hit($x) ? 'hit' : '' }}">{{ $x }}</span>@endforeach</span>
