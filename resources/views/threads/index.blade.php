@php $u = auth()->user(); @endphp
@extends('layouts.app', ['title' => '案件のやり取り', 'wide' => true])
@section('content')
<p class="muted">エンジニアが、案件について担当者に確かめた内容です。{{ $u->role === 'admin' ? '管理者は、すべてのやり取りを読めて、書けます。' : '貴社のエンジニアのものだけが出ます。' }}</p>
@if($u->role === 'admin' && $sesOptions)
<form method="get" class="inline">
<label>会社で絞る <select name="ses" onchange="this.form.submit()"><option value="">すべて</option>@foreach($sesOptions as $k => $t)<option value="{{ $k }}" @selected($sesFilter === (int) $k)>{{ $t }}</option>@endforeach</select></label>
<noscript><button class="btn small">絞る</button></noscript>
</form>
@endif
<x-table :head="array_values(array_filter(['案件', 'エンジニア', $u->role === 'admin' ? '会社' : null, '最後の発言', '未読', ''], fn ($h) => $h !== null))" :count="$list->count()" empty="まだやり取りはありません。">
@foreach($list as $t)<tr><td><x-code :v="$t->case_code" /> {{ $t->title }}</td><td>{{ $t->eg_name }} <x-code :v="$t->eg_code" /></td>
@if($u->role === 'admin')<td>{{ $t->ses_name }}</td>@endif
<td>{{ fmt_dt($t->last_post_at) }}</td>
{{-- 管理者の「未読」は、直属のものだけ（会社の1本は、読んでも未読が消えないため出さない） --}}
<td>@if($u->role === 'admin' && $t->ses_kind !== 'direct')<span class="muted">—</span>@elseif($t->unread)<b class="bad-text">{{ $t->unread }}</b>@endif</td>
<td><a class="btn small" href="{{ url('/cases/'.$t->case_id.'/threads/'.$t->engineer_id) }}">開く</a></td></tr>@endforeach
</x-table>
@include('partials.pager', ['pager' => $pager])
@endsection
