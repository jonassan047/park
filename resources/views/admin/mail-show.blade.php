@extends('layouts.app', ['title' => 'メールの中身'])
@section('content')
<dl class="dl"><dt>宛先</dt><dd>{{ $m->to_name }} &lt;{{ $m->to_email }}&gt;</dd><dt>差出人</dt><dd>{{ $m->from_name ?: '既定' }}</dd><dt>件名</dt><dd>{{ $m->subject }}</dd><dt>状態</dt><dd>{{ badge('mailStatus', $m->status) }}</dd>
<dt>作成</dt><dd>{{ fmt_dt($m->created_at) }}</dd><dt>送信</dt><dd>{{ fmt_dt($m->sent_at) }}</dd><dt>エラー</dt><dd>{{ $m->error ?: '—' }}</dd></dl>
<pre class="mailbody">{{ $m->body }}</pre>
@if($canSend && $m->status !== 'sent')<x-post :action="'/admin/mail/'.$m->id.'/resend'" label="送り直す" />@endif <a class="btn ghost" href="{{ url('/admin/mail') }}">送信箱の一覧に戻る</a>
@endsection
