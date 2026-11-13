@php $u = auth()->user(); $tabs = ['in' => '受け取った', 'out' => '送った'] + ($u->role === 'admin' ? ['all' => 'すべて'] : []); @endphp
@extends('layouts.app', ['title' => '連絡メール', 'wide' => true])
@section('content')
<p class="muted">人から人へ、自由な件名・本文でメールを送る機能です（案件の案内メールとは別です）。@if($u->role === 'eg')所属の会社の担当者（管理者の直属なら管理者）に送れます。@else「エンジニア」の一覧で相手を選んで送ります。@endif</p>
<p class="tabs">@foreach($tabs as $k => $v)<a href="{{ url('/mails?box='.$k) }}" class="{{ $k === $box ? 'on' : '' }}">{{ $v }}</a>@endforeach
@if($u->role === 'eg')<a class="btn small" href="{{ url('/mails/new') }}">担当者にメールを送る</a>
@else<a class="btn ghost small" href="{{ url($u->role === 'admin' ? '/admin/engineers' : '/ses/engineers') }}">エンジニアの一覧へ</a>@endif</p>
<x-table :head="$box === 'in' ? ['日時', '件名', '送った人'] : ($box === 'out' ? ['日時', '件名', '宛先'] : ['日時', '件名', '送った人', '宛先'])" :count="$rows->count()" empty="まだありません。">
@foreach($rows as $m)<tr><td>{{ fmt_dt($m->created_at) }}</td><td><a href="{{ url('/mails/'.$m->id) }}">{{ $m->subject }}</a></td>
@if($box === 'all')<td>{{ $m->from_label }}</td><td>{{ \App\Services\DirectMail::recipientsText($m) }}</td>
@elseif($box === 'out')<td>{{ \App\Services\DirectMail::recipientsText($m) }}</td>
@else<td>{{ $m->from_label }}</td>@endif</tr>@endforeach
</x-table>
@if($page > 1 || $more)<p>@if($page > 1)<a class="btn ghost small" href="{{ url('/mails?box='.$box.'&page='.($page - 1)) }}">前へ</a> @endif @if($more)<a class="btn ghost small" href="{{ url('/mails?box='.$box.'&page='.($page + 1)) }}">次へ</a>@endif</p>@endif
@endsection
