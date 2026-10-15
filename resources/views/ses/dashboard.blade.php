@extends('layouts.app', ['title' => $s->name.' さま', 'wide' => true])
@section('content')
<x-stats :items="[['所属エンジニア', $engs->count(), '待機 '.$engs->where('status', 'available')->count().'名'], ['稼働中', $engs->where('status', 'working')->count()],
    ['選考中', $apps->count()], ['成約（これまで）', $won, '成約率 '.pct($won, $appCount)], ['今月の成功報酬（税別・見込み）', yen($est['subtotal']), this_month()]]" />
<div class="cols"><div>
<x-card title="選考中の応募"><x-table :head="['エンジニア', '案件', '状態', '更新', '']" :count="$apps->count()" empty="ありません。">
@foreach($apps as $a)<tr><td>{{ $a->eg_name }}</td><td>{{ $a->title }}</td><td>{{ badge('appStatus', $a->status) }}</td><td>{{ fmt_date($a->updated_at) }}</td><td><a href="{{ url('/applications/'.$a->id) }}">開く</a></td></tr>@endforeach
</x-table></x-card>
<x-card title="配信済みで、まだ応募していない案件"><p class="muted">ご本人の返事を待っています。貴社から一声かけていただくと、応募が早くなります。</p>
<x-table :head="['エンジニア', '案件', '点数', '配信日', '']" :count="$offers->count()" empty="ありません。">
@foreach($offers as $o)<tr><td>{{ $o->eg_name }}</td><td>{{ $o->title }}</td><td>{{ $o->score }}</td><td>{{ fmt_date($o->sent_at) }}</td><td><a href="{{ url('/cases/'.$o->case_id) }}">開く</a></td></tr>@endforeach
</x-table></x-card>
</div><div>
<x-card title="新しい案件（グループ全体）"><x-slot:actions><a class="btn small" href="{{ url('/cases/new') }}">案件を登録</a></x-slot:actions>
<x-table :head="['案件', '金額', '開始', '']" :count="$newCases->count()">
@foreach($newCases as $c)<tr><td>{{ $c->title }}</td><td>{{ man($c->price) }}</td><td>{{ fmt_date($c->start_date) }}</td><td><a href="{{ url('/cases/'.$c->id) }}">開く</a></td></tr>@endforeach
</x-table></x-card>
@if($noSheet->count())
<x-card title="経験票がまだのエンジニア"><p class="muted">経験票が入っていないと、合う案件が届きません。ご本人に声をかけてください。</p>
<ul>@foreach($noSheet as $e)<li><a href="{{ url('/engineers/'.$e->id) }}">{{ $e->name }}</a></li>@endforeach</ul></x-card>
@endif
</div></div>
@endsection
