@extends('layouts.app', ['title' => '管理者の登録'])
@section('content')
<p class="muted">仮登録すると、ご本人に仮登録のご案内（7日以内に本登録）が届きます。電話番号などは、ご本人が本登録で入れます。本登録が済むと参加中になります。</p>
<x-card><form method="post" action="{{ url('/admin/admins') }}">@csrf
<x-field label="企業名" name="company_name" required hint="本登録のときに、ご本人が確かめます。メールの差出人の名前にも使います。" />
<div class="pair"><x-field label="氏名（姓）" name="name_sei" required /><x-field label="氏名（名）" name="name_mei" required /></div>
<x-field label="メールアドレス" name="email" type="email" required />
<a class="btn ghost" href="{{ url('/admin/admins') }}">管理者の一覧に戻る</a> <button class="btn">仮登録してメールを送る</button></form></x-card>
@endsection
