@extends('layouts.app', ['title' => '仮登録の内容を直す'])
@section('content')
<p class="muted">本登録が済む前だけ、直せます。メールアドレス{{ $sesOptions ? 'や所属' : '' }}を直して保存すると、新しい仮登録のご案内が届き、前のリンクは使えなくなります。</p>
<x-card><form method="post" action="{{ url('/people/'.$t->id) }}">@csrf
@php $pid = $t->role === 'ses' ? $t->staff_code : ($t->engineer_id ? DB::table('engineers')->where('id', $t->engineer_id)->value('code') : null); @endphp
@if($pid)<p>ID：<x-code :v="$pid" /></p>@endif
@if($sesOptions)
<x-field label="所属する SES（参加中の SES から選びます）" name="ses_id" type="select" :options="$sesOptions" :value="$t->ses_id" />
@elseif($company)
<p>所属：{{ $company }}</p>
@endif
<div class="pair"><x-field label="氏名（姓）" name="name_sei" :value="$sei" required /><x-field label="氏名（名）" name="name_mei" :value="$mei" required /></div>
<x-field label="メールアドレス" name="email" type="email" :value="$t->email" required />
<a class="btn ghost" href="{{ url($back) }}">戻る</a> <button class="btn">保存する</button></form></x-card>
@endsection
