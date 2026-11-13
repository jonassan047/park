@php $u = auth()->user(); @endphp
@extends('layouts.app', ['title' => '案件のやり取り', 'wide' => true])
@section('content')
<p class="muted">エンジニアが、案件について担当者に確かめた内容です。{{ $u->role === 'admin' ? '管理者は、すべてのやり取りを読めます。書けるのは、直属のエンジニアとの1本だけです。' : '貴社のエンジニアの1本と、貴社が持ち込んだ案件で他社のエンジニアと話す1本が出ます。' }}</p>
@if($u->role === 'admin' && $sesOptions)
<form method="get" class="inline">
<label>会社で絞る <select name="ses" onchange="this.form.submit()"><option value="">すべて</option>@foreach($sesOptions as $k => $t)<option value="{{ $k }}" @selected($sesFilter === (int) $k)>{{ $t }}</option>@endforeach</select></label>
<noscript><button class="btn small">絞る</button></noscript>
</form>
@endif
<x-table :head="array_values(array_filter(['案件', 'エンジニア', $u->role === 'admin' ? '会社' : null, $u->role === 'admin' ? '相手' : null, '最後の発言', '未読', ''], fn ($h) => $h !== null))" :count="$list->count()" empty="まだやり取りはありません。">
@foreach($list as $t)<tr><td><x-code :v="$t->case_code" /> {{ $t->title }}</td><td>{{ \App\Services\CaseThread::egNameRow($u->role === 'ses' ? (int) $u->ses_id : null, $t) }} @if(\App\Services\CaseThread::isOwnEg($u, $t))<x-code :v="$t->eg_code" />@endif</td>
@if($u->role === 'admin')<td>{{ $t->ses_name }}</td><td>{{ $t->partner_kind === 'direct' ? '管理者' : $t->partner_name }}</td>@endif
<td>{{ fmt_dt($t->last_post_at) }}</td>
{{-- 「未読」は、自分が相手の1本だけ（読むだけの1本は、読んでも未読が消えないため出さない） --}}
<td>@if(\App\Services\CaseThread::isMine($u, $t))@if($t->unread)<b class="bad-text">{{ $t->unread }}</b>@endif @elseif($u->role === 'ses')<span class="muted">読むだけ（相手：{{ $t->partner_name }}）</span>@else<span class="muted">—</span>@endif</td>
<td><a class="btn small" href="{{ url('/cases/'.$t->case_id.'/threads/'.$t->engineer_id) }}">開く</a></td></tr>@endforeach
</x-table>
@include('partials.pager', ['pager' => $pager])
@endsection
