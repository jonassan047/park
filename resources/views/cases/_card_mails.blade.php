@if($mails->count() || (isset($extras) && $extras->count()) || (isset($staffReq) && $staffReq->count()))
{{-- 案内メールの記録：受注 SES の担当者は操作もできる。管理者は読むだけ（v0.11.0）。追加の宛先は v0.15.0 --}}
<x-card title="案内済メール">
<div id="mails"></div>
<x-table :head="$canMail ? ['エンジニア', '送った日', '返事', 'プロバイダーからの結果報告', '記録する', '案件をくれた先へ返す書類'] : ['エンジニア', '送った日', '返事', 'プロバイダーからの結果報告']" :count="$mails->count()" :empty="$canMail ? 'まだ送っていません。右の「エンジニアを検索」から、候補を絞ってメールを送れます。' : 'まだ送っていません。案内は、受注 SES の担当者が行います。'">
@php $replyLabels = array_merge(\App\Services\CaseMail::REPLIES, ['interested' => '応募する']); @endphp
@foreach($mails as $m)@php $sent = isset($providerMails) && $providerMails->where('case_mail_id', $m->id)->where('status', 'sent')->count(); @endphp<tr>
<td>@if($m->route === 'own' || $u->role === 'admin'){{ $m->name }} <x-code :v="$m->eg_code" />@else{{ $m->initials ?: '—' }} <span class="muted">（{{ $m->ses_name }}・PARK から）</span>@endif<div class="muted">{{ $m->score }}点・{{ \App\Services\CaseMail::MODES[$m->mode] ?? '同じ内容' }}</div>
@if(!empty($inboundN[$m->engineer_id]))<div><a href="{{ url('/inbound?case='.$c->id.'&eg='.$m->engineer_id) }}">返信メール {{ $inboundN[$m->engineer_id] }} 件</a></div>@endif</td>
<td>{{ fmt_dt($m->sent_at) }}</td>
<td>{{ $replyLabels[$m->reply] ?? $m->reply }}@if($m->reply_note)<div class="muted">{{ $m->reply_note }}</div>@endif @if($m->reply_via === 'app')<div class="muted">（本人が画面で）</div>@endif
{{-- パートナー経由の行：提案の依頼の日と、パートナーが記録した最後の結果（管理者が読むだけ。v0.17.0） --}}
@if($m->mode === \App\Services\CaseSearch::MODE_PARTNER)@if($m->handoff_at)<div class="muted">提案の依頼：{{ fmt_date($m->handoff_at) }}</div>@endif @if($m->outcome)<div class="muted">最後の結果：{{ \App\Services\PartnerTrack::OUTCOMES[$m->outcome] ?? $m->outcome }}（{{ fmt_date($m->outcome_at) }}）</div>@endif @endif</td>
<td>@if($sent)@include('cases._result', ['m' => $m, 'canEdit' => $canMail, 'primary' => !in_array($m->result, ['passed', 'failed'], true)])@else<span class="muted">プロバイダーへの返信を送ると、登録できます。</span>@endif</td>
@if($canMail)<td>@if($m->route === 'own')<form method="post" action="{{ url('/case-mails/'.$m->id.'/reply') }}" class="inline">@csrf
<select name="reply" autocomplete="off">@foreach($replyLabels as $k => $t)<option value="{{ $k }}" @selected($m->reply === $k)>{{ $t }}</option>@endforeach</select>
<input name="note" value="{{ $m->reply_note }}" placeholder="返信の要点"><button class="btn ghost small">記録</button></form>@else<span class="muted">本人が PARK の画面で返事します</span>@endif</td>
<td>@if(isset($mailApps[$m->engineer_id]))<a class="btn {{ $sent ? 'ghost ' : '' }}small" href="{{ url('/applications/'.$mailApps[$m->engineer_id]) }}">応募する</a><br>@elseif($m->route === 'own' && $m->reply === 'interested')<x-post :action="'/case-mails/'.$m->id.'/submitted'" label="応募する" cls="small" /><br>@endif
@if(!empty($m->canThread))<a class="btn ghost small" href="{{ url('/cases/'.$c->id.'/threads/'.$m->engineer_id) }}">やり取り</a><br>@endif
@if($m->route === 'own')<a href="{{ url('/case-mails/'.$m->id.'/doc/resume') }}">履歴書</a>・<a href="{{ url('/case-mails/'.$m->id.'/doc/careersheet') }}">業務経歴書</a>・<a href="{{ url('/case-mails/'.$m->id.'/doc/careersheet-anon') }}">業務経歴書（名前を伏せる）</a>
@elseif($m->reply === 'interested')<a href="{{ url('/case-mails/'.$m->id.'/doc/careersheet-anon') }}">業務経歴書（名前を伏せる）</a>@else<span class="muted">応募したいと返事が来たら出せます</span>@endif</td>@endif
</tr>@endforeach
</x-table>
@if($canMail)<p class="muted">書類は、押したときの最新の内容で Excel を作ってダウンロードします。メールに添付して、案件をくれた先へ返信してください。</p>@endif
{{-- 追加の宛先（PARK に登録していない人へ送ったもの）：受注 SES の担当者と管理者だけ。管理者は読むだけ --}}
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
{{-- 担当者への依頼（他社の担当者に紹介をお願いしたもの。v0.16.0）：受注 SES の担当者と管理者。管理者は読むだけ。返信の本文は、その会社の中の話なので出さない --}}
@if(isset($staffReq) && $staffReq->count())
<h3 id="staffreq">担当者への依頼</h3>
<x-table :head="['企業名［担当者名］', '送った日', '状態', 'その企業から案内した人数', '提案の依頼', '成約']" :count="$staffReq->count()">
@foreach($staffReq as $t)<tr>
<td>{{ $t->partner_name }}［{{ $t->staff_name }}］</td>
<td>{{ fmt_dt($t->sent_at) }}</td>
<td>@if($t->declined_at)<span class="muted">辞退（{{ fmt_date($t->declined_at) }}）</span>@if($t->declined_note)<div class="muted">{{ $t->declined_note }}</div>@endif @else依頼中@endif</td>
<td>{{ $t->introduced }} 名</td>
<td>{{ $t->handoffN }} 名</td>
<td>{{ $t->wonN }} 名</td>
</tr>@endforeach
</x-table>
<p class="muted">パートナーの担当者が自社のエンジニアに案内した返事は、その企業の中で扱います。応募したい人がいれば、パートナーから「提案の依頼」が届きます。</p>
@endif
{{-- 提案の依頼（パートナーが［受注 SES に提案を頼む］を押した人。v0.17.0）：受注 SES の担当者だけ。イニシャルだけ。返信の本文・氏名は出さない --}}
@if($canMail && isset($handoffs) && $handoffs->count())
<h3 id="handoffs">提案の依頼</h3>
@php $outcomes = \App\Services\PartnerTrack::OUTCOMES; @endphp
<x-table :head="['イニシャル（企業名［担当者名］）', '頼まれた日・ひとこと', 'プロバイダーへの返信', '業務経歴書', '先方の結果', '最後の結果']" :count="$handoffs->count()">
@foreach($handoffs as $h)@php $pm = $providerMails->where('case_mail_id', $h->id)->where('status', '!=', 'discarded')->sortBy(fn ($p) => $p->status === 'draft' ? 0 : 1)->first(); $sentPm = $providerMails->where('case_mail_id', $h->id)->where('status', 'sent')->count(); @endphp<tr>
<td>{{ $h->initials ?: '—' }}（{{ $h->partner_name }}［{{ $h->staff_name }}］）</td>
<td>{{ fmt_dt($h->handoff_at) }}@if($h->handoff_note)<div class="muted">{{ $h->handoff_note }}</div>@endif</td>
<td>@if($pm)<a class="btn {{ $pm->status === 'draft' ? '' : 'ghost ' }}small" href="{{ url('/provider-mails/'.$pm->id) }}">{{ $pm->status === 'draft' ? '下書きを開く' : '送ったメール' }}</a>
@else<x-post :action="'/case-mails/'.$h->id.'/provider-draft'" label="プロバイダーへの返信を作る" cls="small" />@endif</td>
<td><a href="{{ url('/case-mails/'.$h->id.'/doc/careersheet-anon') }}">業務経歴書（名前を伏せる）</a></td>
<td>@if($sentPm)@include('cases._result', ['m' => $h, 'canEdit' => true, 'hideNote' => false, 'primary' => !in_array($h->result, ['passed', 'failed'], true)])@else<span class="muted">プロバイダーへの返信を送ると、登録できます。</span>@endif</td>
<td>@if($h->outcome)<b>{{ $outcomes[$h->outcome] ?? $h->outcome }}</b><div class="muted">{{ fmt_date($h->outcome_at) }}</div>@else<span class="muted">未記録</span>@endif</td>
</tr>@endforeach
</x-table>
@endif
</x-card>
@endif
