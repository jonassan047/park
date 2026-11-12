@extends('layouts.app', ['title' => 'エンジニア検索：'.$c->title, 'wide' => true])
@section('content')
<div class="cols wideside"><div>
<x-card title="元のメール">
@if(trim((string) $c->raw_text) !== '')<pre class="mailbody">{{ $c->raw_text }}</pre>
@else<p class="muted">元のメールはありません。</p>
<h3>作業内容</h3><p>{!! nl2br(e($c->description)) !!}</p>@endif
</x-card>
</div><div>
<form method="post" action="{{ url('/cases/'.$c->id.'/search') }}">@csrf
<p class="muted">メールから抜き出した条件です。いらない項目は「外す」にチェック、足したい項目は下の欄に書いてください。ここで変えても、案件そのものの必須・尚可は変わりません（この検索にだけ使います）。</p>
@foreach(['req' => '必須', 'pref' => '尚可'] as $side => $label)
<x-card :title="$label">
<x-table :head="['項目', '', '外す']" :count="count($cond[$side])" :empty="$label.'の項目はありません。'">
@foreach($cond[$side] as $it)<tr>
<td>{{ $it['name'] }}@if($it['kind'] === 'exp') <span class="muted">（業務）</span>@endif</td>
<td><small class="muted">{{ $it['src'] === 'added' ? '足した' : 'メールから' }}</small></td>
<td><input type="checkbox" name="off[{{ $side }}][]" value="{{ \App\Services\CaseSearch::key($it) }}" @checked(empty($it['on']))></td>
</tr>@endforeach
</x-table>
<x-field :label="$label.'に足す'" :name="'add_'.$side" type="textarea" rows="2" hint="「、」や改行で区切ると、いくつでも足せます。" />
</x-card>
@endforeach
<button class="btn">エンジニアを検索</button> <a class="btn ghost" href="{{ url('/cases/'.$c->id) }}">案件に戻る</a>
</form>
</div></div>
@endsection
