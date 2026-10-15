@extends('layouts.app', ['title' => '案件', 'wide' => true])
@section('content')
<p class="tabs"><a href="?" class="{{ $mine ? '' : 'on' }}">募集中（グループ全体）</a><a href="?mine=1" class="{{ $mine ? 'on' : '' }}">自社が登録した案件</a>
<a class="btn small" href="{{ url('/cases/new') }}">案件を登録</a></p>
<p class="muted">元請けから届いた案件を登録すると、グループ全体のエンジニアから合う人を選べます。自社だけでは埋められない案件も、仲間のエンジニアで埋められます。</p>
<x-table :head="['案件', '金額', '必須', '開始', '貴社で一番合う人', '状態', '']" :count="$list->count()">
@foreach($list as $c)<tr><td>{{ $c->title }}</td><td>{{ man($c->price) }}</td><td><small>{{ implode('、', parse_list($c->required_skills)) }}</small></td><td>{{ fmt_date($c->start_date) }}</td>
<td>{{ $c->best }}</td><td>{{ badge('caseStatus', $c->status) }}</td><td><a href="{{ url('/cases/'.$c->id) }}">開く</a></td></tr>@endforeach
</x-table>
@endsection
