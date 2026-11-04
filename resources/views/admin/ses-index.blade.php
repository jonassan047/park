@extends('layouts.app', ['title' => 'SES'])
@section('content')
<p class="right"><a class="btn small" href="{{ url('/admin/ses/new') }}">SES を仮登録</a></p>
<x-table :head="['会社', 'SES', '状態', 'エンジニア', '稼働中', '参加日', '']" :count="$list->count()">
@foreach($list as $s)@php
$base = '/admin/ses/'.$s->id; $back = ['back' => 'list', 'back_query' => request()->getQueryString()];
$rep = $reps[$s->rep_user_id] ?? null;
// 仮登録の会社は、SES の仮登録の状態（期限切れなど）も出す
$repState = $s->status === 'provisional' ? user_state($rep) : null;
@endphp<tr><td>{{ $s->name }}</td><td>{{ \App\Services\Registration::repName($s)[0] ?: '—' }}@if($rep)<br><small class="muted">{{ account_mail($rep) }}</small>@endif</td><td>{{ badge('sesStatus', $s->status) }}@if($repState && $repState !== '仮登録') <span class="badge warn">{{ $repState }}</span>@endif</td><td>{{ $s->engs }}</td><td>{{ $s->working }}</td><td>{{ fmt_date($s->joined_at) }}</td>
<td>@if($s->status === 'provisional' && $rep)<x-post :action="'/people/'.$rep->id.'/resend'" label="送り直し" cls="ghost small" />
@elseif($s->status === 'suspended')<x-post :action="$base.'/resume'" label="再開" cls="small" :fields="$back" confirm="この SES を再開します。担当者がログインできるようになります。" />
@elseif($s->status === 'active')<x-post :action="$base.'/suspend'" label="停止" cls="ghost small" :fields="$back" confirm="停止すると、この SES の担当者はログインできなくなります。" />@endif
@if($s->status !== 'left')<x-post :action="$base.'/delete'" label="退会" cls="danger small" :fields="$back" confirm="この SES と所属エンジニアを退会済にします。全員ログインできなくなります（記録は残ります）。よろしいですか？" />@endif
<a class="btn small" href="{{ url($base) }}">詳細</a></td></tr>@endforeach
</x-table>
@if($list->isEmpty())<p class="empty">SES はまだありません。「SES を仮登録」から入れます。</p>@endif
@endsection
