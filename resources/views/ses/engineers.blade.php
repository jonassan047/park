@extends('layouts.app', ['title' => 'エンジニア', 'wide' => true])
@section('content')
<p><a class="btn small" href="{{ url('/engineers/new') }}">エンジニアを登録</a></p>
<x-table :head="['名前', '状態', 'スキル', '経験票', '希望', '稼働できる日', 'アカウント', '']" :count="$list->count()">
@foreach($list as $e)<tr><td>{{ $e->name }}</td><td>{{ badge('engStatus', $e->status) }}</td><td><small>{{ mb_substr($e->skills, 0, 50) }}</small></td><td>{{ $e->projs }}件</td>
<td>{{ man($e->desired_price) }}</td><td>{{ fmt_date($e->available_from) }}</td><td>{{ $e->account }}</td><td><a class="btn small" href="{{ url('/engineers/'.$e->id) }}">詳細</a></td></tr>@endforeach
</x-table>
@endsection
