@extends('layouts.app', ['title' => 'アカウント'])
@section('content')
@php
    // 姓・名の列がまだ空の人は、つないだ name・kana から分けて出す
    [$nameSei, $nameMei] = ($u->name_sei || $u->name_mei) ? [$u->name_sei, $u->name_mei] : split_name($u->name);
    [$kanaSei, $kanaMei] = ($u->kana_sei || $u->kana_mei) ? [$u->kana_sei, $u->kana_mei] : split_name($u->kana);
@endphp
<div class="grid2">
<x-card title="基本"><p><a href="{{ url('/account/profile') }}">登録情報を見る</a></p>
<form method="post" action="{{ url('/account') }}">@csrf
<div class="pair">
<x-field label="氏名（姓）" name="name_sei" :value="$nameSei" />
<x-field label="氏名（名）" name="name_mei" :value="$nameMei" />
</div>
<div class="pair">
<x-field label="ふりがな（姓）" name="kana_sei" :value="$kanaSei" />
<x-field label="ふりがな（名）" name="kana_mei" :value="$kanaMei" />
</div>
@if($u->role === 'admin')
<x-field label="企業名" name="company_name" :value="$u->company_name" hint="メールの差出人の名前にも使います。" />
@elseif($u->role === 'ses')
<x-field label="SES名" name="ses_name" :value="$sesName" hint="変えると、管理者にお知らせが届きます。" />
@endif
@unless($u->role === 'eg')
<x-field label="所属・役職" name="position" :value="$u->position" />
@endunless
<x-field label="電話" name="phone" type="tel" :value="$u->phone" />
@if($u->role === 'admin')
<x-field label="郵便番号" name="postal_code" :value="postal_norm($u->postal_code) ?: $u->postal_code" placeholder="123-4567" />
<x-field label="住所" name="address" :value="$u->address" />
@endif
<x-field label="メールでも通知を受け取る" name="mail_opt_in" type="checkbox" :value="$u->mail_opt_in" />
<p class="muted">メールアドレス：{{ $u->email }}（変えるときは管理者にご連絡ください）</p><button class="btn">保存する</button></form></x-card>
<x-card title="パスワードの変更" id="password"><form method="post" action="{{ url('/account/password') }}">@csrf
<x-field label="今のパスワード" name="current" type="password" required autocomplete="current-password" />
<x-field label="新しいパスワード（8文字以上）" name="password" type="password" required autocomplete="new-password" />
<x-field label="新しいパスワード（確認）" name="password_confirmation" type="password" required autocomplete="new-password" />
<button class="btn">変更する</button></form></x-card>
<x-card title="2要素認証"><p>ログインのときは、パスワードのあと、このアカウントのメールアドレス（{{ $u->email }}）に届く6桁の確認コードを入れます。</p>
<p class="muted">メールアドレスを変えるときは、管理者にご連絡ください。</p></x-card>
</div>
@endsection
