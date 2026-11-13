@php $u = auth()->user(); @endphp
@extends('layouts.app', ['title' => $c->title, 'wide' => true])
@section('content')
<div class="cols {{ $u->role === 'eg' ? '' : 'wideside' }}"><div>
<x-card title="案件票">
<x-slot:actions>
@if(\App\Support\Perms::caseEdit($u, $c))<a class="btn small" href="{{ url('/cases/'.$c->id.'/edit') }}">編集</a>@endif
</x-slot:actions>
<dl class="dl">
<dt>ID</dt><dd><x-code :v="$c->code" /></dd>
<dt>状態</dt><dd>{{ badge('caseStatus', $c->status) }}</dd>
<dt>金額</dt><dd>{{ man($c->price) }} <span class="muted">{{ $c->price_note }}</span></dd>
@if($u->role !== 'eg')<dt>元請け</dt><dd>{{ $c->client_label ?: '—' }}</dd><dt>持ち込み</dt><dd>{{ $c->ses_name ?: '管理者' }}</dd>@endif
<dt>必須</dt><dd><x-chips :list="parse_list($c->required_skills)" /></dd>
<dt>尚可</dt><dd><x-chips :list="parse_list($c->preferred_skills)" /></dd>
@php $expReq = \App\Support\CaseExp::split($c->required_exp ?? null); $expPref = \App\Support\CaseExp::split($c->preferred_exp ?? null); @endphp
@if($expReq)<dt>必須の業務</dt><dd><x-chips :list="$expReq" /></dd>@endif
@if($expPref)<dt>尚可の業務</dt><dd><x-chips :list="$expPref" /></dd>@endif
<dt>経験年数</dt><dd>{{ $c->min_years ? num_disp($c->min_years).'年以上' : '—' }}</dd>
<dt>働き方</dt><dd>{{ lbl('workStyle', $c->work_style) }}</dd><dt>場所</dt><dd>{{ $c->location ?: '—' }}</dd>
<dt>開始</dt><dd>{{ fmt_date($c->start_date) }}</dd><dt>期間</dt><dd>{{ $c->period ?: '—' }}</dd>
<dt>稼働</dt><dd>週{{ $c->days_per_week ?: 5 }}日</dd><dt>面談</dt><dd>{{ $c->interviews_count ?? 1 }}回</dd>
<dt>募集人数</dt><dd>{{ $c->headcount ?: 1 }}名</dd><dt>締め切り</dt><dd>{{ fmt_date($c->deadline) }}</dd>
</dl>
<h3>作業内容</h3><p>{!! nl2br(e($c->description)) !!}</p>
@if($c->conditions)<h3>その他の条件</h3><p>{!! nl2br(e($c->conditions)) !!}</p>@endif
</x-card>
@isset($ai)
{{-- AI の読み直し（直せる人だけ。EG・ほかの会社の SES には、欄も AI の文も渡さない） --}}
@php $aj = $ai['job']; $cmp = $ai['cmp']; $reasonText = ['count' => '規則と行数が違います', 'missing' => '本文に無い言い方があります', 'edited' => '登録の前か後に人が直したか、本文を変えた案件です', 'empty' => 'AI が必須も尚可も読めませんでした', 'offered' => 'もう案内・応募があります', 'closed' => '募集を終えた案件です']; @endphp
<x-card title="AI の読み直し" id="ai">
@if(!$aj)
  @if($ai['block'])<p class="muted">{{ $ai['block'] }}</p>
  @else<p>手元の AI に、必須・尚可を読み直してもらえます。</p><x-post :action="'/cases/'.$c->id.'/ai-parse'" label="AI に読み直してもらう" />@endif
@elseif(in_array($aj->status, ['queued', 'running']))
  <p>手元の AI が必須・尚可を読み直しています（1件 1分ほどかかります。手元の PC が止まっていると進みません）。今は規則で読んだ結果です。待たずに案内してもかまいません（案内したあとは、AI の結果は自動では入れません）。</p>
@elseif($aj->status === 'applied')
  @if($ai['auto'])<p>AI で読み直し、規則と同じ数だったので AI の結果にしました（{{ fmt_dt($aj->done_at) }}）。@if($ai['fixed'])本文の文に戻した項目：{{ $ai['fixed'] }}件。@endif</p>
  @else<p>AI の結果を使いました（{{ fmt_dt($aj->done_at) }}）。</p>@endif
@elseif($aj->status === 'done' && $ai['stale'])
  <p>本文が変わりました。もう一度頼んでください。</p>
  @if(!$ai['block'])<x-post :action="'/cases/'.$c->id.'/ai-parse'" label="もう一度頼む" />@else<p class="muted">{{ $ai['block'] }}</p>@endif
