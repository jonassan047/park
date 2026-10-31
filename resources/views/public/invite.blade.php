@extends('layouts.app', ['title' => '本登録'])
@section('content')
@php $company = \App\Services\Registration::companyOf($u); @endphp
<p>{{ trim($company.' '.$u->name) }} 様、PARK の{{ \App\Support\Requirements::roleName($role, $isRep) }}として仮登録されています。内容をお確かめのうえ、本登録をお願いします。</p>
<x-card><form method="post" action="{{ url('/invite/'.$token) }}" class="narrow">@csrf
@include('partials.register-fields', ['role' => $role, 'isRep' => $isRep, 'v' => $v, 'only' => null])
<x-field label="パスワード（8文字以上）" name="password" type="password" required autocomplete="new-password" />
<x-field label="パスワード（確認）" name="password_confirmation" type="password" required autocomplete="new-password" />
<button class="btn">本登録する</button></form>
<form method="post" action="{{ url('/invite/'.$token.'/decline') }}" class="inline" onsubmit="return confirm('このリンクを無効にして、登録した方にお知らせします。よろしいですか？')">@csrf
<button class="link">心当たりがない</button></form></x-card>
@endsection
