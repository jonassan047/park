@extends('layouts.app', ['title' => '通知'])
@section('content')
@if($list->whereNull('read_at')->count())<x-post action="/notifications/read-all" label="すべて既読にする" cls="ghost" />@endif
<x-table :head="['', '日時', '内容']" :count="$list->count()" empty="通知はありません。">
@foreach($list as $n)<tr><td>@unless($n->read_at)<span class="dot"></span>@endunless</td><td>{{ fmt_dt($n->created_at) }}</td>
<td><a href="{{ url('/notifications/'.$n->id) }}"><b>{{ $n->title }}</b></a><div class="muted clamp">{{ $n->body }}</div></td></tr>@endforeach
</x-table>
@endsection
