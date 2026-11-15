@extends('layouts.app', ['title' => 'プロバイダー'])
@section('content')
<p class="muted">プロバイダーは、案件を SES に提供してくれる上位者です。管理者と SES が見られます（エンジニアには出ません）。案件の登録で選びます。</p>
<p class="right"><a class="btn small" href="{{ url($base.'/new') }}">プロバイダーを登録</a></p>
<x-table :head="['ID', '企業名', '氏名', 'メール', '電話', '登録日', '']" :count="$list->count()">
@foreach($list as $p)<tr><td><x-code :v="$p->code" /></td><td>{{ $p->name }}</td><td>{{ $p->sei }} {{ $p->mei }}</td><td>{{ $p->email }}</td><td>{{ $p->phone ?: '—' }}</td><td>{{ fmt_date($p->created_at) }}</td>
<td>@if($base === '/admin/providers')<x-post :action="'/admin/providers/'.$p->id.'/delete'" label="削除" cls="danger small" confirm="このプロバイダーを削除します。元に戻せません。よろしいですか？" />@endif <a class="btn small" href="{{ url($base.'/'.$p->id) }}">詳細</a></td></tr>@endforeach
</x-table>
@include('partials.pager', ['pager' => $pager])
@if($list->isEmpty())<p class="empty">プロバイダーはまだありません。「プロバイダーを登録」から入れます。</p>@endif
@endsection
