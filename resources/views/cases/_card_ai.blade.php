@isset($ai)
{{-- AI の読み直し（直せる人だけ。エンジニア・ほかの会社の SES には、欄も AI の文も渡さない） --}}
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
  <p class="muted">AI の結果を使うと、必須の業務：{{ $ai['exp']['req'] ? implode('、', $ai['exp']['req']) : '—' }}／尚可の業務：{{ $ai['exp']['pref'] ? implode('、', $ai['exp']['pref']) : '—' }}／エンジニアに確認：{{ $ai['confirm'] ? implode('、', $ai['confirm']) : '—' }}</p>
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
