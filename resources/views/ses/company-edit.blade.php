@extends('layouts.app', ['title' => '会社情報の編集', 'wide' => true])
@section('content')
<x-card><x-slot:actions><a class="btn ghost small" href="{{ url('/account#password') }}">パスワード変更</a></x-slot:actions><form method="post" action="{{ url('/ses/company') }}">@csrf<div class="grid2">
<x-field label="SES名（会社名）" name="name" :value="$s->name" required cls="span2" />
<div class="pair"><x-field label="代表者名（姓）" name="rep_sei" :value="$s->rep_sei" /><x-field label="代表者名（名）" name="rep_mei" :value="$s->rep_mei" /></div>
<div class="pair"><x-field label="ご担当者名（姓）" name="contact_sei" :value="$s->contact_sei" /><x-field label="ご担当者名（名）" name="contact_mei" :value="$s->contact_mei" /></div>
<x-field label="電話番号" name="phone" :value="$s->phone" /><x-field label="郵便番号" name="postal_code" :value="postal_norm($s->postal_code) ?: $s->postal_code" placeholder="123-4567" />
<x-field label="所在地" name="address" :value="$s->address" />
<x-field label="所属エンジニアの人数" name="employees" type="number" :value="$s->employees" /></div>
<x-field label="得意な分野" name="strengths" :value="$s->strengths" /><button class="btn">保存する</button> <a class="btn ghost" href="{{ url('/ses/company') }}">戻る</a></form></x-card>
@endsection
