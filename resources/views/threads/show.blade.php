@php $u = auth()->user(); @endphp
@extends('layouts.app', ['title' => '案件のやり取り：'.$e->name])
@section('content')
<x-card title="案件">
<dl class="dl">
<dt>案件</dt><dd><a href="{{ url('/cases/'.$c->id) }}">{{ $c->title }}</a> <x-code :v="$c->code" /> {{ badge('caseStatus', $c->status) }}</dd>
<dt>エンジニア</dt><dd>{{ $e->name }} <x-code :v="$e->code" /></dd>
<dt>相手の会社</dt><dd>@if($u->role === 'ses' && (int) $e->ses_id !== (int) $u->ses_id)（貴社の所属ではなくなりました）@else{{ \App\Services\CaseThread::partnerName((int) $e->ses_id) }}@endif</dd>
</dl>
@if($u->role === 'admin' && !\App\Services\Workflow::isDirect((int) $e->ses_id))<p class="muted">これは、会社の担当者とエンジニアのやり取りです。管理者として書くと「{{ setting('operator_name') }}」と出て、会社の担当者にも知らせが届きます。読んだだけでは、会社の担当者の「未読」は消えません。</p>@endif
</x-card>
<x-card title="やり取り" id="thread">
@include('partials.case-thread', ['threads' => $threads, 'e' => $e, 'why' => $why, 'action' => '/cases/'.$c->id.'/threads/'.$e->id])
</x-card>
<p class="backrow"><a class="btn ghost" href="{{ url('/threads') }}">案件のやり取りの一覧に戻る</a> <a class="btn ghost" href="{{ url('/cases/'.$c->id) }}">案件の画面へ</a></p>
@endsection
