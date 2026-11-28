@extends('layouts.app', ['title' => id_title($s->code, $s->name), 'wide' => true])
@section('content')
{{-- 2026-11-28 TEZZO：ダッシュボード・企業情報と同じく、企業名の下に帯 --}}
<div class="role-hero slim" style="background-image:url('{{ asset('img/hero.jpg') }}')"><p>エンジニア情報</p></div>
<p class="right"><a class="btn small" href="{{ url('/engineers/new') }}">エンジニアを登録</a></p>
@include('partials.left-toggle', ['orders' => array_intersect_key(PARK_ORDERS, ['reg' => 1, 'kana' => 1])])
@include('partials.mail-pick', ['part' => 'top'])
@if($list->isNotEmpty() || !$left['hidden'])
{{-- 並びは TEZZO の指定の表（2026-11-28）：1人を2段にする --}}
@if($list->count())
<div class="tablewrap"><table class="eg-list">
<thead><tr><th rowspan="2">EG-ID</th><th colspan="2">氏名</th><th>Eメール</th><th>担当者ID</th><th rowspan="2">スキル</th><th>実績</th><th>希望額</th><th>稼働可能日</th><th rowspan="2"></th></tr>
<tr><th>稼働状況</th><th>年齢</th><th>登録状況</th><th>担当者</th><th>応募</th><th>最低額</th><th>住所</th></tr></thead>
@foreach($list as $e)@php $egBase = '/engineers/'.$e->id; $back = ['back' => 'list', 'back_query' => request()->getQueryString()]; $gone = is_left_row('eg', $e); @endphp
<tbody><tr @class(['is-left' => $gone])><td rowspan="2">@if(isset($mailable[$e->id]))<input type="checkbox" class="dmpick" name="eg[]" value="{{ $e->id }}" form="dm" aria-label="{{ $e->name }} にメールを送る"> @endif<x-code :v="$e->code" /></td>
<td colspan="2">{{ $e->name }}</td><td class="mail">{{ $e->mail ?: '—' }}</td><td><x-code :v="$e->staff_code" /></td>
<td rowspan="2" class="skills"><small>{{ mb_substr($e->skills, 0, 100) ?: '—' }}</small></td>
<td>{{ $e->wins }}件</td><td>{{ man($e->desired_price) }}</td><td>{{ fmt_date($e->available_from) }}</td>
<td rowspan="2" class="ops"><div>@if(is_provisional_state($e->account) && $e->status !== 'left')<x-post :action="$egBase.'/invite'" label="送り直し" cls="ghost small" />
@if($e->login_id)<a class="btn ghost small" href="{{ url('/people/'.$e->login_id.'/edit') }}">仮登録の内容を直す</a>
<x-post :action="'/people/'.$e->login_id.'/cancel'" label="取り消し" cls="danger small" confirm="この仮登録を取り消します。エンジニアの行も消え、元に戻せません。よろしいですか？" />@endif @endif
@if($e->status !== 'left')@if($e->suspended_at)<x-post :action="$egBase.'/resume'" label="再開" cls="small" :fields="$back" confirm="このエンジニアを再開します。ログインでき、案件の配信の候補にも出るようになります。" />
@else<x-post :action="$egBase.'/suspend'" label="停止" cls="ghost small" :fields="$back" :confirm="engineer_stop_confirm(isset($working[$e->id]), (int) ($inSel[$e->id] ?? 0))" />@endif
<x-post :action="$egBase.'/delete'" label="退会" cls="danger small" :fields="$back" confirm="このエンジニアを退会済みにします。ログインできなくなり、元に戻せません（記録は残ります）。よろしいですか？" />@endif
<a class="btn small" href="{{ url($egBase) }}">詳細</a></div></td></tr>
<tr @class(['is-left' => $gone])><td>{{ work_badge($e) }}</td><td>{{ age_text($e->birth_year) ?: '—' }}</td><td>{{ $e->account }}</td><td>{{ $e->staff_name ?: '—' }}</td>
<td>{{ $e->apps }}件</td><td>{{ man($e->min_price) }}</td><td class="addr">{{ $e->address ?: '—' }}</td></tr></tbody>
@endforeach
</table></div>
@else
<p class="empty">まだありません。</p>
@endif
@endif
@include('partials.pager', ['pager' => $pager])
<x-status-legend kind="work" /><x-status-legend kind="account" :eg="true" />
@include('partials.left-note', $left)
@include('partials.mail-pick', ['part' => 'bottom'])
@endsection
