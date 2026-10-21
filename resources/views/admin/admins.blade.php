@extends('layouts.app', ['title' => '管理者', 'wide' => true])
@section('content')
<p class="right"><a class="btn small" href="{{ url('/admin/admins/new') }}">管理者を登録</a></p>
<x-table :head="['名前', '企業名', 'メール', '状態', '最終ログイン', '']" :count="$admins->count()">
@foreach($admins as $u)@php
$state = user_state($u);
$base = '/admin/admins/'.$u->id;
$dual = \App\Services\Workflow::isDual($u);
$back = ['back' => 'list', 'back_query' => request()->getQueryString()];
$canDo = $state !== '退会済' && (int) $u->id !== (int) auth()->id();
@endphp<tr><td>{{ $u->name }}@if($u->engineer_id) <a class="badge info" href="{{ url('/engineers/'.$u->engineer_id) }}">エンジニア兼任</a>@endif</td><td>{{ $u->company_name ?: '—' }}</td><td>{{ $u->email }}</td><td>{{ $state }}</td><td>{{ fmt_dt($u->last_login) }}</td>
<td>@if($u->active && !$u->password)<form method="post" action="{{ url($base.'/reinvite') }}" class="inline">@csrf<button class="btn ghost small">招待を送り直す</button></form>@endif
@if($canDo)@if($state === '停止')<x-post :action="$base.'/resume'" label="再開" cls="small" :fields="$back" :confirm="$u->name.' さんを再開します。'" />
@else<x-post :action="$base.'/suspend'" label="停止" cls="ghost small" :fields="$back" :confirm="$u->name.' さんを停止します。ログインできなくなります（再開できます）。'.($dual ? 'エンジニアとしても入れなくなります。' : '')" />@endif
<x-post :action="$base.'/delete'" label="退会" cls="danger small" :fields="$back" :confirm="$dual ? $u->name.' さんを管理者から外します。エンジニアとしては引き続き入れます。よろしいですか？' : $u->name.' さんを退会済にします。ログインできなくなり、元に戻せません（記録は残ります）。よろしいですか？'" />@endif
<a class="btn small" href="{{ url($base) }}">詳細</a></td></tr>@endforeach
</x-table>
@endsection
