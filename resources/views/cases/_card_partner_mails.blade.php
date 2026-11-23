{{-- 自社から案内したエンジニア（パートナー側の追跡。v0.17.0）：受注 SES ではない SES・担当者。会社の誰が開いても同じ。プロバイダーのことは何も出さない --}}
@if(isset($partnerMails) && $partnerMails->count())
@php $replyLabels = array_merge(\App\Services\CaseMail::REPLIES, ['interested' => '応募する']); $outcomes = \App\Services\PartnerTrack::OUTCOMES; $openN = \App\Services\PartnerTrack::openCount($partnerMails); @endphp
<x-card title="自社から案内したエンジニア" id="partner-mails">
<p>最後の結果を記録していない人：{{ $openN }} 名</p>
<x-table :head="['エンジニア', '送った日', '返事', '提案の依頼', '先方の結果', '最後の結果', '']" :count="$partnerMails->count()">
@foreach($partnerMails as $m)<tr>
<td>{{ (int) $m->eg_ses_id === (int) auth()->user()->ses_id ? $m->name : ($m->initials ?: '—') }} <x-code :v="(int) $m->eg_ses_id === (int) auth()->user()->ses_id ? $m->eg_code : null" />@if($m->inboundN)<div><a href="{{ url('/inbound?case='.$c->id.'&eg='.$m->engineer_id) }}">返信メール {{ $m->inboundN }} 件</a></div>@endif</td>
<td>{{ fmt_dt($m->sent_at) }}</td>
<td>{{ $replyLabels[$m->reply] ?? $m->reply }}@if($m->reply_note)<div class="muted">{{ $m->reply_note }}</div>@endif @if($m->reply_via === 'app')<div class="muted">（本人が画面で）</div>@endif
<form method="post" action="{{ url('/case-mails/'.$m->id.'/reply') }}" class="inline">@csrf
<select name="reply" autocomplete="off">@foreach($replyLabels as $k => $t)<option value="{{ $k }}" @selected($m->reply === $k)>{{ $t }}</option>@endforeach</select>
<input name="note" value="{{ $m->reply_note }}" placeholder="返信の要点"><button class="btn ghost small">記録</button></form></td>
<td>@if($m->handoff_at)頼みました（{{ fmt_date($m->handoff_at) }}）@if($m->handoff_note)<div class="muted">{{ $m->handoff_note }}</div>@endif
@elseif($m->reply === 'interested' && $c->status === 'open')<form method="post" action="{{ url('/case-mails/'.$m->id.'/handoff') }}" class="inline">@csrf
<input name="note" maxlength="500" placeholder="ひとこと（任意。氏名は書かないでください）"><button class="btn small">受注 SES に提案を頼む</button></form>
@else<span class="muted">—</span>@endif</td>
<td>@include('cases._result', ['m' => $m, 'canEdit' => false, 'hideNote' => true])</td>
<td>@if($m->outcome)<b>{{ $outcomes[$m->outcome] ?? $m->outcome }}</b>@if($m->outcome_note)<div class="muted">メモ：{{ $m->outcome_note }}</div>@endif<div class="muted">{{ fmt_dt($m->outcome_at) }}</div>@else<span class="muted">未記録</span>@endif
<form method="post" action="{{ url('/case-mails/'.$m->id.'/outcome') }}" class="inline">@csrf
<select name="outcome"><option value="">選んでください</option>@foreach($outcomes as $k => $t)<option value="{{ $k }}" @selected($m->outcome === $k)>{{ $t }}</option>@endforeach</select>
<input name="note" value="{{ $m->outcome_note }}" maxlength="500" placeholder="メモ（任意）"><button class="btn {{ $m->outcome ? 'ghost ' : '' }}small">記録</button></form></td>
<td>@if($m->canThread)<a class="btn ghost small" href="{{ url('/cases/'.$c->id.'/threads/'.$m->engineer_id) }}">やり取り</a>@endif</td>
</tr>@endforeach
</x-table>
<p class="muted">プロバイダーへの提案は、受注 SES が行います。応募したい人がいたら［受注 SES に提案を頼む］を押してください。成約したら、最後の結果に［成約］を記録してください（受注 SES に報告が届きます）。</p>
</x-card>
@endif
