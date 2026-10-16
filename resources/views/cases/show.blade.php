@php $u = auth()->user(); @endphp
@extends('layouts.app', ['title' => $c->title, 'wide' => true])
@section('content')
<div class="cols {{ $u->role === 'eg' ? '' : 'wideside' }}"><div>
<x-card title="案件票">
<x-slot:actions>
@if(\App\Support\Perms::caseEdit($u, $c))<a class="btn ghost small" href="{{ url('/cases/'.$c->id.'/edit') }}">編集</a>@endif
</x-slot:actions>
<dl class="dl">
<dt>状態</dt><dd>{{ badge('caseStatus', $c->status) }}</dd>
<dt>金額</dt><dd>{{ man($c->price) }} <span class="muted">{{ $c->price_note }}</span></dd>
@if($u->role !== 'eg')<dt>元請け</dt><dd>{{ $c->client_label ?: '—' }}</dd><dt>持ち込み</dt><dd>{{ $c->ses_name ?: '運営' }}</dd>@endif
<dt>必須</dt><dd><x-chips :list="parse_list($c->required_skills)" /></dd>
<dt>尚可</dt><dd><x-chips :list="parse_list($c->preferred_skills)" /></dd>
<dt>経験年数</dt><dd>{{ $c->min_years ? num_disp($c->min_years).'年以上' : '—' }}</dd>
<dt>働き方</dt><dd>{{ lbl('workStyle', $c->work_style) }}</dd><dt>場所</dt><dd>{{ $c->location ?: '—' }}</dd>
<dt>開始</dt><dd>{{ fmt_date($c->start_date) }}</dd><dt>期間</dt><dd>{{ $c->period ?: '—' }}</dd>
<dt>稼働</dt><dd>週{{ $c->days_per_week ?: 5 }}日</dd><dt>面談</dt><dd>{{ $c->interviews_count ?? 1 }}回</dd>
<dt>募集人数</dt><dd>{{ $c->headcount ?: 1 }}名</dd><dt>締め切り</dt><dd>{{ fmt_date($c->deadline) }}</dd>
</dl>
<h3>作業内容</h3><p>{!! nl2br(e($c->description)) !!}</p>
@if($c->conditions)<h3>その他の条件</h3><p>{!! nl2br(e($c->conditions)) !!}</p>@endif
</x-card>
@if($canMail)
<x-card title="案内メール">
<x-slot:actions>@if($c->status === 'open')<a class="btn small" href="{{ url('/cases/'.$c->id.'/mail') }}">エンジニアに案内する</a>@endif</x-slot:actions>
<div id="mails"></div>
<x-table :head="['エンジニア', '送った日', '返事', '記録する', '案件をくれた先へ返す書類']" :count="$mails->count()" empty="まだ送っていません。「エンジニアに案内する」で、候補を絞ってメールを送れます。">
@foreach($mails as $m)<tr>
<td>@if($m->route === 'own'){{ $m->name }}@else{{ $m->initials ?: '—' }} <span class="muted">（{{ $m->ses_name }}・PARK から）</span>@endif<div class="muted">{{ $m->score }}点・{{ $m->mode === 'each' ? '個別' : '同じ内容' }}</div></td>
<td>{{ fmt_dt($m->sent_at) }}</td>
<td>{{ \App\Services\CaseMail::REPLIES[$m->reply] ?? $m->reply }}@if($m->reply_note)<div class="muted">{{ $m->reply_note }}</div>@endif @if($m->reply_via === 'app')<div class="muted">（本人が画面で）</div>@endif</td>
<td>@if($m->route === 'own')<form method="post" action="{{ url('/case-mails/'.$m->id.'/reply') }}" class="inline">@csrf
<select name="reply">@foreach(\App\Services\CaseMail::REPLIES as $k => $t)<option value="{{ $k }}" @selected($m->reply === $k)>{{ $t }}</option>@endforeach</select>
<input name="note" value="{{ $m->reply_note }}" placeholder="返信の要点"><button class="btn ghost small">記録</button></form>@else<span class="muted">本人が PARK の画面で返事します</span>@endif</td>
<td>@if($m->route === 'own')<a href="{{ url('/case-mails/'.$m->id.'/doc/resume') }}">履歴書</a>・<a href="{{ url('/case-mails/'.$m->id.'/doc/careersheet') }}">業務経歴書</a>・<a href="{{ url('/case-mails/'.$m->id.'/doc/careersheet-anon') }}">業務経歴書（名前を伏せる）</a>
@elseif($m->reply === 'interested')<a href="{{ url('/case-mails/'.$m->id.'/doc/careersheet-anon') }}">業務経歴書（名前を伏せる）</a>@else<span class="muted">応募したいと返事が来たら出せます</span>@endif</td>
</tr>@endforeach
</x-table>
<p class="muted">書類は、押したときの最新の内容で Excel を作ってダウンロードします。メールに添付して、案件をくれた先へ返信してください。</p>
</x-card>
@endif
@if($u->role !== 'eg')
<x-card title="この案件への応募"><x-table :head="['エンジニア', 'SES', '状態', '応募日', '']" :count="$apps->count()">
@foreach($apps as $a)<tr><td>{{ $a->eg_name }}</td><td>{{ $a->ses_name }}</td><td>{{ badge('appStatus', $a->status) }}</td><td>{{ fmt_date($a->applied_at) }}</td><td><a href="{{ url('/applications/'.$a->id) }}">詳細</a></td></tr>@endforeach
</x-table></x-card>
@endif
</div><div>

