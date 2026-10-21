@extends('layouts.app', ['title' => $u->name])
@section('content')
@php
    $state = user_state($u);
    $base = '/admin/admins/'.$u->id;
    $dual = \App\Services\Workflow::isDual($u);
    $canDo = $state !== '退会済' && (int) $u->id !== (int) auth()->id();
@endphp
<x-card title="管理者情報">
<x-slot:actions>@if($state !== '退会済')@if($u->active && !$u->password)<x-post :action="$base.'/reinvite'" label="招待を送り直す" cls="ghost small" />@endif
@if((int) $u->id === (int) auth()->id())<a class="btn ghost small" href="{{ url('/account#password') }}">パスワード変更</a>@endif
@if($canDo)@if($state === '停止')<x-post :action="$base.'/resume'" label="再開" cls="small" :confirm="$u->name.' さんを再開します。'" />
@else<x-post :action="$base.'/suspend'" label="停止" cls="ghost small" :confirm="$u->name.' さんを停止します。ログインできなくなります（再開できます）。'.($dual ? 'エンジニアとしても入れなくなります。' : '')" />@endif
<x-post :action="$base.'/delete'" label="退会" cls="danger small" :confirm="$dual ? $u->name.' さんを管理者から外します。エンジニアとしては引き続き入れます。よろしいですか？' : $u->name.' さんを退会済にします。ログインできなくなり、元に戻せません（記録は残ります）。よろしいですか？'" />@endif
<a class="btn small" href="{{ url($base.'/edit') }}">編集</a>@endif
</x-slot:actions>
<dl class="dl">
<dt>状態</dt><dd>{{ $state }}</dd>
<dt>氏名</dt><dd>{{ $u->name }}@if($u->engineer_id) <a class="badge info" href="{{ url('/engineers/'.$u->engineer_id) }}">エンジニア兼任</a>@endif</dd>
<dt>ふりがな</dt><dd>{{ $u->kana ?: '—' }}</dd>
<dt>企業名</dt><dd>{{ $u->company_name ?: '—' }}</dd>
<dt>所属・役職</dt><dd>{{ $u->position ?: '—' }}</dd>
<dt>電話番号</dt><dd>{{ $u->phone ?: '—' }}</dd>
<dt>住所</dt><dd>{{ postal_address($u->postal_code, $u->address) }}</dd>
<dt>メールアドレス</dt><dd>{{ $u->email }}</dd>
<dt>最終ログイン</dt><dd>{{ fmt_dt($u->last_login) }}</dd>
</dl>
<p><a class="btn ghost" href="{{ url('/admin/admins') }}">管理者の一覧に戻る</a></p>
</x-card>
@endsection
