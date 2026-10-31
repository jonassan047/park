@extends('layouts.app', ['title' => '足りない項目の入力'])
@section('content')
<p class="muted">{{ \App\Support\Requirements::roleName($role, $isRep) }}として使い続けるために、次の項目と同意をお願いします。入れると、いつもの画面に進めます。</p>
<x-card title="足りない項目"><form method="post" action="{{ url('/register/complete') }}" class="narrow">@csrf
@include('partials.register-fields', ['role' => $role, 'isRep' => $isRep, 'v' => $v, 'only' => $only])
<button class="btn">保存して進む</button></form>
<form method="post" action="{{ url('/logout') }}" class="inline">@csrf<button class="link">あとでログアウトする</button></form></x-card>
@endsection
