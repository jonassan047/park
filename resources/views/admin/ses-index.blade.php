@extends('layouts.app', ['title' => 'SES'])
@section('content')
<p><a class="btn small" href="{{ url('/admin/ses/new') }}">SES を登録</a></p>
<x-table :head="['会社', '担当', '状態', 'エンジニア', '稼働中', '参加日', '']" :count="$list->count() + 1">
@foreach($list as $s)<tr><td>{{ $s->name }}</td><td>{{ $s->contact_name }}</td><td>{{ badge('sesStatus', $s->status) }}</td><td>{{ $s->engs }}</td><td>{{ $s->working }}</td><td>{{ fmt_date($s->joined_at) }}</td><td><a href="{{ url('/admin/ses/'.$s->id) }}">詳細</a></td></tr>@endforeach
<tr><td>管理者の直属（SES なし）</td><td>—</td><td>—</td><td>{{ $direct->engs }}</td><td>—</td><td>—</td><td><a href="{{ url('/engineers/new?ses=direct') }}">エンジニアを登録</a> <a href="{{ url('/admin/engineers?ses=direct') }}">一覧</a></td></tr>
</x-table>
@if($list->isEmpty())<p class="empty">SES はまだありません。「SES を登録」から入れます。</p>@endif
@endsection
