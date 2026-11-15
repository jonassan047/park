@extends('layouts.app', ['title' => 'プロバイダー'])
@section('content')
<p class="muted">プロバイダーは、案件を SES に提供してくれる上位者です。管理者だけが見られます（エンジニアには出ません）。</p>
<p class="right"><a class="btn small" href="{{ url('/admin/providers/new') }}">プロバイダーを登録</a></p>
<x-table :head="['ID', '企業名', '氏名', 'メール', '電話', '登録日', '']" :count="$list->count()">
@foreach($list as $p)<tr><td><x-code :v="$p->code" /></td><td>{{ $p->name }}</td><td>{{ $p->sei }} {{ $p->mei }}</td><td>{{ $p->email }}</td><td>{{ $p->phone ?: '—' }}</td><td>{{ fmt_date($p->created_at) }}</td>
<td><x-post :action="'/admin/providers/'.$p->id.'/delete'" label="削除" cls="danger small" confirm="このプロバイダーを削除します。元に戻せません。よろしいですか？" /> <a class="btn small" href="{{ url('/admin/providers/'.$p->id) }}">詳細</a></td></tr>@endforeach
</x-table>
@include('partials.pager', ['pager' => $pager])
@if($list->isEmpty())<p class="empty">プロバイダーはまだありません。「プロバイダーを登録」から入れます。</p>@endif
@endsection
