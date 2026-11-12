@php $u = auth()->user(); $e = $row['e']; $j = $row['j']; @endphp
@extends('layouts.app', ['title' => 'エンジニアの詳細：'.$row['name'], 'wide' => true])
@section('content')
<div class="cols"><div>
<x-card :title="$row['name']">
<x-slot:actions><a class="btn ghost small" href="{{ url('/searches/'.$s->id) }}">一覧に戻る</a></x-slot:actions>
<p>案件：<a href="{{ url('/cases/'.$c->id) }}">{{ $c->title }}</a></p>
<p class="muted">{{ lbl('engStatus', $e->status) }}・{{ $row['score'] }}点@if($row['own'] || $u->role === 'admin')　<a href="{{ url('/engineers/'.$e->id) }}">エンジニアの画面を開く</a>@endif</p>
@if($row['sent'] !== null)<p class="note">この案件の案内は送信済みです。</p>@endif
@foreach(['req' => '必須', 'pref' => '尚可'] as $side => $label)
<h3>{{ $label }}　{{ $side === 'req' ? $j['h'].'/'.$j['n'] : $j['k'].'/'.$j['m'] }}</h3>
<x-table :head="['条件', '', '年数']" :count="count($j[$side])" :empty="$label.'の条件はありません。'">
@foreach($j[$side] as $it)<tr>
<td>{{ $it['name'] }}@if($it['kind'] === 'exp') <span class="muted">（業務）</span>@endif</td>
<td>@if($it['hit'])<b>○</b>@else<b class="bad-text">×</b>@endif</td>
<td><small>{{ $it['hit'] ? $it['label'] : '' }}</small></td>
</tr>@endforeach
</x-table>
@endforeach
@if($note)<div class="note"><b>確認のメモ</b>（SES の方への注意書きです。メールには入りません）<br>{{ $note }}</div>@endif
</x-card>
</div><div>
<form method="post" action="{{ url('/searches/'.$s->id.'/members/'.$e->id) }}">@csrf
<x-card title="このエンジニアへのメッセージ">
<x-field label="個別メッセージ" name="message" type="textarea" rows="4" :value="$row['member']->message" hint="このエンジニアあてのメールにだけ入ります（空でもかまいません）。" />
<button class="btn">保存して一覧に戻る</button>
</x-card>
</form>
<x-card title="送るメールの見本">
<p class="muted">いまの保存内容で作ったものです（保存してから見ると、個別メッセージが入ります）。返事のリンクは、送るときに付きます。</p>
<p><b>{{ $mail['subject'] }}</b></p><pre class="mailbody">{{ $mail['body'] }}</pre>
</x-card>
</div></div>
@endsection
