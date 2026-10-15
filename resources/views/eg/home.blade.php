@extends('layouts.app', ['title' => $e->name.' さん', 'wide' => true])
@section('content')
<x-stats :items="[['スキルシートの完成度', $check['score'].'%', $check['score'] < 80 ? 'あと少し' : 'いい感じです'], ['届いている案件', $offers->count()],
    ['選考中', $apps->where('status', '!=', 'won')->count()], ['今の状態', lbl('engStatus', $e->status)]]" />
@if($ivs->count())
<x-card title="面談の予定"><x-table :head="['日時', '案件', '']">
@foreach($ivs as $iv)<tr><td>{{ fmt_dt($iv->scheduled_at) }}</td><td>{{ $iv->title }}</td><td><a class="btn small" href="{{ url('/interviews/'.$iv->id) }}">面談対策の Q&A を見る</a></td></tr>@endforeach
</x-table></x-card>
@endif
@if($needFollow)<x-card title="配属後のひとこと"><p>「{{ $contract->title }}」が始まりました。困りごとや相性など、短く教えてください。早めに気づけば手を打てます。</p><a class="btn small" href="{{ url('/contracts/'.$contract->id) }}">記入する</a></x-card>@endif
@if($unread)<x-card title="運営からのメッセージ"><p>未読が {{ $unread }} 件あります。</p><a class="btn small" href="{{ url('/engineers/'.$e->id.'/messages') }}">読む</a></x-card>@endif
<div class="cols"><div>
<x-card title="あなたに届いた案件"><x-table :head="['案件', '相性', '金額', '開始', '']" :count="$offers->count()" empty="いまは届いていません。経験票を充実させると、合う案件が届きやすくなります。">
@foreach($offers as $o)<tr><td>{{ $o->title }}</td><td><x-score :n="$o->score" /></td><td>{{ man($o->price) }}</td><td>{{ fmt_date($o->start_date) }}</td><td><a href="{{ url('/cases/'.$o->case_id) }}">@if($o->status === 'sent')<b>新着</b>@else開く@endif</a></td></tr>@endforeach
</x-table></x-card>
<x-card title="応募・選考"><x-table :head="['案件', '状態', '']" :count="$apps->count()" empty="ありません。">
@foreach($apps as $a)<tr><td>{{ $a->title }}</td><td>{{ badge('appStatus', $a->status) }}</td><td><a href="{{ url('/applications/'.$a->id) }}">開く</a></td></tr>@endforeach
</x-table></x-card>
</div><div>
<x-card title="スキルシートを良くするには"><div class="meter"><i style="width:{{ $check['score'] }}%"></i><b>{{ $check['score'] }}%</b></div>
<ul class="tips">@foreach(array_slice($check['tips'], 0, 5) as $t)<li>{{ $t }}</li>@endforeach</ul>
<p><a class="btn small" href="{{ url('/engineers/'.$e->id) }}">経験票を編集</a> <a class="btn ghost small" href="{{ url('/engineers/'.$e->id.'/sheet') }}">スキルシートを見る</a> <a class="btn ghost small" href="{{ url('/engineers/'.$e->id.'/messages') }}">運営に見てもらう</a></p>
</x-card>
</div></div>
@endsection
