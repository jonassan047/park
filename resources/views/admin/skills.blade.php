@extends('layouts.app', ['title' => '技術マスタ', 'wide' => true])
@section('content')
<p class="muted">案件メールの技術とエンジニアのスキルを、同じ「正式な名前」にそろえて突き合わせるための辞書です。別名（例：Postgres → PostgreSQL）も一致として扱います。
案件やスキルにマスタに無い言葉が出てくると、自動で追加され「確認待ち」になります。分類と別名を確かめて「確認済みにする」を押すか、同じ技術の言い換えなら「統合」で正式な技術の別名にまとめてください。</p>
@if($unreviewed)<div class="flash warn">確認待ちの技術が {{ $unreviewed }} 件あります（一覧の上に出ています）。</div>@endif
<form method="get" class="filters"><x-field name="q" :value="$q" placeholder="名前・別名で探す" />
<x-field name="cat" type="select" :options="PARK_LABELS['skillCat']" blank="すべての分類" :value="$cat" /><button class="btn ghost small">絞り込む</button></form>
<x-card title="技術を追加"><form method="post" action="{{ url('/admin/skills') }}"><div class="grid3">
<x-field label="正式な名前" name="name" required /><x-field label="分類" name="category" type="select" :options="PARK_LABELS['skillCat']" value="other" />
<x-field label="別名（「、」区切り）" name="aliases" /></div>@csrf<button class="btn small">追加する</button></form></x-card>
<x-table :head="['状態', '正式な名前・分類・別名', '使っている人', '統合']" :count="$list->count()">
@foreach($list as $t)<tr>
<td>@if($t->reviewed)<span class="badge ok">確認済み</span>@else<span class="badge warn">確認待ち</span>@endif<div class="muted small">{{ ['master' => '最初から', 'auto' => '自動で追加', 'user' => '手で追加'][$t->source] ?? $t->source }}</div></td>
<td><form method="post" action="{{ url('/admin/skills/'.$t->id) }}" class="skillrow">@csrf
<input name="name" value="{{ $t->name }}"><select name="category">@foreach(PARK_LABELS['skillCat'] as $k => $v)<option value="{{ $k }}" @selected($t->category === $k)>{{ $v }}</option>@endforeach</select>
<input name="aliases" value="{{ $t->aliases }}" placeholder="別名"><button class="btn ghost small">{{ $t->reviewed ? '保存' : '確認済みにする' }}</button></form></td>
<td>{{ $t->used }}人</td>
<td><form method="post" action="{{ url('/admin/skills/'.$t->id.'/merge') }}" class="skillrow" onsubmit="return confirm('この言葉を、選んだ技術の別名にまとめます。よろしいですか？')">@csrf
<select name="to"><option value="">まとめ先</option>@foreach($all as $id => $n)@if($id !== $t->id)<option value="{{ $id }}">{{ $n }}</option>@endif @endforeach</select><button class="btn ghost small">統合</button></form></td>
</tr>@endforeach
</x-table>
@endsection
