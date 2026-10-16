@php $items = \App\Services\Todo::for(auth()->user()); @endphp
<x-card :title="'今やること（'.count($items).'件）'">
@if($items)<ol class="todo">@foreach($items as $t)<li><a href="{{ url($t['url']) }}"><b>{{ $t['text'] }}</b><span>→ {{ $t['do'] }}</span></a></li>@endforeach</ol>
@else<p class="muted">いま、やることはありません。</p>@endif
</x-card>
