@extends('layouts.app', ['title' => ($s->status === 'provisional' ? '仮登録の内容を直す：' : '会社情報の編集：').$s->name])
@section('content')
@if($s->status === 'provisional')
<p class="muted">本登録が済む前だけ、企業正式名称・代表者の姓名・メールを直せます。メールを変えると、新しいアドレスに仮登録のご案内を送り直し、前のリンクは使えなくなります。</p>
@php [, $rsei, $rmei] = \App\Services\Registration::repName($s); @endphp
<x-card><form method="post" action="{{ url('/admin/ses/'.$s->id) }}">@csrf
@include('admin.ses-form', ['mode' => 'provisional', 'v' => (object) ['name' => $s->name, 'rep_sei' => $rsei, 'rep_mei' => $rmei, 'email' => $s->email]])
<a class="btn ghost" href="{{ url('/admin/ses/'.$s->id) }}">戻る</a> <button class="btn">保存する</button></form></x-card>
@else
<x-card><form method="post" action="{{ url('/admin/ses/'.$s->id) }}">@csrf
@include('admin.ses-form', ['mode' => $s->status === 'pending' ? 'pending' : 'registered', 'v' => null])
<a class="btn ghost" href="{{ url('/admin/ses/'.$s->id) }}">戻る</a> <button class="btn">保存する</button></form></x-card>
@endif
@endsection
