@extends('layouts.app', ['title' => 'あなたの情報'])
@section('content')
<p class="muted">はじめに、あなたの情報を登録してください。あとから「アカウント」で直せます。</p>
<x-card title="あなたの情報"><form method="post" action="{{ url('/welcome') }}">@csrf
<x-field label="氏名" name="name" :value="$v->name" required autofocus />
<x-field label="ふりがな" name="kana" :value="$v->kana" />
<x-field label="電話" name="phone" type="tel" :value="$v->phone" />
@unless($u->role === 'eg')
<x-field label="所属・役職" name="position" :value="$v->position" hint="例：営業部 部長" />
@endunless
<p class="muted">メールアドレス：{{ $u->email }}（変えるときは管理者にご連絡ください）</p>
<button class="btn">登録する</button></form></x-card>
@endsection
