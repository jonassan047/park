{{-- mode：provisional＝仮登録の4つの項目（新しく登録・仮登録中の直し・承認）／registered＝本登録のあとの会社情報／pending＝申込中の会社（申し込みの内容） --}}
@if($mode === 'provisional')
<div class="grid2">
<x-field label="企業正式名称" name="name" :value="$v->name ?? null" required cls="span2" hint="登記の名前でお願いします。SES が本登録のときに確かめます。" />
<div class="pair"><x-field label="SES の氏名（姓）" name="rep_sei" :value="$v->rep_sei ?? null" required /><x-field label="SES の氏名（名）" name="rep_mei" :value="$v->rep_mei ?? null" required /></div>
<x-field label="SES のメールアドレス" name="email" type="email" :value="$v->email ?? null" required />
</div>
@elseif($mode === 'registered')
<div class="grid2">
<x-field label="企業正式名称" name="name" :value="$s->name" required cls="span2" />
<div class="pair"><x-field label="SES の氏名（姓）" name="rep_sei" :value="$rep['sei']" required /><x-field label="SES の氏名（名）" name="rep_mei" :value="$rep['mei']" required /></div>
<p class="muted">メールアドレス：{{ $s->email ?: '—' }}（ここでは変えられません）</p>
<x-field label="電話番号" name="phone" :value="$s->phone" required />
<x-field label="郵便番号" name="postal_code" :value="postal_norm($s->postal_code) ?: $s->postal_code" placeholder="123-4567" required /><x-field label="所在地" name="address" :value="$s->address" required />
<x-field label="所属エンジニアの人数" name="employees" type="number" :value="$s->employees" /><x-field label="得意な分野" name="strengths" :value="$s->strengths" /></div>
<x-field label="管理者のメモ（SES には見えません）" name="notes" type="textarea" rows="3" :value="$s->notes" />
@else
<div class="grid2">
<x-field label="会社名" name="name" :value="$s->name ?? null" required cls="span2" />
<div class="pair"><x-field label="担当者名（姓）" name="contact_sei" :value="$s->contact_sei ?? null" /><x-field label="担当者名（名）" name="contact_mei" :value="$s->contact_mei ?? null" /></div>
<div class="pair"><x-field label="担当者のふりがな（姓）" name="contact_kana_sei" :value="$s->contact_kana_sei ?? null" placeholder="やまだ" /><x-field label="担当者のふりがな（名）" name="contact_kana_mei" :value="$s->contact_kana_mei ?? null" placeholder="たろう" /></div>
<x-field label="メールアドレス" name="email" type="email" :value="$s->email ?? null" /><x-field label="電話番号" name="phone" :value="$s->phone ?? null" />
<div class="pair"><x-field label="SES の氏名（姓）" name="rep_sei" :value="$s->rep_sei ?? null" /><x-field label="SES の氏名（名）" name="rep_mei" :value="$s->rep_mei ?? null" /></div>
<x-field label="郵便番号" name="postal_code" :value="postal_norm($s->postal_code ?? null) ?: ($s->postal_code ?? null)" placeholder="123-4567" /><x-field label="所在地" name="address" :value="$s->address ?? null" />
<x-field label="所属エンジニアの人数" name="employees" type="number" :value="$s->employees ?? null" /><x-field label="得意な分野" name="strengths" :value="$s->strengths ?? null" /></div>
<x-field label="管理者のメモ（SES には見えません）" name="notes" type="textarea" rows="3" :value="$s->notes ?? null" />
@endif
