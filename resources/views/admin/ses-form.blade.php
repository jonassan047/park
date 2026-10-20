@php $isNew = !isset($s->id); @endphp
<form method="post" action="{{ url($action) }}">@csrf<div class="grid2">
<x-field label="会社名" name="name" :value="$s->name ?? null" required cls="span2" hint="略称でもかまいません。正式名称は、SES のご担当者が最初のログインで入れます。" />
<div class="pair"><x-field label="担当者名（姓）" name="contact_sei" :value="$s->contact_sei ?? null" :required="$isNew" :hint="$isNew ? '招待メールの宛名になります。' : null" /><x-field label="担当者名（名）" name="contact_mei" :value="$s->contact_mei ?? null" /></div>
<x-field label="メールアドレス" name="email" type="email" :value="$s->email ?? null" :required="$isNew" /><x-field label="電話番号" name="phone" :value="$s->phone ?? null" />
<div class="pair"><x-field label="代表者名（姓）" name="rep_sei" :value="$s->rep_sei ?? null" /><x-field label="代表者名（名）" name="rep_mei" :value="$s->rep_mei ?? null" /></div>
<x-field label="郵便番号" name="postal_code" :value="postal_norm($s->postal_code ?? null) ?: ($s->postal_code ?? null)" placeholder="123-4567" /><x-field label="所在地" name="address" :value="$s->address ?? null" />
<x-field label="所属エンジニアの人数" name="employees" type="number" :value="$s->employees ?? null" /><x-field label="得意な分野" name="strengths" :value="$s->strengths ?? null" /></div>
<x-field label="管理者のメモ（SES には見えません）" name="notes" type="textarea" rows="3" :value="$s->notes ?? null" /><a class="btn ghost" href="{{ url($back ?? '/admin/ses') }}">戻る</a> <button class="btn">{{ $isNew ? '登録して招待メールを送る' : '保存する' }}</button></form>
