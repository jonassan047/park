@extends('layouts.app', ['title' => 'ログイン'])
@section('content')
@php $em = old('email', $lastEmail ?? ''); @endphp
<x-card><form method="post" action="{{ url('/login') }}" class="narrow">@csrf
<x-field label="メールアドレス" name="email" type="email" required autocomplete="username" :value="$em" :autofocus="$em === ''" />
<x-field label="パスワード" name="password" type="password" required autocomplete="current-password" :autofocus="$em !== ''" />
<button class="btn">ログイン</button> <a href="{{ url('/forgot') }}">パスワードを忘れたとき</a></form></x-card>
@endsection
