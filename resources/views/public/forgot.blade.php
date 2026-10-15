@extends('layouts.app', ['title' => 'パスワードの再設定'])
@section('content')
<x-card><form method="post" action="{{ url('/forgot') }}" class="narrow">@csrf<p>登録したメールアドレスに、再設定のリンクを送ります。</p>
<x-field label="メールアドレス" name="email" type="email" required /><button class="btn">送る</button></form></x-card>
@endsection
