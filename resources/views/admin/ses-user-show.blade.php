@extends('layouts.app', ['title' => $u->name])
@section('content')
@php
    $state = user_state($u);
    $base = '/admin/ses/'.$s->id.'/users/'.$u->id;
    $back = ['back' => 'detail'];
    $isRepRow = (int) $u->id === (int) $s->rep_user_id || (int) $u->id === (int) $s->next_rep_user_id; // SES（次の SES）は、交代のあとでなければ止められない
@endphp
<x-card title="担当者情報">
<x-slot:actions>@if(is_provisional_state($state))<x-post :action="'/people/'.$u->id.'/resend'" label="送り直し" cls="ghost small" />
<a class="btn ghost small" href="{{ url('/people/'.$u->id.'/edit') }}">仮登録の内容を直す</a>
@if((int) $u->id !== (int) $s->rep_user_id)<x-post :action="'/people/'.$u->id.'/cancel'" label="取り消し" cls="danger small" confirm="この仮登録を取り消します。元に戻せません。よろしいですか？" />@endif @endif
@if(!$isRepRow && $state === '停止')<x-post :action="$base.'/resume'" label="再開" cls="small" :fields="$back" :confirm="$u->name.' さんを再開します。'" />
@elseif(!$isRepRow && $state !== '退会済')<x-post :action="$base.'/suspend'" label="停止" cls="ghost small" :fields="$back" :confirm="$u->name.' さんを停止します。ログインできなくなります（再開できます）。'" />@endif
@if(!$isRepRow && $state !== '退会済')<x-post :action="$base.'/delete'" label="退会" cls="danger small" :fields="$back" :confirm="$u->name.' さんを退会済にします。ログインできなくなり、元に戻せません（記録は残ります）。よろしいですか？'" />@endif
</x-slot:actions>
<dl class="dl">
<dt>ID</dt><dd><x-code :v="$u->staff_code" /></dd>
<dt>状態</dt><dd>{{ $state }}@if((int) $u->id === (int) $s->rep_user_id)（SES）@elseif((int) $u->id === (int) $s->next_rep_user_id)（次の SES）@endif</dd>
<dt>SES</dt><dd>{{ $s->name }}</dd>
<dt>氏名</dt><dd>{{ $u->name }}</dd>
<dt>ふりがな</dt><dd>{{ $u->kana ?: '—' }}</dd>
<dt>メールアドレス</dt><dd>{{ account_mail($u) }}</dd>
<dt>電話番号</dt><dd>{{ $u->phone ?: '—' }}</dd>
<dt>所属・役職</dt><dd>{{ $u->position ?: '—' }}</dd>
<dt>エンジニア兼任</dt><dd>@if($u->engineer_id)<a href="{{ url('/engineers/'.$u->engineer_id) }}">あり（エンジニアの詳細）</a>@else なし @endif</dd>
<dt>最終ログイン</dt><dd>{{ fmt_dt($u->last_login) }}</dd>
<dt>登録日</dt><dd>{{ fmt_date($u->created_at) }}</dd>
</dl>
<p><a class="btn ghost" href="{{ url('/admin/ses/'.$s->id) }}">SES の詳細に戻る</a></p>
</x-card>
@endsection
