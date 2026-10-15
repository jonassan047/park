@php $u = auth()->user(); $mine = $u->role === 'eg'; @endphp
@extends('layouts.app', ['title' => $mine ? 'プロフィール・経験票' : $e->name, 'wide' => true])
@section('content')
<div class="cols"><div>
<x-card title="基本情報">
<x-slot:actions><a class="btn ghost small" href="{{ url('/engineers/'.$e->id.'/edit') }}">編集</a>
@if(!$mine && $e->email && !($eu && $eu->password))
<x-post :action="'/engineers/'.$e->id.'/invite'" label="招待を送る" cls="ghost small" />
@endif
</x-slot:actions>
<dl class="dl">
<dt>氏名</dt><dd>{{ $e->name }} <span class="muted">{{ $e->kana }}（{{ $e->initials ?: '—' }}）</span></dd>
<dt>所属 SES</dt><dd>{{ $e->ses_name }}</dd><dt>状態</dt><dd>{{ badge('engStatus', $e->status) }}</dd>
<dt>年齢</dt><dd>{{ age_text($e->birth_year) ?: '—' }}</dd><dt>最寄り駅</dt><dd>{{ $e->nearest_station ?: '—' }}</dd>
<dt>経験年数</dt><dd>{{ $e->years_exp ? num_disp($e->years_exp).'年' : '—' }}</dd>
<dt>希望単価</dt><dd>{{ man($e->desired_price) }}</dd><dt>下限</dt><dd>{{ man($e->min_price) }}</dd>
<dt>稼働できる日</dt><dd>{{ fmt_date($e->available_from) }}</dd>
<dt>働き方</dt><dd>{{ lbl('workStyle', $e->work_style) }}・週{{ $e->days_per_week ?: 5 }}日</dd>
<dt>強み</dt><dd>{{ $e->strengths ?: '—' }}</dd><dt>自己PR</dt><dd>{!! nl2br(e($e->summary)) ?: '—' !!}</dd>
@unless($mine)<dt>アカウント</dt><dd>{{ $eu ? ($eu->password ? '利用中（最終ログイン '.fmt_dt($eu->last_login).'）' : '招待中（未設定）') : 'なし' }}</dd>@endunless
<dt>個人情報の同意</dt><dd>@if($e->consent_at){{ fmt_date($e->consent_at) }}@else<span class="bad-text">未</span>@endif</dd>
</dl></x-card>

<x-card title="スキル"><x-slot:actions><a class="btn ghost small" href="{{ url('/engineers/'.$e->id.'/skills') }}">スキルを編集</a></x-slot:actions>
@if($skills->count())<div class="skillgrid">@foreach($skills as $s)<div class="sk"><b>{{ $s->name }}</b><span>{{ lbl('skillCat', $s->category) }}</span><span>{{ $s->years ? num_disp($s->years).'年' : '' }}</span>
<span class="lv">{{ str_repeat('★', (int) $s->level) }}{{ str_repeat('☆', 5 - (int) $s->level) }}</span></div>@endforeach</div>
@else<p class="empty">まだ登録がありません。</p>@endif</x-card>

<x-card title="経験票（これまでの案件）"><x-slot:actions><a class="btn small" href="{{ url('/engineers/'.$e->id.'/projects/new') }}">案件を追加</a></x-slot:actions>
@forelse($projects as $p)<div class="proj">
<div class="projhead"><b>{{ $p->title }}</b><span class="muted">{{ $p->period_from ?: '?' }}〜{{ $p->period_to ?: '現在' }}・{{ $p->industry }}・{{ $p->role }}{{ $p->team_size ? '・'.$p->team_size.'名' : '' }}</span>
<a class="small" href="{{ url('/engineers/'.$e->id.'/projects/'.$p->id.'/edit') }}">編集</a></div>
<x-chips :list="parse_list($p->tech)" /> <span class="muted small">{{ $p->phases }}</span><p>{!! nl2br(e($p->description)) !!}</p>
@if($p->achievements)<p class="ach">成果：{{ $p->achievements }}</p>@endif</div>
@empty<p class="empty">まだ登録がありません。直近の案件から入れていきましょう。</p>@endforelse
</x-card>
</div><div>
<x-card title="スキルシートの完成度"><div class="meter"><i style="width:{{ $check['score'] }}%"></i><b>{{ $check['score'] }}%</b></div>
<ul class="tips">@foreach($check['tips'] as $t)<li>{{ $t }}</li>@endforeach</ul>
<p><a class="btn" href="{{ url('/engineers/'.$e->id.'/sheet') }}">スキルシートを見る</a> <a class="btn ghost" href="{{ url('/engineers/'.$e->id.'/sheet?anon=1') }}">名前を伏せた版</a></p>
@if($u->role !== 'ses')<p><a href="{{ url('/engineers/'.$e->id.'/messages') }}">運営とのやり取り（スキルシートの相談）</a></p>@endif
</x-card>
<x-card title="応募・選考"><x-table :head="['案件', '状態', '']" :count="$apps->count()">
@foreach($apps as $a)<tr><td>{{ $a->title }}</td><td>{{ badge('appStatus', $a->status) }}</td><td><a href="{{ url('/applications/'.$a->id) }}">詳細</a></td></tr>@endforeach
</x-table></x-card>
</div></div>
@endsection
