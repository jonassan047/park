@php $u = auth()->user(); @endphp
@extends('layouts.app', ['title' => '受信メール', 'wide' => true])
@section('content')
<x-card title="受信の状態">
<x-slot:actions><x-post action="/admin/inbound/fetch" label="今すぐ取り込む" cls="ghost small" /></x-slot:actions>
<dl class="dl">
<dt>受信の設定</dt><dd>{{ $enabled ? 'あり' : 'まだ（.env の PARK_IMAP_* が空です。案内メールの返信先は今までどおりです）' }}</dd>
<dt>最後に取り込んだ日時</dt><dd>{{ $lastFetch ? fmt_dt($lastFetch) : 'まだ' }}</dd>
</dl>
<p class="muted">管理者は、すべての会社のエンジニアの返信を、氏名を伏せずに読めます（公平性のルールの対象です）。</p>
</x-card>
<p>
@foreach($tabs as $k => $label)<a class="btn small {{ $tab === $k ? '' : 'ghost' }}" href="{{ url('/admin/inbound?tab='.$k) }}">{{ $label }}（{{ $counts[$k] ?? 0 }}）</a> @endforeach
</p>
<x-table :head="['受け取った日時', '差出人', '件名', '案件', '会社', '紐付け方', '状態', '']" :count="$list->count()" empty="まだ受信メールはありません。">
@foreach($list as $m)<tr><td>{{ fmt_dt($m->received_at) }}</td>
<td>{{ \App\Services\Inbound::senderLabel($u, $m) }}@if($m->auto) <span class="badge mute">自動</span>@endif</td>
<td>{{ $m->subject ?: '（件名なし）' }}</td>
<td>@if($m->case_title)<a href="{{ url('/cases/'.$m->case_id) }}">{{ $m->case_title }}</a> <x-code :v="$m->case_code" />@else—@endif</td>
<td>{{ $m->ses_name ?: '—' }}</td>
<td>{{ \App\Services\Inbound::MATCH_BY[$m->match_by] ?? '—' }}</td>
<td>{{ \App\Services\Inbound::STATUSES[$m->status] ?? $m->status }}</td>
<td><a class="btn small" href="{{ url('/inbound/'.$m->id) }}">開く</a></td></tr>@endforeach
</x-table>
@include('partials.pager', ['pager' => $pager])
@endsection
