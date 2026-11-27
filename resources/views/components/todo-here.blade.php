@props(['area'])
{{-- SES の「今やること」のうち、この画面（タブ）に関わるもの。無ければ何も出さない（2026-11-28 TEZZO：ダッシュボードから各タブへ） --}}
@php $items = \App\Services\Todo::sesArea(auth()->user(), $area); @endphp
@if($items)<x-card :title="'やること（'.count($items).'件）'"><ol class="todo">@foreach($items as $t)<li><a href="{{ url($t['url']) }}"><b>{{ $t['text'] }}</b><span>→ {{ $t['do'] }}</span></a></li>@endforeach</ol></x-card>@endif
