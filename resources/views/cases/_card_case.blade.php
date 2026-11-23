{{-- 案件票の中身（読み取った項目・作業内容・その他の条件）。「案件票」のカードと、「メール本文」の下の折りたたみの両方で使う --}}
<dl class="dl">
<dt>ID</dt><dd><x-code :v="$c->code" /></dd>
<dt>状態</dt><dd>{{ badge('caseStatus', $c->status) }}</dd>
<dt>金額</dt><dd>{{ man($c->price) }} <span class="muted">{{ $c->price_note }}</span></dd>
@if($u->role !== 'eg')<dt>元請け</dt><dd>{{ $c->client_label ?: '—' }}</dd><dt>みなもと SES</dt><dd>{{ $c->ses_name ?: '—' }}</dd>@endif
<dt>必須</dt><dd><x-chips :list="skill_items($c->required_skills)" /></dd>
<dt>尚可</dt><dd><x-chips :list="skill_items($c->preferred_skills)" /></dd>
@php $expReq = \App\Support\CaseExp::split($c->required_exp ?? null); $expPref = \App\Support\CaseExp::split($c->preferred_exp ?? null); @endphp
@if($expReq)<dt>必須の業務</dt><dd><x-chips :list="$expReq" /></dd>@endif
@if($expPref)<dt>尚可の業務</dt><dd><x-chips :list="$expPref" /></dd>@endif
{{-- エンジニアに確認：マスタに無い条件。点数には使わない。全員に出す（元の必須・尚可の文は、直せる人だけ） --}}
@php $confirmItems = array_values(array_filter(array_map('trim', preg_split('/\R/u', (string) ($c->confirm_items ?? ''))))); @endphp
@if($confirmItems)<dt>{{ $u->role === 'eg' ? 'ご確認ください' : 'エンジニアに確認' }}</dt><dd><ul>@foreach($confirmItems as $it)<li>{{ $it }}</li>@endforeach</ul></dd>@endif
@if(\App\Support\Perms::caseEdit($u, $c) && ($c->required_lines || $c->preferred_lines))<dt>元の文</dt><dd><details><summary>元の必須・尚可の文</summary>
@if($c->required_lines)<p><strong>必須</strong><br>{!! nl2br(e($c->required_lines)) !!}</p>@endif
@if($c->preferred_lines)<p><strong>尚可</strong><br>{!! nl2br(e($c->preferred_lines)) !!}</p>@endif
</details></dd>@endif
<dt>経験年数</dt><dd>{{ $c->min_years ? num_disp($c->min_years).'年以上' : '—' }}</dd>
<dt>働き方</dt><dd>{{ lbl('workStyle', $c->work_style) }}</dd><dt>場所</dt><dd>{{ $c->location ?: '—' }}</dd>
<dt>開始</dt><dd>{{ fmt_date($c->start_date) }}</dd><dt>期間</dt><dd>{{ $c->period ?: '—' }}</dd>
<dt>稼働</dt><dd>週{{ $c->days_per_week ?: 5 }}日</dd><dt>面談</dt><dd>{{ $c->interviews_count ?? 1 }}回</dd>
<dt>募集人数</dt><dd>{{ $c->headcount ?: 1 }}名</dd><dt>締め切り</dt><dd>{{ fmt_date($c->deadline) }}</dd>
</dl>
<h3>作業内容</h3><p>{!! nl2br(e($c->description)) !!}</p>
@if($c->conditions)<h3>その他の条件</h3><p>{!! nl2br(e($c->conditions)) !!}</p>@endif
