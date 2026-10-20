@extends('layouts.app', ['title' => $u->name])
@section('content')
@php
    $state = !$u->active ? '停止' : ($u->password ? '使用中' : '招待中');
@endphp
<x-card title="管理者情報">
<x-slot:actions>@if($u->active && !$u->password)<x-post :action="'/admin/admins/'.$u->id.'/reinvite'" label="招待を送り直す" cls="ghost small" />@endif
@if((int) $u->id === (int) auth()->id())<a class="btn ghost small" href="{{ url('/account#password') }}">パスワード変更</a>@endif
<a class="btn small" href="{{ url('/admin/admins/'.$u->id.'/edit') }}">編集</a>
</x-slot:actions>
<dl class="dl">
<dt>状態</dt><dd>{{ $state }}</dd>
<dt>氏名</dt><dd>{{ $u->name }}</dd>
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
