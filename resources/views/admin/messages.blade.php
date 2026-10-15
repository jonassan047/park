@extends('layouts.app', ['title' => 'やり取り（スキルシートの相談）'])
@section('content')
<x-table :head="['エンジニア', '件数', '未読', '最後', '']" :count="$list->count()">
@foreach($list as $m)<tr><td>{{ $m->name }}</td><td>{{ $m->n }}</td><td>@if($m->unread)<b class="bad-text">{{ $m->unread }}</b>@endif</td><td>{{ fmt_dt($m->last) }}</td><td><a href="{{ url('/engineers/'.$m->id.'/messages') }}">開く</a></td></tr>@endforeach
</x-table>
@endsection
