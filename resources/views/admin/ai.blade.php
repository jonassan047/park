@extends('layouts.app', ['title' => 'AI の仕事の箱', 'wide' => true])
@section('content')
<x-card title="しくみ"><p>PARK は、AI への仕事を箱に入れるだけです。手元の PC で動く AI（<code>tools/ai-worker</code>）が取りに来て、下書きを返します。採用するかどうかは、人が画面で決めます。案件や個人の情報は、手元の PC の外には出ません。</p>
<dl class="dl"><dt>合言葉</dt><dd>{{ $hasToken ? '設定済み' : 'まだ（.env の PARK_AI_TOKEN）。AI は動きません' }}</dd>
<dt>AI が最後に取りに来た</dt><dd>{{ $lastPick ? fmt_dt($lastPick) : 'まだ一度も来ていません' }}</dd></dl></x-card>
<x-card title="仕事の一覧"><x-table :head="['#', '仕事', '頼んだ人', '頼んだ日時', '状態', 'AI']" :count="$list->count()" empty="まだありません。">
@foreach($list as $j)@php $eid = json_decode($j->input, true)['engineer_id'] ?? null; @endphp<tr><td>{{ $j->id }}</td>
<td><a href="{{ url($j->ref_type === 'interview' ? '/interviews/'.$j->ref_id : '/engineers/'.$eid.'#docs') }}">{{ \App\Services\AiJobs::KINDS[$j->kind] ?? $j->kind }}</a></td>
<td>{{ $j->name }}</td><td>{{ fmt_dt($j->created_at) }}</td><td>{{ \App\Services\AiJobs::STATUS[$j->status] ?? $j->status }}@if($j->error)<div class="bad-text small">{{ $j->error }}</div>@endif</td><td><small>{{ $j->engine }}</small></td></tr>@endforeach
</x-table></x-card>
@endsection
