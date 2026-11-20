@php $u = auth()->user(); @endphp
@extends('layouts.app', ['title' => '返信メール', 'wide' => true])
@section('content')
<x-card title="返信メール">
<dl class="dl">
<dt>受け取った日時</dt><dd>{{ fmt_dt($m->received_at) }}@if($m->auto) <span class="badge mute">自動（{{ $m->auto === 'bounce' ? '不達の通知' : '自動返信' }}）</span>@endif</dd>
<dt>差出人</dt><dd>{{ $m->sender }}@if($isAdmin)<div class="muted">{{ trim($m->from_name.' <'.$m->from_email.'>') }}</div>@endif @if($m->from_mismatch)<div class="bad-text">登録と違うアドレスから届いています。</div>@endif</dd>
<dt>件名</dt><dd>{{ $m->subject ?: '（件名なし）' }}</dd>
<dt>案件</dt><dd>@if($c)<a href="{{ url('/cases/'.$c->id) }}">{{ $c->title }}</a> <x-code :v="$c->code" />@elseif($m->case_id)案件の記録はありません。@else—@endif</dd>
@if($m->case_mail_id)<dt>案内メール</dt><dd>@if($cm)<a href="{{ url('/cases/'.$m->case_id.'#mails') }}">案内メールの記録</a>（{{ fmt_dt($cm->sent_at) }}）@else案内メールの記録はありません。@endif</dd>@endif
@if($isAdmin)
<dt>状態</dt><dd>{{ \App\Services\Inbound::STATUSES[$m->status] ?? $m->status }}@if($m->match_by)（{{ \App\Services\Inbound::MATCH_BY[$m->match_by] ?? $m->match_by }}）@endif</dd>
<dt>会社</dt><dd>{{ $m->ses_name ?: '—' }}</dd>
<dt>宛先</dt><dd>{{ $m->to_addrs ?: '—' }}</dd>
@if($m->note)<dt>メモ</dt><dd>{{ $m->note }}</dd>@endif
@endif
</dl>
@if($m->masked)<p class="muted">ほかの会社のエンジニアの返信なので、氏名・ふりがな・メールアドレス・電話番号は伏せています。</p>@endif
<h3>本文</h3>
<p>{!! nl2br(e($m->body_text)) !!}</p>
@if($m->files)
<h3>添付</h3>
<ul>@foreach($m->files as $f)<li>{{ $f['name'] ?? '' }}（{{ $f['type'] ?? '' }}・{{ number_format(((int) ($f['size'] ?? 0)) / 1024, 1) }} KB）</li>@endforeach</ul>
<p class="muted">添付の中身は、この画面からは取り出せません。</p>
@endif
@if($isAdmin)<p><a class="btn ghost small" href="{{ url('/inbound/'.$m->id.'/raw') }}">元のメール（.eml）</a></p>
<p class="muted">大きすぎるメールは、元のメールの一部（ヘッダーなど）だけを保存します。</p>@endif
</x-card>

@if($m->status === 'linked' && !$m->auto)
@if($isProvider)
<x-card title="プロバイダーからの返信">
<p>プロバイダー（{{ $providerName ?: '—' }}）からの返信です。@if($pm)<a href="{{ url('/provider-mails/'.$pm->id) }}">送ったメール</a>に対する返事です。@endif</p>
@if($cm)<h3>先方の結果</h3>@include('cases._result', ['m' => $cm, 'canEdit' => $canResult, 'back' => 'inbound', 'backId' => $m->id])@endif
</x-card>
@else
<x-card title="プロバイダーへの返信">
@if($pm)<p>{{ \App\Services\ProviderReply::STATUSES[$pm->status] ?? $pm->status }}：<a class="btn small" href="{{ url('/provider-mails/'.$pm->id) }}">開く</a></p>
@elseif($canResult)<p class="muted">この返信の下書きは、まだありません。</p><x-post :action="'/inbound/'.$m->id.'/provider-draft'" label="下書きを作る" cls="small" />
@else<p class="muted">下書きはありません。作れるのは、持ち込み元の担当者だけです。</p>@endif
</x-card>
@endif
@endif

@if($isAdmin)
@if($m->status === 'linked')
<x-card title="紐付け">
<p>この返信は、{{ $sourceName ?: '（会社なし）' }} の担当者に見えています（提案元 SES）。</p>
@if($notifyName)<p>お知らせは、案内メールを送った {{ $notifyName }} の担当者に届きました。</p>@endif
<x-post :action="'/inbound/'.$m->id.'/unlink'" label="紐付けを外す" cls="ghost small" confirm="紐付けを外します。担当者からは見えなくなります。よろしいですか？" />
</x-card>
@elseif($m->status === 'ignored')
<x-card title="対象外"><p>対象外にしています。</p><x-post :action="'/inbound/'.$m->id.'/restore'" label="未紐付けに戻す" cls="ghost small" /></x-card>
@else
<x-card title="案内メールに紐付ける">
<form method="get" action="{{ url('/inbound/'.$m->id) }}" class="inline">
<x-field label="案件 ID（CA-）" name="code" :value="$search['code'] ?? ''" placeholder="CA-000001" />
<button class="btn small">探す</button></form>
@if($search)
  @if(!$search['case'])<p class="empty">その案件 ID の案件は見つかりません。</p>
  @else
  <p><a href="{{ url('/cases/'.$search['case']->id) }}">{{ $search['case']->title }}</a> <x-code :v="$search['case']->code" /> の案内メール</p>
  <x-table :head="['エンジニア', '所属の会社', '送った日', '']" :count="$search['mails']->count()" empty="この案件の案内メールはまだありません。">
  @foreach($search['mails'] as $row)<tr><td>{{ $row->eg_name }} <x-code :v="$row->eg_code" /></td><td>{{ $row->eg_ses_name ?: '—' }}</td><td>{{ fmt_dt($row->sent_at) }}</td>
  <td><a class="btn small" href="{{ url('/inbound/'.$m->id.'?code='.urlencode($search['code']).'&pick='.$row->id) }}">これを選ぶ</a></td></tr>@endforeach
  </x-table>
  @if($search['pick'])
  <div class="flash warn">
  <p>この返信は、{{ $search['sourceName'] ? $search['sourceName'].' の担当者に見えるようになります（提案元 SES）。' : '提案元 SES がないので、だれにも見えません。' }}選び間違えると、ほかの会社に見えてしまいます。よく確かめてください。</p>
  @if($search['notifyName'])<p>お知らせは、案内メールを送った {{ $search['notifyName'] }} の担当者に届きます（見える会社と違います）。</p>@endif
  <p>選んだ案内メール：{{ $search['pick']->eg_name }}（{{ fmt_dt($search['pick']->sent_at) }} に送信）</p>
  <x-post :action="'/inbound/'.$m->id.'/link'" label="紐付ける" :fields="['case_mail_id' => $search['pick']->id, 'case_id' => $search['case']->id]" />
  </div>
  @endif
  @endif
@endif
<hr>
<form method="post" action="{{ url('/inbound/'.$m->id.'/ignore') }}" class="inline">@csrf
<x-field label="対象外にする理由（任意）" name="note" />
<button class="btn ghost small">対象外にする</button></form>
</x-card>
@endif
<p class="backrow"><a class="btn ghost" href="{{ url('/admin/inbound') }}">受信メールの一覧に戻る</a></p>
@else
<p class="backrow"><a class="btn ghost" href="{{ url('/inbound') }}">返信メールの一覧に戻る</a>@if($c) <a class="btn ghost" href="{{ url('/cases/'.$c->id) }}">案件の画面へ</a>@endif</p>
@endif
@endsection
