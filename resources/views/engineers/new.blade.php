@extends('layouts.app', ['title' => 'エンジニアの仮登録'])
@section('content')
<p class="muted">姓・名・メールアドレスの3つを入れて、仮登録します。ご本人に仮登録のご案内（7日間有効のリンク）が届き、住所・電話などはご本人が本登録で入れます。</p>
<x-card><form method="post" action="{{ url('/engineers') }}">@csrf
@if($sesFixed)<p>所属：{{ $sesFixed['name'] }}</p><input type="hidden" name="ses_id" value="{{ $sesFixed['id'] }}">
@elseif($sesOptions)<x-field label="所属する SES（参加中の SES から選びます）" name="ses_id" type="select" :options="$sesOptions" blank="選んでください" required />@endif
<div class="pair"><x-field label="氏名（姓）" name="name_sei" required /><x-field label="氏名（名）" name="name_mei" required /></div>
<x-field label="メールアドレス" name="email" type="email" required hint="ご本人に仮登録のご案内が届きます" />
<a class="btn ghost" href="{{ $sesFixed ? url('/admin/ses/'.$sesFixed['id']) : list_url('engineers') }}">戻る</a> <button class="btn">仮登録してメールを送る</button></form></x-card>
@endsection
