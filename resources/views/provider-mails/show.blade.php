@php
$u = auth()->user();
$mailState = ['saved' => '保存のみ（送信の設定が無い）', 'queued' => '送信待ち', 'sent' => '送信済み', 'failed' => '失敗（管理者の「メール」画面で再送できます）'];
@endphp
@extends('layouts.app', ['title' => 'プロバイダーへの返信'.($c ? '：'.id_title($c->code, $c->title) : ''), 'wide' => true])
@section('content')
<x-card title="プロバイダーへの返信">
<dl class="dl">
<dt>状態</dt><dd>{{ \App\Services\ProviderReply::STATUSES[$pm->status] ?? $pm->status }}</dd>
<dt>案件</dt><dd>@if($c)<a href="{{ url('/cases/'.$c->id) }}">{{ $c->title }}</a> <x-code :v="$c->code" />@else案件の記録はありません。@endif</dd>
<dt>宛先</dt><dd>@if($pm->status === 'sent'){{ $pm->to_name }}（{{ $pm->to_email }}）
  @elseif($pv){{ $pv->name }} {{ $pv->sei }}{{ $pv->mei }} 様（{{ $pv->email }}）
  @else<span class="bad-text">案件にプロバイダーが付いていません。案件の編集でプロバイダーを選んでから送ってください。</span>@endif</dd>
<dt>エンジニア</dt><dd>{{ $egLabel }}@if($e && $ownEg) <x-code :v="$e->code" />@endif</dd>
<dt>元の返信</dt><dd>@if($inboundLink)<a href="{{ url('/inbound/'.$pm->inbound_mail_id) }}">エンジニアの返信メール</a>@elseif($pm->inbound_mail_id)元の返信メールは、今は紐付いていません。@else受信メールはありません（案内メールの行から作りました）。@endif</dd>
@if($pm->status === 'sent')
<dt>送った日時</dt><dd>{{ fmt_dt($pm->sent_at) }}（{{ $sentBy ?: '—' }}）</dd>
<dt>メールの状態</dt><dd>{{ $outbox ? ($mailState[$outbox->status] ?? $outbox->status) : '—' }}</dd>
<dt>プロバイダーからの返信</dt><dd>@if($inboundN)<a href="{{ url(($isAdmin ? '/admin/inbound?tab=all&pm=' : '/inbound?pm=').$pm->id) }}">{{ $inboundN }} 件</a>@else<span class="muted">まだありません</span>@endif</dd>
@endif
@if($pm->status === 'discarded' && $pm->note)<dt>取りやめた理由</dt><dd>{{ $pm->note }}</dd>@endif
</dl>
<p class="muted">プロバイダーには、エンジニアはイニシャル「{{ $initials }}」で伝わります。会社名・氏名・連絡先は伝わりません。</p>
</x-card>

@if($pm->status === 'draft' && $canEdit)
<x-card title="下書き（まだ送っていません）">
@if($leaks)<div class="flash bad"><p>本文に、エンジニアの{{ implode('・', $leaks) }}が残っています。直すか、「このまま送る」に印を付けてください。</p></div>@endif
<form method="post" action="{{ url('/provider-mails/'.$pm->id) }}">@csrf
<x-field label="本人の意向" name="reply" type="select" :options="$intents" :value="$pm->reply" hint="意向を変えても、本文は変わりません。意向の文を変えたいときは［作り直す］を使ってください。" />
<x-field label="件名" name="subject" :value="$pm->subject" required />
<x-field label="本文" name="body" type="textarea" rows="18" :value="$pm->body" required />
<p class="muted">送るときに、本文の最後へ会社名・あなたの氏名・お問い合わせ番号を、件名の最後へ番号を、自動で足します。</p>
@if($leaks)<x-field label="残っていることを分かったうえで、このまま送る" name="force" type="checkbox" />@endif
<button class="btn">保存</button>
<button class="btn" formaction="{{ url('/provider-mails/'.$pm->id.'/send') }}" onclick="return confirm(@js(($pv ? $pv->name.' '.$pv->sei.' 様（'.$pv->email.'）' : 'プロバイダー').'へ送ります。よろしいですか？'))">送信</button>
</form>
<p>
<x-post :action="'/provider-mails/'.$pm->id.'/redraft'" label="作り直す" cls="ghost small" confirm="元から作り直します。直した文は消えます。よろしいですか？" />
<form method="post" action="{{ url('/provider-mails/'.$pm->id.'/discard') }}" class="inline">@csrf
<x-field label="取りやめる理由（任意）" name="note" /><button class="btn ghost small" onclick="return confirm('送らずに取りやめます。よろしいですか？')">取りやめる</button></form></p>
</x-card>
@else
<x-card :title="$pm->status === 'sent' ? '送った内容' : '内容'">
<p><b>{{ $pm->subject }}</b></p>
<p>{!! nl2br(e($pm->body)) !!}</p>
@if($pm->status === 'discarded' && $canEdit)<x-post :action="'/provider-mails/'.$pm->id.'/redraft'" label="作り直す（下書きに戻す）" cls="ghost small" />@endif
@if($pm->status === 'sent')<p class="muted">送ったあとは直せません。続きは、メールソフトで返信してください（返信先に、送った担当者のアドレスも入っています）。</p>@endif
@if($isAdmin)<p class="muted">管理者は読むだけです。</p>@endif
</x-card>
@endif

@if($cm && $pm->status === 'sent')
<x-card title="プロバイダーからの結果報告" id="result">
@include('cases._result', ['m' => $cm, 'canEdit' => $canEdit, 'back' => 'pm', 'backId' => $pm->id])
</x-card>
@endif
<p class="backrow">@if($c)<a class="btn ghost" href="{{ url('/cases/'.$c->id.'#provider-mails') }}">案件の画面へ</a>@endif</p>
@endsection
