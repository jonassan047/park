@extends('layouts.app', ['title' => 'アカウント'])
@section('content')
<div class="grid2">
<x-card title="基本"><form method="post" action="{{ url('/account') }}">@csrf
<x-field label="お名前" name="name" :value="$u->name" required />
<x-field label="メールでも通知を受け取る" name="mail_opt_in" type="checkbox" :value="$u->mail_opt_in" />
<p class="muted">メールアドレス：{{ $u->email }}（変えるときは運営にご連絡ください）</p><button class="btn">保存</button></form></x-card>
<x-card title="パスワードの変更"><form method="post" action="{{ url('/account/password') }}">@csrf
<x-field label="今のパスワード" name="current" type="password" required autocomplete="current-password" />
<x-field label="新しいパスワード（8文字以上）" name="password" type="password" required autocomplete="new-password" />
<x-field label="新しいパスワード（確認）" name="password_confirmation" type="password" required autocomplete="new-password" />
<button class="btn">変更</button></form></x-card>
<x-card title="2要素認証"><p>設定済み（{{ fmt_date($u->two_factor_confirmed_at) }}）。ログインのときに、認証アプリの6桁のコードを入れます。</p>
<p class="muted">予備のコードの残り：{{ count(\App\Support\TwoFactor::recoveryCodesOf($u)) }}個。作り直すと、前の予備のコードは使えなくなります。</p>
<form method="post" action="{{ url('/two-factor/recovery-codes') }}" onsubmit="return confirm('予備のコードを作り直しますか？')">@csrf<button class="btn ghost small">予備のコードを作り直す</button></form>
<p class="muted">スマホを替える・なくしたときは、運営に解除を頼み、次のログインで設定し直します。</p></x-card>
</div>
@endsection
