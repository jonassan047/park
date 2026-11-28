@extends('layouts.app', ['title' => 'エンジニアを選択：'.id_title($c->code, $c->title), 'wide' => true])
@section('content')
@unless($canSend)<p class="note">いまはメールを「保存のみ」にしています。送った記録は残りますが、実際には届きません。</p>@endunless
@php
// マッチした点・マッチしなかった点：必須・尚可ごとに、当たった項目・当たらなかった項目を並べる（業務は「（業務）」を添える）
$jlist = function (array $items, bool $hit) {
    $out = [];
    foreach ($items as $it) if ((bool) $it['hit'] === $hit) $out[] = $it['name'].($it['kind'] === 'exp' ? '（業務）' : '');
    return $out;
};
$u = auth()->user();
$head = ['', 'エンジニア', '年齢', '価格', '最寄り駅', '点数', 'マッチした点', 'マッチしなかった点', '件数'];
@endphp
@if($batchN > 0)
<p class="note">まだ送っていない担当者が {{ $batchN }} 名います。 <a class="btn small" href="{{ url('/searches/'.$s->id.'/compose') }}">続きを作成する</a></p>
@endif
<form method="post" action="{{ url('/searches/'.$s->id) }}">@csrf
<x-card title="エンジニアを選択">
<x-slot:actions><a class="btn ghost small" href="{{ url('/cases/'.$c->id.'#keywords') }}">キーワードを変える</a> <a class="btn ghost small" href="{{ url('/cases/'.$c->id) }}">案件に戻る</a></x-slot:actions>
<p><b>{{ $c->code }}　{{ $c->title }}</b><br><span class="muted">{{ $partnerSide ? '伝えられた単価' : '金額' }}：{{ $price }}　開始：{{ $c->start_date ? date('Y年n月', strtotime($c->start_date)) : ($c->period ?: '相談') }}@if($c->work_style !== 'remote')　最寄り駅：{{ $c->location ?: '—' }}@else　フルリモート@endif</span></p>
<p><label class="check"><input type="checkbox" id="pickall"> すべてを選択</label></p>

<h3>プロパー（自社のエンジニア）</h3>
<x-table :head="$head" :count="count($proper)" empty="条件に合う自社のエンジニアがいません。キーワードを外すか、下の「エンジニア・担当者を追加」で足してください。">
@foreach($proper as $row)@php $e = $row['e']; $j = $row['j']; @endphp<tr>
<td>@if($row['sent'] !== null)<span class="muted">送信済み</span>@else<input type="checkbox" name="pick[]" value="{{ $e->id }}" @checked($row['member']->checked)>@endif</td>
<td><button class="linkbtn" name="go" value="{{ $e->id }}" style="background:none;border:0;padding:0;color:var(--blue);cursor:pointer;text-decoration:underline;font:inherit">{{ $row['name'] }}</button>
<x-code :v="$e->code" />
@if($row['member']->by_hand)<span class="badge info">手で足した</span>@endif
@if(!$row['pass'])<div><small class="muted">条件に届いていません@if($row['over'])（単価が案件＋{{ \App\Services\CaseSearch::PRICE_OVER }}万円以上）@endif</small></div>@endif</td>
<td>{{ $e->birth_year ? (date('Y') - $e->birth_year).'歳' : '—' }}</td><td>{{ man($e->desired_price) }}</td><td>{{ $e->nearest_station ?: '—' }}</td>
<td><x-score :n="$row['score']" /></td>
@foreach([true, false] as $hit)@php $rq = $jlist($j['req'], $hit); $pf = $jlist($j['pref'], $hit); @endphp
<td class="{{ $hit ? 'hit-text' : 'bad-text' }}"><small>@if($rq)<b>必須</b> {{ implode('、', $rq) }}<br>@endif @if($pf)<b>尚可</b> {{ implode('、', $pf) }}<br>@endif @if(!$rq && !$pf)—@endif</small></td>
@endforeach
<td>必須 {{ $j['h'] }}/{{ $j['n'] }}　尚可 {{ $j['k'] }}/{{ $j['m'] }}</td>
</tr>@endforeach
</x-table>

