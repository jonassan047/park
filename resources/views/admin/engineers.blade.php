@extends('layouts.app', ['title' => 'エンジニア', 'wide' => true])
@section('content')
<form method="get" class="filters"><x-field name="q" :value="$q" placeholder="名前・スキル・SES・ID で探す" />
<x-field name="status" type="select" :options="array_diff_key(PARK_LABELS['engStatus'], ['left' => 1])" blank="すべての稼働状況" :value="$st" />
<x-field name="order" type="select" :options="PARK_ORDERS" :value="$order" />
<x-field name="left" type="select" :options="['' => '退会済みを表示しない', '1' => '退会済みを表示する']" :value="show_left() ? '1' : ''" /><button class="btn ghost small">絞り込む</button>
<a class="btn ghost small" href="{{ url('/admin/skills') }}">技術マスタ</a></form>
@include('partials.mail-pick', ['part' => 'top'])
@if($list->isNotEmpty() || !$left['hidden'])
<x-table :head="['選ぶ', 'ID', '名前', 'SES', '担当者', '稼働状況', '登録状況', 'スキル', '経験票', '希望', '稼働できる日', '']" :count="$list->count()">
@foreach($list as $e)@php $egBase = '/engineers/'.$e->id; $back = ['back' => 'list', 'back_query' => request()->getQueryString()]; @endphp<tr @class(['is-left' => is_left_row('eg', $e)])><td>@if(isset($mailable[$e->id]))<input type="checkbox" class="dmpick" name="eg[]" value="{{ $e->id }}" form="dm" aria-label="{{ $e->name }} にメールを送る">@endif</td><td><x-code :v="$e->code" /></td><td>{{ $e->name }}@if($e->is_operator) <span class="badge warn">管理者</span>@endif</td><td><x-code :v="$e->ses_code" /> {{ $e->ses_name }}</td><td>@if($e->staff_name)<x-code :v="$e->staff_code" /> {{ $e->staff_name }}@else — @endif</td><td>{{ work_badge($e) }}</td><td>{{ $e->account }}<br><small class="muted">{{ $e->mail }}</small></td>
<td><small>{{ mb_substr($e->skills, 0, 60) }}</small></td><td>{{ $e->projs }}件</td><td>{{ man($e->desired_price) }}</td><td>{{ fmt_date($e->available_from) }}</td><td>@if(is_provisional_state($e->account) && $e->status !== 'left')<x-post :action="$egBase.'/invite'" label="送り直し" cls="ghost small" />
@if($e->login_id)<a class="btn ghost small" href="{{ url('/people/'.$e->login_id.'/edit') }}">仮登録の内容を直す</a>
<x-post :action="'/people/'.$e->login_id.'/cancel'" label="取り消し" cls="danger small" confirm="この仮登録を取り消します。エンジニアの行も消え、元に戻せません。よろしいですか？" />@endif @endif
@if($e->status !== 'left')@if($e->suspended_at)<x-post :action="$egBase.'/resume'" label="再開" cls="small" :fields="$back" confirm="このエンジニアを再開します。ログインでき、案件の配信の候補にも出るようになります。" />
@else<x-post :action="$egBase.'/suspend'" label="停止" cls="ghost small" :fields="$back" :confirm="engineer_stop_confirm(isset($working[$e->id]), (int) ($inSel[$e->id] ?? 0))" />@endif
<x-post :action="$egBase.'/delete'" label="退会" cls="danger small" :fields="$back" confirm="このエンジニアを退会済みにします。ログインできなくなり、元に戻せません（記録は残ります）。よろしいですか？" />@endif
<a class="btn small" href="{{ url($egBase) }}">詳細</a></td></tr>@endforeach
</x-table>
@endif
@include('partials.pager', ['pager' => $pager])
<x-status-legend kind="work" /><x-status-legend kind="account" :eg="true" />
@include('partials.left-note', $left)
@include('partials.mail-pick', ['part' => 'bottom'])
<p class="muted">「管理者」の印：管理をしながらエンジニアとしても入る人。配信・選考は記録に残し、公平に扱います（誓約書は別に用意）。</p>
<form method="post" action="{{ url('/admin/engineers/operator') }}" class="filters">@csrf
<x-field name="engineer_id" type="select" :options="$list->mapWithKeys(fn ($e) => [$e->id => id_title($e->code, $e->name)])->all()" blank="管理者の印を付け外しする人" /><button class="btn ghost small">切り替え</button></form>
@endsection
