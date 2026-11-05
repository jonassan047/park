@extends('layouts.app', ['title' => 'スキルの編集（'.$e->name.'）'])
@section('content')
@php
    $isEg = auth()->user()->role === 'eg';
    // 入力の誤りで戻ったときは、送った値で行を作り直す
    if (is_array(old('skill_name'))) {
        $skills = collect(old('skill_name'))->map(fn ($n, $i) => (object) ['name' => $n, 'category' => old('skill_cat.'.$i), 'years' => old('skill_years.'.$i), 'level' => old('skill_level.'.$i),
            'last_used_ym' => old('skill_last.'.$i), 'is_primary' => old('skill_primary.'.$i) === '1', 'note' => old('skill_note.'.$i)])->filter(fn ($s) => trim((string) $s->name) !== '')->values();
    }
    // 案件を選んでいるときだけ、案件に合うかの印（行の番号 → 必須／尚可）を作る。チェックは見るための印で、保存しない
    $marks = $missing = $isEmpty = null;
    if ($pick) {
        $m = \App\Services\Matcher::rowMarks($skills->pluck('name')->all(), $pick);
        [$marks, $missing, $isEmpty] = [$m['marks'], $m['missing'], $m['empty']];
    }
    $row = function ($s = null, $mark = null) use ($isEg, $pick) {
        $cat = $s->category ?? 'other'; $lv = (int) ($s->level ?? 3);
        $h = '<tr>';
        // チェックボックスには name を付けない（保存で送られない）
        if ($pick) $h .= '<td><input type="checkbox" class="skill-hit"'.($mark ? ' checked' : '').'>'.($mark ? ' <small>'.($mark === 'required' ? '必須' : '尚可').'</small>' : '').'</td>';
        $h .= '<td><input name="skill_name[]" list="skillterms" value="'.e($s->name ?? '').'" placeholder="Java"></td><td><select name="skill_cat[]">';
        foreach (PARK_LABELS['skillCat'] as $k => $v) $h .= '<option value="'.$k.'"'.($cat === $k ? ' selected' : '').'>'.e($v).'</option>';
        $h .= '</select></td><td><input name="skill_years[]" type="number" step="0.5" min="0" value="'.e(num_disp($s->years ?? null)).'"></td>';
        // 経歴から数えた月数と件数（見るだけ）。申告より経歴がだいぶ短いときは、本人にだけ注意を出す
        $h .= '<td>';
        if (!empty($s->projects)) $h .= e(($s->years ? '申告 '.num_disp($s->years).'年 ／ ' : '').'経歴 '.fmt_months((int) $s->months).'（'.$s->projects.'件）');
        else $h .= '<span class="muted">—</span>';
        if ($isEg && !empty($s->warn)) $h .= '<br><small class="warn">経歴に書かれていない期間があります。経歴を足すか、年数を見直してください。</small>';
        $h .= '</td><td><select name="skill_level[]">';
        foreach (\App\Services\SkillTable::LEVELS as $n => $t) $h .= '<option value="'.$n.'"'.($lv === $n ? ' selected' : '').'>'.e($n.'　'.$t).'</option>';
        $h .= '</select></td><td><input name="skill_last[]" type="month" value="'.e($s->last_used_ym ?? '').'">';
        if (empty($s->last_used_ym) && !empty($s->last_ym)) $h .= '<br><small class="muted">経歴から：'.e($s->last_ym).'</small>';
        $h .= '</td><td><select name="skill_primary[]"><option value="0">—</option><option value="1"'.(!empty($s->is_primary) ? ' selected' : '').'>得意</option></select></td>';
        $h .= '<td><input name="skill_note[]" maxlength="200" value="'.e($s->note ?? '').'"></td>';
        return $h.'<td><button type="button" class="link" onclick="this.closest(\'tr\').remove()">削除</button></td></tr>';
    };
@endphp
@if($pickable !== null)
<x-card>
@if($pickable->isEmpty())
<p class="muted">案件に合わせてチェックする：案件が届くと、ここで選べます。</p>
@else
<form method="get" action="{{ url('/engineers/'.$e->id.'/skills') }}">
<label>案件に合わせてチェックする
<select name="case"><option value="">（選ばない）</option>@foreach($pickable as $pc)<option value="{{ $pc->id }}"{{ $pick && (int) $pick->id === (int) $pc->id ? ' selected' : '' }}>{{ $pc->title }}{{ $pc->status === 'open' ? '' : '（'.lbl('caseStatus', $pc->status).'）' }}</option>@endforeach</select></label>
<button class="btn ghost small">合わせる</button>
<small class="muted">先に保存してから選んでください（選び直すと、保存していない入力は消えます）。</small>
</form>
@endif
@if($pick)
<p>案件「{{ $pick->title }}」の必須・尚可に合うスキルにチェックを入れました。チェックは見るための印で、保存はされません。</p>
@if($isEmpty)<p class="muted">この案件には、必須・尚可のスキルが書かれていません。</p>@endif
@if($missing)<p class="warn">スキル表に無い必須：{{ implode('、', $missing) }}</p>@endif
@endif
</x-card>
@endif
<x-card><form method="post" action="{{ url('/engineers/'.$e->id.'/skills') }}">@csrf
<p class="muted">レベルの目安：@foreach(\App\Services\SkillTable::LEVELS as $n => $t){{ $n }} {{ $t }}{{ $loop->last ? '' : '／' }}@endforeach</p>
<p class="muted">得意は{{ \App\Services\SkillTable::MAX_PRIMARY }}つまでです。スキルシートの頭に出ます。ひとことは、名前を伏せたスキルシートには出ません。</p>
<table class="edit" id="skills"><thead><tr>@if($pick)<th>合う</th>@endif<th>スキル</th><th>分類</th><th>年数（申告）</th><th>経歴から</th><th>レベル</th><th>最後に使った年月</th><th>得意</th><th>ひとこと</th><th></th></tr></thead>
<tbody>@foreach($skills as $i => $s){!! $row($s, $marks[$i] ?? null) !!}@endforeach{!! $row() !!}{!! $row() !!}{!! $row() !!}</tbody></table>
<datalist id="skillterms">@foreach($terms as $t)<option value="{{ $t->name }}">{{ lbl('skillCat', $t->category) }}</option>@endforeach</datalist>
<p class="muted">スキル名は、候補（技術マスタ）から選ぶと、案件とのマッチングが正確になります。候補に無いものも入れられます（管理者が分類を確かめます）。同じ技術は、保存すると1つにまとまります。</p>
<template id="skillrow">{!! $row() !!}</template>
<p><button type="button" class="btn ghost small" onclick="addRow('skills','skillrow')">行を足す</button></p>
<a class="btn ghost" href="{{ url('/engineers/'.$e->id) }}">戻る</a> <button class="btn">保存する</button></form></x-card>
@endsection
