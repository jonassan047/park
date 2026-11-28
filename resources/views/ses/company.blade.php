@extends('layouts.app', ['title' => id_title($s->code, $s->name, $s->status === 'left'), 'wide' => true])
@section('content')
{{-- 2026-11-28 TEZZO：ダッシュボードと同じく企業名の下に帯。帯が「企業情報」なので、下のカードの題は出さない --}}
<div class="role-hero slim" style="background-image:url('{{ asset('img/hero.jpg') }}')"><p>企業情報</p></div>
<div class="cols"><div>
<x-card><x-slot:actions><a class="btn ghost small" href="{{ url('/account#password') }}">パスワード変更</a>
@if($isRep)<a class="btn small" href="{{ url('/ses/company/edit') }}">編集</a>@endif
</x-slot:actions><dl class="dl">
<dt>ID</dt><dd><x-code :v="$s->code" /></dd>
<dt>参加状況</dt><dd>{{ badge('sesStatus', $s->status) }}</dd><dt>PARK窓口担当者</dt><dd><x-code :v="$repCode" /> {{ $repName ?: '—' }}</dd>
<dt>企業代表者</dt><dd>@if(!empty($s->ceo_same))<x-code :v="$repCode" /> @endif{{ ceo_label($s) }}</dd>
<dt>メール</dt><dd>{{ $s->email ?: '—' }}</dd><dt>電話</dt><dd>{{ $s->phone ?: '—' }}</dd><dt>所在地</dt><dd>{{ postal_address($s->postal_code, $s->address) }}</dd>
<dt>人数</dt><dd>{{ $s->employees ?? '—' }}</dd><dt>得意な分野</dt><dd>{{ $s->strengths ?: '—' }}</dd><dt>ひとこと</dt><dd>{!! nl2br(e($s->message)) ?: '—' !!}</dd>
<dt>守秘義務への同意</dt><dd>{{ fmt_dt($s->agreed_at) }}</dd><dt>参加日</dt><dd>{{ fmt_date($s->joined_at) }}</dd>
</dl>
</x-card>
</div><div>
@include('partials.left-toggle')
<x-card title="担当者">@if($isRep && $s->status === 'active')<x-slot:actions><a class="btn small" href="{{ url('/ses/company/users/new') }}">一般担当者を登録</a></x-slot:actions> @endif
@unless($isRep)<p class="muted">一般担当者の登録・停止・退会は、PARK窓口担当者だけができます。PARK窓口担当者の交代は、管理者にご依頼ください。</p>@endunless
@if($users->count() || !$staffLeft['hidden'])
<x-table :head="['ID', '名前', 'メール', '登録状況', '最終ログイン', '']" :count="$users->count()">
@foreach($users as $u)@php
$state = user_state($u);
$base = '/admin/ses/'.$s->id.'/users/'.$u->id;
$self = (int) $u->id === (int) auth()->id(); // ご自身の行には操作を出さない
$isRepRow = (int) $u->id === (int) $repId || (int) $u->id === (int) $s->next_rep_user_id; // PARK窓口担当者（次の人も）の行は、交代のあとでなければ止められない
$isAdminRow = $u->role === 'admin'; // 担当者を兼ねている管理者。貴社からは止められない（やめるのは本人だけ）
@endphp<tr @class(['is-left' => is_left_row('staff', $u)])><td><x-code :v="$u->staff_code" /></td><td>{{ $u->name }}@if($isAdminRow) <span class="badge warn">管理者（兼任）</span>@endif @if((int) $u->id === (int) $repId) <span class="badge info">PARK窓口担当者</span>@elseif((int) $u->id === (int) $s->next_rep_user_id) <span class="badge info">次のPARK窓口担当者</span>@endif
@if($u->engineer_id && !$isAdminRow) <a class="badge info" href="{{ url('/engineers/'.$u->engineer_id) }}">エンジニア兼任</a>@endif</td><td>{{ $isAdminRow ? '—' : account_mail($u) }}</td><td>{{ $state }}</td>
<td>{{ fmt_dt($u->last_login) }}</td><td>@if($isRep && !$isAdminRow && !$isRepRow && is_provisional_state($state))<x-post :action="'/people/'.$u->id.'/resend'" label="送り直し" cls="ghost small" />
<a class="btn ghost small" href="{{ url('/people/'.$u->id.'/edit') }}">仮登録の内容を直す</a>
<x-post :action="'/people/'.$u->id.'/cancel'" label="取り消し" cls="danger small" confirm="この仮登録を取り消します。元に戻せません。よろしいですか？" />@endif
@if($isRep && !$isAdminRow && !$self && !$isRepRow && empty($u->left_at))@if($state === PARK_ST_SUSPENDED)<x-post :action="$base.'/resume'" label="再開" cls="small" :confirm="$u->name.' さんを再開します。'" />
@else<x-post :action="$base.'/suspend'" label="停止" cls="ghost small" :confirm="$u->name.' さんを停止します。ログインできなくなります（再開できます）。'" />@endif
<x-post :action="$base.'/delete'" label="退会" cls="danger small" :confirm="$u->name.' さんを退会済みにします。ログインできなくなり、元に戻せません（記録は残ります）。よろしいですか？'" />@endif</td></tr>@endforeach
</x-table>
@endif
@include('partials.left-note', $staffLeft)
</x-card>
<x-card title="エンジニア">@if($s->status === 'active')<x-slot:actions><a class="btn small" href="{{ url('/engineers/new') }}">エンジニアを登録</a></x-slot:actions> @endif
@if($engs->count() || !$egLeft['hidden'])
<x-table :head="['', '']" :count="$engs->count()">
@foreach($engs as $e)@php $egBase = '/engineers/'.$e->id; $back = ['back' => 'ses']; @endphp<tr @class(['is-left' => is_left_row('eg', $e)])><td class="eg-who"><x-code :v="$e->code" />　<span class="eg-name">{{ $e->name }}</span><div class="eg-state">@if($e->status !== 'left'){{ work_badge($e) }}@endif @if($e->account !== PARK_ST_ACTIVE)<span>{{ $e->account === 'なし' ? 'ログインなし' : $e->account }}</span>@endif @if($e->mail !== '—')<small class="muted">{{ $e->mail }}</small>@endif</div></td>
<td class="eg-ops"><div>@if(is_provisional_state($e->account) && $e->status !== 'left')<x-post :action="$egBase.'/invite'" label="送り直し" cls="ghost small" />
@if($e->login_id)<a class="btn ghost small" href="{{ url('/people/'.$e->login_id.'/edit') }}">仮登録の内容を直す</a>
<x-post :action="'/people/'.$e->login_id.'/cancel'" label="取り消し" cls="danger small" confirm="この仮登録を取り消します。エンジニアの行も消え、元に戻せません。よろしいですか？" />@endif @endif
@if($e->status !== 'left')@if($e->suspended_at)<x-post :action="$egBase.'/resume'" label="再開" cls="small" :fields="$back" confirm="このエンジニアを再開します。ログインでき、案件の配信の候補にも出るようになります。" />
@else<x-post :action="$egBase.'/suspend'" label="停止" cls="ghost small" :fields="$back" :confirm="engineer_stop_confirm(isset($working[$e->id]), (int) ($inSel[$e->id] ?? 0))" />@endif
<x-post :action="$egBase.'/delete'" label="退会" cls="danger small" :fields="$back" confirm="このエンジニアを退会済みにします。ログインできなくなり、元に戻せません（記録は残ります）。よろしいですか？" />@endif
<a class="btn small" href="{{ url($egBase) }}">詳細</a></div></td></tr>@endforeach
</x-table>
@endif
@include('partials.left-note', $egLeft)
</x-card>
<x-card title="請求"><x-table :head="['請求書番号', '月', '合計', '状態', '']" :count="$invs->count()">
@foreach($invs as $i)<tr><td><x-code :v="$i->code" /></td><td>{{ $i->period }}</td><td>{{ yen($i->total) }}</td><td>{{ badge('invoiceStatus', $i->status) }}</td><td><a class="btn small" href="{{ url('/invoices/'.$i->id) }}">詳細</a></td></tr>@endforeach
</x-table></x-card>
</div></div>
{{-- 状況の言葉の説明は、画面の下に1か所だけ（開くと出る） --}}
<details class="legend-fold noprint"><summary>状況の言葉の説明（参加状況・登録状況・稼働状況）</summary>
<p class="muted">エンジニアの欄では、登録状況が「参加中」の人は表示を省いています。</p>
<x-status-legend kind="ses" /><x-status-legend kind="account" :eg="true" /><x-status-legend kind="work" />
</details>
@endsection
