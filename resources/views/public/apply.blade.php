@extends('layouts.app', ['title' => '参加のお申し込み（SES 企業さま）'])
@section('content')
<p>PARK は、SES 企業どうしが結束して、エンジニアの成約率を上げるための仕組みです。お申し込みのあと、管理者が内容を確認してご連絡します。</p>
<x-card><form method="post" action="{{ url('/apply') }}">@csrf<div class="grid2">
<x-field label="会社名" name="name" required /><x-field label="代表者名（姓）" name="rep_sei" /><x-field label="代表者名（名）" name="rep_mei" />
<x-field label="ご担当者名（姓）" name="contact_sei" required /><x-field label="ご担当者名（名）" name="contact_mei" /><x-field label="メールアドレス" name="email" type="email" required />
<x-field label="電話番号" name="phone" type="tel" /><x-field label="所在地" name="address" />
<x-field label="所属エンジニアの人数" name="employees" type="number" min="0" /><x-field label="得意な分野" name="strengths" placeholder="例：Java の業務系、インフラ" /></div>
<x-field label="ひとこと" name="message" type="textarea" rows="3" />
<x-field label="守秘義務を守り、案件・エンジニアの情報をグループの外に出さないことに同意します" name="agree" type="checkbox" required />
<button class="btn">申し込む</button></form></x-card>
@endsection
