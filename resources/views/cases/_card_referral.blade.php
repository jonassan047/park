{{-- 紹介の依頼（パートナーの SES・担当者。v0.16.0）：受注 SES の担当者から、自社のエンジニアの紹介を頼まれたもの。会社の誰が開いても見える。
     プロバイダーからの金額は出さない（伝えられた単価だけ） --}}
<x-card title="紹介の依頼" id="referral">
@foreach($referrals as $t)
<div @if(!$loop->first)style="border-top:1px solid #ddd;margin-top:1em;padding-top:1em"@endif>
<dl class="dl">
<dt>依頼元</dt><dd>{{ $t->from_company }}［{{ $t->from_name }}］</dd>
<dt>依頼した日</dt><dd>{{ fmt_dt($t->sent_at) }}</dd>
<dt>伝えられた単価</dt><dd>{{ $t->price_text ?: '—' }}　<span class="muted">（ここに書いた単価が、貴社から案内するときの単価です）</span></dd>
<dt>依頼されたエンジニア</dt><dd>@forelse($t->engineers as $e)<div><a href="{{ url('/engineers/'.$e->id) }}">{{ $e->name }}</a> <x-code :v="$e->code" /> @if(in_array($e->id, $t->guided))<span class="badge info">案内済み</span>@endif</div>@empty<span class="muted">指定なし（貴社のエンジニアからお探しください）</span>@endforelse</dd>
</dl>
<details><summary>届いた本文</summary><pre class="mailbody">{{ $t->body }}</pre></details>
@if($t->declined_at)
<p class="muted">辞退しました（{{ fmt_date($t->declined_at) }}）@if($t->declined_note)：{{ $t->declined_note }}@endif</p>
@elseif($c->status === 'open')
<form method="post" action="{{ url('/cases/'.$c->id.'/referral/'.$t->id.'/search') }}" class="inline">@csrf<button class="btn">自社のエンジニアを検索する</button></form>
<form method="post" action="{{ url('/cases/'.$c->id.'/referral/'.$t->id.'/decline') }}" style="margin-top:.6em" onsubmit="return confirm('今回は紹介できる人がいない、と依頼した担当者に伝えます。よろしいですか？')">@csrf
<x-field label="ひとこと（なくてもかまいません）" name="note" type="textarea" rows="2" />
<button class="btn ghost">今回は紹介できる人がいません</button></form>
@else
<p class="muted">募集を終えた案件です。</p>
@endif
</div>
@endforeach
</x-card>
