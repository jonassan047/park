@extends('layouts.app', ['title' => '管理者', 'wide' => true])
@section('content')
<x-card title="管理者"><x-table :head="['名前', '企業名', 'メール', '状態', '最終ログイン', '']" :count="$admins->count()">
@foreach($admins as $u)<tr><td>{{ $u->name }}</td><td>{{ $u->company_name ?: '—' }}</td><td>{{ $u->email }}</td><td>{{ !$u->active ? '停止' : ($u->password ? '使用中' : '招待中') }}</td><td>{{ fmt_dt($u->last_login) }}</td>
<td><a class="btn ghost small" href="{{ url('/admin/admins/'.$u->id) }}">直す</a>
@if($u->active && !$u->password)<form method="post" action="{{ url('/admin/admins/'.$u->id.'/reinvite') }}" class="inline">@csrf<button class="btn ghost small">招待を送り直す</button></form>@endif</td></tr>@endforeach</x-table>
<p class="muted">登録すると、招待メール（7日間有効のリンク）が届きます。</p>
<form method="post" action="{{ url('/admin/admins') }}">@csrf<div class="grid2"><x-field label="担当者名（姓）" name="name_sei" required /><x-field label="担当者名（名）" name="name_mei" /><x-field label="企業名" name="company_name" required /><x-field label="メールアドレス" name="email" type="email" required /></div><button class="btn ghost small">登録して招待メールを送る</button></form></x-card>
@endsection
