@extends('layouts.app', ['title' => '会社情報', 'wide' => true])
@section('content')
<div class="cols"><div><x-card><form method="post" action="{{ url('/ses/company') }}">@csrf<div class="grid2">
<x-field label="会社名" name="name" :value="$s->name" required /><x-field label="代表者名" name="rep_name" :value="$s->rep_name" />
<x-field label="ご担当者名" name="contact_name" :value="$s->contact_name" /><x-field label="電話番号" name="phone" :value="$s->phone" />
<x-field label="所在地" name="address" :value="$s->address" /><x-field label="所属エンジニアの人数" name="employees" type="number" :value="$s->employees" /></div>
<x-field label="得意な分野" name="strengths" :value="$s->strengths" /><button class="btn">保存する</button></form></x-card></div>
<div><x-card title="参加の状況"><dl class="dl"><dt>状態</dt><dd>{{ badge('sesStatus', $s->status) }}</dd><dt>参加日</dt><dd>{{ fmt_date($s->joined_at) }}</dd><dt>守秘義務への同意</dt><dd>{{ fmt_dt($s->agreed_at) }}</dd></dl></x-card>
<x-card title="担当者"><x-table :head="['名前', 'メール', '最終ログイン']" :count="$users->count()">
@foreach($users as $u)<tr><td>{{ $u->name }}</td><td>{{ $u->email }}</td><td>{{ fmt_dt($u->last_login) }}</td></tr>@endforeach</x-table></x-card></div></div>
@endsection
