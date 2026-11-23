@extends('layouts.app', ['title' => 'メールを作成：'.$c->code.'　'.$c->title, 'wide' => true])
@section('content')
@unless($canSend)<p class="note">いまはメールを「保存のみ」にしています。送った記録は残りますが、実際には届きません。</p>@endunless
{{-- 段の表示：1. プロパーへ → 2. 担当者へ（残り n 名） --}}
<p class="muted">
@if($hasProper)<b>{{ $mode === 'proper' ? '1. エンジニアへ（今ここ）' : '1. エンジニアへ' }}</b> → @endif
<b>{{ $mode === 'staff' ? ($hasProper ? '2' : '1').'. 担当者へ（今ここ・残り '.$batchN.' 名）' : ($batchN > 0 ? '2. 担当者へ（残り '.$batchN.' 名）' : '') }}</b>
</p>
<p><b>{{ $c->code }}　{{ $c->title }}</b></p>
@php
$formUrl = $mode === 'proper' ? url('/searches/'.$s->id.'/compose/proper') : url('/searches/'.$s->id.'/compose/staff/'.$top->id);
@endphp
<form method="post" action="{{ $formUrl }}" id="composeForm">@csrf
<input type="hidden" name="kind" value="{{ $mode }}">
@if($mode === 'staff')<input type="hidden" name="stid" value="{{ $top->id }}">@endif
<div class="cols"><div>
<x-card :title="$mode === 'proper' ? 'エンジニアへのメール' : '担当者へのメール'">
<x-field label="件名" name="subject" :value="$subject" />
<x-field label="本文" name="body" type="textarea" rows="24" :value="$body" :hint="$help" />
<p class="muted">本文の「■単価：」の行は、はじめは{{ $priceLabel }}（{{ $price }}）です。{{ $who }}に伝える金額に直してから送ってください。送るときに、返事のリンクなどが後ろに付きます。</p>
<p><button class="btn ghost small" formaction="{{ url('/searches/'.$s->id.'/compose/draft') }}">下書きを保存</button></p>
</x-card>
</div><div>
@if($mode === 'proper')
<x-card :title="'送るエンジニア（'.count($rows).' 名）'">
<x-table :head="['名前', 'ID']" :count="count($rows)" empty="チェックしたエンジニアはいません。">
@foreach($rows as $row)<tr><td>{{ $row['name'] }}</td><td><x-code :v="$row['e']->code" /></td></tr>@endforeach
</x-table>
@if($extras->count())
<h3>追加の宛先（{{ $extras->count() }} 件）</h3>
<x-table :head="['名前', 'アドレス']" :count="$extras->count()">
@foreach($extras as $x)<tr><td>{{ $x->name ?: '—' }}</td><td>{{ $x->email }}</td></tr>@endforeach
</x-table>
@endif
<p>
<button class="btn" id="sendbtn" data-n="{{ count($rows) + $extras->count() }}" onclick="return parkComposeConfirm(this)">エンジニアへメールを送信</button>
<a class="btn ghost" href="{{ url('/searches/'.$s->id) }}">エンジニアを選択に戻る</a>
</p>
</x-card>
@else
<x-card :title="'担当者（残り '.$batchN.' 名）'">
<p><label class="check"><input type="checkbox" id="staffall"> すべてを選択</label></p>
<x-table :head="['', '担当者', '依頼するエンジニア']" :count="1 + $rest->count()">
<tr><td><input type="checkbox" checked disabled><input type="hidden" name="top" value="{{ $top->id }}"></td><td>{{ $top->label }}</td><td>{{ $top->initials ? implode('・', $top->initials) : '指定なし' }}</td></tr>
@foreach($rest as $b)<tr><td><input type="checkbox" name="others[]" value="{{ $b->id }}" class="others"></td><td>{{ $b->label }}</td><td>{{ $b->initials ? implode('・', $b->initials) : '指定なし' }}</td></tr>@endforeach
</x-table>
<p class="muted">同じ条件で送信する際はチェックを入れてください</p>
<p>
<button class="btn" id="sendbtn" data-n="1" onclick="return parkComposeConfirm(this)">担当者へメールを送信</button>
<button class="btn ghost" formaction="{{ url('/searches/'.$s->id.'/compose/staff/'.$top->id.'/skip') }}" onclick="return confirm('この担当者には、今回は送りません。次へ進みます。よろしいですか？')">この担当者には今回は送らない</button>
<a class="btn ghost" href="{{ url('/searches/'.$s->id) }}">エンジニアを選択に戻る</a>
</p>
</x-card>
@endif
</div></div>
</form>
<script>
(function () {
    var form = document.getElementById('composeForm');
    var body = form.querySelector('textarea[name="body"]');
    var sent = false;
    // 担当者の画面：「すべてを選択」は、残りの担当者のチェックに効く（一番上は外せない）
    var all = document.getElementById('staffall');
    if (all) all.addEventListener('change', function () {
        document.querySelectorAll('input.others').forEach(function (b) { b.checked = all.checked; });
    });
    var priceOf = function (t) { var m = /^■単価：(.*)$/m.exec(t.replace(/\r\n/g, '\n')); return m ? m[1].trim() : null; };
    @php $jf = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT; @endphp
    var orig = @json($price, $jf);
    var label = @json($priceLabel, $jf);
    var who = @json($who === '担当者' ? '担当者向けの金額' : 'エンジニア向けの金額', $jf);
    var unit = @json($who === '担当者' ? '名の担当者' : '名', $jf);
    window.parkComposeConfirm = function (btn) {
        if (sent) return false;
        var n = parseInt(btn.getAttribute('data-n'), 10) || 1;
        if (document.getElementById('staffall')) n = 1 + document.querySelectorAll('input.others:checked').length; // 担当者の画面：一番上＋チェックした人
        var now = priceOf(body.value);
        var msg;
        if (now === null) msg = '本文に「■単価：」の行がありません。金額を書かずに ' + n + unit + 'に送信します。よろしいですか？';
        else if (now === orig) msg = '金額が' + label + '（' + orig + '）のままです。\n' + who + 'に変えましたか？\n\n［OK］このまま ' + n + unit + 'に送信する\n［キャンセル］戻って直す';
        else msg = '金額を「' + orig + '」から「' + now + '」に変えました。\n' + n + unit + 'に送信します。よろしいですか？';
        if (!confirm(msg)) return false;
        sent = true; // 二度押し防止（サーバー側でも同じ宛先に2通出さない）
        setTimeout(function () { btn.disabled = true; }, 0);
        return true;
    };
})();
</script>
@endsection
