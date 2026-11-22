@php
$u = auth()->user();
// 新しい配置（v0.15.0）：提案元 SES の担当者だけ。ほかの人（管理者・ほかの SES・EG）は今までの配置
$newLayout = $canMail;
@endphp
@extends('layouts.app', ['title' => $c->title, 'wide' => true])
@section('content')
@if($newLayout)
{{-- 左：メール本文 → 案内メール → プロバイダーへの返信 → この案件への応募。右：AI の読み直し → エンジニアを検索 --}}
<div class="cols"><div>
<x-card title="メール本文">
<x-slot:actions>
<span class="muted">ID <x-code :v="$c->code" />・状態 {{ badge('caseStatus', $c->status) }}・{{ man($c->price) }}</span>
@if(\App\Support\Perms::caseEdit($u, $c))<a class="btn small" href="{{ url('/cases/'.$c->id.'/edit') }}">編集</a>@endif
</x-slot:actions>
@if(trim((string) $c->raw_text) !== '')<pre class="mailbody">{{ $c->raw_text }}</pre>
@else<p class="muted">元のメールはありません。</p>
<h3>作業内容</h3><p>{!! nl2br(e($c->description)) !!}</p>@endif
<details><summary>案件票（読み取った項目）</summary>
@include('cases._card_case')
</details>
</x-card>
@include('cases._card_mails')
@include('cases._card_provider')
@include('cases._card_apps')
@include('cases._card_threads')
</div><div>
@include('cases._card_ai')
@include('cases._card_keywords')
</div></div>
@else
<div class="cols"><div>
<x-card title="案件票">
<x-slot:actions>
@if(\App\Support\Perms::caseEdit($u, $c))<a class="btn small" href="{{ url('/cases/'.$c->id.'/edit') }}">編集</a>@endif
</x-slot:actions>
@include('cases._card_case')
</x-card>
@include('cases._card_ai')
@if($canSeeMails)
@include('cases._card_mails')
@include('cases._card_provider')
@endif
@if($u->role !== 'eg')
@include('cases._card_threads')
@include('cases._card_apps')
@endif
</div><div>

@if($u->role === 'admin')
  {{-- 管理者は案件を発信できない。案内は、提案元 SES の担当者が行う（v0.11.0） --}}
  <x-card title="案内">
  @if($c->ses_name)<p class="muted">案内は、提案元 SES（{{ $c->ses_name }}）の担当者が行います。</p>
  @else<p class="muted">提案元 SES がまだ決まっていません。案件の「編集」で選んでください。案内は、提案元 SES の担当者が行います。</p>@endif
  </x-card>
  <x-card title="配信済み"><x-table :head="['エンジニア', '点数', '状態', '配信日', '']" :count="$offers->count()">
  @foreach($offers as $o)<tr><td>{{ $o->name }} <x-code :v="$o->eg_code" /></td><td>{{ $o->score }}</td><td>{{ badge('offerStatus', $o->status) }}@if($o->decline_reason)<div class="muted">{{ $o->decline_reason }}</div>@endif</td><td>{{ fmt_dt($o->sent_at) }}</td>
  <td><a class="btn ghost small" href="{{ url('/cases/'.$c->id.'/threads/'.$o->engineer_id) }}">やり取り</a></td></tr>@endforeach
  </x-table></x-card>
  <x-card title="状態の変更">@foreach(['open', 'filled', 'closed'] as $s)@if($s !== $c->status)<x-post :action="'/cases/'.$c->id.'/status'" :label="lbl('caseStatus', $s).'にする'" :fields="['status' => $s]" cls="ghost small" /> @endif @endforeach</x-card>

