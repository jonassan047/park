@extends('layouts.app', ['title' => '設定', 'wide' => true])
@section('content')
<div class="cols"><div>
<x-card title="基本と報酬の率（仮）"><form method="post" action="{{ url('/admin/settings') }}">@csrf<div class="grid2">
<x-field label="サービス名" name="app_name" :value="setting('app_name')" /><x-field label="運営の名前（メールの署名）" name="operator_name" :value="setting('operator_name')" />
<x-field label="初期費用（%）" name="fee_initial_rate" type="number" step="0.1" :value="setting('fee_initial_rate')" />
<x-field label="月額費用（%）" name="fee_monthly_rate" type="number" step="0.1" :value="setting('fee_monthly_rate')" />
<x-field label="月額は稼働の何か月目から" name="monthly_from" type="select" :options="['1' => '1か月目から', '2' => '2か月目から']" :value="setting('monthly_from')" />
<x-field label="消費税（%）" name="tax_rate" type="number" :value="setting('tax_rate')" />
<x-field label="配信候補に出す最低点" name="match_threshold" type="number" min="0" :value="setting('match_threshold')" /></div>
<button class="btn">保存する</button></form></x-card>
<x-card title="メールの送信"><p>{{ $canSend ? '設定済み：'.config('mail.default').'（'.config('mail.mailers.smtp.host').'）' : '未設定（メールは保存だけ）' }}</p>
<p class="muted">パスワードを画面や Git に残さないため、メールの送信の設定は <code>.env</code> の MAIL_ の欄に書きます（Git には上げません）。書き方は README にあります。</p>
<form method="post" action="{{ url('/admin/settings/testmail') }}">@csrf<x-field label="テストメールの宛先" name="to" type="email" :value="auth()->user()->email" /><button class="btn ghost small">テストメールを送る</button></form></x-card>
</div><div>
<x-card title="運営メンバー"><x-table :head="['名前', 'メール', '2要素認証', '最終ログイン']" :count="$admins->count()">
@foreach($admins as $u)<tr><td>{{ $u->name }}</td><td>{{ $u->email }}</td><td>@include('admin.two-factor-cell', ['u' => $u])</td><td>{{ fmt_dt($u->last_login) }}</td></tr>@endforeach</x-table>
<form method="post" action="{{ url('/admin/settings/admins') }}">@csrf<div class="grid2"><x-field label="名前" name="name" required /><x-field label="メール" name="email" type="email" required /></div><button class="btn ghost small">運営メンバーを招待</button></form></x-card>
<x-card title="操作の記録"><p>配信・選考・請求などの操作は、すべて記録しています（公平性のため）。</p><a class="btn ghost small" href="{{ url('/admin/audit') }}">記録を見る</a></x-card>
</div></div>
@endsection
