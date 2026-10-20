@extends('layouts.app', ['title' => '契約', 'wide' => true])
@section('content')
<x-table :head="['案件', 'エンジニア', '金額', '期間', '延長', '状態', '']" :count="$list->count()">
@foreach($list as $k)<tr><td>{{ $k->title }}</td><td>{{ $k->eg_name }}</td><td>{{ man($k->price) }}</td><td>{{ fmt_date($k->start_date) }}〜{{ fmt_date($k->end_date) }}</td>
<td>{{ $k->extensions }}回</td><td>{{ badge('contractStatus', $k->status) }}</td><td><a class="btn small" href="{{ url('/contracts/'.$k->id) }}">開く</a></td></tr>@endforeach
</x-table>
@endsection