@elseif($u->role === 'ses')
  {{-- 提案元ではない会社の SES・担当者：自社の人との相性と、推薦（提案元 SES の担当者は、新しい配置の「エンジニアを検索」から提案する） --}}
  <x-card title="エンジニアとの相性">
  @if($c->status !== 'open')<p class="muted">募集を終えた案件です。</p>
  @else
  <p class="muted">{{ $c->ses_name ? 'この案件の提案元は、ほかの SES です。合う方がいれば「推薦する」で、提案元 SES に知らせます（名前は伏せて伝えます）。' : 'この案件は、提案元 SES がまだ決まっていません。合う方がいれば「推薦する」で、管理者に知らせます（名前は伏せて伝えます）。' }}</p>
  <x-table :head="['エンジニア', '点数', 'マッチした点', '']" :count="count($rows)" empty="登録しているエンジニアがいません。">
  @foreach($rows as $row)<tr><td><a href="{{ url('/engineers/'.$row['e']->id) }}">{{ $row['e']->name }}</a> <x-code :v="$row['e']->code" /><div class="muted">{{ lbl('engStatus', $row['e']->status) }}</div></td>
    <td><x-score :n="$row['score']" /></td><td><small>@php $rs = $row['reasons'] ?? []; $rq = []; $pf = []; $ot = [];
      foreach ($rs as $r) { if (str_starts_with($r, '必須 ')) $rq[] = substr($r, strlen('必須 ')); elseif (str_starts_with($r, '尚可 ')) $pf[] = substr($r, strlen('尚可 ')); else $ot[] = $r; } @endphp
    @if($rq)<b>必須</b> {{ implode(' ', $rq) }}<br>@endif
    @if($pf)<b>尚可</b> {{ implode('、', $pf) }}<br>@endif
    @foreach($ot as $o){{ $o }}<br>@endforeach
    @if(!$rs)—@endif</small></td>
    <td>@if(in_array($row['e']->id, $applied))<span class="muted">応募済み</span>@elseif(isset($offered[$row['e']->id]))<span class="muted">案内済み</span>
    @else<x-post :action="'/cases/'.$c->id.'/recommend'" label="推薦する" :fields="['engineer_id' => $row['e']->id]" cls="ghost small" />@endif
    @if(in_array($row['e']->id, $applied) || isset($offered[$row['e']->id]))<a class="btn ghost small" href="{{ url('/cases/'.$c->id.'/threads/'.$row['e']->id) }}">やり取り</a>@endif</td></tr>@endforeach
  </x-table>
  @endif
  </x-card>

@else
  <x-card title="あなたとの相性"><x-score :n="$fit['score']" /><p><b>合う点：</b>{{ implode('、', $fit['reasons']) ?: '—' }}</p>
  @if($fit['missing'])<p class="bad-text"><b>足りない点：</b>{{ implode('、', $fit['missing']) }}</p>@endif</x-card>
  @if($myApp)
    <x-card title="応募済み"><p>{{ badge('appStatus', $myApp->status) }}</p><p><a class="btn" href="{{ url('/applications/'.$myApp->id) }}">選考の状況を見る</a></p></x-card>
  @elseif($c->status !== 'open')
    <x-card><p class="muted">この案件は募集を終えました。</p></x-card>
  @elseif($offer && $offer->status === 'declined')
    <x-card title="見送りました"><p class="muted">{{ $offer->decline_reason }}</p></x-card>
  @else
    <x-card title="応募する"><form method="post" action="{{ url('/cases/'.$c->id.'/apply') }}">@csrf
    <p class="muted">面談で条件が合わずに落ちるのを防ぐため、応募の前に確かめてください。</p>
    <x-field :label="'開始（'.fmt_date($c->start_date).'）に間に合う'" name="start_ok" type="checkbox" />
    <x-field :label="'金額（'.man($c->price).'）で問題ない'" name="price_ok" type="checkbox" />
    <x-field :label="'働き方・場所（'.lbl('workStyle', $c->work_style).($c->location ? '／'.$c->location : '').'）で問題ない'" name="style_ok" type="checkbox" />
    <x-field label="気になること・伝えたいこと" name="condition_note" type="textarea" rows="2" />
    <button class="btn">この案件に応募する</button></form></x-card>
    @php $reasons = ['スキルが合わない', '金額が合わない', '時期が合わない', '場所・働き方が合わない', '内容に興味がない', 'その他']; @endphp
    <x-card title="見送る"><form method="post" action="{{ url('/cases/'.$c->id.'/decline') }}">@csrf
    <x-field label="理由（今後の案件選びに使います）" name="reason" type="select" :options="array_combine($reasons, $reasons)" />
    <button class="btn ghost">見送る</button></form></x-card>
  @endif
  {{-- 応募の前でも後でも、同じ1本が続く。どの状態でも出す（書けないときは理由を出す） --}}
  <x-card title="担当者とのやり取り" id="thread">
  <p class="muted">応募の前に、案件の内容で確かめたいことを担当者に聞けます。</p>
  @include('partials.case-thread', ['threads' => $threads, 'e' => $me, 'why' => $threadWhy, 'action' => '/cases/'.$c->id.'/threads/'.$u->engineer_id])
  </x-card>
@endif
</div></div>
@endif
<p class="backrow"><a class="btn ghost" href="{{ list_url('cases') }}">案件の一覧に戻る</a></p>
@endsection
