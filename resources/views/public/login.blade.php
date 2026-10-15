@extends('layouts.app', ['title' => 'ログイン'])
@section('content')
<x-card><form method="post" action="{{ url('/login') }}" class="narrow">@csrf
<x-field label="メールアドレス" name="email" type="email" required autocomplete="username" />
<x-field label="パスワード" name="password" type="password" required autocomplete="current-password" />
<button class="btn">ログイン</button> <a href="{{ url('/forgot') }}">パスワードを忘れたとき</a></form></x-card>
@endsection
