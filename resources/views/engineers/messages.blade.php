@php $u = auth()->user(); @endphp
@extends('layouts.app', ['title' => $u->role === 'eg' ? '運営とのやり取り' : 'やり取り：'.$e->name])
@section('content')
<p class="muted">スキルシート・面談の準備について、運営と相談できます。@if($u->role === 'eg')「見てほしい」と送れば、運営がスキルシートを確認して返事をします。@endif</p>
<div class="thread">@forelse($msgs as $m)<div class="msg {{ $m->role === $u->role ? 'me' : '' }}"><div class="who">{{ $m->name ?: '—' }}<small>{{ fmt_dt($m->created_at) }}</small></div><div>{!! nl2br(e($m->body)) !!}</div></div>
@empty<p class="empty">まだやり取りはありません。</p>@endforelse</div>
<x-card><form method="post" action="{{ url('/engineers/'.$e->id.'/messages') }}">@csrf
<x-field label="メッセージ" name="body" type="textarea" rows="3" required />
@if($u->role === 'eg')<x-field label="スキルシートを見てほしい（レビューの依頼）" name="review" type="checkbox" />@endif
<button class="btn">送る</button></form></x-card>
@endsection
