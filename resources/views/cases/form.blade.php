@extends('layouts.app', ['title' => isset($c->id) ? '案件の編集：'.$c->title : '案件の登録'])
@section('content')
@if($import)
<x-card title="案件メールから取り込む"><form method="post" action="{{ url('/cases/import') }}">@csrf
<p class="muted">届いた案件メールの本文を貼り付けると、案件名・金額・必須スキルなどを抜き出して下の欄に入れます（このPCの中で処理し、外部には送りません）。</p>
<x-field name="raw_text" type="textarea" rows="6" placeholder="【案件名】…&#10;【単価】〜70万円&#10;【必須】Java、Spring&#10;…" />
<button class="btn ghost">取り込む</button></form></x-card>
@endif
<x-card title="案件票"><form method="post" action="{{ url($action) }}">@csrf
<div class="grid2">
<x-field label="案件名" name="title" :value="$c->title ?? null" required cls="span2" />
<x-field label="元請け（伏せ字でよい）" name="client_label" :value="$c->client_label ?? null" placeholder="例：SH-A 社" hint="元請けの名前は外に出しません。伏せ字で構いません。" />
@if($sesList)<x-field label="案件を持ち込んだ SES" name="source_ses_id" type="select" :options="$sesList" blank="（管理者が直接）" :value="$c->source_ses_id ?? null" />@else<div></div>@endif
<x-field label="金額（万円／月）" name="price" type="number" step="0.5" :value="num_disp($c->price ?? null)" hint="SES が元請けから受け取る月額。成功報酬の基準になります。" />
<x-field label="金額のメモ" name="price_note" :value="$c->price_note ?? null" placeholder="例：スキル見合い、精算 140-180h" />
<x-field label="必須スキル" name="required_skills" type="textarea" rows="2" :value="$c->required_skills ?? null" placeholder="Java、Spring Boot、SQL（「、」区切り）" />
<x-field label="尚可スキル" name="preferred_skills" type="textarea" rows="2" :value="$c->preferred_skills ?? null" placeholder="AWS、Docker" />
@php
    // 業務経験・業務領域（必須・尚可）。今の選び方：項目の名前 → required／preferred
    $expReq = \App\Support\CaseExp::split($c->required_exp ?? null);
    $expPref = \App\Support\CaseExp::split($c->preferred_exp ?? null);
    $expSecs = [];
    foreach (\App\Support\SkillForm::caseItems() as $expName => $expIt) $expSecs[$expIt['section']][$expName] = $expIt['id'];
@endphp
<details open class="span2"><summary>業務経験・業務領域（必須・尚可）</summary>
<p class="muted">取り込みでは、必須・尚可の文から拾って選んでおきます。確かめて直してください。</p>
<input type="hidden" name="_exp" value="1">
@foreach($expSecs as $secKey => $expItems)
<p><b>{{ \App\Support\SkillForm::shortTitle($secKey) }}</b></p>
@foreach($expItems as $expName => $expId)
@php $expNow = in_array($expName, $expReq, true) ? 'required' : (in_array($expName, $expPref, true) ? 'preferred' : ''); @endphp
<p><select name="exp[{{ $expId }}]" aria-label="{{ $expName }}">
<option value="">—</option>
<option value="required"{{ $expNow === 'required' ? ' selected' : '' }}>必須</option>
<option value="preferred"{{ $expNow === 'preferred' ? ' selected' : '' }}>尚可</option>
</select> {{ $expName }}</p>
@endforeach
@endforeach
</details>
<x-field label="経験年数（以上）" name="min_years" type="number" step="0.5" :value="num_disp($c->min_years ?? null)" />
<x-field label="働き方" name="work_style" type="select" :options="PARK_LABELS['workStyle']" :value="$c->work_style ?? 'any'" />
<x-field label="場所" name="location" :value="$c->location ?? null" placeholder="例：東京都港区（最寄り：品川）" />
<x-field label="開始" name="start_date" type="date" :value="isset($c->start_date) ? fmt_date($c->start_date) : null" />
<x-field label="期間" name="period" :value="$c->period ?? null" placeholder="例：3か月〜長期" />
<x-field label="稼働（週◯日）" name="days_per_week" type="number" min="1" :value="$c->days_per_week ?? 5" />
<x-field label="面談回数" name="interviews_count" type="number" min="0" :value="$c->interviews_count ?? 1" />
<x-field label="募集人数" name="headcount" type="number" min="1" :value="$c->headcount ?? 1" />
<x-field label="応募の締め切り" name="deadline" type="date" :value="isset($c->deadline) ? fmt_date($c->deadline) : null" />
<x-field label="状態" name="status" type="select" :options="PARK_LABELS['caseStatus']" :value="$c->status ?? 'open'" />
</div>
<x-field label="作業内容" name="description" type="textarea" rows="5" :value="$c->description ?? null" />
<x-field label="その他の条件・備考" name="conditions" type="textarea" rows="3" :value="$c->conditions ?? null" />
<details><summary>元の案件メール（記録用）</summary><x-field name="raw_text" type="textarea" rows="6" :value="$c->raw_text ?? null" /></details>
<button class="btn">{{ $submit }}</button> <a class="btn ghost" href="{{ isset($c->id) ? url('/cases/'.$c->id) : list_url('cases') }}">戻る</a></form></x-card>
@endsection
