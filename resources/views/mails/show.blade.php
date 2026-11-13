@php $u = auth()->user(); @endphp
@extends('layouts.app', ['title' => $m->subject])
@section('content')
<x-card title="連絡メール">
<x-slot:actions>@if($fromEng)<a class="btn small" href="{{ url('/engineers/'.$fromEng) }}">このエンジニアの詳細</a>@endif
<a class="btn ghost small" href="{{ url('/mails') }}">一覧に戻る</a></x-slot:actions>
<dl class="dl">
<dt>送った人</dt><dd>{{ $m->from_label }} <span class="muted">（{{ lbl('role', $m->from_role) }}）</span></dd>
<dt>日時</dt><dd>{{ fmt_dt($m->created_at) }}</dd>
@if($recipients->count())<dt>宛先</dt><dd>{{ $m->to_count }}名</dd>@elseif($mine)<dt>宛先</dt><dd>あなた</dd>@endif
</dl>
<p>{!! nl2br(e($m->body)) !!}</p>
</x-card>
@if($recipients->count())
<x-card title="宛先の一覧">
<x-table :head="['名前', '立場', '状態']" :count="$recipients->count()">
@foreach($recipients as $r)<tr><td>{{ $r->to_label }}</td><td>{{ lbl('role', $r->to_role) }}</td><td>@if($r->mail_status){{ badge('mailStatus', $r->mail_status) }}@else<span class="muted">—</span>@endif</td></tr>@endforeach
</x-table>
</x-card>
@endif
@endsection
