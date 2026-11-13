{{-- 案件のやり取りの中身（発言の一覧と入力欄）。$threads は発言つき、$e はエンジニア、$why は書けない理由（書けるなら null）、$action は送り先 --}}
@php
  $u = auth()->user();
  $partnerId = $partnerId ?? \App\Services\CaseThread::currentPartner($c, $e);
  $partner = \App\Services\CaseThread::partnerName($partnerId);
  $partnerDirect = \App\Services\Workflow::isDirect($partnerId);
@endphp
@foreach($threads as $t)
  @if(!$t->current && $u->role === 'eg')<h3>前の所属（{{ $t->ses_then }}）のときのやり取り（相手：{{ $t->partner }}・読むだけ）</h3>@endif
  <div class="thread">@foreach($t->posts as $p)<div class="msg {{ $p->from_side === $u->role ? 'me' : '' }}"><div class="who">{{ $p->from_side === 'eg' ? \App\Services\CaseThread::egName($u, $e, $t) : $p->from_label }}<small>{{ fmt_dt($p->created_at) }}</small></div><div>{!! nl2br(e($p->body)) !!}</div></div>@endforeach</div>
@endforeach
@if($u->role === 'eg' && !$threads->contains(fn ($t) => $t->current))
  <p class="empty">まだやり取りはありません。案件について確かめたいことを書いて送ってください。相手：{{ $partner }}{{ $partnerDirect ? '' : 'の担当者' }}@if(!$partnerDirect && $partnerId !== (int) $e->ses_id)（{{ \App\Services\CaseThread::partnerName((int) $e->ses_id) }}も読めます）@endif</p>
@endif
@if($why)
  <p class="muted">{{ $why }}</p>
@else
  <form method="post" action="{{ url($action) }}">@csrf
  <x-field label="メッセージ（1〜2000 字）" name="body" type="textarea" rows="4" required />
  <button class="btn">送る</button></form>
@endif
