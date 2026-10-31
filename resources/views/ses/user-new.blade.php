@extends('layouts.app', ['title' => '担当者の仮登録'])
@section('content')
<p class="muted">姓・名・メールアドレスの3つを入れて、仮登録します。担当の方に仮登録のご案内（7日間有効のリンク）が届き、電話などは担当の方が本登録で入れます。</p>
<x-card><form method="post" action="{{ url('/ses/company/users') }}">@csrf
<p>企業名：{{ $s->name }}</p>
<div class="pair"><x-field label="氏名（姓）" name="name_sei" required /><x-field label="氏名（名）" name="name_mei" required /></div>
<x-field label="メールアドレス" name="email" type="email" required />
<a class="btn ghost" href="{{ url('/ses/company') }}">戻る</a> <button class="btn">仮登録してメールを送る</button></form></x-card>
@endsection
