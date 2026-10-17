@extends('layouts.app', ['title' => 'アカウント'])
@section('content')
<div class="grid2">
<x-card title="基本"><form method="post" action="{{ url('/account') }}">@csrf
<x-field label="お名前" name="name" :value="$u->name" required />
<x-field label="ふりがな" name="kana" :value="$u->kana" />
<x-field label="電話" name="phone" type="tel" :value="$u->phone" />
@unless($u->role === 'eg')
<x-field label="所属・役職" name="position" :value="$u->position" />
@endunless
<x-field label="メールでも通知を受け取る" name="mail_opt_in" type="checkbox" :value="$u->mail_opt_in" />
<p class="muted">メールアドレス：{{ $u->email }}（変えるときは運営にご連絡ください）</p><button class="btn">保存</button></form></x-card>
<x-card title="パスワードの変更"><form method="post" action="{{ url('/account/password') }}">@csrf
<x-field label="今のパスワード" name="current" type="password" required autocomplete="current-password" />
<x-field label="新しいパスワード（8文字以上）" name="password" type="password" required autocomplete="new-password" />
<x-field label="新しいパスワード（確認）" name="password_confirmation" type="password" required autocomplete="new-password" />
<button class="btn">変更</button></form></x-card>
<x-card title="2要素認証"><p>ログインのときは、パスワードのあと、このアカウントのメールアドレス（{{ $u->email }}）に届く6桁の確認コードを入れます。</p>
<p class="muted">メールアドレスを変えるときは、運営にご連絡ください。</p></x-card>
</div>
@endsection
