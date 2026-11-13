@extends('layouts.app', ['title' => '応募・面談'])
@section('content')
<x-table :head="['ID', '案件', '状態', '次の面談', '応募日', '']" :count="$list->count()" empty="まだ応募はありません。">
@foreach($list as $a)<tr><td><x-code :v="$a->code" /></td><td>{{ $a->title }} <x-code :v="$a->case_code" /></td><td>{{ badge('appStatus', $a->status) }}</td><td>{{ fmt_dt($a->next_iv) }}</td><td>{{ fmt_date($a->applied_at) }}</td><td><a class="btn small" href="{{ url('/applications/'.$a->id) }}">開く</a></td></tr>@endforeach
</x-table>
@endsection
