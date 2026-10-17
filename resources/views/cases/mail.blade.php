@php $u = auth()->user(); @endphp
@extends('layouts.app', ['title' => '案件を案内する：'.$c->title, 'wide' => true])
@section('content')
<form method="post" action="{{ url('/cases/'.$c->id.'/mail/preview') }}">@csrf
<input type="hidden" name="scope" value="{{ $scope }}">
<x-card title="1. 送る相手を絞る">
@if($u->role === 'ses')
<p>
@if($scope === 'own')<b>貴社のエンジニア</b> ／ <a href="?scope=group">グループのほかの人も探す（プラスα）</a>
@else<a href="?scope=own">貴社のエンジニア</a> ／ <b>グループのほかの人（プラスα）</b>@endif
</p>
<p class="muted">@if($scope === 'own')貴社のエンジニアには、貴社から直接メールを送ります（返信は貴社に届きます）。@else グループのほかの SES の人は、名前を伏せて出します。メールは PARKS から送り、返事は PARKS の画面でもらいます（その人の SES にも知らせます）。@endif</p>
@endif
<p class="muted">登録されている得意分野・経験から、合う順に並べました。{{ $th }}点以上に、はじめからチェックを入れています。チェックで足したり外したりしてください。</p>
<x-table :head="['', 'エンジニア', '点数', '合う点', '足りない点', 'これまでの案内']" :count="count($cands)" empty="候補がいません。">
@foreach($cands as $m)@php $e = $m['engineer']; @endphp<tr>
<td>@if($m['sent']){{ \App\Services\CaseMail::REPLIES[$m['sent']] ?? '' }}<div class="muted">送信済み</div>@else<input type="checkbox" name="engineer_id[]" value="{{ $e->id }}" @checked($m['score'] >= $th)>@endif</td>
<td>@if($m['own'])<a href="{{ url('/engineers/'.$e->id) }}">{{ $e->name }}</a>@if($e->nickname) <span class="muted">（{{ $e->nickname }}）</span>@endif @else{{ $e->initials ?: '—' }}<div class="muted">{{ $e->ses_name }}</div>@endif
<div class="muted">{{ lbl('engStatus', $e->status) }}</div></td>
<td><x-score :n="$m['score']" /></td><td><small>{{ implode('、', $m['reasons']) }}</small></td><td><small class="bad-text">{{ implode('、', $m['missing']) }}</small></td>
<td><small>@if($m['past']['sent'])案内 {{ $m['past']['sent'] }}回・応募したい {{ $m['past']['interested'] }}・見送り {{ $m['past']['declined'] }}@else—@endif</small></td></tr>@endforeach
</x-table>
</x-card>
<x-card title="2. メールを作る">
<x-field label="送り方" name="mode" type="select" :options="['same' => 'すべて同じ内容で送る（宛名などは一人ずつ変わります）', 'each' => '一人ずつ条件・本文を変える（次の画面で直せます）']" />
<x-field label="件名" name="subject" :value="$subject" required />
<x-field label="本文" name="body" type="textarea" rows="16" :value="$body" required />
<p class="muted">差し込み：{{ implode('　', \App\Services\CaseMail::PLACEHOLDERS) }}。{呼び名} は、エンジニアの「メールで呼ぶ名前（愛称など）」で、空なら氏名になります。</p>
<button class="btn">送る前に確かめる</button> <a class="btn ghost" href="{{ url('/cases/'.$c->id) }}">やめる</a>
</x-card>
</form>
@endsection
