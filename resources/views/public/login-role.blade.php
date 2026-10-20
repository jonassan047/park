@extends('layouts.app', ['title' => 'どの立場で入りますか'])
@section('content')
<p>このアカウントは、いくつかの立場で使えます。どの立場で入りますか。</p>
<x-card><form method="post" action="{{ url('/login/role') }}" class="narrow">@csrf
@foreach($roles as $role)
<p><button class="btn" name="to" value="{{ $role }}">{{ $role === 'ses' ? 'SES 担当者'.($sesName ? '（'.$sesName.'）' : '') : lbl('role', $role) }}</button></p>
@endforeach
<p class="muted">立場を変えるときは、ログアウトして入り直してください。</p></form></x-card>
@endsection
