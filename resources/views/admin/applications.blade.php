@extends('layouts.app', ['title' => '応募・選考', 'wide' => true])
@section('content')
<div class="board">@foreach($lanes as $st)@php $in = $list->where('status', $st); @endphp
<div class="lane"><h3>{{ lbl('appStatus', $st) }} <span>{{ $in->count() }}</span></h3>
@foreach($in as $a)<a class="ticket" href="{{ url('/applications/'.$a->id) }}"><b>{{ $a->eg_name }}</b><span>{{ $a->title }}</span><small>{{ $a->ses_name }}・{{ fmt_date($a->updated_at) }}</small></a>@endforeach
</div>@endforeach</div>
@php $done = $list->whereNotIn('status', $lanes); @endphp
<x-card title="終わったもの"><x-table :head="['エンジニア', '案件', '状態', '理由', '更新', '']" :count="$done->count()">
@foreach($done as $a)<tr><td>{{ $a->eg_name }}</td><td>{{ $a->title }}</td><td>{{ badge('appStatus', $a->status) }}</td><td>{{ $a->reject_reason }}</td><td>{{ fmt_date($a->updated_at) }}</td><td><a class="btn small" href="{{ url('/applications/'.$a->id) }}">開く</a></td></tr>@endforeach
</x-table></x-card>
@endsection
