@extends('layouts.app', ['title' => id_title($s->code, $s->name).' さま', 'wide' => true])
@section('content')
{{-- 構成は TEZZO の SESダッシュボード.xlsx（2026-11-27）：「稼働状況」の細い帯 → 企業内・グループ内の数 → 成約に近い順の5つの一覧。今までの帯と「今やること」は外した --}}
<div class="role-hero slim" style="background-image:url('{{ asset('img/hero.jpg') }}')"><p>稼働状況</p></div>
@if(!$engs->count())<x-card title="エンジニアを登録しましょう"><p>貴社のエンジニアを登録すると、合う案件が届くようになります。</p><a class="btn small" href="{{ url('/engineers/new') }}">エンジニアを登録する</a></x-card>@endif
<x-card><x-table :head="['', '所属エンジニア', '稼働中', '提案可', '選考中', '直近実績（今月）', '年度累計（4月から）']">
@foreach($rows as $label => $n)<tr><th>{{ $label }}</th>@foreach($n as $i => $v)<td>{{ $v }}{{ $i < 3 ? '名' : '件' }}</td>@endforeach</tr>@endforeach
</x-table><p class="muted">グループ内は、PARK に参加している SES 企業全体の合計です。実績は成約した件数です。</p></x-card>
@php
    $isNew = fn ($at) => $at && strtotime($at) >= strtotime('-3 days'); // 3日以内に動きがあったもの
    $secs = [
        ['合否待ち案件', $decide, 'appStatus', 'updated_at', '/applications/'],
        ['面談予定案件', $meet, 'appStatus', 'updated_at', '/applications/'],
        ['プロバイダー回答待ち案件', $pv, 'appStatus', 'updated_at', '/applications/'],
        ['エンジニア回答待ち案件', $offers, 'offerStatus', 'sent_at', '/cases/'],
        ['提案作成中案件', $making, 'appStatus', 'updated_at', '/applications/'],
    ];
@endphp
<x-card class="dash-list">
@foreach($secs as [$title, $list, $map, $at, $link])
<h3>▶{{ $title }}（{{ $list->count() }}件）</h3>
@forelse($list as $x)
<div class="dl-row"><span class="dl-new">@if($isNew($x->$at))<span class="badge bad">新着あり</span>@endif</span>
<span class="dl-main">{{ $loop->iteration }}．<x-code :v="$x->case_code" />　{{ $x->title }}<small><x-code :v="$x->eg_code" />　{{ $x->eg_name }}　{{ badge($map, $x->status) }}　{{ fmt_date($x->$at) }}</small></span>
<a class="btn small" href="{{ url($link.($map === 'offerStatus' ? $x->case_id : $x->id)) }}">詳細</a></div>
@empty<p class="dl-empty muted">ありません。</p>@endforelse
@endforeach
</x-card>
@php $unconf = $engs->whereNull('self_confirmed_at'); @endphp
@if($unconf->count())<x-card title="ご本人の確認がまだのエンジニア"><p>登録の内容は、エンジニアご本人が責任を持つものです。ご本人がホームで「確認しました」を押すまで、案件に応募できません。御社で書き換えたときも、もう一度確認が必要になります。</p>
<p>@foreach($unconf as $x)<x-code :v="$x->code" />　<a href="{{ url('/engineers/'.$x->id) }}">{{ $x->name }}</a>@if(!$loop->last)、@endif @endforeach</p></x-card>@endif
@if($noSheet->count())
<x-card title="経験票がまだのエンジニア"><p class="muted">経験票が入っていないと、合う案件が届きません。ご本人に声をかけてください。</p>
<ul>@foreach($noSheet as $e)<li><x-code :v="$e->code" />　<a href="{{ url('/engineers/'.$e->id) }}">{{ $e->name }}</a></li>@endforeach</ul></x-card>
@endif
@endsection
