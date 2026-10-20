@extends('layouts.app', ['title' => 'スキルの編集（'.$e->name.'）'])
@section('content')
@php
    $row = function ($s = null) {
        $cat = $s->category ?? 'other'; $lv = (int) ($s->level ?? 3);
        $h = '<tr><td><input name="skill_name[]" list="skillterms" value="'.e($s->name ?? '').'" placeholder="Java"></td><td><select name="skill_cat[]">';
        foreach (PARK_LABELS['skillCat'] as $k => $v) $h .= '<option value="'.$k.'"'.($cat === $k ? ' selected' : '').'>'.e($v).'</option>';
        $h .= '</select></td><td><input name="skill_years[]" type="number" step="0.5" min="0" value="'.e(num_disp($s->years ?? null)).'"></td><td><select name="skill_level[]">';
        foreach ([1, 2, 3, 4, 5] as $n) $h .= '<option value="'.$n.'"'.($lv === $n ? ' selected' : '').'>'.$n.'</option>';
        return $h.'</select></td><td><button type="button" class="link" onclick="this.closest(\'tr\').remove()">削除</button></td></tr>';
    };
@endphp
<x-card><form method="post" action="{{ url('/engineers/'.$e->id.'/skills') }}">@csrf
<p class="muted">レベルの目安：1 触ったことがある／2 指示があればできる／3 1人でできる／4 人に教えられる／5 設計・判断をリードできる</p>
<table class="edit" id="skills"><thead><tr><th>スキル</th><th>分類</th><th>年数</th><th>レベル</th><th></th></tr></thead>
<tbody>@foreach($skills as $s){!! $row($s) !!}@endforeach{!! $row() !!}{!! $row() !!}{!! $row() !!}</tbody></table>
<datalist id="skillterms">@foreach($terms as $t)<option value="{{ $t->name }}">{{ lbl('skillCat', $t->category) }}</option>@endforeach</datalist>
<p class="muted">スキル名は、候補（技術マスタ）から選ぶと、案件とのマッチングが正確になります。候補に無いものも入れられます（管理者が分類を確かめます）。</p>
<template id="skillrow">{!! $row() !!}</template>
<p><button type="button" class="btn ghost small" onclick="addRow('skills','skillrow')">行を足す</button></p>
<a class="btn ghost" href="{{ url('/engineers/'.$e->id) }}">戻る</a> <button class="btn">保存する</button></form></x-card>
@endsection
