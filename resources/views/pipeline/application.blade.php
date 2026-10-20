@php $u = auth()->user(); $yes = fn ($v) => $v ? '○' : new \Illuminate\Support\HtmlString('<span class="bad-text">要確認</span>'); @endphp
@extends('layouts.app', ['title' => '選考：'.$a->title, 'wide' => true])
@section('content')
<div class="cols"><div>
<x-card title="応募の内容"><dl class="dl">
<dt>案件</dt><dd><a href="{{ url('/cases/'.$a->case_id) }}">{{ $a->title }}</a></dd>
<dt>エンジニア</dt><dd><a href="{{ url('/engineers/'.$a->engineer_id) }}">{{ $a->eg_name }}</a></dd>
<dt>SES</dt><dd>{{ $a->ses_name }}</dd><dt>状態</dt><dd>{{ badge('appStatus', $a->status) }}</dd>
<dt>提示の金額</dt><dd>{{ man($a->proposed_price) }}</dd><dt>応募日</dt><dd>{{ fmt_dt($a->applied_at) }}</dd>
<dt>開始に間に合う</dt><dd>{{ $yes($a->start_ok) }}</dd><dt>金額</dt><dd>{{ $yes($a->price_ok) }}</dd><dt>働き方・場所</dt><dd>{{ $yes($a->style_ok) }}</dd>
<dt>気になること</dt><dd>{!! nl2br(e($a->condition_note)) ?: '—' !!}</dd>
@if($a->status === 'rejected')<dt>見送りの理由</dt><dd>{{ $a->reject_reason }} {{ $a->reject_note }}</dd>@endif
<dt>スキルシート</dt><dd><a href="{{ $sheetUrl }}" target="_blank">この案件に合わせたスキルシート</a></dd>
@if($contract)<dt>契約</dt><dd><a href="{{ url('/contracts/'.$contract->id) }}">{{ fmt_date($contract->start_date) }}〜{{ fmt_date($contract->end_date) }}（{{ man($contract->price) }}）</a></dd>@endif
</dl></x-card>

<x-card title="面談"><x-table :head="['回', '日時', '場所', '結果', '']" :count="$ivs->count()" empty="まだ面談はありません。">
@foreach($ivs as $iv)<tr><td>{{ $iv->round }}回目</td><td>{{ fmt_dt($iv->scheduled_at) }}</td><td>{{ $iv->place }}</td><td>{{ badge('ivResult', $iv->result) }}</td><td><a href="{{ url('/interviews/'.$iv->id) }}">Q&A・結果</a></td></tr>@endforeach
</x-table></x-card>

@php $staff = in_array($u->role, ['admin', 'ses']); @endphp
@if($staff && $open)
<x-card title="次の手順">
@if($a->status === 'applied')
  <p>1. 案件に合わせたスキルシート（<a href="{{ $sheetUrl }}" target="_blank">名前を伏せた版</a>）を確かめて、元請けに提出します。</p>
  <x-post :action="'/applications/'.$a->id.'/status'" label="スキルシートを提出した" :fields="['status' => 'submitted']" />
@elseif($a->status === 'submitted')
  <p>書類の結果が出たら記録します。</p><x-post :action="'/applications/'.$a->id.'/status'" label="書類が通過した" :fields="['status' => 'doc_passed']" />
@elseif($a->status === 'interview')
  <p>面談の結果は、面談の画面（Q&A・結果）で記録します。</p>
@elseif($a->status === 'interviewed')
  <p>面談を通過しました。次の面談を設定するか、成約を記録します。</p>
@endif
@if(in_array($a->status, ['applied', 'submitted', 'doc_passed', 'interview', 'interviewed']))
  <form method="post" action="{{ url('/applications/'.$a->id.'/interview') }}">@csrf<h3>面談依頼が来た（{{ $ivs->count() + 1 }}回目）</h3>
  <div class="grid2"><x-field label="日時" name="scheduled_at" type="datetime-local" /><x-field label="場所・URL" name="place" placeholder="オンライン（URL は追って）" /></div>
  <x-field label="依頼の内容（先方が確かめたいこと。1行に1つ）" name="request_note" type="textarea" rows="3" placeholder="Spring Boot での設計の経験&#10;リーダー経験の有無" />
  <p class="muted">記録すると、案件と本人のスキルを突き合わせて<b>面談スクリプト</b>（想定質問と答え方）を作り、本人・SES・管理者に知らせます。</p><button class="btn">面談依頼を記録する</button></form>
@endif
@if(in_array($a->status, ['interview', 'interviewed']))
  <form method="post" action="{{ url('/applications/'.$a->id.'/contract') }}" onsubmit="return confirm('成約として記録し、本人と SES に知らせます。よろしいですか？')">@csrf<h3>成約を記録</h3>
  <div class="grid3"><x-field label="契約の金額（万円／月）" name="price" type="number" step="0.5" :value="num_disp($a->proposed_price ?: $a->case_price)" required />
  <x-field label="開始日" name="start_date" type="date" :value="$a->case_start ?: today_str()" required /><x-field label="契約の終わり（空なら3か月）" name="end_date" type="date" /></div>
  <button class="btn">成約にする</button></form>
@endif
<hr>
<form method="post" action="{{ url('/applications/'.$a->id.'/status') }}">@csrf<input type="hidden" name="status" value="rejected">
<x-field label="見送りの理由" name="reason" type="select" :options="array_combine(PARK_REJECT_REASONS, PARK_REJECT_REASONS)" />
<x-field label="くわしく（次に活かすため1行で）" name="note" /><button class="btn ghost">見送りにする</button></form>
</x-card>
@elseif($u->role === 'eg' && $open)
<x-card title="応募の取り下げ"><form method="post" action="{{ url('/applications/'.$a->id.'/withdraw') }}" onsubmit="return confirm('応募を取り下げますか？')">@csrf
<x-field label="理由" name="note" required /><button class="btn ghost">取り下げる</button></form></x-card>
@endif
</div><div>
<x-card title="経過"><ol class="timeline">@foreach($events as $ev)<li><b>{{ lbl('appStatus', $ev->status) }}</b> <small>{{ fmt_dt($ev->at) }}・{{ $ev->name }}</small>@if($ev->note)<div>{{ $ev->note }}</div>@endif</li>@endforeach</ol></x-card>
@if($staff)
<x-card title="メモ・フィードバックを残す"><form method="post" action="{{ url('/applications/'.$a->id.'/note') }}">@csrf
<x-field name="note" type="textarea" rows="2" placeholder="元請けからの印象、落ちた理由など（次の応募・面談に活かす）" /><button class="btn ghost small">残す</button></form></x-card>
@endif
</div></div>
<p class="backrow"><a class="btn ghost" href="{{ list_url('applications') }}">応募・選考の一覧に戻る</a></p>
@endsection
