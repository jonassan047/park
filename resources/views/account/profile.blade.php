@extends('layouts.app', ['title' => '登録情報'])
@section('content')
@if(!$u->profile_completed_at)
<x-card title="登録情報"><p>まだ登録していません。<a href="{{ url('/welcome') }}">あなたの情報を登録する</a></p></x-card>
@else
@php
    // 姓・名の列がまだ空の人は、つないだ name・kana から分けて出す
    [$nameSei, $nameMei] = ($u->name_sei || $u->name_mei) ? [$u->name_sei, $u->name_mei] : split_name($u->name);
    [$kanaSei, $kanaMei] = ($u->kana_sei || $u->kana_mei) ? [$u->kana_sei, $u->kana_mei] : split_name($u->kana);
    $dash = fn ($v) => ($v === null || $v === '') ? '—' : $v;
@endphp
<x-card title="登録情報">
<dl class="dl">
<dt>氏名（姓）</dt><dd>{{ $dash($nameSei) }}</dd><dt>氏名（名）</dt><dd>{{ $dash($nameMei) }}</dd>
<dt>ふりがな（姓）</dt><dd>{{ $dash($kanaSei) }}</dd><dt>ふりがな（名）</dt><dd>{{ $dash($kanaMei) }}</dd>
@if($u->role === 'admin')
<dt>企業名</dt><dd>{{ $dash($u->company_name) }}</dd>
@elseif($u->role === 'ses')
<dt>SES名</dt><dd>{{ $dash($sesName) }}</dd>
@endif
@unless($u->role === 'eg')
<dt>所属・役職</dt><dd>{{ $dash($u->position) }}</dd>
@endunless
<dt>電話</dt><dd>{{ $dash($u->phone) }}</dd>
<dt>メールアドレス</dt><dd>{{ $u->email }}</dd>
<dt>立場</dt><dd>{{ lbl('role', $u->role) }}</dd>
<dt>メールでの通知</dt><dd>{{ $u->mail_opt_in ? '受け取る' : '受け取らない' }}</dd>
<dt>登録した日</dt><dd>{{ fmt_date($u->profile_completed_at) }}</dd>
<dt>最後のログイン</dt><dd>{{ fmt_dt($u->last_login) }}</dd>
</dl>
<p><a class="btn" href="{{ url('/account') }}">編集する</a>
@if($u->role === 'ses')
<a class="btn ghost" href="{{ url('/ses/company') }}">会社情報を見る</a>
@elseif($u->role === 'eg' && $engineerId)
<a class="btn ghost" href="{{ url('/engineers/'.$engineerId) }}">プロフィール・経験票を見る</a>
@endif
</p>
</x-card>
@endif
@endsection
