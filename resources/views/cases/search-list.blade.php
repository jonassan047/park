@extends('layouts.app', ['title' => 'エンジニアを選択：'.$c->code.'　'.$c->title, 'wide' => true])
@section('content')
@unless($canSend)<p class="note">いまはメールを「保存のみ」にしています。送った記録は残りますが、実際には届きません。</p>@endunless
@php
// マッチした点・マッチしなかった点：必須・尚可ごとに、当たった項目・当たらなかった項目を並べる（業務は「（業務）」を添える）
$jlist = function (array $items, bool $hit) {
    $out = [];
    foreach ($items as $it) if ((bool) $it['hit'] === $hit) $out[] = $it['name'].($it['kind'] === 'exp' ? '（業務）' : '');
    return $out;
};
@endphp
<form method="post" action="{{ url('/searches/'.$s->id) }}">@csrf
<x-card title="エンジニアを選択">
<x-slot:actions><a class="btn ghost small" href="{{ url('/cases/'.$c->id.'#keywords') }}">キーワードを変える</a> <a class="btn ghost small" href="{{ url('/cases/'.$c->id) }}">案件に戻る</a></x-slot:actions>
<p><label class="check"><input type="checkbox" id="pickall"> すべてを選択</label> <b>{{ $c->title }}</b></p>
<p class="muted">案件ID：<x-code :v="$c->code" />　金額：{{ man($c->price) }}　開始：{{ $c->start_date ? date('Y年n月', strtotime($c->start_date)) : '相談' }}@if($c->work_style !== 'remote')　最寄り駅：{{ $c->location ?: '—' }}@else　フルリモート@endif</p>
@php $u = auth()->user(); @endphp
<x-table :head="['', 'エンジニア', '年齢', '価格', '最寄り駅', '点数', 'マッチした点', 'マッチしなかった点', '件数']" :count="count($rows)" empty="条件に合うエンジニアがいません。キーワードを外すか、下の「エンジニアを追加」で足してください。">
@foreach($rows as $row)@php $e = $row['e']; $j = $row['j']; @endphp<tr>
<td>@if($row['sent'] !== null)<span class="muted">送信済み</span>@else<input type="checkbox" name="pick[]" value="{{ $e->id }}" @checked($row['member']->checked)>@endif</td>
<td><button class="linkbtn" name="go" value="{{ $e->id }}" style="background:none;border:0;padding:0;color:var(--blue);cursor:pointer;text-decoration:underline;font:inherit">{{ $row['name'] }}</button>
@if($row['own'] || auth()->user()->role === 'admin') <x-code :v="$e->code" />@endif
@if($row['member']->by_hand)<span class="badge info">手で足した</span>@endif
@if(!$row['pass'])<div><small class="muted">条件に届いていません@if($row['over'])（単価が案件＋{{ \App\Services\CaseSearch::PRICE_OVER }}万円以上）@endif</small></div>@endif</td>
@if($row['own'] || $u->role === 'admin')<td>{{ $e->birth_year ? (date('Y') - $e->birth_year).'歳' : '—' }}</td><td>{{ man($e->desired_price) }}</td><td>{{ $e->nearest_station ?: '—' }}</td>
@else<td class="muted">—</td><td class="muted">—</td><td class="muted">—</td>@endif
<td><x-score :n="$row['score']" /></td>
@foreach([true, false] as $hit)@php $rq = $jlist($j['req'], $hit); $pf = $jlist($j['pref'], $hit); @endphp
<td class="{{ $hit ? 'hit-text' : 'bad-text' }}"><small>@if($rq)<b>必須</b> {{ implode('、', $rq) }}<br>@endif @if($pf)<b>尚可</b> {{ implode('、', $pf) }}<br>@endif @if(!$rq && !$pf)—@endif</small></td>
@endforeach
<td>必須 {{ $j['h'] }}/{{ $j['n'] }}　尚可 {{ $j['k'] }}/{{ $j['m'] }}</td>
</tr>@endforeach
</x-table>
@if($failed)<p class="muted">条件に届かなかった人：{{ $failed }}名（下の「エンジニアを追加」で足せます）</p>@endif
<x-field label="全員へのメッセージ" name="message_all" type="textarea" rows="3" :value="$s->message_all" hint="どのエンジニアへのメールにも入ります（空でもかまいません）。" />
</x-card>
<x-card title="エンジニアを追加">
<p class="muted">名前などで探して、一覧に足せます（他社の人はイニシャルで探します）。</p>
<p><input name="q" value="{{ $q }}" placeholder="名前・愛称・イニシャル" onkeydown="if(event.key==='Enter'){event.preventDefault();document.getElementById('findbtn').click();}"> <button class="btn ghost small" id="findbtn" name="find" value="1">探す</button></p>
@if($q !== '')
<x-table :head="['氏名（所属会社）', '']" :count="count($found)" empty="見つかりませんでした。">
@foreach($found as $f)<tr><td>{{ $f['name'] }}</td>
<td><button class="btn small" formaction="{{ url('/searches/'.$s->id.'/add') }}" name="engineer_id" value="{{ $f['e']->id }}">一覧に足す</button></td></tr>@endforeach
</x-table>
@endif
</x-card>
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
<button class="btn" formaction="{{ url('/searches/'.$s->id.'/send') }}" onclick="return parkSendConfirm()">エンジニアへメールを送信</button>
<button class="btn ghost">チェックとメッセージを保存</button>
</form>
<script>
function parkSendConfirm() {
    var n = document.querySelectorAll('input[name="pick[]"]:checked, input[name="xpick[]"]:checked').length;
    if (n === 0) { alert('送る相手を選んでください。'); return false; }
    return confirm(n + '件に送ります。よろしいですか？');
}
(function () {
    var all = document.getElementById('pickall');
    if (!all) return;
    all.addEventListener('change', function () {
        document.querySelectorAll('input[name="pick[]"], input[name="xpick[]"]').forEach(function (b) { b.checked = all.checked; });
    });
})();
</script>
@endsection
