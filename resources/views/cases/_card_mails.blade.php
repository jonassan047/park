@if($mails->count() || (isset($extras) && $extras->count()))
{{-- 案内メールの記録：提案元 SES の担当者は操作もできる。管理者は読むだけ（v0.11.0）。追加の宛先は v0.15.0 --}}
<x-card title="案内メール">
<x-slot:actions>@if($canMail && $c->status === 'open')<a class="btn small" href="#keywords">エンジニアを検索</a>@endif</x-slot:actions>
<div id="mails"></div>
<x-table :head="$canMail ? ['エンジニア', '送った日', '返事', '先方の結果', '記録する', '案件をくれた先へ返す書類'] : ['エンジニア', '送った日', '返事', '先方の結果']" :count="$mails->count()" :empty="$canMail ? 'まだ送っていません。「エンジニアを検索」で、候補を絞ってメールを送れます。' : 'まだ送っていません。案内は、提案元 SES の担当者が行います。'">
@foreach($mails as $m)<tr>
<td>@if($m->route === 'own' || $u->role === 'admin'){{ $m->name }} <x-code :v="$m->eg_code" />@else{{ $m->initials ?: '—' }} <span class="muted">（{{ $m->ses_name }}・PARK から）</span>@endif<div class="muted">{{ $m->score }}点・{{ \App\Services\CaseMail::MODES[$m->mode] ?? '同じ内容' }}</div>
@if(!empty($inboundN[$m->engineer_id]))<div><a href="{{ url('/inbound?case='.$c->id.'&eg='.$m->engineer_id) }}">返信メール {{ $inboundN[$m->engineer_id] }} 件</a></div>@endif
@if($canMail)<div>@if(isset($pmByCm[$m->id]))<a class="btn ghost small" href="{{ url('/provider-mails/'.$pmByCm[$m->id]->id) }}">{{ $pmByCm[$m->id]->status === 'draft' ? '下書きを開く' : 'プロバイダーへの返信を見る' }}</a>@else<x-post :action="'/case-mails/'.$m->id.'/provider-draft'" label="プロバイダーへの返信を作る" cls="ghost small" />@endif</div>@endif</td>
<td>{{ fmt_dt($m->sent_at) }}</td>
<td>{{ \App\Services\CaseMail::REPLIES[$m->reply] ?? $m->reply }}@if($m->reply_note)<div class="muted">{{ $m->reply_note }}</div>@endif @if($m->reply_via === 'app')<div class="muted">（本人が画面で）</div>@endif</td>
<td>@include('cases._result', ['m' => $m, 'canEdit' => $canMail])</td>
@if($canMail)<td>@if($m->route === 'own')<form method="post" action="{{ url('/case-mails/'.$m->id.'/reply') }}" class="inline">@csrf
<select name="reply">@foreach(\App\Services\CaseMail::REPLIES as $k => $t)<option value="{{ $k }}" @selected($m->reply === $k)>{{ $t }}</option>@endforeach</select>
<input name="note" value="{{ $m->reply_note }}" placeholder="返信の要点"><button class="btn ghost small">記録</button></form>@else<span class="muted">本人が PARK の画面で返事します</span>@endif</td>
<td>@if(isset($mailApps[$m->engineer_id]))<a class="btn small" href="{{ url('/applications/'.$mailApps[$m->engineer_id]) }}">応募・面談の画面へ</a><br>@elseif($m->route === 'own' && $m->reply === 'interested')<x-post :action="'/case-mails/'.$m->id.'/submitted'" label="書類を返した（応募した）" cls="small" /><br>@endif
@if(!empty($m->canThread))<a class="btn ghost small" href="{{ url('/cases/'.$c->id.'/threads/'.$m->engineer_id) }}">やり取り</a><br>@endif
@if($m->route === 'own')<a href="{{ url('/case-mails/'.$m->id.'/doc/resume') }}">履歴書</a>・<a href="{{ url('/case-mails/'.$m->id.'/doc/careersheet') }}">業務経歴書</a>・<a href="{{ url('/case-mails/'.$m->id.'/doc/careersheet-anon') }}">業務経歴書（名前を伏せる）</a>
@elseif($m->reply === 'interested')<a href="{{ url('/case-mails/'.$m->id.'/doc/careersheet-anon') }}">業務経歴書（名前を伏せる）</a>@else<span class="muted">応募したいと返事が来たら出せます</span>@endif</td>@endif
</tr>@endforeach
</x-table>
@if($canMail)<p class="muted">書類は、押したときの最新の内容で Excel を作ってダウンロードします。メールに添付して、案件をくれた先へ返信してください。</p>@endif
{{-- 追加の宛先（PARK に登録していない人へ送ったもの）：提案元 SES の担当者と管理者だけ。管理者は読むだけ --}}
@if(isset($extras) && $extras->count())
<h3 id="extras">追加の宛先</h3>
<x-table :head="['宛先', '送った日', '返信']" :count="$extras->count()">
@foreach($extras as $x)<tr>
<td>{{ $x->name ?: '—' }}<div class="muted">{{ $x->email }}</div></td>
<td>{{ fmt_dt($x->sent_at) }}</td>
<td>@if(!empty($extraN[$x->id]))@if($canMail)<a href="{{ url('/inbound?case='.$c->id.'&extra='.$x->id) }}">返信メール {{ $extraN[$x->id] }} 件</a>@else返信メール {{ $extraN[$x->id] }} 件@endif @else<span class="muted">まだありません</span>@endif</td>
</tr>@endforeach
</x-table>
@endif
</x-card>
@endif