@elseif($aj->status === 'done')
  <h3>AI の読み直しの結果を確かめてください</h3>
  <p>規則で読んだ結果とそのまま入れ替えず、確かめてもらうことにしました。理由：{{ implode('、', array_map(fn ($k) => $reasonText[$k] ?? $k, $cmp['reasons'])) ?: '—' }}。</p>
  <div class="tablewrap"><table><thead><tr><th></th><th>今</th><th>AI</th></tr></thead><tbody>
  @foreach([['必須', $ai['now']['req'], $cmp['req'], $ai['diff']['req']], ['尚可', $ai['now']['pref'], $cmp['pref'], $ai['diff']['pref']]] as [$name, $now, $aiRows, $df])
  <tr><th>{{ $name }}</th>
  <td>@forelse($now as $i => $t)<div>{{ $t }}@if($df['now'][$i]) <span class="badge warn">今だけ</span>@endif</div>@empty<span class="muted">—</span>@endforelse</td>
  <td>@forelse($aiRows as $i => $x)<div>{{ $x['text'] }}@if($x['mark'] === 'fixed') <span class="badge info" title="AI の文：{{ $x['from'] }}">本文の文に戻した</span>@elseif($x['mark'] === 'missing') <span class="badge bad">本文に無い言い方</span>@endif @if($df['ai'][$i]) <span class="badge warn">AI だけ</span>@endif</div>@empty<span class="muted">—</span>@endforelse</td></tr>
  @endforeach
  </tbody></table></div>
  <p class="muted">AI の結果を使うと、必須の業務：{{ $ai['exp']['req'] ? implode('、', $ai['exp']['req']) : '—' }}／尚可の業務：{{ $ai['exp']['pref'] ? implode('、', $ai['exp']['pref']) : '—' }}</p>
  @if(in_array('edited', $cmp['reasons']))<p class="bad-text">人が直したか、本文を変えた案件です。使うと、直した必須・尚可・業務も置き換わります。</p>@endif
  <x-post :action="'/ai-jobs/'.$aj->id.'/apply-case'" label="AI の結果を使う" /> <x-post :action="'/ai-jobs/'.$aj->id.'/drop'" label="今のままにする" cls="ghost" />
@else
  @if($aj->status === 'failed')<p>AI で読めませんでした（{{ $aj->error }}）。規則の結果のまま使えます。</p>
  @elseif($ai['same'])<p>AI も同じ読み方でした。</p>
  @else<p>前の AI の結果は使っていません。規則の結果のまま使えます。</p>@endif
  @if(!$ai['block'])<x-post :action="'/cases/'.$c->id.'/ai-parse'" label="もう一度頼む" />@else<p class="muted">{{ $ai['block'] }}</p>@endif
