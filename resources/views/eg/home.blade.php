@extends('layouts.app', ['title' => $e->name.' さん', 'wide' => true])
@section('content')
<x-stats :items="[['スキルシートの完成度', $check['score'].'%', $check['score'] < 80 ? 'あと少し' : 'いい感じです'], ['届いている案件', $offers->count()],
    ['選考中', $apps->where('status', '!=', 'won')->count()], ['今の状態', lbl('engStatus', $e->status)]]" />
@if(!$e->self_confirmed_at)<x-card title="登録の内容の確認"><p>あなたの登録の内容（プロフィール・スキル・経験票）は、所属の SES や運営が入れたものも含めて、<b>あなたご自身が責任を持つもの</b>です。中身を確かめ、違うところは直してから、「確認しました」を押してください。確認が済むまでは、案件に応募できません。</p>
<p><a class="btn ghost small" href="{{ url('/engineers/'.$e->id) }}">内容を見る・直す</a></p>
<x-post :action="'/engineers/'.$e->id.'/confirm'" label="内容を確認しました（責任を持って登録します）" cls="small" :confirm="'登録の内容が正しいことを確認しましたか？'" /></x-card>@endif
@if($historyTodo->count())<x-card title="経歴の更新"><p>案件ごとに、経験票（経歴）を書いてください。次の案件のスキルシートに使います。</p><x-table :head="['案件', '期間', '']">
@foreach($historyTodo as $k)<tr><td>{{ $k->title }}</td><td>{{ fmt_date($k->start_date) }}〜{{ $k->status === 'ended' ? fmt_date($k->end_date).'（終了）' : '' }}</td><td><a class="btn small" href="{{ url('/engineers/'.$e->id.'/projects/new?contract='.$k->id) }}">{{ $k->status === 'ended' ? '終わった案件を書く' : '経験票に追加' }}</a></td></tr>@endforeach
</x-table><p class="muted">すでに経験票にある案件は、その案件の「編集」で直してから、ここの「経験票に追加」で新しく書いても構いません（古いほうは消せます）。</p></x-card>@endif
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
