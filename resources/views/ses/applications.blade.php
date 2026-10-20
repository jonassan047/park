@extends('layouts.app', ['title' => '応募・選考', 'wide' => true])
@section('content')
<x-card title="貴社の応募 → 成約"><x-funnel :rows="$funnel" /></x-card>
<x-table :head="['エンジニア', '案件', '状態', '応募日', '更新', '']" :count="$list->count()">
@foreach($list as $a)<tr><td>{{ $a->eg_name }}</td><td>{{ $a->title }}</td><td>{{ badge('appStatus', $a->status) }}</td><td>{{ fmt_date($a->applied_at) }}</td><td>{{ fmt_date($a->updated_at) }}</td><td><a class="btn small" href="{{ url('/applications/'.$a->id) }}">開く</a></td></tr>@endforeach
</x-table>
@endsection