@endif
</x-card>
@endisset
@if($canMail)
<x-card title="案内メール">
<x-slot:actions>@if($c->status === 'open')<a class="btn small" href="{{ url('/cases/'.$c->id.'/search') }}">エンジニアに案内する</a>@endif</x-slot:actions>
<div id="mails"></div>
<x-table :head="['エンジニア', '送った日', '返事', '記録する', '案件をくれた先へ返す書類']" :count="$mails->count()" empty="まだ送っていません。「エンジニアに案内する」で、候補を絞ってメールを送れます。">
@foreach($mails as $m)<tr>
<td>@if($m->route === 'own'){{ $m->name }} <x-code :v="$m->eg_code" />@else{{ $m->initials ?: '—' }} <span class="muted">（{{ $m->ses_name }}・PARK から）</span>@endif<div class="muted">{{ $m->score }}点・{{ \App\Services\CaseMail::MODES[$m->mode] ?? '同じ内容' }}</div></td>
<td>{{ fmt_dt($m->sent_at) }}</td>
<td>{{ \App\Services\CaseMail::REPLIES[$m->reply] ?? $m->reply }}@if($m->reply_note)<div class="muted">{{ $m->reply_note }}</div>@endif @if($m->reply_via === 'app')<div class="muted">（本人が画面で）</div>@endif</td>
<td>@if($m->route === 'own')<form method="post" action="{{ url('/case-mails/'.$m->id.'/reply') }}" class="inline">@csrf
<select name="reply">@foreach(\App\Services\CaseMail::REPLIES as $k => $t)<option value="{{ $k }}" @selected($m->reply === $k)>{{ $t }}</option>@endforeach</select>
<input name="note" value="{{ $m->reply_note }}" placeholder="返信の要点"><button class="btn ghost small">記録</button></form>@else<span class="muted">本人が PARK の画面で返事します</span>@endif</td>
<td>@if(isset($mailApps[$m->engineer_id]))<a class="btn small" href="{{ url('/applications/'.$mailApps[$m->engineer_id]) }}">応募・面談の画面へ</a><br>@elseif($m->route === 'own' && $m->reply === 'interested')<x-post :action="'/case-mails/'.$m->id.'/submitted'" label="書類を返した（応募した）" cls="small" /><br>@endif
@if($m->route === 'own')<a href="{{ url('/case-mails/'.$m->id.'/doc/resume') }}">履歴書</a>・<a href="{{ url('/case-mails/'.$m->id.'/doc/careersheet') }}">業務経歴書</a>・<a href="{{ url('/case-mails/'.$m->id.'/doc/careersheet-anon') }}">業務経歴書（名前を伏せる）</a>
@elseif($m->reply === 'interested')<a href="{{ url('/case-mails/'.$m->id.'/doc/careersheet-anon') }}">業務経歴書（名前を伏せる）</a>@else<span class="muted">応募したいと返事が来たら出せます</span>@endif</td>
</tr>@endforeach
</x-table>
<p class="muted">書類は、押したときの最新の内容で Excel を作ってダウンロードします。メールに添付して、案件をくれた先へ返信してください。</p>
</x-card>
@endif
@if($u->role !== 'eg')
@if($caseThreads->count())
<x-card title="エンジニアとのやり取り" id="threads">
<x-table :head="array_values(array_filter(['エンジニア', $u->role === 'admin' ? '会社' : null, '最後の発言', '未読', ''], fn ($h) => $h !== null))" :count="$caseThreads->count()">
@foreach($caseThreads as $t)<tr><td>{{ $t->eg_name }} <x-code :v="$t->eg_code" /></td>@if($u->role === 'admin')<td>{{ $t->ses_name }}</td>@endif<td>{{ fmt_dt($t->last_post_at) }}</td>
<td>@if($u->role === 'admin' && $t->ses_kind !== 'direct')<span class="muted">—</span>@elseif($t->unread)<b class="bad-text">{{ $t->unread }}</b>@endif</td>
<td><a class="btn small" href="{{ url('/cases/'.$c->id.'/threads/'.$t->engineer_id) }}">開く</a></td></tr>@endforeach
</x-table>
</x-card>
@endif
<x-card title="この案件への応募"><x-table :head="['ID', 'エンジニア', 'SES', '状態', '応募日', '']" :count="$apps->count()">
@foreach($apps as $a)<tr><td><x-code :v="$a->code" /></td><td>{{ $a->eg_name }} <x-code :v="($u->role !== 'ses' || (int) $a->eg_ses_id === (int) $u->ses_id) ? $a->eg_code : null" /></td><td>{{ $a->ses_name }}</td><td>{{ badge('appStatus', $a->status) }}</td><td>{{ fmt_date($a->applied_at) }}</td><td><a class="btn small" href="{{ url('/applications/'.$a->id) }}">詳細</a>@if($u->role === 'admin' || (int) $a->eg_ses_id === (int) $u->ses_id) <a class="btn ghost small" href="{{ url('/cases/'.$c->id.'/threads/'.$a->engineer_id) }}">やり取り</a>@endif</td></tr>@endforeach
</x-table></x-card>
@endif
</div><div>

@if($u->role === 'admin')
  <x-card title="エンジニアに案内する">
  @if($c->status !== 'open')<p class="muted">募集中の案件だけ案内できます。</p>
  @else<p class="muted">グループ全体のエンジニアから、得意分野で候補を出し、メールで案内します（返事のリンク付き）。送った記録と返事は、左の「案内メール」に出ます。</p>
  <a class="btn" href="{{ url('/cases/'.$c->id.'/search') }}">エンジニアに案内する</a>@endif
  </x-card>
  <x-card title="配信済み"><x-table :head="['エンジニア', '点数', '状態', '配信日', '']" :count="$offers->count()">
  @foreach($offers as $o)<tr><td>{{ $o->name }} <x-code :v="$o->eg_code" /></td><td>{{ $o->score }}</td><td>{{ badge('offerStatus', $o->status) }}@if($o->decline_reason)<div class="muted">{{ $o->decline_reason }}</div>@endif</td><td>{{ fmt_dt($o->sent_at) }}</td>
  <td><a class="btn ghost small" href="{{ url('/cases/'.$c->id.'/threads/'.$o->engineer_id) }}">やり取り</a></td></tr>@endforeach
  </x-table></x-card>
  <x-card title="状態の変更">@foreach(['open', 'filled', 'closed'] as $s)@if($s !== $c->status)<x-post :action="'/cases/'.$c->id.'/status'" :label="lbl('caseStatus', $s).'にする'" :fields="['status' => $s]" cls="ghost small" /> @endif @endforeach</x-card>

