@extends('layouts.app', ['title' => $s->name, 'wide' => true])
@section('content')
<div class="cols"><div>
<x-card title="会社"><dl class="dl">
<dt>状態</dt><dd>{{ badge('sesStatus', $s->status) }}</dd><dt>代表者</dt><dd>{{ $s->rep_name ?: '—' }}</dd><dt>担当</dt><dd>{{ $s->contact_name ?: '—' }}</dd>
<dt>メール</dt><dd>{{ $s->email ?: '—' }}</dd><dt>電話</dt><dd>{{ $s->phone ?: '—' }}</dd><dt>所在地</dt><dd>{{ $s->address ?: '—' }}</dd>
<dt>人数</dt><dd>{{ $s->employees ?? '—' }}</dd><dt>得意な分野</dt><dd>{{ $s->strengths ?: '—' }}</dd><dt>ひとこと</dt><dd>{!! nl2br(e($s->message)) ?: '—' !!}</dd>
<dt>守秘義務への同意</dt><dd>{{ fmt_dt($s->agreed_at) }}</dd><dt>参加日</dt><dd>{{ fmt_date($s->joined_at) }}</dd><dt>メモ</dt><dd>{!! nl2br(e($s->notes)) ?: '—' !!}</dd>
</dl>
<p>@if($s->status !== 'active')<x-post :action="'/admin/ses/'.$s->id.'/approve'" label="承認して招待を送る" confirm="参加を承認し、担当の方に招待メールを送ります。" />@endif
@if($s->status === 'active')<x-post :action="'/admin/ses/'.$s->id.'/suspend'" label="停止する" cls="ghost" confirm="停止すると、この SES の担当者はログインできなくなります。" />@endif</p>
</x-card>
<x-card title="会社情報の編集">@include('admin.ses-form', ['action' => '/admin/ses/'.$s->id])</x-card>
</div><div>
<x-card title="担当者のアカウント"><x-table :head="['名前', 'メール', '状態', '2要素認証', '最終ログイン']" :count="$users->count()">
@foreach($users as $u)<tr><td>{{ $u->name }}</td><td>{{ $u->email }}</td><td>{{ $u->password ? '利用中' : '招待中' }}</td>
<td>@include('admin.two-factor-cell', ['u' => $u])</td><td>{{ fmt_dt($u->last_login) }}</td></tr>@endforeach
</x-table>
<form method="post" action="{{ url('/admin/ses/'.$s->id.'/users') }}">@csrf<div class="grid2"><x-field label="名前" name="name" required /><x-field label="メール" name="email" type="email" required /></div><button class="btn ghost small">担当者を追加して招待</button></form>
</x-card>
<x-card title="エンジニア"><x-slot:actions><a class="btn small" href="{{ url('/engineers/new?ses='.$s->id) }}">エンジニアを登録</a></x-slot:actions>
<x-table :head="['名前', '状態', '希望', '']" :count="$engs->count()">
@foreach($engs as $e)<tr><td>{{ $e->name }}</td><td>{{ badge('engStatus', $e->status) }}</td><td>{{ man($e->desired_price) }}</td><td><a href="{{ url('/engineers/'.$e->id) }}">詳細</a></td></tr>@endforeach
</x-table></x-card>
<x-card title="請求"><x-table :head="['月', '合計', '状態', '']" :count="$invs->count()">
@foreach($invs as $i)<tr><td>{{ $i->period }}</td><td>{{ yen($i->total) }}</td><td>{{ badge('invoiceStatus', $i->status) }}</td><td><a href="{{ url('/invoices/'.$i->id) }}">開く</a></td></tr>@endforeach
</x-table></x-card>
</div></div>
@endsection
