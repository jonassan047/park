@php $display = $anon ? ($e->initials ?: 'イニシャル未設定') : $e->name; @endphp
@extends('layouts.app', ['title' => 'スキルシート '.$display, 'print' => true, 'noTitle' => true])
@section('content')
<div class="noprint toolbar"><a class="btn ghost small" href="{{ url('/engineers/'.$e->id) }}">戻る</a>
<form method="get" class="inline">@if($c)<input type="hidden" name="case" value="{{ $c->id }}">@endif<label><input type="checkbox" name="anon" value="1" @checked($anon) onchange="this.form.submit()"> 名前を伏せる</label></form>
<button class="btn small" onclick="print()">印刷・PDF に保存</button></div>
<article class="sheet">
<header><h1>スキルシート</h1><div class="muted">作成日 {{ today_str() }}@if($c)　／　ご提案：{{ $c->title }}@endif</div></header>
<table class="sheetinfo"><tr><th>氏名</th><td>{{ $display }}</td><th>年齢</th><td>{{ age_text($e->birth_year) }}</td><th>最寄り駅</th><td>{{ $e->nearest_station }}</td></tr>
<tr><th>経験年数</th><td>{{ $e->years_exp ? num_disp($e->years_exp).'年' : '' }}</td><th>稼働開始</th><td>{{ fmt_date($e->available_from) }}</td><th>働き方</th><td>{{ lbl('workStyle', $e->work_style) }}</td></tr></table>
@if($e->strengths)<p class="lead">{{ $e->strengths }}</p>@endif
<h2>自己PR</h2><p>{!! nl2br(e($e->summary)) !!}</p>
@if($c)<h2>案件の必須要件との対応</h2><table class="grid">@foreach($reqRows as [$k, $v])<tr><th>{{ $k }}</th><td>{{ $v }}</td></tr>@endforeach</table>@endif
<h2>スキル</h2>
@php
    // 項目の言葉のどれかが案件の要件に当たれば印（hit）。名前は「PHP（5年以上）」の形
    $hit = fn ($x) => collect($x['words'])->contains(fn ($w) => $isHit($w));
    $yt = fn ($x) => $x['name'].($x['years'] ? '（'.$x['years'].'）' : '');
@endphp
@if($sections)
<table class="grid">@foreach($sections as $s)<tr><th>{{ $s['title'] }}</th><td>@foreach($s['entries'] as $en)@if(isset($en['children']))<span class="sk2">@if($en['checked'])<span class="{{ $hit($en) ? 'hit' : '' }}">{{ $yt($en) }}</span>@else<b>{{ $en['name'] }}</b>@endif @if($en['children'])：@foreach($en['children'] as $ch)<span class="{{ $hit($ch) ? 'hit' : '' }}">{{ $yt($ch) }}</span>{{ $loop->last ? '' : '、' }}@endforeach @endif</span>@else<span class="sk2 {{ $hit($en) ? 'hit' : '' }}">{{ $yt($en) }}</span>@endif @endforeach</td></tr>@endforeach</table>
@else<p class="muted">まだ登録がありません。</p>@endif
<h2>経歴</h2>
@foreach($projects as $i => $p)<section class="sp"><div class="sph"><b>{{ $i + 1 }}. {{ $p->title }}</b><span>{{ $p->period_from ?: '?' }}〜{{ $p->period_to ?: '現在' }}</span></div>
<div class="spm">{{ implode('／', array_filter([$p->industry, $p->role, $p->team_size ? $p->team_size.'名体制' : ''])) }}　@if($p->phases)<span class="muted">工程：{{ $p->phases }}</span>@endif</div>
<div>@foreach(project_techs($p) as $t)<span class="chip {{ $isHit($t) ? 'hit' : '' }}">{{ $t }}</span>@endforeach</div>
<p>{!! nl2br(e($p->description)) !!}</p>@if($p->achievements)<p class="ach">成果：{{ $p->achievements }}</p>@endif</section>@endforeach
<footer class="muted">{{ setting('app_name') }}　本シートの内容は本人が事実であることを確認しています。</footer>
</article>
@endsection