@unless($partnerSide)
<h3>パートナー（他社のエンジニア）</h3>
<p class="muted">他社のエンジニアには、直接メールを送りません。その企業の担当者に紹介をお願いします（名前の後ろの［　］が担当者です）。</p>
<x-table :head="$head" :count="count($partner) + count($staffRows)" empty="条件に合う他社のエンジニアがいません。">
@foreach($partner as $row)@php $e = $row['e']; $j = $row['j']; @endphp<tr>
<td>@if($row['sent'] !== null)<span class="muted">案内済み</span>@elseif($row['requested'])<span class="muted">依頼済み</span>@elseif(!$row['staff'])<span class="muted">担当者がいません</span>@else<input type="checkbox" name="pick[]" value="{{ $e->id }}" @checked($row['member']->checked)>@endif</td>
<td><button class="linkbtn" name="go" value="{{ $e->id }}" style="background:none;border:0;padding:0;color:var(--blue);cursor:pointer;text-decoration:underline;font:inherit">{{ $row['name'] }}</button>
@if($row['member']->by_hand)<span class="badge info">手で足した</span>@endif
@if(!$row['pass'])<div><small class="muted">条件に届いていません@if($row['over'])（単価が案件＋{{ \App\Services\CaseSearch::PRICE_OVER }}万円以上）@endif</small></div>@endif</td>
<td class="muted">—</td><td class="muted">—</td><td class="muted">—</td>
<td><x-score :n="$row['score']" /></td>
@foreach([true, false] as $hit)@php $rq = $jlist($j['req'], $hit); $pf = $jlist($j['pref'], $hit); @endphp
<td class="{{ $hit ? 'hit-text' : 'bad-text' }}"><small>@if($rq)<b>必須</b> {{ implode('、', $rq) }}<br>@endif @if($pf)<b>尚可</b> {{ implode('、', $pf) }}<br>@endif @if(!$rq && !$pf)—@endif</small></td>
@endforeach
<td>必須 {{ $j['h'] }}/{{ $j['n'] }}　尚可 {{ $j['k'] }}/{{ $j['m'] }}</td>
</tr>@endforeach
{{-- 「エンジニア・担当者を追加」で足した担当者（エンジニアの指定なし） --}}
@foreach($staffRows as $sr)@php $st = $sr['st']; @endphp<tr>
<td>@if($st->sent_at)<span class="muted">依頼済み（{{ fmt_date($st->sent_at) }}）</span>@elseif($sr['requested'])<span class="muted">依頼済み</span>@elseif(!$sr['user'])<span class="muted">担当者がいません</span>@else<input type="checkbox" name="spick[]" value="{{ $st->id }}" @checked($st->checked)>@endif</td>
<td colspan="8">{{ $sr['label'] }} <span class="badge info">手で足した</span>
@unless($st->sent_at)<button class="btn ghost small" formaction="{{ url('/searches/'.$s->id.'/staff/'.$st->id.'/remove') }}">外す</button>@endunless</td>
</tr>@endforeach
</x-table>
@endunless
@if($failed)<p class="muted">条件に届かなかった人：{{ $failed }}名（下の「エンジニア・担当者を追加」で足せます）</p>@endif
</x-card>
<x-card title="エンジニア・担当者を追加">
<p class="muted">名前で探して、一覧に足せます。自社のエンジニアは氏名・愛称・イニシャル@unless($partnerSide)、他社は企業名か担当者の氏名@endunless で探します。</p>
<p><input name="q" value="{{ $q }}" placeholder="名前・愛称・イニシャル・企業名" onkeydown="if(event.key==='Enter'){event.preventDefault();document.getElementById('findbtn').click();}"> <button class="btn ghost small" id="findbtn" name="find" value="1">探す</button></p>
@if($q !== '')
<x-table :head="['種類', '名前', '']" :count="count($found)" empty="見つかりませんでした。">
@foreach($found as $f)<tr><td>{{ $f['type'] === 'engineer' ? 'エンジニア' : '担当者' }}</td><td>{{ $f['name'] }}</td>
<td>@if($f['type'] === 'engineer')<button class="btn small" formaction="{{ url('/searches/'.$s->id.'/add') }}" name="engineer_id" value="{{ $f['e']->id }}">一覧に足す</button>
@else<button class="btn small" formaction="{{ url('/searches/'.$s->id.'/staff') }}" name="user_id" value="{{ $f['user']->id }}">一覧に足す</button>@endif</td></tr>@endforeach
</x-table>
@endif
</x-card>
@unless($partnerSide)
<x-card title="メールアドレスを追加" id="extras">
<p class="muted">PARK に登録していない方にも、同じ案件のご案内を送れます。1行に1件、「アドレス」か「名前 &lt;アドレス&gt;」で書いてください（1回に {{ \App\Services\CaseSearch::MAX_EXTRA_ADD }} 件まで）。返信は、このメールを送った担当者と PARK に届きます。</p>
<x-field label="追加するメールアドレス" name="extra_text" type="textarea" rows="3" />
<p><button class="btn ghost small" formaction="{{ url('/searches/'.$s->id.'/extras') }}">宛先に足す</button></p>
<x-table :head="['', '名前', 'アドレス', '']" :count="$extras->count()" empty="追加の宛先はまだありません。">
@foreach($extras as $x)<tr>
<td>@if($x->sent_at)<span class="muted">送信済み（{{ fmt_date($x->sent_at) }}）</span>@else<input type="checkbox" name="xpick[]" value="{{ $x->id }}" @checked($x->checked)>@endif</td>
<td>{{ $x->name ?: '—' }}</td><td>{{ $x->email }}</td>
<td>@unless($x->sent_at)<button class="btn ghost small" formaction="{{ url('/searches/'.$s->id.'/extras/'.$x->id.'/remove') }}">外す</button>@endunless</td>
</tr>@endforeach
</x-table>
</x-card>
@endunless
<button class="btn" formaction="{{ url('/searches/'.$s->id.'/compose') }}">エンジニア・担当者へメールを作成</button>
<button class="btn ghost">チェックを保存</button>
</form>
<script>
(function () {
    var all = document.getElementById('pickall');
    if (!all) return;
    all.addEventListener('change', function () {
        // プロパー・パートナー・追加の宛先の全部のチェックに効く（送信済み・依頼済み・担当者がいない行はチェックの欄が無い）
        document.querySelectorAll('input[name="pick[]"], input[name="xpick[]"], input[name="spick[]"]').forEach(function (b) { b.checked = all.checked; });
    });
})();
</script>
@endsection