@if($u->role === 'admin')
  <x-card title="マッチング（配信の候補）">
  @if($c->status !== 'open')<p class="muted">募集中の案件だけ配信できます。</p>
  @else
  <form method="post" action="{{ url('/cases/'.$c->id.'/distribute') }}">@csrf
  <p class="muted">条件に合う順に並べています（{{ $th }}点以上。@if($showAll)<a href="?">候補だけにする</a>@else<a href="?all=1">全員を表示</a>@endif／<a href="?busy=1&all=1">稼働中の人も含める</a>）。最終の判断は人がします。合わない人に送らないことも、成約率を上げます。</p>
  <x-table :head="['', 'エンジニア', '点数', '合う点', '足りない点', '希望']" :count="count($cands)" empty="条件に合うエンジニアがいません。「全員を表示」で確かめてください。">
  @foreach($cands as $m)<tr>
    <td>@if($m['offered']){{ badge('offerStatus', $m['offered']) }}@else<input type="checkbox" name="engineer_id[]" value="{{ $m['engineer']->id }}" @checked($m['score'] >= $th + 20)>@endif</td>
    <td><a href="{{ url('/engineers/'.$m['engineer']->id) }}">{{ $m['engineer']->name }}</a>@if($m['engineer']->is_operator) <span class="badge warn">運営者</span>@endif<div class="muted">{{ $m['engineer']->ses_name }}・{{ lbl('engStatus', $m['engineer']->status) }}</div></td>
    <td><x-score :n="$m['score']" /></td><td><small>{{ implode('、', $m['reasons']) }}</small></td><td><small class="bad-text">{{ implode('、', $m['missing']) }}</small></td>
    <td><small>{{ man($m['engineer']->desired_price) }}<br>{{ fmt_date($m['engineer']->available_from) }}〜</small></td></tr>@endforeach
  </x-table>
  <x-field label="エンジニアへのひとこと（任意）" name="message" type="textarea" rows="2" />
  <button class="btn">選んだ人に配信する（メール）</button></form>
  @endif
  </x-card>
  <x-card title="配信済み"><x-table :head="['エンジニア', '点数', '状態', '配信日']" :count="$offers->count()">
  @foreach($offers as $o)<tr><td>{{ $o->name }}</td><td>{{ $o->score }}</td><td>{{ badge('offerStatus', $o->status) }}@if($o->decline_reason)<div class="muted">{{ $o->decline_reason }}</div>@endif</td><td>{{ fmt_dt($o->sent_at) }}</td></tr>@endforeach
  </x-table></x-card>
  <x-card title="状態の変更">@foreach(['open', 'filled', 'closed'] as $s)@if($s !== $c->status)<x-post :action="'/cases/'.$c->id.'/status'" :label="lbl('caseStatus', $s).'にする'" :fields="['status' => $s]" cls="ghost small" /> @endif @endforeach</x-card>

@elseif($u->role === 'ses')
  <x-card title="貴社のエンジニアとの相性">
  @if($c->status !== 'open')<p class="muted">募集を終えた案件です。</p>
  @else
  <p class="muted">貴社から個別にご案内いただくこともできます。応募を出すときは、ご本人に条件を確かめてからにしてください。</p>
  <x-table :head="['エンジニア', '点数', '足りない点', '']" :count="count($rows)" empty="登録しているエンジニアがいません。">
  @foreach($rows as $row)<tr><td><a href="{{ url('/engineers/'.$row['e']->id) }}">{{ $row['e']->name }}</a><div class="muted">{{ lbl('engStatus', $row['e']->status) }}</div></td>
    <td><x-score :n="$row['score']" /></td><td><small class="bad-text">{{ implode('、', $row['missing']) }}</small></td>
    <td>@if(in_array($row['e']->id, $applied))<span class="muted">応募済み</span>@else
      @if(isset($offered[$row['e']->id]))<span class="muted">配信済み</span>@else<x-post :action="'/cases/'.$c->id.'/distribute'" label="本人に案内" :fields="['engineer_id[]' => $row['e']->id]" cls="ghost small" />@endif
      <x-post :action="'/cases/'.$c->id.'/apply'" label="応募を出す" :fields="['engineer_id' => $row['e']->id, 'start_ok' => 1, 'price_ok' => 1, 'style_ok' => 1, 'condition_note' => '所属 SES が本人に確認のうえ応募']" cls="small" :confirm="$row['e']->name.' さんの応募を出します。ご本人に条件を確かめましたか？'" />
    @endif</td></tr>@endforeach
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
@endif
</div></div>
@endsection
