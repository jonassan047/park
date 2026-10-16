@php $labels = \App\Services\CaseMail::REPLIES; @endphp
@extends('layouts.app', ['title' => 'ご返事：'.$m->title])
@section('content')
<x-card><form method="post" action="{{ url('/r/'.$token) }}" class="narrow">@csrf
@if($m->case_status !== 'open')<p class="note">この案件は、すでに募集を終えています。ご返事は担当者に届きます。</p>@endif
<p>案件「<b>{{ $m->title }}</b>」へのご返事です。</p>
<x-field label="ご返事" name="answer" type="select" :options="array_diff_key($labels, ['none' => 1])" :value="$answer" />
<x-field label="ひとこと（任意。条件で気になること、質問、見送りの理由など）" name="note" type="textarea" rows="3" />
<button class="btn">この内容で返事をする</button>
</form></x-card>
@endsection
