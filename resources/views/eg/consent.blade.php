@extends('layouts.app', ['title' => '個人情報の取り扱いへの同意'])
@section('content')
<p>エンジニアとして初めて入るので、次の内容への同意をお願いします。</p>
<x-card><form method="post" action="{{ url('/eg/consent') }}" class="narrow">@csrf
@include('partials.consent')
<x-field label="上の取り扱いに同意します" name="consent" type="checkbox" required />
<button class="btn">同意して進む</button></form>
<form method="post" action="{{ url('/logout') }}" class="inline">@csrf<button class="link">同意しないでログアウトする</button></form></x-card>
@endsection
