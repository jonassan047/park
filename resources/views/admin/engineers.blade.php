@extends('layouts.app', ['title' => 'エンジニア', 'wide' => true])
@section('content')
<form method="get" class="filters"><x-field name="q" :value="$q" placeholder="名前・スキル・SES で探す" />
<x-field name="status" type="select" :options="PARK_LABELS['engStatus']" blank="すべての状態" :value="$st" /><button class="btn ghost small">絞り込む</button>
<input type="hidden" name="ses" value="{{ $direct ? 'direct' : '' }}">
@if($hasDirect || $direct)<a class="btn ghost small" href="{{ url('/admin/engineers'.($direct ? '' : '?ses=direct')) }}">{{ $direct ? 'すべて表示' : '直属だけ' }}</a> @endif<a class="btn ghost small" href="{{ url('/admin/skills') }}">技術マスタ</a></form>
<x-table :head="['名前', 'SES', '状態', 'スキル', '経験票', '希望', '稼働できる日', '']" :count="$list->count()">
@foreach($list as $e)<tr><td>{{ $e->name }}@if($e->is_operator) <span class="badge warn">管理者</span>@endif</td><td>@if($e->ses_kind === 'direct')<span class="badge info">直属</span>@else{{ $e->ses_name }}@endif</td><td>{{ badge('engStatus', $e->status) }}</td>
<td><small>{{ mb_substr($e->skills, 0, 60) }}</small></td><td>{{ $e->projs }}件</td><td>{{ man($e->desired_price) }}</td><td>{{ fmt_date($e->available_from) }}</td><td><a class="btn small" href="{{ url('/engineers/'.$e->id) }}">詳細</a></td></tr>@endforeach
</x-table>
<p class="muted">「管理者」の印：管理をしながらエンジニアとしても入る人。配信・選考は記録に残し、公平に扱います（誓約書は別に用意）。</p>
<form method="post" action="{{ url('/admin/engineers/operator') }}" class="filters">@csrf
<x-field name="engineer_id" type="select" :options="$list->pluck('name', 'id')->all()" blank="管理者の印を付け外しする人" /><button class="btn ghost small">切り替え</button></form>
@endsection
