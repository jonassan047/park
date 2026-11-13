@php
  $u = auth()->user();
  $own = $u->role !== 'ses' || (int) $e->ses_id === (int) $u->ses_id; // 自社のエンジニアか（違えば、イニシャルだけ出す）
  $egName = \App\Services\CaseThread::egName($u, $e, $threads->first()); // 1本あれば、その1本を作ったときの所属で見る（前の所属の会社に、今の所属の会社名を出さないため）
  $partnerDirect = \App\Services\Workflow::isDirect($partnerId);
  // 前の所属の会社（自社の ses_id の1本があり、今は所属でも相手でもない）には、今の相手の会社名を出さない
  $formerSes = $u->role === 'ses' && (int) $e->ses_id !== (int) $u->ses_id && $partnerId !== (int) $u->ses_id
      && $threads->contains(fn ($t) => (int) $t->ses_id === (int) $u->ses_id);
  // 1本はあるが、今は相手ではなくなった会社（前の所属ではない）
  $notPartner = $u->role === 'ses' && !$formerSes && !$own && $partnerId !== (int) $u->ses_id;
@endphp
@extends('layouts.app', ['title' => '案件のやり取り：'.$egName])
@section('content')
<x-card title="案件">
<dl class="dl">
<dt>案件</dt><dd><a href="{{ url('/cases/'.$c->id) }}">{{ $c->title }}</a> <x-code :v="$c->code" /> {{ badge('caseStatus', $c->status) }}</dd>
<dt>エンジニア</dt><dd>{{ $egName }} @if($own || $formerSes)<x-code :v="$e->code" />@endif</dd>
<dt>相手の会社</dt><dd>@if($formerSes)（貴社の所属ではなくなりました）@elseif($notPartner)（今は相手ではありません）@else{{ \App\Services\CaseThread::partnerName($partnerId) }}@if(!$partnerDirect && (int) $e->ses_id !== $partnerId)（案件を持ち込んだ会社）@endif @endif</dd>
</dl>
@if($u->role === 'admin' && !$partnerDirect)<p class="muted">これは、会社の担当者とエンジニアのやり取りです。管理者は読むだけです（直属のエンジニアとのやり取りだけ書けます）。読んだだけでは、担当者の「未読」は消えません。</p>@endif
</x-card>
<x-card title="やり取り" id="thread">
@include('partials.case-thread', ['threads' => $threads, 'e' => $e, 'why' => $why, 'partnerId' => $partnerId, 'action' => '/cases/'.$c->id.'/threads/'.$e->id])
</x-card>
<p class="backrow"><a class="btn ghost" href="{{ url('/threads') }}">案件のやり取りの一覧に戻る</a> <a class="btn ghost" href="{{ url('/cases/'.$c->id) }}">案件の画面へ</a></p>
@endsection
