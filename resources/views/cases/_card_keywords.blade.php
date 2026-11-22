{{-- エンジニアを検索（提案元 SES の担当者だけ。v0.15.0）：チェックした項目で、エンジニアを検索する。案件の必須・尚可は変わらない --}}
<x-card title="エンジニアを検索" id="keywords">
@if($c->status !== 'open')
<p class="muted">募集を終えた案件です。</p>
@else
<form method="post" action="{{ url('/cases/'.$c->id.'/search') }}">@csrf
<input type="hidden" name="kw" value="1">
@php
$kwSections = [
    'req' => ['必須', '「、」や改行で区切ると、いくつでも足せます。'],
    'pref' => ['尚可', '「、」や改行で区切ると、いくつでも足せます。'],
    'confirm' => ['エンジニアに確認', '1行に1つ。チェックした項目は、メールの「ご確認ください」に入り、返事が必須になります。点数には使いません。'],
];
@endphp
@foreach($kwSections as $side => [$label, $hint])
<h3>{{ $label }}</h3>
@forelse($cond[$side] as $it)
<div><label class="check"><input type="checkbox" name="on[{{ $side }}][]" value="{{ \App\Services\CaseSearch::key($it) }}" @checked(!empty($it['on']))> {{ $it['name'] }}</label>@if($it['kind'] === 'exp') <span class="muted">（業務）</span>@endif @if($it['src'] === 'added')<small class="muted">足した</small>@endif</div>
@empty
<p class="muted">項目はありません。</p>
@endforelse
<x-field :label="$label.'に足す'" :name="'add_'.$side" type="textarea" rows="2" :hint="$hint" />
<p><button class="btn ghost small" formaction="{{ url('/cases/'.$c->id.'/keywords') }}">追加</button></p>
@endforeach
<p><button class="btn">エンジニアを検索</button></p>
<p class="muted">この検索にだけ使います。案件の必須・尚可は変わりません。チェックを外した項目は、検索に使いません。</p>
</form>
@endif
</x-card>
