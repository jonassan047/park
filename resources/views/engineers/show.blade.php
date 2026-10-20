@php $u = auth()->user(); $mine = $u->role === 'eg'; @endphp
@extends('layouts.app', ['title' => $mine ? 'プロフィール・経験票' : $e->name, 'wide' => true])
@section('content')
<div class="cols"><div>
<x-card title="基本情報">
<x-slot:actions>@if(!$mine && $u->role !== 'eg' && $e->status !== 'left')<x-post :action="'/engineers/'.$e->id.'/delete'" label="退会" cls="danger small" confirm="このエンジニアを退会済にします。ログインできなくなります（記録は残ります）。よろしいですか？" />@endif
<a class="btn small" href="{{ url('/engineers/'.$e->id.'/edit') }}">編集</a>
@if(!$mine && $e->email && !($eu && $eu->password))
<x-post :action="'/engineers/'.$e->id.'/invite'" label="招待を送る" cls="small" />
@endif
</x-slot:actions>
<dl class="dl">
<dt>氏名</dt><dd>{{ $e->name }} <span class="muted">{{ $e->kana }}（{{ $e->initials ?: '—' }}）</span></dd>
<dt>所属 SES</dt><dd>{{ $e->ses_name }}</dd><dt>状態</dt><dd>{{ badge('engStatus', $e->status) }}</dd>
<dt>年齢</dt><dd>{{ age_text($e->birth_year) ?: '—' }}</dd><dt>最寄り駅</dt><dd>{{ trim($e->nearest_line.' '.$e->nearest_station) ?: '—' }}{{ $e->walk_minutes ? '（徒歩'.$e->walk_minutes.'分）' : '' }}</dd>
<dt>経験年数</dt><dd>{{ $e->years_exp ? num_disp($e->years_exp).'年' : '—' }}</dd>
<dt>希望単価</dt><dd>{{ man($e->desired_price) }}</dd><dt>下限</dt><dd>{{ man($e->min_price) }}</dd>
<dt>稼働できる日</dt><dd>{{ fmt_date($e->available_from) }}</dd>
<dt>働き方</dt><dd>{{ lbl('workStyle', $e->work_style) }}・週{{ $e->days_per_week ?: 5 }}日</dd>
<dt>強み</dt><dd>{{ $e->strengths ?: '—' }}</dd><dt>自己PR</dt><dd>{!! nl2br(e($e->summary)) ?: '—' !!}</dd>
<dt>本人の確認</dt><dd>@if($e->self_confirmed_at)済み（{{ fmt_dt($e->self_confirmed_at) }}）@else<b>まだ</b>@if(!$mine)<span class="muted">（ご本人が確かめるまで、応募できません。管理者・SES が書き換えると、もう一度確認が必要になります）</span>@endif @endif</dd>
@unless($mine)<dt>アカウント</dt><dd>{{ $eu ? ($eu->password ? '使用中（最終ログイン '.fmt_dt($eu->last_login).'）' : '招待中（未設定）') : 'なし' }}</dd>@endunless

<dt>個人情報の同意</dt><dd>@if($e->consent_at){{ fmt_date($e->consent_at) }}@else<span class="bad-text">未</span>@endif</dd>
</dl>
@unless($mine)<p><a class="btn ghost" href="{{ list_url('engineers') }}">エンジニアの一覧に戻る</a></p>@endunless</x-card>

<x-card title="詳しいプロフィール（履歴書・業務経歴書の項目）"><x-slot:actions><a class="btn small" href="{{ url('/engineers/'.$e->id.'/profile') }}">編集</a></x-slot:actions>
<dl class="dl">
<dt>生年月日</dt><dd>{{ $e->birth_date ? fmt_date($e->birth_date) : '—' }}</dd><dt>性別</dt><dd>{{ $e->gender ?: '—' }}</dd>
<dt>電話</dt><dd>{{ $e->phone ?: '—' }}</dd><dt>現住所</dt><dd>{{ postal_address($e->postal_code, $e->address) }}</dd>
<dt>最終学歴</dt><dd>{{ $e->education ?: '—' }}{{ $e->graduated_ym ? '（'.$e->graduated_ym.' 卒業）' : '' }}</dd>
<dt>希望業務</dt><dd>{{ $e->desired_roles ?: '—' }}</dd><dt>語学</dt><dd>{{ $e->english_level ?: '—' }}</dd>
<dt>職務概要</dt><dd>{!! nl2br(e($e->career_summary)) ?: '—' !!}</dd>
<dt>学歴・職歴</dt><dd>@forelse($hist as $h)<div><span class="muted">{{ $h->ym ?: '' }}</span> {{ $h->kind === 'edu' ? '［学歴］' : '' }}{{ $h->text }}</div>@empty — @endforelse</dd>
<dt>資格・免許</dt><dd>@forelse($certs as $c)<div>{{ $c->name }} <span class="muted">{{ $c->ym }}</span></div>@empty — @endforelse</dd>
<dt>アピール</dt><dd>{!! nl2br(e($e->appeal)) ?: '—' !!}</dd><dt>本人希望</dt><dd>{!! nl2br(e($e->wishes)) ?: '—' !!}</dd>
</dl></x-card>

