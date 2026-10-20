@extends('layouts.app', ['title' => $s->name, 'wide' => true])
@section('content')
<div class="cols"><div>
<x-card title="会社情報"><x-slot:actions>@if($s->status === 'active')<x-post :action="'/admin/ses/'.$s->id.'/suspend'" label="停止" cls="ghost small" confirm="停止すると、この SES の担当者はログインできなくなります。" />@endif
@if($s->status !== 'left')<x-post :action="'/admin/ses/'.$s->id.'/delete'" label="退会" cls="danger small" confirm="この SES と所属エンジニアを退会済にします。全員ログインできなくなります（記録は残ります）。よろしいですか？" />@endif
<a class="btn small" href="{{ url('/admin/ses/'.$s->id.'/edit') }}">編集</a>
@if(in_array($s->status, ['pending', 'suspended']))<x-post :action="'/admin/ses/'.$s->id.'/approve'" label="承認して招待を送る" cls="small" confirm="参加を承認し、担当の方に招待メールを送ります。" />@endif
</x-slot:actions><dl class="dl">
<dt>状態</dt><dd>{{ badge('sesStatus', $s->status) }}</dd><dt>代表者</dt><dd>{{ $s->rep_name ?: '—' }}</dd><dt>担当</dt><dd>{{ $s->contact_name ?: '—' }}</dd>
<dt>メール</dt><dd>{{ $s->email ?: '—' }}</dd><dt>電話</dt><dd>{{ $s->phone ?: '—' }}</dd><dt>所在地</dt><dd>{{ postal_address($s->postal_code, $s->address) }}</dd>
<dt>人数</dt><dd>{{ $s->employees ?? '—' }}</dd><dt>得意な分野</dt><dd>{{ $s->strengths ?: '—' }}</dd><dt>会社の情報の確認</dt><dd>{{ $s->self_confirmed_at ? fmt_dt($s->self_confirmed_at) : 'まだ（管理者が書き換えると、もう一度確認してもらいます）' }}</dd><dt>ひとこと</dt><dd>{!! nl2br(e($s->message)) ?: '—' !!}</dd>
<dt>守秘義務への同意</dt><dd>{{ fmt_dt($s->agreed_at) }}</dd><dt>参加日</dt><dd>{{ fmt_date($s->joined_at) }}</dd><dt>メモ</dt><dd>{!! nl2br(e($s->notes)) ?: '—' !!}</dd>
</dl>
<p><a class="btn ghost" href="{{ url('/admin/ses') }}">SES の一覧に戻る</a></p>
</x-card>
</div><div>
<x-card title="担当者のアカウント"><x-slot:actions><a class="btn small" href="{{ url('/admin/ses/'.$s->id.'/users/new') }}">担当者を登録</a></x-slot:actions>
@if($noUsable === 'stopped')<p class="bad-text">使える担当者がいません。止めている担当者を再開するか、担当者を登録してください。</p>@endif
@if($noUsable === 'left')<p class="bad-text">使える担当者がいません。担当者を登録してください。</p>@endif
<x-table :head="['名前', 'メール', '状態', '最終ログイン', '']" :count="$users->count()">
@foreach($users as $u)@php
$state = user_state($u);
$base = '/admin/ses/'.$s->id.'/users/'.$u->id;
@endphp<tr><td>{{ $u->name }}</td><td>{{ $u->email }}</td><td>{{ $state }}</td>
<td>{{ fmt_dt($u->last_login) }}</td><td>@if($state === '停止')<x-post :action="$base.'/resume'" label="再開" cls="small" :confirm="$u->name.' さんを再開します。'" />
@elseif($state !== '退会済')<x-post :action="$base.'/suspend'" label="停止" cls="ghost small" :confirm="$u->name.' さんを停止します。ログインできなくなります（再開できます）。'" />@endif
@if($state !== '退会済')<x-post :action="$base.'/delete'" label="退会" cls="danger small" :confirm="$u->name.' さんを退会済にします。ログインできなくなり、元に戻せません（記録は残ります）。よろしいですか？'" />@endif</td></tr>@endforeach
</x-table>
</x-card>
<x-card title="エンジニア">@if($s->status === 'active')<x-slot:actions><a class="btn small" href="{{ url('/engineers/new?ses='.$s->id) }}">エンジニアを登録</a></x-slot:actions> @endif
<x-table :head="['名前', '状態', '希望', '']" :count="$engs->count()">
@foreach($engs as $e)<tr><td>{{ $e->name }}</td><td>{{ badge('engStatus', $e->status) }}</td><td>{{ man($e->desired_price) }}</td><td><a class="btn small" href="{{ url('/engineers/'.$e->id) }}">詳細</a></td></tr>@endforeach
</x-table></x-card>
<x-card title="請求"><x-table :head="['月', '合計', '状態', '']" :count="$invs->count()">
@foreach($invs as $i)<tr><td>{{ $i->period }}</td><td>{{ yen($i->total) }}</td><td>{{ badge('invoiceStatus', $i->status) }}</td><td><a class="btn small" href="{{ url('/invoices/'.$i->id) }}">詳細</a></td></tr>@endforeach
</x-table></x-card>
</div></div>
@endsection
