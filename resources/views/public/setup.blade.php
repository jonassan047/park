@extends('layouts.app', ['title' => 'はじめの設定'])
@section('content')
<p>最初に、管理者のアカウントを作ります。</p>
<x-card><form method="post" action="{{ url('/setup') }}" class="narrow">@csrf
<x-field label="お名前" name="name" required /><x-field label="メールアドレス" name="email" type="email" required />
<x-field label="パスワード（8文字以上）" name="password" type="password" required /><button class="btn">作成する</button></form></x-card>
@endsection
