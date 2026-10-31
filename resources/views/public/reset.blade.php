@extends('layouts.app', ['title' => 'パスワードの再設定'])
@section('content')
<p>新しいパスワードを入れてください。</p>
<x-card><form method="post" action="{{ url('/reset/'.$token) }}" class="narrow">@csrf
<x-field label="新しいパスワード（8文字以上）" name="password" type="password" required autocomplete="new-password" />
<x-field label="新しいパスワード（確認）" name="password_confirmation" type="password" required autocomplete="new-password" />
<button class="btn">設定する</button></form></x-card>
@endsection