@elseif($u->role === 'ses')
  <x-card title="貴社のエンジニアとの相性">
  @if($c->status !== 'open')<p class="muted">募集を終えた案件です。</p>
  @else
  @if($canMail)<p class="muted">案内は「エンジニアに案内する」から、メールで送ります（返事のリンク付き。送った記録と返事は、左の「案内メール」に出ます）。</p>
  <p><a class="btn" href="{{ url('/cases/'.$c->id.'/search') }}">エンジニアに案内する</a></p>
  @else<p class="muted">この案件は{{ $c->ses_name ? 'ほかの SES' : '管理者' }}が持ち込んだものです。合う方がいれば「推薦する」で、持ち込んだ{{ $c->ses_name ? ' SES' : '管理者' }}に知らせます（名前は伏せて伝えます）。</p>@endif
  <x-table :head="['エンジニア', '点数', '足りない点', '']" :count="count($rows)" empty="登録しているエンジニアがいません。">
  @foreach($rows as $row)<tr><td><a href="{{ url('/engineers/'.$row['e']->id) }}">{{ $row['e']->name }}</a> <x-code :v="$row['e']->code" /><div class="muted">{{ lbl('engStatus', $row['e']->status) }}</div></td>
    <td><x-score :n="$row['score']" /></td><td><small class="bad-text">{{ implode('、', $row['missing']) }}</small></td>
    <td>@if(in_array($row['e']->id, $applied))<span class="muted">応募済み</span>@elseif(isset($offered[$row['e']->id]))<span class="muted">案内済み</span>
    @elseif(!$canMail)<x-post :action="'/cases/'.$c->id.'/recommend'" label="推薦する" :fields="['engineer_id' => $row['e']->id]" cls="ghost small" />@endif
    @if(in_array($row['e']->id, $applied) || isset($offered[$row['e']->id]))<a class="btn ghost small" href="{{ url('/cases/'.$c->id.'/threads/'.$row['e']->id) }}">やり取り</a>@endif</td></tr>@endforeach
  </x-table>
  @endif
  </x-card>

@else
  <x-card title="あなたとの相性"><x-score :n="$fit['score']" /><p><b>合う点：</b>{{ implode('、', $fit['reasons']) ?: '—' }}</p>
  @if($fit['missing'])<p class="bad-text"><b>足りない点：</b>{{ implode('、', $fit['missing']) }}</p>@endif</x-card>
  @if($myApp)
    <x-card title="応募済み"><p>{{ badge('appStatus', $myApp->status) }}</p><p><a class="btn" href="{{ url('/applications/'.$myApp->id) }}">選考の状況を見る</a></p></x-card>
  @elseif($c->status !== 'open')
    <x-card><p class="muted">この案件は募集を終えました。</p></x-card>
  @elseif($offer && $offer->status === 'declined')
    <x-card title="見送りました"><p class="muted">{{ $offer->decline_reason }}</p></x-card>
  @else
    <x-card title="応募する"><form method="post" action="{{ url('/cases/'.$c->id.'/apply') }}">@csrf
    <p class="muted">面談で条件が合わずに落ちるのを防ぐため、応募の前に確かめてください。</p>
    <x-field :label="'開始（'.fmt_date($c->start_date).'）に間に合う'" name="start_ok" type="checkbox" />
    <x-field :label="'金額（'.man($c->price).'）で問題ない'" name="price_ok" type="checkbox" />
    <x-field :label="'働き方・場所（'.lbl('workStyle', $c->work_style).($c->location ? '／'.$c->location : '').'）で問題ない'" name="style_ok" type="checkbox" />
    <x-field label="気になること・伝えたいこと" name="condition_note" type="textarea" rows="2" />
    <button class="btn">この案件に応募する</button></form></x-card>
    @php $reasons = ['スキルが合わない', '金額が合わない', '時期が合わない', '場所・働き方が合わない', '内容に興味がない', 'その他']; @endphp
    <x-card title="見送る"><form method="post" action="{{ url('/cases/'.$c->id.'/decline') }}">@csrf
    <x-field label="理由（今後の案件選びに使います）" name="reason" type="select" :options="array_combine($reasons, $reasons)" />
    <button class="btn ghost">見送る</button></form></x-card>
  @endif
  {{-- 応募の前でも後でも、同じ1本が続く。どの状態でも出す（書けないときは理由を出す） --}}
  <x-card title="担当者とのやり取り" id="thread">
  <p class="muted">応募の前に、案件の内容で確かめたいことを担当者に聞けます。</p>
  @include('partials.case-thread', ['threads' => $threads, 'e' => $me, 'why' => $threadWhy, 'action' => '/cases/'.$c->id.'/threads/'.$u->engineer_id])
  </x-card>
@endif
</div></div>
<p class="backrow"><a class="btn ghost" href="{{ list_url('cases') }}">案件の一覧に戻る</a></p>
@endsection
