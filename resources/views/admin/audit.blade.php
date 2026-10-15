@extends('layouts.app', ['title' => '操作の記録', 'wide' => true])
@section('content')
<x-table :head="['日時', '人', '操作', '内容']" :count="$list->count()">
@foreach($list as $l)<tr><td>{{ fmt_dt($l->at) }}</td><td>{{ $l->name ?: '—' }}</td><td>{{ $l->action }}</td><td>{{ $l->detail }}</td></tr>@endforeach
</x-table>
@endsection
