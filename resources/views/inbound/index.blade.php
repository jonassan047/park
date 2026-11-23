@php $u = auth()->user(); @endphp
@extends('layouts.app', ['title' => '返信メール', 'wide' => true])
@section('content')
<p class="muted">案内メールへの返信です。貴社が受注 SES の案件の分だけが出ます。ほかの会社のエンジニアの氏名・連絡先は伏せています。開くと「読んだ」になります（会社で 1 つ）。</p>
<x-table :head="['受け取った日時', '差出人', '件名', '案件', '']" :count="$list->count()" empty="まだ返信メールはありません。">
@foreach($list as $row)@php $m = \App\Services\Inbound::masked($u, $row); $unread = !$row->ses_read_at && !$row->auto; @endphp
<tr><td>{{ fmt_dt($row->received_at) }}</td>
<td>{{ $m->sender }}@if($row->auto) <span class="badge mute">自動</span>@endif</td>
<td>@if($unread)<b>{{ $m->subject ?: '（件名なし）' }}</b> <span class="badge warn">未読</span>@else{{ $m->subject ?: '（件名なし）' }}@endif</td>
<td>@if($row->case_title)<a href="{{ url('/cases/'.$row->case_id) }}">{{ $row->case_title }}</a> <x-code :v="$row->case_code" />@else—@endif</td>
<td><a class="btn small" href="{{ url('/inbound/'.$row->id) }}">開く</a></td></tr>@endforeach
</x-table>
@include('partials.pager', ['pager' => $pager])
@endsection
