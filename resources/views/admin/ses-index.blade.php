@extends('layouts.app', ['title' => 'SES'])
@section('content')
<p><a class="btn small" href="{{ url('/admin/ses/new') }}">SES を登録</a></p>
<x-table :head="['会社', '担当', '状態', 'エンジニア', '稼働中', '参加日', '']" :count="$list->count()">
@foreach($list as $s)<tr><td>{{ $s->name }}</td><td>{{ $s->contact_name }}</td><td>{{ badge('sesStatus', $s->status) }}</td><td>{{ $s->engs }}</td><td>{{ $s->working }}</td><td>{{ fmt_date($s->joined_at) }}</td><td><a href="{{ url('/admin/ses/'.$s->id) }}">詳細</a></td></tr>@endforeach
</x-table>
@if($list->isEmpty())<p class="empty">SES はまだありません。「SES を登録」から入れます。</p>@endif
@endsection
