@extends('layouts.app', ['title' => 'PARK', 'noTitle' => true, 'wide' => true])
@section('content')
<section class="hero">
  <div>
    <span class="pill"><i></i>木漏れ日の下で、仕事と仲間がつながる庭</span>
    <h1>SES 企業のみなさまと、<br>エンジニアが、同じ木の下で。</h1>
    <p>小さな SES 企業どうしが1つのグループにまとまり、届いた案件に、グループ全体のエンジニアからいちばん合う人を出します。いちばん大事にするのは、<b>エンジニアの成約率を上げること</b>です。</p>
    <p style="margin-top:22px"><a class="btn" href="{{ url('/apply') }}">参加のお申し込み（SES 企業さま） →</a> <a class="btn ghost" href="{{ url('/login') }}">ログイン</a></p>
  </div>
  <div class="photo" style="background-image:url('{{ asset('img/hero.jpg') }}')"><span>🌱 成約したときだけ、成功報酬</span></div>
</section>

<div class="section-title"><h2>PARK の歩き方</h2><p>あなたの立場に合わせて、公園の中のスポットを訪れてみてください。</p></div>
<div class="spots">
  <a class="spot" href="{{ url('/manual/ses') }}" style="background-image:url('{{ asset('img/ses.jpg') }}')"><div><em>SES 企業さま</em><b>🏡 グループで、仕事を回す</b>
    <small>1社では受けきれない案件も、仲間のエンジニアで埋められます。自社のエンジニアとの相性もひと目でわかります。</small></div></a>
  <a class="spot" href="{{ url('/manual/engineer') }}" style="background-image:url('{{ asset('img/engineers.jpg') }}')"><div><em class="orange">エンジニアのみなさま</em><b>🪑 合う案件が、届く</b>
    <small>運営と一緒に、事実にもとづいたスキルシートを作り、面談の準備（Q&A）まで支えます。利用は無料です。</small></div></a>
</div>

<div class="section-title"><h2>PARK のしくみ</h2><p>準備から成約まで、3つのステップで支えます。</p></div>
<div class="grid3 steps">
<x-card><h3><span class="num">1</span>準備</h3><p>SES がエンジニアの基本情報を登録。エンジニアは運営とやり取りしながら、事実にもとづいた理想のスキルシートを作ります。</p></x-card>
<x-card><h3><span class="num">2</span>適材を判断して配信</h3><p>案件が届くと、条件に合うエンジニアを選んでメールで配信。SES にもお知らせします。二重応募はグループで一本化します。</p></x-card>
<x-card><h3><span class="num">3</span>面談サポート</h3><p>面談が決まると、案件に合わせた想定質問と答え方（Q&A）を用意。結果と理由をためて、次に活かします。</p></x-card>
</div>
<x-card title="💰 費用（案）"><p>先払いはありません。<b>成約したときだけ</b>、案件の金額に対して初期費用 {{ setting('fee_initial_rate') }}%、契約が続く間は月額 {{ setting('fee_monthly_rate') }}% をいただきます。率・条件はご相談のうえ決めます。</p></x-card>
@endsection