<x-card title="スキル"><x-slot:actions><a class="btn small" href="{{ url('/engineers/'.$e->id.'/skills') }}">スキルを編集</a></x-slot:actions>
@if($skills->count())<div class="skillgrid">@foreach($skills as $s)<div class="sk"><b>{{ $s->name }}</b><span>{{ lbl('skillCat', $s->category) }}</span><span>{{ $s->years ? num_disp($s->years).'年' : '' }}</span>
<span class="lv">{{ str_repeat('★', (int) $s->level) }}{{ str_repeat('☆', 5 - (int) $s->level) }}</span></div>@endforeach</div>
@else<p class="empty">まだ登録がありません。</p>@endif</x-card>

<x-card title="経験票（これまでの案件）"><x-slot:actions><a class="btn small" href="{{ url('/engineers/'.$e->id.'/projects/new') }}">案件を追加</a></x-slot:actions>
@forelse($projects as $p)<div class="proj">
<div class="projhead"><b>{{ $p->title }}</b><span class="muted">{{ $p->period_from ?: '?' }}〜{{ $p->period_to ?: '現在' }}・{{ $p->industry }}・{{ $p->role }}{{ $p->team_size ? '・'.$p->team_size.'名' : '' }}</span>
<a class="btn small" href="{{ url('/engineers/'.$e->id.'/projects/'.$p->id.'/edit') }}">編集</a></div>
<x-chips :list="project_techs($p)" /> <span class="muted small">{{ $p->phases }}</span><p>{!! nl2br(e($p->description)) !!}</p>
@if($p->achievements)<p class="ach">成果：{{ $p->achievements }}</p>@endif</div>
@empty<p class="empty">まだ登録がありません。直近の案件から入れていきましょう。</p>@endforelse
</x-card>
</div><div>
<section id="docs"></section>
@php $aiJobs = DB::table('ai_jobs')->where('kind', 'resume')->whereIn('status', ['queued', 'running', 'done', 'failed'])->where('input', 'like', '%"engineer_id":'.$e->id.'}%')->orderByDesc('id')->get(); $added = []; @endphp
@foreach($aiJobs as $ai)
<x-card :title="'AI の経験票の下書き（#'.$ai->id.'）'">
@if(in_array($ai->status, ['queued', 'running']))<p>{{ \App\Services\AiJobs::STATUS[$ai->status] }}です（{{ fmt_dt($ai->created_at) }} に頼みました）。手元の PC の AI が動いていると、数分でできます。</p>
@elseif($ai->status === 'failed')<p class="bad-text">失敗しました：{{ $ai->error }}</p><x-post :action="'/ai-jobs/'.$ai->id.'/drop'" label="閉じる" cls="ghost small" />
@else @php $done = json_decode((string) session('ai_added_'.$ai->id, '[]'), true); $ps = \App\Services\AiJobs::items($ai) ?? []; @endphp
<p>経歴書から取り出した案件です（{{ $ai->engine }}）。書かれていないことが足されていないか確かめて、合っているものだけ「経験票に足す」を押してください。足したあとも、経験票の画面で直せます。</p>
<x-table :head="['案件', '期間', '役割・技術', '']" :count="count($ps)" empty="案件を取り出せませんでした。">
@foreach($ps as $n => $p)<tr><td><b>{{ $p['title'] ?? '' }}</b><div class="muted small">{{ \Illuminate\Support\Str::limit($p['description'] ?? '', 80) }}</div></td><td>{{ $p['period_from'] ?? '' }}〜{{ $p['period_to'] ?? '' }}</td>
<td><small>{{ $p['role'] ?? '' }}<br>{{ $p['tech'] ?? '' }}</small></td><td>@if(in_array($n, $done))<span class="muted">足しました</span>@else<x-post :action="'/ai-jobs/'.$ai->id.'/project/'.$n" label="経験票に足す" cls="small" />@endif</td></tr>@endforeach
</x-table>
<x-post :action="'/ai-jobs/'.$ai->id.'/drop'" label="この下書きを閉じる" :fields="['done' => 1]" cls="ghost small" />
@endif
</x-card>
@endforeach
<x-card title="📁 データ領域（履歴書・業務経歴書）">
<p class="muted">登録した内容から、最新の履歴書・業務経歴書を Excel で書き出して保存します。書き出すたびに版が残り、いつでもダウンロードできます。手持ちの書類（PDF など）も置けます。見られるのは、本人・所属の SES・管理者だけです。</p>
<p><x-post :action="'/engineers/'.$e->id.'/docs/generate'" label="履歴書を書き出す" :fields="['kind' => 'resume']" cls="small" />
<x-post :action="'/engineers/'.$e->id.'/docs/generate'" label="業務経歴書を書き出す" :fields="['kind' => 'careersheet']" cls="small" />
<x-post :action="'/engineers/'.$e->id.'/docs/generate'" label="業務経歴書（名前を伏せた版）" :fields="['kind' => 'careersheet', 'anon' => 1]" cls="ghost small" /></p>
<x-table :head="['書類', '日時', '']" :count="$files->count()" empty="まだ書類はありません。">
@foreach($files as $f)<tr><td><a href="{{ url('/engineers/'.$e->id.'/docs/'.$f->id) }}">{{ $f->title }}</a><div class="muted small">{{ $f->filename }}（{{ max(1, round($f->size / 1024)) }}KB）</div></td>
<td>{{ fmt_dt($f->created_at) }}</td><td>@if(!$f->generated && in_array(strtolower(pathinfo($f->filename, PATHINFO_EXTENSION)), ['xlsx', 'xls', 'docx', 'txt', 'csv']))<x-post :action="'/engineers/'.$e->id.'/docs/'.$f->id.'/ai'" label="AI で経験票の下書き" cls="ghost small" /> @endif<x-post :action="'/engineers/'.$e->id.'/docs/'.$f->id.'/delete'" label="削除" cls="ghost small" confirm="この書類を削除しますか？" /></td></tr>@endforeach
</x-table>
<form method="post" action="{{ url('/engineers/'.$e->id.'/docs/upload') }}" enctype="multipart/form-data" class="upload">@csrf
<div class="grid2"><x-field label="書類をアップロード（10MB まで）" name="file" type="file" required />
<x-field label="種類" name="kind" type="select" :options="\App\Services\EngineerDocs::KINDS" value="other" /></div>
<x-field label="題名（任意）" name="title" placeholder="例：2026-10 版の履歴書（PDF）" /><button class="btn ghost small">アップロード</button></form>
</x-card>
<x-card title="スキルシートの完成度"><div class="meter"><i style="width:{{ $check['score'] }}%"></i><b>{{ $check['score'] }}%</b></div>
<ul class="tips">@foreach($check['tips'] as $t)<li>{{ $t }}</li>@endforeach</ul>
<p><a class="btn" href="{{ url('/engineers/'.$e->id.'/sheet') }}">スキルシートを見る</a> <a class="btn ghost" href="{{ url('/engineers/'.$e->id.'/sheet?anon=1') }}">名前を伏せた版</a></p>
@if($u->role !== 'ses')<p><a href="{{ url('/engineers/'.$e->id.'/messages') }}">管理者とのやり取り（スキルシートの相談）</a></p>@endif
</x-card>
<x-card title="応募・選考"><x-table :head="['案件', '状態', '']" :count="$apps->count()">
@foreach($apps as $a)<tr><td>{{ $a->title }}</td><td>{{ badge('appStatus', $a->status) }}</td><td><a class="btn small" href="{{ url('/applications/'.$a->id) }}">詳細</a></td></tr>@endforeach
</x-table></x-card>
</div></div>
@endsection
