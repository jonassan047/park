@extends('layouts.app', ['title' => 'エンジニアの一覧：'.$c->title, 'wide' => true])
@section('content')
@unless($canSend)<p class="note">いまはメールを「保存のみ」にしています。送った記録は残りますが、実際には届きません。</p>@endunless
<form method="post" action="{{ url('/searches/'.$s->id) }}">@csrf
<x-card>
<x-slot:actions><a class="btn ghost small" href="{{ url('/cases/'.$c->id.'/search') }}">条件を変える</a> <a class="btn ghost small" href="{{ url('/cases/'.$c->id) }}">案件に戻る</a></x-slot:actions>
<p><label class="check"><input type="checkbox" id="pickall"> 全員選択</label> <b>{{ $c->title }}</b></p>
<x-field label="全員へのメッセージ" name="message_all" type="textarea" rows="3" :value="$s->message_all" hint="どのエンジニアへのメールにも入ります（空でもかまいません）。" />
@if($failed)<p class="muted">条件に届かなかった人：{{ $failed }}名（下の「一覧に足す」で足せます）</p>@endif
<x-table :head="['', '氏名（所属会社）', '合致', '']" :count="count($rows)" empty="条件に合うエンジニアがいません。条件を外すか、下の［一覧に足す］で足してください。">
@foreach($rows as $row)@php $e = $row['e']; $j = $row['j']; @endphp<tr>
<td>@if($row['sent'] !== null)<span class="muted">送信済み</span>@else<input type="checkbox" name="pick[]" value="{{ $e->id }}" @checked($row['member']->checked)>@endif</td>
<td><button class="linkbtn" name="go" value="{{ $e->id }}" style="background:none;border:0;padding:0;color:var(--blue);cursor:pointer;text-decoration:underline;font:inherit">{{ $row['name'] }}</button>
@if($row['member']->by_hand)<span class="badge info">手で足した</span>@endif</td>
<td>必須 {{ $j['h'] }}/{{ $j['n'] }}　尚可 {{ $j['k'] }}/{{ $j['m'] }}</td>
<td>@if(!$row['pass'])<small class="muted">条件に届いていません</small>@endif</td>
</tr>@endforeach
</x-table>
</x-card>
<x-card title="一覧に足す">
<p class="muted">名前などで探して、一覧に足せます（他社の人はイニシャルで探します）。</p>
<p><input name="q" value="{{ $q }}" placeholder="名前・愛称・イニシャル" onkeydown="if(event.key==='Enter'){event.preventDefault();document.getElementById('findbtn').click();}"> <button class="btn ghost small" id="findbtn" name="find" value="1">探す</button></p>
@if($q !== '')
<x-table :head="['氏名（所属会社）', '']" :count="count($found)" empty="見つかりませんでした。">
@foreach($found as $f)<tr><td>{{ $f['name'] }}</td>
<td><button class="btn small" formaction="{{ url('/searches/'.$s->id.'/add') }}" name="engineer_id" value="{{ $f['e']->id }}">一覧に足す</button></td></tr>@endforeach
</x-table>
@endif
</x-card>
<button class="btn" formaction="{{ url('/searches/'.$s->id.'/send') }}" onclick="return parkSendConfirm()">エンジニアへメールを送信</button>
<button class="btn ghost">チェックとメッセージを保存</button>
</form>
<script>
function parkSendConfirm() {
    var n = document.querySelectorAll('input[name="pick[]"]:checked').length;
    if (n === 0) { alert('送る相手を選んでください。'); return false; }
    return confirm(n + '名に送ります。よろしいですか？');
}
(function () {
    var all = document.getElementById('pickall');
    if (!all) return;
    all.addEventListener('change', function () {
        document.querySelectorAll('input[name="pick[]"]').forEach(function (b) { b.checked = all.checked; });
    });
})();
</script>
@endsection
