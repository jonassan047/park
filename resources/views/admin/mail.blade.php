@extends('layouts.app', ['title' => 'メール', 'wide' => true])
@section('content')
@if($canSend)<p>メールは {{ config('mail.default') }}（{{ config('mail.mailers.smtp.host') }}）から送っています。</p>
@else<p class="flash warn">メールの送信が設定されていないため、メールは<b>送らずに保存だけ</b>しています（中身はここで確認できます）。送るには <code>.env</code> の MAIL_ の欄を設定します（README）。</p>@endif
<div class="cols"><div>
<x-card title="送信箱">@if($canSend)<x-post action="/admin/mail/flush" label="送信待ちを今すぐ送る" cls="ghost small" />@endif
<x-table :head="['日時', '宛先', '件名', '種類', '状態', '']" :count="$list->count()">
@foreach($list as $m)<tr><td>{{ fmt_dt($m->created_at) }}</td><td>{{ $m->to_name }}<div class="muted">{{ $m->to_email }}</div></td><td>{{ $m->subject }}</td><td>{{ $m->kind }}</td>
<td>{{ badge('mailStatus', $m->status) }}@if($m->error)<div class="bad-text small">{{ $m->error }}</div>@endif</td><td><a href="{{ url('/admin/mail/'.$m->id) }}">中身</a></td></tr>@endforeach
</x-table></x-card>
</div><div>
<x-card title="一斉配信"><form method="post" action="{{ url('/admin/mail/broadcast') }}" onsubmit="return confirm('一斉にメールを配信します。よろしいですか？')">@csrf
<x-field label="宛先" name="target" type="select" :options="['ses' => '参加中の SES の担当者すべて', 'eg' => 'エンジニアすべて', 'eg_available' => '待機中のエンジニア', 'all' => 'SES とエンジニアすべて']" value="ses" />
<x-field label="件名" name="subject" required /><x-field label="本文" name="body" type="textarea" rows="8" required hint="宛名（◯◯ 様）は自動で入ります。" />
<button class="btn">配信する</button></form></x-card>
</div></div>
@endsection
