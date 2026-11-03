@extends('layouts.app', ['title' => 'スキルの編集（'.$e->name.'）'])
@section('content')
@php
    $isEg = auth()->user()->role === 'eg';
    // 入力の誤りで戻ったときは、送った値で行を作り直す
    if (is_array(old('skill_name'))) {
        $skills = collect(old('skill_name'))->map(fn ($n, $i) => (object) ['name' => $n, 'category' => old('skill_cat.'.$i), 'years' => old('skill_years.'.$i), 'level' => old('skill_level.'.$i),
            'last_used_ym' => old('skill_last.'.$i), 'is_primary' => old('skill_primary.'.$i) === '1', 'note' => old('skill_note.'.$i)])->filter(fn ($s) => trim((string) $s->name) !== '')->values();
    }
    $row = function ($s = null) use ($isEg) {
        $cat = $s->category ?? 'other'; $lv = (int) ($s->level ?? 3);
        $h = '<tr><td><input name="skill_name[]" list="skillterms" value="'.e($s->name ?? '').'" placeholder="Java"></td><td><select name="skill_cat[]">';
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
<x-card><form method="post" action="{{ url('/engineers/'.$e->id.'/skills') }}">@csrf
<p class="muted">レベルの目安：@foreach(\App\Services\SkillTable::LEVELS as $n => $t){{ $n }} {{ $t }}{{ $loop->last ? '' : '／' }}@endforeach</p>
<p class="muted">得意は{{ \App\Services\SkillTable::MAX_PRIMARY }}つまでです。スキルシートの頭に出ます。ひとことは、名前を伏せたスキルシートには出ません。</p>
<table class="edit" id="skills"><thead><tr><th>スキル</th><th>分類</th><th>年数（申告）</th><th>経歴から</th><th>レベル</th><th>最後に使った年月</th><th>得意</th><th>ひとこと</th><th></th></tr></thead>
<tbody>@foreach($skills as $s){!! $row($s) !!}@endforeach{!! $row() !!}{!! $row() !!}{!! $row() !!}</tbody></table>
<datalist id="skillterms">@foreach($terms as $t)<option value="{{ $t->name }}">{{ lbl('skillCat', $t->category) }}</option>@endforeach</datalist>
<p class="muted">スキル名は、候補（技術マスタ）から選ぶと、案件とのマッチングが正確になります。候補に無いものも入れられます（管理者が分類を確かめます）。同じ技術は、保存すると1つにまとまります。</p>
<template id="skillrow">{!! $row() !!}</template>
<p><button type="button" class="btn ghost small" onclick="addRow('skills','skillrow')">行を足す</button></p>
<a class="btn ghost" href="{{ url('/engineers/'.$e->id) }}">戻る</a> <button class="btn">保存する</button></form></x-card>
@endsection
