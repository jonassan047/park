@extends('layouts.app', ['title' => $s->name, 'wide' => true])
@section('content')
<div class="cols"><div>
<x-card title="会社情報"><x-slot:actions><a class="btn ghost small" href="{{ url('/account#password') }}">パスワード変更</a>
@if($isRep)<a class="btn small" href="{{ url('/ses/company/edit') }}">編集</a>@endif
</x-slot:actions><dl class="dl">
<dt>状態</dt><dd>{{ badge('sesStatus', $s->status) }}</dd><dt>SES</dt><dd>{{ $repName ?: '—' }}</dd>
<dt>メール</dt><dd>{{ $s->email ?: '—' }}</dd><dt>電話</dt><dd>{{ $s->phone ?: '—' }}</dd><dt>所在地</dt><dd>{{ postal_address($s->postal_code, $s->address) }}</dd>
<dt>人数</dt><dd>{{ $s->employees ?? '—' }}</dd><dt>得意な分野</dt><dd>{{ $s->strengths ?: '—' }}</dd><dt>ひとこと</dt><dd>{!! nl2br(e($s->message)) ?: '—' !!}</dd>
<dt>守秘義務への同意</dt><dd>{{ fmt_dt($s->agreed_at) }}</dd><dt>参加日</dt><dd>{{ fmt_date($s->joined_at) }}</dd>
</dl>
</x-card>
</div><div>
<x-card title="担当者">@if($isRep && $s->status === 'active')<x-slot:actions><a class="btn small" href="{{ url('/ses/company/users/new') }}">担当者を登録</a></x-slot:actions> @endif
@unless($isRep)<p class="muted">担当者の登録・停止・退会は、SES だけができます。SES の交代は、管理者にご依頼ください。</p>@endunless
<x-table :head="['名前', 'メール', '状態', '最終ログイン', '']" :count="$users->count()">
@foreach($users as $u)@php
$state = user_state($u);
$base = '/admin/ses/'.$s->id.'/users/'.$u->id;
$self = (int) $u->id === (int) auth()->id(); // ご自身の行には操作を出さない
$isRepRow = (int) $u->id === (int) $repId || (int) $u->id === (int) $s->next_rep_user_id; // SES（次の SES）の行は、交代のあとでなければ止められない
@endphp<tr><td>{{ $u->name }}@if((int) $u->id === (int) $repId) <span class="badge info">SES</span>@elseif((int) $u->id === (int) $s->next_rep_user_id) <span class="badge info">次の SES</span>@endif
@if($u->engineer_id) <a class="badge info" href="{{ url('/engineers/'.$u->engineer_id) }}">エンジニア兼任</a>@endif</td><td>{{ $u->email }}</td><td>{{ $state }}</td>
<td>{{ fmt_dt($u->last_login) }}</td><td>@if($isRep && !$isRepRow && is_provisional_state($state))<x-post :action="'/people/'.$u->id.'/resend'" label="送り直し" cls="ghost small" />
<a class="btn ghost small" href="{{ url('/people/'.$u->id.'/edit') }}">仮登録の内容を直す</a>
<x-post :action="'/people/'.$u->id.'/cancel'" label="取り消し" cls="danger small" confirm="この仮登録を取り消します。元に戻せません。よろしいですか？" />@endif
@if($isRep && !$self && !$isRepRow && $state !== '退会済')@if($state === '停止')<x-post :action="$base.'/resume'" label="再開" cls="small" :confirm="$u->name.' さんを再開します。'" />
@else<x-post :action="$base.'/suspend'" label="停止" cls="ghost small" :confirm="$u->name.' さんを停止します。ログインできなくなります（再開できます）。'" />@endif
<x-post :action="$base.'/delete'" label="退会" cls="danger small" :confirm="$u->name.' さんを退会済にします。ログインできなくなり、元に戻せません（記録は残ります）。よろしいですか？'" />@endif</td></tr>@endforeach
</x-table></x-card>
<x-card title="エンジニア">@if($s->status === 'active')<x-slot:actions><a class="btn small" href="{{ url('/engineers/new') }}">エンジニアを登録</a></x-slot:actions> @endif
<x-table :head="['名前', '状態', 'アカウント', '希望', '']" :count="$engs->count()">
@foreach($engs as $e)@php $egBase = '/engineers/'.$e->id; $back = ['back' => 'ses']; @endphp<tr><td>{{ $e->name }}</td><td>{{ badge('engStatus', $e->status) }}@if($e->suspended_at) <span class="badge mute">停止中</span>@endif</td><td>{{ $e->account }}</td><td>{{ man($e->desired_price) }}</td>
<td>@if(is_provisional_state($e->account) && $e->status !== 'left')<x-post :action="$egBase.'/invite'" label="送り直し" cls="ghost small" />
@if($e->login_id)<a class="btn ghost small" href="{{ url('/people/'.$e->login_id.'/edit') }}">仮登録の内容を直す</a>
<x-post :action="'/people/'.$e->login_id.'/cancel'" label="取り消し" cls="danger small" confirm="この仮登録を取り消します。エンジニアの行も消え、元に戻せません。よろしいですか？" />@endif @endif
@if($e->status !== 'left')@if($e->suspended_at)<x-post :action="$egBase.'/resume'" label="再開" cls="small" :fields="$back" confirm="このエンジニアを再開します。ログインでき、案件の配信の候補にも出るようになります。" />
@else<x-post :action="$egBase.'/suspend'" label="停止" cls="ghost small" :fields="$back" :confirm="engineer_stop_confirm(isset($working[$e->id]), (int) ($inSel[$e->id] ?? 0))" />@endif
<x-post :action="$egBase.'/delete'" label="退会" cls="danger small" :fields="$back" confirm="このエンジニアを退会済にします。ログインできなくなり、元に戻せません（記録は残ります）。よろしいですか？" />@endif
<a class="btn small" href="{{ url($egBase) }}">詳細</a></td></tr>@endforeach
</x-table></x-card>
<x-card title="請求"><x-table :head="['月', '合計', '状態', '']" :count="$invs->count()">
@foreach($invs as $i)<tr><td>{{ $i->period }}</td><td>{{ yen($i->total) }}</td><td>{{ badge('invoiceStatus', $i->status) }}</td><td><a class="btn small" href="{{ url('/invoices/'.$i->id) }}">詳細</a></td></tr>@endforeach
</x-table></x-card>
</div></div>
@endsection
