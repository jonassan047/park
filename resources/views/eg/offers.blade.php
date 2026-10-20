@extends('layouts.app', ['title' => '届いた案件', 'wide' => true])
@section('content')
<x-table :head="['案件', '相性', '合う点', '金額', '開始', '状態', '']" :count="$list->count()" empty="まだ届いていません。">
@foreach($list as $o)<tr><td>{{ $o->title }}</td><td><x-score :n="$o->score" /></td><td><small>{{ $o->reasons }}</small></td><td>{{ man($o->price) }}</td><td>{{ fmt_date($o->start_date) }}</td>
<td>@if($o->case_status !== 'open' && $o->status !== 'applied')<span class="muted">募集終了</span>@else{{ badge('offerStatus', $o->status) }}@endif</td><td><a class="btn small" href="{{ url('/cases/'.$o->case_id) }}">開く</a></td></tr>@endforeach
</x-table>
@endsection
