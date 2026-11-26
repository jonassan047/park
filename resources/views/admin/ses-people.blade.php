@extends('layouts.app', ['title' => 'SES・担当者一覧', 'wide' => true])
@section('content')
@include('partials.ses-view-tabs', ['view' => 'people'])
<form method="get" class="filters"><x-field name="q" :value="$q" placeholder="氏名・メール・会社・ID で探す" />
<x-field name="kind" type="select" :options="['rep' => 'SES代表担当者', 'general' => '一般担当者']" blank="すべての区分" :value="$kind" />
<x-field name="status" type="select" :options="$statuses" blank="すべての状態" :value="$status" />
<x-field name="order" type="select" :options="PARK_ORDERS" :value="$order" />
<x-field name="left" type="select" :options="['' => '退会済みを表示しない', '1' => '退会済みを表示する']" :value="show_left() ? '1' : ''" /><button class="btn ghost small">絞り込む</button></form>
{{-- 退会済みを隠して 0 行のときは、「まだいません」を出さず、件数の文だけにする --}}
@if($list->isNotEmpty() || !$left['hidden'])
<x-table :head="['ID', '氏名', '区分', '会社', 'メール', '状態', '最終ログイン', '']" :count="$list->count()" empty="担当者はまだいません。「SES を仮登録」から入れます。">
@foreach($list as $u)<tr @class(['is-left' => is_left_row('person', $u)])><td><x-code :v="$u->staff_code" /></td><td>{{ $u->name }}</td>
<td>{{ $u->kind_name }}@if((int) $u->next_rep_user_id === (int) $u->id) <span class="badge info">次のSES代表担当者</span>@endif @if($u->role === 'admin') <span class="badge warn">管理者（兼任）</span>@endif @if($u->engineer_id) <a class="badge info" href="{{ url('/engineers/'.$u->engineer_id) }}">エンジニア兼任</a>@endif</td>
<td><x-code :v="$u->ses_code" /> {{ $u->ses_name }}@if($u->ses_status !== 'active') {{ badge('sesStatus', $u->ses_status) }}@endif</td>
<td>{{ account_mail($u) }}</td><td>{{ $u->state }}</td><td>{{ fmt_dt($u->last_login) }}</td>
<td><a class="btn small" href="{{ url($u->role === 'admin' ? '/admin/admins/'.$u->id : '/admin/ses/'.$u->ses_id.'/users/'.$u->id) }}">詳細</a>
<a class="btn ghost small" href="{{ url('/admin/ses/'.$u->ses_id) }}">会社</a></td></tr>@endforeach
</x-table>
@endif
@include('partials.pager', ['pager' => $pager])
@include('partials.left-note', $left)
@if($noStaff)<p class="muted">担当者がまだいない会社が {{ $noStaff }} 社あります。<a href="{{ url('/admin/ses').'?'.http_build_query(['view' => 'company'] + (show_left() ? ['left' => '1'] : [])) }}">会社の表示で見る</a></p>@endif
@endsection
