@extends('layouts.app', ['title' => '会社情報', 'wide' => true])
@section('content')
<div class="cols"><div><x-card><form method="post" action="{{ url('/ses/company') }}">@csrf<div class="grid2">
<x-field label="SES名（会社名）" name="name" :value="$s->name" required /><x-field label="代表者名" name="rep_name" :value="$s->rep_name" />
<x-field label="ご担当者名" name="contact_name" :value="$s->contact_name" /><x-field label="電話番号" name="phone" :value="$s->phone" />
<x-field label="所在地" name="address" :value="$s->address" /><x-field label="所属エンジニアの人数" name="employees" type="number" :value="$s->employees" /></div>
<x-field label="得意な分野" name="strengths" :value="$s->strengths" /><button class="btn">保存する</button></form></x-card></div>
<div>@if(!$s->self_confirmed_at)<x-card title="会社の情報の確認"><p>会社の情報は、管理者が入れたものも含めて、<b>御社が責任を持つもの</b>です。左の内容を確かめ、違うところは直して保存してから、「確認しました」を押してください。</p>
<x-post action="/ses/company/confirm" label="内容を確認しました" cls="small" :confirm="'会社の情報が正しいことを確認しましたか？'" /></x-card>@endif
<x-card title="参加の状況"><dl class="dl"><dt>状態</dt><dd>{{ badge('sesStatus', $s->status) }}</dd><dt>参加日</dt><dd>{{ fmt_date($s->joined_at) }}</dd><dt>守秘義務への同意</dt><dd>{{ fmt_dt($s->agreed_at) }}</dd><dt>会社の情報の確認</dt><dd>{{ $s->self_confirmed_at ? fmt_dt($s->self_confirmed_at) : 'まだ' }}</dd></dl></x-card>
<x-card title="担当者"><x-table :head="['名前', 'メール', '最終ログイン']" :count="$users->count()">
@foreach($users as $u)<tr><td>{{ $u->name }}</td><td>{{ $u->email }}</td><td>{{ fmt_dt($u->last_login) }}</td></tr>@endforeach</x-table></x-card></div></div>
@endsection
