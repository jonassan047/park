@extends('layouts.app', ['title' => '使い方（マニュアル）'])
@section('content')
<div class="grid3">
@foreach($kinds as $k => $t)<x-card :title="$t"><p class="muted">{{ ['admin' => 'PARK を運営する人向け。承認・マッチングと配信・選考・請求など。', 'ses' => 'SES 企業のご担当者さま向け。参加・エンジニアの登録・案件・応募など。', 'engineer' => 'エンジニアのみなさま向け。経験票・スキルシート・応募・面談の準備など。'][$k] }}</p>
<a class="btn small" href="{{ url('/manual/'.$k) }}">読む</a></x-card>@endforeach
</div>
@endsection
