@extends('layouts.app', ['title' => $s->name, 'wide' => true])
@section('content')
<div class="cols"><div>
<x-card title="会社情報"><x-slot:actions>@if($s->status === 'active')<x-post :action="'/admin/ses/'.$s->id.'/suspend'" label="停止" cls="ghost small" confirm="停止すると、この SES の担当者はログインできなくなります。" />@endif
@if($s->status === 'suspended')<x-post :action="'/admin/ses/'.$s->id.'/resume'" label="再開" cls="small" confirm="この SES を再開します。担当者がログインできるようになります。" />@endif
@if($s->status !== 'left')<x-post :action="'/admin/ses/'.$s->id.'/delete'" label="退会" cls="danger small" confirm="この SES と所属エンジニアを退会済にします。全員ログインできなくなります（記録は残ります）。よろしいですか？" />@endif
@if($s->status === 'pending')<a class="btn ghost small" href="{{ url('/admin/ses/'.$s->id.'/approve') }}">承認する（内容を確かめて仮登録）</a>@endif
@if($s->status === 'provisional' && $s->rep_user_id)<x-post :action="'/people/'.$s->rep_user_id.'/resend'" label="送り直し" cls="ghost small" confirm="SES に、仮登録のご案内を送り直します。前のリンクは使えなくなります。" />
<x-post :action="'/people/'.$s->rep_user_id.'/cancel'" label="取り消し" cls="danger small" confirm="この仮登録を取り消します。仮登録の行が消え、元に戻せません。よろしいですか？" />@endif
<a class="btn small" href="{{ url('/admin/ses/'.$s->id.'/edit') }}">{{ $s->status === 'provisional' ? '仮登録の内容を直す' : '編集' }}</a>
</x-slot:actions><dl class="dl">
<dt>状態</dt><dd>{{ badge('sesStatus', $s->status) }}</dd><dt>SES</dt><dd>{{ $repName ?: '—' }}</dd>
<dt>メール</dt><dd>{{ $s->email ?: '—' }}</dd><dt>電話</dt><dd>{{ $s->phone ?: '—' }}</dd><dt>所在地</dt><dd>{{ postal_address($s->postal_code, $s->address) }}</dd>
<dt>人数</dt><dd>{{ $s->employees ?? '—' }}</dd><dt>得意な分野</dt><dd>{{ $s->strengths ?: '—' }}</dd><dt>ひとこと</dt><dd>{!! nl2br(e($s->message)) ?: '—' !!}</dd>
<dt>守秘義務への同意</dt><dd>{{ fmt_dt($s->agreed_at) }}</dd><dt>参加日</dt><dd>{{ fmt_date($s->joined_at) }}</dd><dt>メモ</dt><dd>{!! nl2br(e($s->notes)) ?: '—' !!}</dd>
</dl>
<p><a class="btn ghost" href="{{ url('/admin/ses') }}">SES の一覧に戻る</a></p>
</x-card>
</div><div>
<x-card title="担当者"><x-slot:actions>@if($s->status === 'active')<a class="btn small" href="{{ url('/admin/ses/'.$s->id.'/users/new') }}">担当者を登録</a>@endif</x-slot:actions>
@if($noUsable === 'stopped')<p class="bad-text">使える担当者がいません。止めている担当者を再開するか、担当者を登録してください。</p>@endif
@if($noUsable === 'left')<p class="bad-text">使える担当者がいません。担当者を登録してください。</p>@endif
<x-table :head="['名前', 'メール', '状態', '最終ログイン', '']" :count="$users->count()">
@foreach($users as $u)@php
$state = user_state($u);
$base = '/admin/ses/'.$s->id.'/users/'.$u->id;
$isRepRow = (int) $u->id === (int) $s->rep_user_id || (int) $u->id === (int) $s->next_rep_user_id; // SES（次の SES）は、交代のあとでなければ止められない
@endphp<tr><td>{{ $u->name }}@if((int) $u->id === (int) $s->rep_user_id) <span class="badge info">SES</span>@elseif((int) $u->id === (int) $s->next_rep_user_id) <span class="badge info">次の SES</span>@endif
@if($u->engineer_id) <a class="badge info" href="{{ url('/engineers/'.$u->engineer_id) }}">エンジニア兼任</a>@endif</td><td>{{ $u->email }}</td><td>{{ $state }}</td>
<td>{{ fmt_dt($u->last_login) }}</td><td>@if(is_provisional_state($state))<x-post :action="'/people/'.$u->id.'/resend'" label="送り直し" cls="ghost small" />
<a class="btn ghost small" href="{{ url('/people/'.$u->id.'/edit') }}">仮登録の内容を直す</a>
@if((int) $s->rep_user_id !== (int) $u->id)<x-post :action="'/people/'.$u->id.'/cancel'" label="取り消し" cls="danger small" confirm="この仮登録を取り消します。元に戻せません。よろしいですか？" />@endif @endif
@if(!$isRepRow && $state === '停止')<x-post :action="$base.'/resume'" label="再開" cls="small" :confirm="$u->name.' さんを再開します。'" />
@elseif(!$isRepRow && $state !== '退会済')<x-post :action="$base.'/suspend'" label="停止" cls="ghost small" :confirm="$u->name.' さんを停止します。ログインできなくなります（再開できます）。'" />@endif
@if(!$isRepRow && $state !== '退会済')<x-post :action="$base.'/delete'" label="退会" cls="danger small" :confirm="$u->name.' さんを退会済にします。ログインできなくなり、元に戻せません（記録は残ります）。よろしいですか？'" />@endif
<a class="btn small" href="{{ url($base) }}">詳細</a></td></tr>@endforeach
</x-table>
</x-card>
@if($s->status === 'active')
<x-card title="SES の交代">
@if(!$s->rep_user_id)<p class="bad-text">この会社には SES がいません。本登録が済んだ担当者から選んで、SES にしてください。</p>@endif
@if($repCandidates->count())
<form method="post" action="{{ url('/admin/ses/'.$s->id.'/rep') }}" class="filters">@csrf
<x-field label="新しい SES（本登録が済んだ担当者から選びます）" name="user_id" type="select" :options="$repCandidates->pluck('name', 'id')->all()" blank="選んでください" required />
<button class="btn small" onclick="return confirm('SES を交代します。前の SES は担当者になります。よろしいですか？')">SES にする</button></form>
@else
<p class="muted">本登録が済んだ担当者がいません。次の SES を仮登録すると、その方の本登録が済んだときに SES が入れ替わります（それまでは今の SES のままです）。</p>
@if($s->next_rep_user_id && $users->firstWhere('id', $s->next_rep_user_id))<p>次の SES：{{ $users->firstWhere('id', $s->next_rep_user_id)->name }}（上の担当者の表から、送り直し・直し・取り消しができます）</p>
@else
<form method="post" action="{{ url('/admin/ses/'.$s->id.'/rep') }}">@csrf
<div class="pair"><x-field label="氏名（姓）" name="name_sei" required /><x-field label="氏名（名）" name="name_mei" required /></div>
<x-field label="メールアドレス" name="email" type="email" required />
<button class="btn small">次の SES を仮登録する</button></form>
@endif
@endif
</x-card>
@endif
<x-card title="エンジニア">@if($s->status === 'active')<x-slot:actions><a class="btn small" href="{{ url('/engineers/new?ses='.$s->id) }}">エンジニアを登録</a></x-slot:actions> @endif
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
