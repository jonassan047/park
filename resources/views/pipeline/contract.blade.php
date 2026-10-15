@php $u = auth()->user(); $base = $k->price * 10000; @endphp
@extends('layouts.app', ['title' => '契約：'.$k->title, 'wide' => true])
@section('content')
<div class="cols"><div>
<x-card title="契約"><dl class="dl">
<dt>案件</dt><dd><a href="{{ url('/cases/'.$k->case_id) }}">{{ $k->title }}</a></dd>
<dt>エンジニア</dt><dd><a href="{{ url('/engineers/'.$k->engineer_id) }}">{{ $k->eg_name }}</a></dd><dt>SES</dt><dd>{{ $k->ses_name }}</dd>
<dt>状態</dt><dd>{{ badge('contractStatus', $k->status) }}</dd><dt>金額</dt><dd>{{ man($k->price) }}</dd>
<dt>期間</dt><dd>{{ fmt_date($k->start_date) }}〜{{ fmt_date($k->end_date) }}</dd><dt>延長</dt><dd>{{ $k->extensions }}回</dd>
@if($k->end_reason)<dt>終了の理由</dt><dd>{{ $k->end_reason }}</dd>@endif
@if($u->role !== 'eg')<dt>成功報酬（税別）</dt><dd>初期費用 {{ yen($base * (float) setting('fee_initial_rate') / 100) }}／月額 {{ yen($base * (float) setting('fee_monthly_rate') / 100) }}</dd>@endif
</dl></x-card>
@if($u->role === 'admin' && $k->status === 'active')
<x-card title="延長・終了">
<form method="post" action="{{ url('/contracts/'.$k->id.'/extend') }}">@csrf<div class="grid2"><x-field label="延長する月数" name="months" type="number" min="1" value="3" /><div><button class="btn">延長を記録</button></div></div></form>
<hr><form method="post" action="{{ url('/contracts/'.$k->id.'/end') }}" onsubmit="return confirm('契約を終了にしますか？')">@csrf
<div class="grid2"><x-field label="終了日" name="end_date" type="date" :value="$k->end_date" /><x-field label="理由" name="end_reason" placeholder="満了、元請けの都合、相性 など" /></div><button class="btn ghost">終了にする</button></form>
</x-card>
@endif
</div><div>
<x-card title="成約後のフォロー"><p class="muted">配属後の最初の1か月に、困りごと・相性を短く聞きます。早めに気づけば、延長につながります。</p>
@if($u->role !== 'ses' && $k->status === 'active')
<form method="post" action="{{ url('/contracts/'.$k->id.'/followup') }}">@csrf
<x-field label="今の調子（5がとても良い）" name="mood" type="select" :options="[5 => '5 とても良い', 4 => '4 良い', 3 => '3 ふつう', 2 => '2 少し不安', 1 => '1 困っている']" value="3" />
<x-field label="困っていること" name="troubles" type="textarea" rows="2" /><x-field label="メモ" name="note" /><button class="btn">記録する</button></form>
@endif
<x-table :head="['日', '調子', '困りごと', 'メモ', '記入']" :count="$fus->count()" empty="まだ記録はありません。">
@foreach($fus as $f)<tr><td>{{ fmt_date($f->at) }}</td><td>{{ $f->mood }}</td><td>{{ $f->troubles }}</td><td>{{ $f->note }}</td><td>{{ $f->name }}</td></tr>@endforeach
</x-table></x-card>
</div></div>
@endsection
