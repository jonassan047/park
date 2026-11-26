@php $u = auth()->user(); $mine = $rows->first(); @endphp
@extends('layouts.app', ['title' => '推薦文（社内用）：'.id_title($e->code, $e->name)])
@section('content')
<p class="muted">推薦文は、個人情報を含む社内用の文です。見られるのは、管理者と、このエンジニアが所属する SES の担当者だけです（ご本人・他社には見えません）。このPCの中で処理し、外部のサービスには送りません。</p>
@if($result)
<x-card title="スキルの抜き出しの結果">
<dl class="dl">
<dt>足したスキル</dt><dd>{{ $result['added'] ? implode('、', $result['added']) : 'なし' }}</dd>
<dt>更新したスキル</dt><dd>{{ !empty($result['updated']) ? implode('、', $result['updated']) : 'なし' }}<span class="muted small">（推薦文から入れたスキルの年数が変わったものです）</span></dd>
<dt>既にあったスキル</dt><dd>{{ $result['kept'] ? implode('、', $result['kept']) : 'なし' }}<span class="muted small">（画面や移行で入っているスキルは、年数も含めて変えていません）</span></dd>
<dt>外したスキル</dt><dd>{{ $result['removed'] ? implode('、', $result['removed']) : 'なし' }}</dd>
<dt>スキル表に置き場所が無いため入れなかった言葉</dt><dd>{{ $result['unplaced'] ? implode('、', $result['unplaced']) : 'なし' }}</dd>
<dt>読まなかった行</dt><dd>{{ (int) ($result['skippedLines'] ?? 0) }}行<span class="muted small">（氏名・年齢・希望月額・最寄駅・人物などの見出しの行とその続き）</span></dd>
</dl>
<p class="muted small">年数が取れなかったスキルは、年数なしで入っています。スキルの画面で、年数を選んでください。</p>
<p><a class="btn" href="{{ url('/engineers/'.$e->id.'/skills') }}">スキルを確かめる</a></p>
</x-card>
@endif
@if($canEdit)
<x-card :title="$mine ? '推薦文を更新する' : '推薦文を登録する'">
<form method="post" action="{{ url('/engineers/'.$e->id.'/intro') }}">@csrf
<x-field label="推薦文（10,000文字まで）" name="body" type="textarea" :rows="14" :value="$mine->body ?? ''" hint="書式はばらばらでかまいません。保存のたびに、本文からスキルを抜き出して、このエンジニアのスキルに入れます。氏名・年齢・希望月額・最寄駅などの行は読みません。" />
<button class="btn">保存してスキルを抜き出す</button></form>
@if($mine)<p><x-post :action="'/engineers/'.$e->id.'/intro/delete'" label="推薦文を消す" cls="ghost small" confirm="推薦文を消します。推薦文から入れて、まだ誰も確かめていないスキルも外れます。" /></p>@endif
</x-card>
@elseif($rows->isNotEmpty())
<x-card title="推薦文">
@foreach($rows as $ir)
@if($u->role === 'admin')<p class="muted small">{{ $ir->ses_name }}{{ !empty($ir->former) ? '（前の所属）' : '' }}　更新 {{ fmt_date($ir->updated_at) }} {{ $ir->updater_name }}</p>@endif
<pre class="mailbody">{{ $ir->body }}</pre>
@endforeach
@if($u->role === 'admin')<p class="muted small">管理者は読むだけです（登録・更新・消すのは、そのエンジニアが所属する SES の担当者です）。</p>@endif
</x-card>
@else
<p class="empty">推薦文はまだありません。</p>
@endif
<p><a class="btn ghost" href="{{ url('/engineers/'.$e->id.'#intro') }}">エンジニアの画面に戻る</a></p>
@endsection
