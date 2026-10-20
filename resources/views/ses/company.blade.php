@extends('layouts.app', ['title' => $s->name, 'wide' => true])
@section('content')
<div class="cols"><div>
<x-card title="会社情報"><x-slot:actions><a class="btn ghost small" href="{{ url('/account#password') }}">パスワード変更</a>
<a class="btn small" href="{{ url('/ses/company/edit') }}">編集</a>
</x-slot:actions><dl class="dl">
<dt>状態</dt><dd>{{ badge('sesStatus', $s->status) }}</dd><dt>代表者</dt><dd>{{ $s->rep_name ?: '—' }}</dd><dt>担当</dt><dd>{{ $s->contact_name ?: '—' }}</dd>
<dt>メール</dt><dd>{{ $s->email ?: '—' }}</dd><dt>電話</dt><dd>{{ $s->phone ?: '—' }}</dd><dt>所在地</dt><dd>{{ postal_address($s->postal_code, $s->address) }}</dd>
<dt>人数</dt><dd>{{ $s->employees ?? '—' }}</dd><dt>得意な分野</dt><dd>{{ $s->strengths ?: '—' }}</dd><dt>ひとこと</dt><dd>{!! nl2br(e($s->message)) ?: '—' !!}</dd>
<dt>守秘義務への同意</dt><dd>{{ fmt_dt($s->agreed_at) }}</dd><dt>参加日</dt><dd>{{ fmt_date($s->joined_at) }}</dd>
</dl>
</x-card>
</div><div>
<x-card title="担当者"><x-table :head="['名前', 'メール', '状態', '最終ログイン', '']" :count="$users->count()">
@foreach($users as $u)@php
$state = user_state($u);
$base = '/admin/ses/'.$s->id.'/users/'.$u->id;
$self = (int) $u->id === (int) auth()->id(); // ご自身の行には操作を出さない
@endphp<tr><td>{{ $u->name }}@if($u->engineer_id) <a class="badge info" href="{{ url('/engineers/'.$u->engineer_id) }}">エンジニア兼任</a>@endif</td><td>{{ $u->email }}</td><td>{{ $state }}</td>
<td>{{ fmt_dt($u->last_login) }}</td><td>@if(!$self && $state !== '退会済')@if($state === '停止')<x-post :action="$base.'/resume'" label="再開" cls="small" :confirm="$u->name.' さんを再開します。'" />
@else<x-post :action="$base.'/suspend'" label="停止" cls="ghost small" :confirm="$u->name.' さんを停止します。ログインできなくなります（再開できます）。'" />@endif
<x-post :action="$base.'/delete'" label="退会" cls="danger small" :confirm="$u->name.' さんを退会済にします。ログインできなくなり、元に戻せません（記録は残ります）。よろしいですか？'" />@endif</td></tr>@endforeach
</x-table></x-card>
<x-card title="エンジニア">@if($s->status === 'active')<x-slot:actions><a class="btn small" href="{{ url('/engineers/new') }}">エンジニアを登録</a></x-slot:actions> @endif
<x-table :head="['名前', '状態', '希望', '']" :count="$engs->count()">
@foreach($engs as $e)@php $egBase = '/engineers/'.$e->id; $back = ['back' => 'ses']; @endphp<tr><td>{{ $e->name }}</td><td>{{ badge('engStatus', $e->status) }}@if($e->suspended_at) <span class="badge mute">停止中</span>@endif</td><td>{{ man($e->desired_price) }}</td>
<td>@if($e->status !== 'left')@if($e->suspended_at)<x-post :action="$egBase.'/resume'" label="再開" cls="small" :fields="$back" confirm="このエンジニアを再開します。ログインでき、案件の配信の候補にも出るようになります。" />
@else<x-post :action="$egBase.'/suspend'" label="停止" cls="ghost small" :fields="$back" :confirm="engineer_stop_confirm(isset($working[$e->id]), (int) ($inSel[$e->id] ?? 0))" />@endif
<x-post :action="$egBase.'/delete'" label="退会" cls="danger small" :fields="$back" confirm="このエンジニアを退会済にします。ログインできなくなり、元に戻せません（記録は残ります）。よろしいですか？" />@endif
<a class="btn small" href="{{ url($egBase) }}">詳細</a></td></tr>@endforeach
</x-table></x-card>
<x-card title="請求"><x-table :head="['月', '合計', '状態', '']" :count="$invs->count()">
@foreach($invs as $i)<tr><td>{{ $i->period }}</td><td>{{ yen($i->total) }}</td><td>{{ badge('invoiceStatus', $i->status) }}</td><td><a class="btn small" href="{{ url('/invoices/'.$i->id) }}">詳細</a></td></tr>@endforeach
</x-table></x-card>
</div></div>
@endsection
