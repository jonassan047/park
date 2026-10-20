@extends('layouts.app', ['title' => '管理者の編集：'.$u->name])
@section('content')
@php
    // 姓・名の列がまだ空の人は、つないだ name から分けて出す
    [$nameSei, $nameMei] = ($u->name_sei || $u->name_mei) ? [$u->name_sei, $u->name_mei] : split_name($u->name);
    $state = !$u->active ? '停止' : ($u->password ? '使用中' : '招待中');
    $self = (int) $u->id === (int) auth()->id();
    $emailFixed = $self || !$u->active || $u->password; // 変えられるのは、招待中の他人だけ
    // 古い形の郵便番号も、そろえられれば「123-4567」で出す
    $postal = postal_norm($u->postal_code) ?: $u->postal_code;
@endphp
<x-card>
<p>状態：{{ $state }}</p>
<form method="post" action="{{ url('/admin/admins/'.$u->id) }}">@csrf
<div class="pair">
<x-field label="氏名（姓）" name="name_sei" :value="$nameSei" required />
<x-field label="氏名（名）" name="name_mei" :value="$nameMei" />
</div>
<x-field label="企業名" name="company_name" :value="$u->company_name" required hint="メールの差出人の名前にも使います。" />
<x-field label="所属・役職" name="position" :value="$u->position" />
<x-field label="電話番号" name="phone" :value="$u->phone" />
<x-field label="郵便番号" name="postal_code" :value="$postal" placeholder="123-4567" />
<x-field label="住所" name="address" :value="$u->address" />
@if($emailFixed)
<label class="field"><span>メールアドレス</span><input type="email" value="{{ $u->email }}" disabled>
<small>{{ $self ? 'ご自分のメールは、ほかの管理者に頼んで変えてもらいます。' : (!$u->active ? '停止中の人のメールアドレスは変えられません。' : '使用中の方のメールは変えられません。') }}</small></label>
@else
<x-field label="メールアドレス" name="email" type="email" :value="$u->email" required hint="変えると、新しいアドレスに招待を送り直します。" />
@endif
<a class="btn ghost" href="{{ url('/admin/admins/'.$u->id) }}">戻る</a> <button class="btn">保存する</button></form>
</x-card>
@endsection
