{{-- 先方の結果（合否）とメモ。$m＝案内メールの行（id・result・result_note）、$canEdit＝登録できる人（持ち込み元の担当者）。管理者は読むだけ。$back・$backId＝登録のあとに戻る画面（inbound／pm）、$primary＝「登録」を緑地にする（応募を確定させたあと。案件詳細） --}}
@php $results = \App\Services\CaseResult::RESULTS; @endphp
<div>@if($m->result === 'passed')<span class="result-passed">{{ $results['passed'] ?? '合格' }}</span>@elseif($m->result)<b>{{ $results[$m->result] ?? $m->result }}</b>@else<span class="muted">未登録</span>@endif
@if($m->result_note)<div class="muted">メモ：{{ $m->result_note }}</div>@endif
@if($m->result_at)<div class="muted">{{ fmt_dt($m->result_at) }}</div>@endif</div>
@if($canEdit)
<form method="post" action="{{ url('/case-mails/'.$m->id.'/result') }}" class="inline">@csrf
@if(!empty($back))<input type="hidden" name="back" value="{{ $back }}"><input type="hidden" name="{{ $back === 'pm' ? 'pm' : 'inbound' }}" value="{{ $backId }}">@endif
<select name="result">@foreach($results as $k => $t)<option value="{{ $k }}" @selected(($m->result ?? 'pending') === $k)>{{ $t }}</option>@endforeach</select>
<input name="note" value="{{ $m->result_note }}" maxlength="1000" placeholder="メモ（任意。プロバイダーの評価など）"><button class="btn {{ empty($primary) ? 'ghost ' : '' }}small">登録</button></form>
<div class="muted">合格・不合格は、エンジニア本人に知らせが届きます（メモは届きません）。</div>
@endif
