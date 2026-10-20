@extends('layouts.app', ['title' => '管理者の登録'])
@section('content')
<p class="muted">登録すると、担当の方に招待メール（7日間有効のリンク）が届きます。</p>
<x-card><form method="post" action="{{ url('/admin/admins') }}">@csrf
<div class="pair"><x-field label="担当者名（姓）" name="name_sei" required /><x-field label="担当者名（名）" name="name_mei" /></div>
<x-field label="企業名" name="company_name" required hint="メールの差出人の名前にも使います。" />
<x-field label="メールアドレス" name="email" type="email" required />
<x-field label="所属・役職" name="position" />
<x-field label="電話番号" name="phone" />
<x-field label="郵便番号" name="postal_code" placeholder="123-4567" />
<x-field label="住所" name="address" />
<a class="btn ghost" href="{{ url('/admin/admins') }}">管理者の一覧に戻る</a> <button class="btn">登録して招待メールを送る</button></form></x-card>
@endsection
