<form method="post" action="{{ url($action) }}">@csrf<div class="grid2">
<x-field label="会社名" name="name" :value="$s->name ?? null" required hint="略称でもかまいません。正式名称は、SES のご担当者が最初のログインで入れます。" />
<x-field label="担当者名" name="contact_name" :value="$s->contact_name ?? null" :hint="isset($s->id) ? null : 'メールアドレスを入れたときは必須です（招待メールの宛名になります）。'" /><x-field label="メールアドレス" name="email" type="email" :value="$s->email ?? null" />
<x-field label="代表者名" name="rep_name" :value="$s->rep_name ?? null" /><x-field label="電話番号" name="phone" :value="$s->phone ?? null" /><x-field label="所在地" name="address" :value="$s->address ?? null" />
<x-field label="所属エンジニアの人数" name="employees" type="number" :value="$s->employees ?? null" /><x-field label="得意な分野" name="strengths" :value="$s->strengths ?? null" /></div>
<x-field label="管理者のメモ（SES には見えません）" name="notes" type="textarea" rows="3" :value="$s->notes ?? null" /><button class="btn">保存する</button></form>
