@extends('layouts.app', ['title' => 'PARK', 'noTitle' => true])
@section('content')
<section class="hero"><h1>SES 企業のみなさまと、エンジニアが集う庭</h1>
<p>小さな SES 企業どうしが1つのグループにまとまり、届いた案件に、グループ全体のエンジニアからいちばん合う人を出します。<br>
いちばん大事にするのは、<b>エンジニアの成約率を上げること</b>です。</p>
<p><a class="btn" href="{{ url('/apply') }}">参加のお申し込み（SES 企業さま）</a> <a class="btn ghost" href="{{ url('/login') }}">ログイン</a></p></section>
<div class="grid3">
<x-card title="1. 準備"><p>SES がエンジニアの基本情報を登録。エンジニアは運営とやり取りしながら、事実にもとづいた理想のスキルシートを作ります。</p></x-card>
<x-card title="2. 適材を判断して配信"><p>案件が届くと、条件に合うエンジニアを選んでメールで配信。SES にもお知らせします。二重応募はグループで一本化します。</p></x-card>
<x-card title="3. 面談サポート"><p>面談が決まると、案件に合わせた想定質問と答え方（Q&A）を用意。結果と理由をためて、次に活かします。</p></x-card>
</div>
<x-card title="費用（案）"><p>先払いはありません。<b>成約したときだけ</b>、案件の金額に対して初期費用 {{ setting('fee_initial_rate') }}%、契約が続く間は月額 {{ setting('fee_monthly_rate') }}% をいただきます。率・条件はご相談のうえ決めます。</p></x-card>
@endsection
