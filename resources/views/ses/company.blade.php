@extends('layouts.app', ['title' => '会社情報', 'wide' => true])
@section('content')
<div class="cols"><div><x-card><x-slot:actions><a class="btn ghost small" href="{{ url('/account#password') }}">パスワード変更</a></x-slot:actions><form method="post" action="{{ url('/ses/company') }}">@csrf<div class="grid2">
<x-field label="SES名（会社名）" name="name" :value="$s->name" required cls="span2" />
<div class="pair"><x-field label="代表者名（姓）" name="rep_sei" :value="$s->rep_sei" /><x-field label="代表者名（名）" name="rep_mei" :value="$s->rep_mei" /></div>
<div class="pair"><x-field label="ご担当者名（姓）" name="contact_sei" :value="$s->contact_sei" /><x-field label="ご担当者名（名）" name="contact_mei" :value="$s->contact_mei" /></div>
<x-field label="電話番号" name="phone" :value="$s->phone" /><x-field label="郵便番号" name="postal_code" :value="postal_norm($s->postal_code) ?: $s->postal_code" placeholder="123-4567" />
<x-field label="所在地" name="address" :value="$s->address" />
<x-field label="所属エンジニアの人数" name="employees" type="number" :value="$s->employees" /></div>
<x-field label="得意な分野" name="strengths" :value="$s->strengths" /><button class="btn">保存する</button></form></x-card></div>
<div><x-card title="参加の状況"><dl class="dl"><dt>状態</dt><dd>{{ badge('sesStatus', $s->status) }}</dd><dt>参加日</dt><dd>{{ fmt_date($s->joined_at) }}</dd><dt>守秘義務への同意</dt><dd>{{ fmt_dt($s->agreed_at) }}</dd></dl></x-card>
<x-card title="担当者"><x-table :head="['名前', 'メール', '状態', '最終ログイン', '']" :count="$users->count()">
@foreach($users as $u)@php
$state = user_state($u);
$base = '/admin/ses/'.$s->id.'/users/'.$u->id;
$self = (int) $u->id === (int) auth()->id(); // ご自身の行には操作を出さない
@endphp<tr><td>{{ $u->name }}</td><td>{{ $u->email }}</td><td>{{ $state }}</td><td>{{ fmt_dt($u->last_login) }}</td>
<td>@if(!$self && $state !== '退会済')@if($state === '停止')<x-post :action="$base.'/resume'" label="再開" cls="small" :confirm="$u->name.' さんを再開します。'" />
@else<x-post :action="$base.'/suspend'" label="停止" cls="ghost small" :confirm="$u->name.' さんを停止します。ログインできなくなります（再開できます）。'" />@endif
<x-post :action="$base.'/delete'" label="退会" cls="danger small" :confirm="$u->name.' さんを退会済にします。ログインできなくなり、元に戻せません（記録は残ります）。よろしいですか？'" />@endif</td></tr>@endforeach</x-table></x-card></div></div>
@endsection
