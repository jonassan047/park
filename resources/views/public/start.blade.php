@extends('layouts.app', ['title' => $kind === 'ses' ? 'SES の作業' : 'エンジニアの作業', 'noTitle' => true, 'wide' => true, 'noPersonal' => true])
@section('content')
@php
    // やることの箇条書き（マニュアルに書いてあることだけ）
    $todos = [
        'ses' => ['一般担当者の登録（SES代表担当者が行います）', 'エンジニアの登録・推薦文', '案件の登録と、エンジニアへの案内メール', '返信・やり取り',
            '応募から成約まで（書類・面談依頼・結果・成約の記録）', '契約と請求の確認'],
        'engineer' => ['プロフィール・スキル・経験票を整える', '管理者に見てもらう', '届いた案件に返事・質問する', '面談スクリプトで準備する', '成約したら経験票を更新する'],
    ][$kind];
    [$heading, $listTitle] = $kind === 'ses' ? ['ここからは SES の作業です', 'SES でやること'] : ['ここからはエンジニアの作業です', 'エンジニアでやること'];
@endphp
<section class="hero start">
  <img class="sprig hero-sprig" src="{{ asset('img/sprig.jpg') }}" alt="">
  <h1>{{ $heading }}</h1>
</section>

@include('partials.flow-steps', ['kind' => $kind])

<x-card :title="$listTitle">
<ul class="tips">@foreach($todos as $t)<li>{{ $t }}</li>@endforeach</ul>
</x-card>

<p class="start-actions">
@if($state === 'work')
  <a class="btn" href="{{ url($kind === 'ses' ? '/ses' : '/eg') }}">作業を始める（ダッシュボードへ）</a>
@elseif($state === 'choose')
  <a class="btn" href="{{ url('/login/role') }}">立場を選ぶ</a>
@else
  @if($state === 'login')
    <a class="btn" href="{{ url('/login') }}">ログイン</a>
  @else
    <a class="btn" href="{{ url($back) }}">今の画面へ戻る</a>
  @endif
  <a class="btn ghost" href="{{ url('/manual/'.$kind) }}">使い方</a>
@endif
</p>
@endsection
