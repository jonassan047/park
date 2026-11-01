@extends('layouts.app', ['title' => $approve ? 'SES の承認：'.$approve->name : 'SES の仮登録'])
@section('content')
@if($approve)
<p class="muted">申し込みの内容を入れてあります。企業正式名称・SES の姓名・メールをお確かめのうえ、送ってください。SES に仮登録のご案内が届き、申し込んだ方にも承認をお知らせします。</p>
@else
<p class="muted">仮登録すると、SES に仮登録のご案内（7日以内に本登録）が届きます。本登録が済むと参加中になります。</p>
@endif
<x-card><form method="post" action="{{ url($approve ? '/admin/ses/'.$approve->id.'/approve' : '/admin/ses') }}">@csrf
@include('admin.ses-form', ['mode' => 'provisional', 'v' => $v])
<a class="btn ghost" href="{{ url($approve ? '/admin/ses/'.$approve->id : '/admin/ses') }}">戻る</a> <button class="btn">{{ $approve ? '承認して仮登録のご案内を送る' : '仮登録してメールを送る' }}</button></form></x-card>
@endsection
