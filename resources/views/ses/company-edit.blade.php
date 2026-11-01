@extends('layouts.app', ['title' => '会社情報の編集', 'wide' => true])
@section('content')
<x-card><x-slot:actions><a class="btn ghost small" href="{{ url('/account#password') }}">パスワード変更</a></x-slot:actions><form method="post" action="{{ url('/ses/company') }}">@csrf<div class="grid2">
<x-field label="企業正式名称" name="name" :value="$s->name" required cls="span2" />
<div class="pair"><x-field label="SES の氏名（姓）" name="rep_sei" :value="$rep['sei']" required /><x-field label="SES の氏名（名）" name="rep_mei" :value="$rep['mei']" required /></div>
<x-field label="電話番号" name="phone" :value="$s->phone" required /><x-field label="郵便番号" name="postal_code" :value="postal_norm($s->postal_code) ?: $s->postal_code" placeholder="123-4567" required />
<x-field label="所在地" name="address" :value="$s->address" required />
<x-field label="所属エンジニアの人数" name="employees" type="number" :value="$s->employees" /></div>
<x-field label="得意な分野" name="strengths" :value="$s->strengths" /><button class="btn">保存する</button> <a class="btn ghost" href="{{ url('/ses/company') }}">戻る</a></form></x-card>
@endsection
