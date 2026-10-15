@php $display = $anon ? ($e->initials ?: 'イニシャル未設定') : $e->name; @endphp
@extends('layouts.app', ['title' => 'スキルシート '.$display, 'print' => true, 'noTitle' => true])
@section('content')
<div class="noprint toolbar"><a href="{{ url('/engineers/'.$e->id) }}">← 戻る</a>
<form method="get" class="inline">@if($c)<input type="hidden" name="case" value="{{ $c->id }}">@endif<label><input type="checkbox" name="anon" value="1" @checked($anon) onchange="this.form.submit()"> 名前を伏せる</label></form>
<button class="btn small" onclick="print()">印刷・PDF に保存</button></div>
<article class="sheet">
<header><h1>スキルシート</h1><div class="muted">作成日 {{ today_str() }}@if($c)　／　ご提案：{{ $c->title }}@endif</div></header>
<table class="sheetinfo"><tr><th>氏名</th><td>{{ $display }}</td><th>年齢</th><td>{{ age_text($e->birth_year) }}</td><th>最寄り駅</th><td>{{ $e->nearest_station }}</td></tr>
<tr><th>経験年数</th><td>{{ $e->years_exp ? num_disp($e->years_exp).'年' : '' }}</td><th>稼働開始</th><td>{{ fmt_date($e->available_from) }}</td><th>働き方</th><td>{{ lbl('workStyle', $e->work_style) }}</td></tr></table>
@if($e->strengths)<p class="lead">{{ $e->strengths }}</p>@endif
<h2>自己PR</h2><p>{!! nl2br(e($e->summary)) !!}</p>
@if($c)<h2>案件の必須要件との対応</h2><table class="grid">@foreach($reqRows as [$k, $v])<tr><th>{{ $k }}</th><td>{{ $v }}</td></tr>@endforeach</table>@endif
<h2>スキル</h2><table class="grid">@foreach($byCat as $cat => $list)<tr><th>{{ lbl('skillCat', $cat) }}</th><td>@foreach($list as $s)<span class="sk2 {{ $isHit($s->name) ? 'hit' : '' }}">{{ $s->name }}{{ $s->years ? '（'.num_disp($s->years).'年）' : '' }}</span>@endforeach</td></tr>@endforeach</table>
<h2>経歴</h2>
@foreach($projects as $i => $p)<section class="sp"><div class="sph"><b>{{ $i + 1 }}. {{ $p->title }}</b><span>{{ $p->period_from ?: '?' }}〜{{ $p->period_to ?: '現在' }}</span></div>
<div class="spm">{{ implode('／', array_filter([$p->industry, $p->role, $p->team_size ? $p->team_size.'名体制' : ''])) }}　@if($p->phases)<span class="muted">工程：{{ $p->phases }}</span>@endif</div>
<div>@foreach(parse_list($p->tech) as $t)<span class="chip {{ $isHit($t) ? 'hit' : '' }}">{{ $t }}</span>@endforeach</div>
<p>{!! nl2br(e($p->description)) !!}</p>@if($p->achievements)<p class="ach">成果：{{ $p->achievements }}</p>@endif</section>@endforeach
<footer class="muted">{{ setting('app_name') }}　本シートの内容は本人が事実であることを確認しています。</footer>
</article>
@endsection
