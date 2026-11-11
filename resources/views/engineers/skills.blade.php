@extends('layouts.app', ['title' => 'スキルの編集（'.$e->name.'）'])
@section('content')
@php
    // $form＝項目の木（SkillForm::tree）、$state＝チェック・年数・その他の状態（保存済み。入力の誤りで戻ったときは送った値）
    $marks = $caseInfo['marks'] ?? [];
    $isOn = fn ($id) => \App\Services\SkillChecks::isOn($state, $id);
    $years = \App\Support\SkillForm::YEARS;

    // 節ごとのチェック数と、開いて出す節（役割・工程、チェックのある節、案件の印のある節、入力の誤りで戻ったとき）
    $count = [];
    foreach (\App\Support\SkillForm::nodes() as $n) if ($n['checkable'] && $isOn($n['id'])) $count[$n['section']] = ($count[$n['section']] ?? 0) + 1;
    $markSections = [];
    foreach ($marks as $id => $m) $markSections[\App\Support\SkillForm::node($id)['section']] = true;
    $isOpen = fn ($key) => $failed || in_array($key, ['roles', 'phases'], true) || !empty($count[$key]) || isset($markSections[$key]);

    // 年数のプルダウン（先頭は「（年数）」の空）
    $yearSelect = function (string $name, ?string $sel) use ($years) {
        $h = '<select name="'.e($name).'" class="sf-yr"><option value="">（年数）</option>';
        foreach ($years as $label => $v) $h .= '<option value="'.e($label).'"'.($sel === $label ? ' selected' : '').'>'.e($label).'</option>';
        return $h.'</select>';
    };
    // 案件の印（必須・尚可）
    $markOf = function ($id) use ($marks) {
        $m = $marks[$id] ?? null;
        return $m ? ' <small class="sf-mark">'.($m === 'required' ? '必須' : '尚可').'</small>' : '';
    };
    // チェックと名前（年数は付けない）
    $labelOf = function (array $n, bool $bold = false) use ($isOn, $markOf, $marks) {
        $id = $n['id'];
        $name = $bold ? '<b>'.e($n['item']).'</b>' : e($n['item']);
        return '<label class="sf-item'.(isset($marks[$id]) ? ' hit' : '').'"><input type="checkbox" name="chk['.e($id).']" value="1" data-sf="'.e($id).'"'.($isOn($id) ? ' checked' : '').'> '.$name.$markOf($id).'</label>';
    };
    // 項目1つ（親も同じ）。年数のある項目はプルダウンを横に付ける。その他は名前の入力欄を付ける
    $row = function (array $n, bool $bold = false) use ($labelOf, $yearSelect, $state, $isOn) {
        $id = $n['id'];
        if (!$n['other']) {
            return '<div class="sf-row">'.$labelOf($n, $bold).($n['years'] ? $yearSelect('yr['.$id.']', $state['yr'][$id] ?? null) : '').'</div>';
        }
        $names = array_values($state['oth'][$id] ?? []);
        $yrs = array_values($state['othyr'][$id] ?? []);
        $h = '<div class="sf-row sf-otherrow">'.$labelOf($n).'<div class="sf-other" data-sf-years="'.($n['years'] ? '1' : '0').'"'.($isOn($id) ? '' : ' hidden').'>';
        foreach ($names as $i => $nm) {
            $h .= '<div class="sf-orow"><input name="oth['.e($id).'][]" maxlength="'.\App\Support\SkillForm::OTHER_MAX.'" value="'.e($nm).'" placeholder="名前">'
                .($n['years'] ? $yearSelect('othyr['.$id.'][]', $yrs[$i] ?? null) : '').' <button type="button" class="link sf-del">×</button></div>';
        }
        return $h.'<button type="button" class="btn ghost small sf-add">＋</button></div></div>';
    };
