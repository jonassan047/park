@extends('layouts.app', ['title' => $p ? 'プロバイダーの編集：'.id_title($p->code, $p->name) : 'プロバイダーの登録'])
@section('content')
<x-card><form method="post" action="{{ url($p ? $base.'/'.$p->id : $base) }}">@csrf
<div class="grid2">
<x-field label="企業名" name="name" :value="$p->name ?? null" required cls="span2" />
<div class="pair"><x-field label="氏名（姓）" name="sei" :value="$p->sei ?? null" required /><x-field label="氏名（名）" name="mei" :value="$p->mei ?? null" /></div>
<x-field label="メールアドレス" name="email" type="email" :value="$p->email ?? null" required />
<x-field label="電話番号" name="phone" :value="$p->phone ?? null" />
<x-field label="郵便番号" name="postal_code" :value="postal_norm($p->postal_code ?? null) ?: ($p->postal_code ?? null)" placeholder="123-4567" /><x-field label="所在地" name="address" :value="$p->address ?? null" />
<x-field label="所属エンジニアの人数" name="employees" type="number" :value="$p->employees ?? null" /><x-field label="得意な分野" name="strengths" :value="$p->strengths ?? null" /></div>
@if($base === '/admin/providers')<x-field label="管理者のメモ" name="notes" type="textarea" rows="3" :value="$p->notes ?? null" />@endif
<a class="btn ghost" href="{{ url($p ? $base.'/'.$p->id : $base) }}">戻る</a> <button class="btn">{{ $p ? '保存する' : '登録する' }}</button></form></x-card>
@endsection
