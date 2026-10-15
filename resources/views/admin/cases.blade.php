@extends('layouts.app', ['title' => '案件', 'wide' => true])
@section('content')
<p class="tabs">@foreach(PARK_LABELS['caseStatus'] + ['all' => 'すべて'] as $k => $v)<a href="?status={{ $k }}" class="{{ $k === $st ? 'on' : '' }}">{{ $v }}</a>@endforeach
<a class="btn small" href="{{ url('/cases/new') }}">案件を登録</a></p>
<x-table :head="['案件', '持ち込み', '金額', '必須', '開始', '配信', '応募', '状態', '']" :count="$list->count()">
@foreach($list as $c)<tr><td>{{ $c->title }}</td><td>{{ $c->ses_name ?: '運営' }}</td><td>{{ man($c->price) }}</td><td><small>{{ implode('、', parse_list($c->required_skills)) }}</small></td>
<td>{{ fmt_date($c->start_date) }}</td><td>{{ $c->offers }}</td><td>{{ $c->apps }}</td><td>{{ badge('caseStatus', $c->status) }}</td><td><a href="{{ url('/cases/'.$c->id) }}">開く</a></td></tr>@endforeach
</x-table>
@endsection
