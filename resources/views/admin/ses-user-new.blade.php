@extends('layouts.app', ['title' => '担当者の登録：'.$s->name])
@section('content')
<p class="muted">登録すると、担当の方に招待メール（7日間有効のリンク）が届きます。</p>
<x-card><form method="post" action="{{ url('/admin/ses/'.$s->id.'/users') }}">@csrf
<div class="pair"><x-field label="氏名（姓）" name="name_sei" required /><x-field label="氏名（名）" name="name_mei" /></div>
<div class="pair"><x-field label="ふりがな（姓）" name="kana_sei" placeholder="やまだ" /><x-field label="ふりがな（名）" name="kana_mei" placeholder="たろう" /></div>
<x-field label="メールアドレス" name="email" type="email" required />
<a class="btn ghost" href="{{ url('/admin/ses/'.$s->id) }}">戻る</a> <button class="btn">登録して招待メールを送る</button></form></x-card>
@endsection
