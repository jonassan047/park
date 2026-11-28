@extends('layouts.app', ['title' => 'SES・担当者一覧'])
@section('content')
@include('partials.ses-view-tabs', ['view' => 'company'])
@include('partials.left-toggle')
{{-- 退会済みを隠して 0 行のときは、「まだありません」を出さず、件数の文だけにする --}}
@if($list->isNotEmpty() || !$left['hidden'])
<x-table :head="['ID', '企業', 'PARK窓口担当者', '参加状況', 'エンジニア', '稼働中', '参加日', '']" :count="$list->count()" empty="SES はまだありません。「SES を仮登録」から入れます。">
@foreach($list as $s)@php
$base = '/admin/ses/'.$s->id; $back = ['back' => 'list', 'back_query' => request()->getQueryString()];
$rep = $reps[$s->rep_user_id] ?? null;
// 仮登録中の企業は、PARK窓口担当者の仮登録の状況（期限切れなど）も出す
$repState = $s->status === 'provisional' ? user_state($rep) : null;
@endphp<tr @class(['is-left' => is_left_row('ses', $s)])><td><x-code :v="$s->code" /></td><td>{{ $s->name }}</td><td><x-code :v="$rep->staff_code ?? null" /> {{ \App\Services\Registration::repName($s)[0] ?: '—' }}@if($rep)<br><small class="muted">{{ account_mail($rep) }}</small>@endif</td><td>{{ badge('sesStatus', $s->status) }}@if($repState && $repState !== PARK_ST_PROVISIONAL) <span class="badge warn">窓口：{{ $repState }}</span>@endif</td><td>{{ $s->engs }}</td><td>{{ $s->working }}</td><td>{{ fmt_date($s->joined_at) }}</td>
<td>@if($s->status === 'provisional' && $rep)<x-post :action="'/people/'.$rep->id.'/resend'" label="送り直し" cls="ghost small" />
@elseif($s->status === 'suspended')<x-post :action="$base.'/resume'" label="再開" cls="small" :fields="$back" confirm="この SES を再開します。担当者がログインできるようになります。" />
@elseif($s->status === 'active')<x-post :action="$base.'/suspend'" label="停止" cls="ghost small" :fields="$back" confirm="停止すると、この SES の担当者はログインできなくなります。" />@endif
@if($s->status !== 'left')<x-post :action="$base.'/delete'" label="退会" cls="danger small" :fields="$back" confirm="この SES と所属エンジニアを退会済みにします。全員ログインできなくなります（記録は残ります）。よろしいですか？" />@endif
<a class="btn small" href="{{ url($base) }}">詳細</a></td></tr>@endforeach
</x-table>
@endif
@include('partials.pager', ['pager' => $pager])
<x-status-legend kind="ses" /><x-status-legend kind="account" />
@include('partials.left-note', $left)
@endsection