@endphp
@if($pickable !== null)
<x-card>
@if($pickable->isEmpty())
<p class="muted">案件に合わせて印を付ける：案件が届くと、ここで選べます。</p>
@else
<form method="get" action="{{ url('/engineers/'.$e->id.'/skills') }}">
<label>案件に合わせて印を付ける
<select name="case"><option value="">（選ばない）</option>@foreach($pickable as $pc)<option value="{{ $pc->id }}"{{ $pick && (int) $pick->id === (int) $pc->id ? ' selected' : '' }}>{{ $pc->title }}{{ $pc->status === 'open' ? '' : '（'.lbl('caseStatus', $pc->status).'）' }}</option>@endforeach</select></label>
<button class="btn ghost small">合わせる</button>
<small class="muted">先に保存してから選んでください（選び直すと、保存していない入力は消えます）。</small>
</form>
@endif
@if($pick)
<p>案件「{{ $pick->title }}」の必須・尚可に当たる項目に印を付けました。経験のある項目だけチェックして保存してください。印は保存されません。</p>
@if($caseInfo['empty'])<p class="muted">この案件には、必須・尚可のスキルが書かれていません。</p>@endif
@if($caseInfo['missing'])<p class="warn">あなたのスキルに無い必須：{{ implode('、', $caseInfo['missing']) }}（経験があればチェックを入れてください。盛らないでください）</p>@endif
@endif
</x-card>
@endif
<x-card><form method="post" action="{{ url('/engineers/'.$e->id.'/skills') }}">@csrf
<input type="hidden" name="_sf" value="1">
<p class="muted">経験のある項目にチェックを入れ、年数を選んでください。年数がわからないときは選ばなくてかまいません（「経験あり」と出ます）。業界は当てはまるものすべて、PM/PL の設問は1つずつ選んでください。一覧に無いものは「その他」にチェックして名前を書きます。盛らずに、本当に経験のあるものだけを選んでください。</p>
<p class="sf-bar"><button type="button" class="btn ghost small" id="sf-open">すべて開く</button> <button type="button" class="btn ghost small" id="sf-close">すべて閉じる</button></p>
@foreach($form as $sec)
@php
    // 出す条件のある節（PM/PL の設問）：条件の項目（PM・PL）のどれかにチェックがあるときだけ出す。隠れている間、中のラジオは送らない
    $showOk = !$sec['showIf'] || count(array_filter($sec['showIf'], $isOn)) > 0;
    $secOpen = $sec['showIf'] ? true : $isOpen($sec['key']);
@endphp
<details class="sf-sec" data-sf-sec="{{ $sec['key'] }}"{!! $sec['showIf'] ? ' data-sf-showif="'.e(implode(',', $sec['showIf'])).'"' : '' !!}{{ $secOpen ? ' open' : '' }}{{ $showOk ? '' : ' hidden' }}>
<summary>{{ $sec['title'] }} <small class="muted">（{{ $count[$sec['key']] ?? 0 }} 件{{ $sec['single'] ? '回答' : 'チェック' }}）</small></summary>
@if($sec['showIf'])
<p class="muted">PM・PL のチェックを外して保存すると、ここの回答は消えます。</p>
@endif
@if($sec['single'])
<div class="sf-groups">@foreach($sec['groups'] as $g)
@php $gid = $g['node']['id']; $picked = $state['one'][$gid] ?? null; @endphp
<div class="sf-group"><div class="sf-ghead"><b>{{ $g['node']['item'] }}</b></div>
<div class="sf-gitems sf-children">@foreach($g['items'] as $n)
<div class="sf-row"><label class="sf-item"><input type="radio" name="one[{{ $gid }}]" value="{{ $n['id'] }}"{{ ($picked === $n['id'] || ($picked === null && $n['none'])) ? ' checked' : '' }}{{ $showOk ? '' : ' disabled' }}> {{ $n['item'] }}</label></div>
@endforeach</div></div>
@endforeach</div>
@elseif($sec['items'])
<div class="sf-items">@foreach($sec['items'] as $n){!! $row($n) !!}@endforeach</div>
@else
<div class="sf-groups">@foreach($sec['groups'] as $g)
<div class="sf-group"><div class="sf-ghead">@if($g['node']['checkable']){!! $row($g['node'], true) !!}@else<b>{{ $g['node']['item'] }}</b>@endif</div>
<div class="sf-gitems sf-children">@foreach($g['items'] as $n){!! $row($n) !!}@endforeach</div></div>
@endforeach</div>
@endif
</details>
@endforeach
@if(count($gone))
<div class="sf-gone"><b>今の表に無い項目</b>
<p class="muted">前に保存した項目のうち、今の表に無くなったものです。残すものにチェックを入れたまま保存してください。外して保存すると消えます。</p>
<div class="sf-children">@foreach($gone as $g)<label class="sf-item"><input type="checkbox" name="keep[]" value="{{ $g->id }}"{{ in_array($g->id, $keep, true) ? ' checked' : '' }}> {{ $g->path ? $g->path.'／' : '' }}{{ $g->name }}{{ $g->years ? '（'.$g->years.'）' : '' }}</label>@endforeach</div>
</div>
@endif
<template id="sf-tpl-y"><div class="sf-orow"><input maxlength="{{ \App\Support\SkillForm::OTHER_MAX }}" placeholder="名前">{!! $yearSelect('', null) !!} <button type="button" class="link sf-del">×</button></div></template>
<template id="sf-tpl-n"><div class="sf-orow"><input maxlength="{{ \App\Support\SkillForm::OTHER_MAX }}" placeholder="名前"> <button type="button" class="link sf-del">×</button></div></template>
<input type="hidden" name="_sf_end" value="1">
<p><a class="btn ghost" href="{{ url('/engineers/'.$e->id) }}">戻る</a> <button class="btn">保存する</button></p></form></x-card>
@endsection
