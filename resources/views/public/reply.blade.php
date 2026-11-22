@php $labels = \App\Services\CaseMail::REPLIES; @endphp
@extends('layouts.app', ['title' => ['interested' => '案件へ応募', 'question' => '質問事項', 'declined' => '案件見送り'][$answer] ?? 'ご返事のお願い'])
@section('content')
<x-card><form method="post" action="{{ url('/r/'.$token) }}" class="narrow">@csrf
@if($m->case_status !== 'open')<p class="note">この案件は、すでに募集を終えています。ご返事は担当者に届きます。</p>@endif
<p>案件：<b>{{ $m->case_code }} {{ $m->title }}</b></p>
@if($answer === 'question')<p class="note">PARK にログインすると、<a href="{{ url('/cases/'.$m->case_id) }}">案件の画面</a>から、担当者とやり取りできます（案件についての質問は、そちらが早く届きます）。</p>@endif
@if($confirm)
{{-- 検索から送った案内の「ご確認ください」：お答えが必須 --}}
<div class="note"><p><b>ご確認ください（お答えが必須です）</b></p>
<ul>@foreach($confirm as $it)<li>{{ $it }}</li>@endforeach</ul></div>
@endif
<x-field label="ご返事" name="answer" type="select" :options="array_diff_key($labels, ['none' => 1])" :value="$answer" />
@if($confirm)<x-field label="ひとこと（必須。上の項目へのお答えを書いてください）" name="note" type="textarea" rows="3" :required="true" />
@else<x-field label="ひとこと（任意。条件で気になること、質問、見送りの理由など）" name="note" type="textarea" rows="3" />@endif
<button class="btn">この内容で返事をする</button>
</form></x-card>
@endsection
