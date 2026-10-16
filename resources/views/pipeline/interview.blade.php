@php $u = auth()->user(); $staff = in_array($u->role, ['admin', 'ses']); @endphp
@extends('layouts.app', ['title' => '面談 '.$iv->round.'回目：'.$a->title, 'wide' => true])
@section('content')
<div class="cols"><div>
<x-card title="面談"><dl class="dl">
<dt>案件</dt><dd><a href="{{ url('/cases/'.$a->case_id) }}">{{ $a->title }}</a></dd><dt>エンジニア</dt><dd>{{ $a->eg_name }}</dd>
<dt>日時</dt><dd>{{ fmt_dt($iv->scheduled_at) }}</dd><dt>場所</dt><dd>{{ $iv->place ?: '—' }}</dd>
@if($iv->request_note)<dt>依頼の内容</dt><dd>{!! nl2br(e($iv->request_note)) !!}</dd>@endif
<dt>結果</dt><dd>{{ badge('ivResult', $iv->result) }}</dd><dt>感想・フィードバック</dt><dd>{!! nl2br(e($iv->feedback)) ?: '—' !!}</dd>
</dl></x-card>
<x-card title="面談スクリプト（想定質問と答え方）">
<x-slot:actions>
@if($staff)
<x-post :action="'/interviews/'.$iv->id.'/regen'" label="作り直す" cls="ghost small" confirm="今の Q&A を消して作り直します。よろしいですか？" />
@endif
</x-slot:actions>
<p class="muted">想定質問と答え方のヒントです。丸暗記ではなく、自分の言葉で、事実だけを答えられるようにしておきましょう。</p>
<ol class="qa">@foreach($qa as $x)<li><span class="badge info">{{ $x['category'] }}</span><p class="q">Q. {{ $x['q'] }}</p><p class="hint">答え方のヒント：{{ $x['hint'] }}</p></li>@endforeach</ol>
</x-card>
@if($staff)
<x-card title="スクリプトを直す"><form method="post" action="{{ url('/interviews/'.$iv->id.'/qa') }}">@csrf
@foreach($qa as $x)<div class="qaedit"><input name="category[]" value="{{ $x['category'] }}"><input name="q[]" value="{{ $x['q'] }}"><textarea name="hint[]" rows="2">{{ $x['hint'] }}</textarea></div>@endforeach
<div class="qaedit"><input name="category[]" placeholder="分類（追加）"><input name="q[]" placeholder="質問（追加）"><textarea name="hint[]" rows="2" placeholder="答え方のヒント"></textarea></div>
<button class="btn">Q&A を保存</button></form></x-card>
@endif
</div><div>
@if($u->role === 'eg')
<x-card title="自分用のメモ（答えの練習）"><form method="post" action="{{ url('/interviews/'.$iv->id.'/memo') }}">@csrf<x-field name="eg_memo" type="textarea" rows="10" :value="$iv->eg_memo" /><button class="btn">保存</button></form></x-card>
@elseif($u->role === 'admin' && $iv->eg_memo)
<x-card title="本人のメモ"><p>{!! nl2br(e($iv->eg_memo)) !!}</p></x-card>
@endif
@if($staff && $iv->result === 'pending')
<x-card title="結果を記録"><form method="post" action="{{ url('/interviews/'.$iv->id.'/result') }}">@csrf
<x-field label="結果" name="result" type="select" :options="['pass' => '通過', 'fail' => '見送り']" value="pass" />
<x-field label="感想・元請けからの印象" name="feedback" type="textarea" rows="3" /><button class="btn">記録する</button></form></x-card>
@endif
<p><a href="{{ url('/applications/'.$a->id) }}">← 選考の画面へ</a></p>
</div></div>
@endsection
