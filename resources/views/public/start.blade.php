@extends('layouts.app', ['title' => $kind === 'ses' ? 'SES の作業' : 'エンジニアの作業', 'noTitle' => true, 'wide' => true, 'noPersonal' => true, 'noHeader' => true])
@section('content')
@php
    // やることの箇条書き（マニュアルに書いてあることだけ）
    $todos = [
        'ses' => ['一般担当者の登録（SES代表担当者が行います）', 'エンジニアの登録・推薦文', '案件の登録と、エンジニアへの案内メール', '返信・やり取り',
            '応募から成約まで（書類・面談依頼・結果・成約の記録）', '契約と請求の確認'],
        'engineer' => ['プロフィール・スキル・経験票を整える', '管理者に見てもらう', '届いた案件に返事・質問する', '面談スクリプトで準備する', '成約したら経験票を更新する'],
    ][$kind];
    $listTitle = $kind === 'ses' ? 'SES でやること' : 'エンジニアでやること';
@endphp
{{-- 1stアクセス画面（2026-11-27 TEZZO の見本 1stアクセス.xlsx）。上の白い段は出さず、左にドーム・右に2行の文の横長の帯 --}}
<section class="hero first">
  <a class="first-dome" href="{{ url('/') }}" title="トップへ"><img class="hero-dome" src="{{ asset('img/dome.png') }}" alt="PARK"></a>
  <h1><span>はじめて PARK システムへアクセスされた方へ</span><span class="l2">PARK システムの概要をご説明いたします。</span></h1>
</section>

@include('partials.flow-steps', ['kind' => $kind])

<x-card :title="$listTitle">
<ul class="tips">@foreach($todos as $t)<li>{{ $t }}</li>@endforeach</ul>
</x-card>

@php
    // PARK に出てくる立場（見本の「プレイヤー」。プロバイダーは PARK を使わない上位の会社）
    $players = [
        ['管理者', 'PARK の運営者。案件の登録、スキルシートの確認、契約と請求書を受け持ちます。'],
        ['SES代表担当者', 'SES 企業に1人。会社を PARK につなぎ、一般担当者とエンジニアを登録します。'],
        ['一般担当者', 'SES代表担当者が登録した担当者。案件の案内から成約まで、代表と同じように作業します。'],
        ['エンジニア', '案件に参加する人。プロフィール・スキル・経験票を整え、届いた案件に返事をします。利用は無料です。'],
        ['プロバイダー', 'SES に案件を出してくる上位の会社。PARK にはログインしません（SES が案件を登録します）。'],
    ];
@endphp
<x-card title="PARK のプレイヤー">
<dl class="players">@foreach($players as [$who, $what])<dt>{{ $who }}</dt><dd>{{ $what }}</dd>@endforeach</dl>
</x-card>

<p class="start-actions">
@if($state === 'choose')
  <a class="btn" href="{{ url('/login/role') }}">立場を選ぶ</a>
@else
  {{-- ログイン前はログインへ。ログインのあとは、その人のダッシュボードへ（見本：この画面 → ログイン → ダッシュボード） --}}
  <a class="btn" href="{{ url($state === 'login' ? '/login' : $back) }}">作業を始める（ダッシュボードへ）</a>
@endif
</p>
@endsection
