@extends('layouts.app', ['title' => 'ダッシュボード', 'wide' => true])
@section('content')
<x-todo />
@php $next = ['applied' => 'スキルシートを提出', 'doc_passed' => '面談を設定', 'interviewed' => '成約 or 次の面談']; @endphp
<x-stats :items="[['参加中の SES', $counts['ses'], $pendingSes->count() ? '申込 '.$pendingSes->count().'件' : ''], ['エンジニア', $counts['eng'], '待機 '.$counts['engAvail'].'名'],
    ['募集中の案件', $counts['cases']], ['選考中', $counts['apps']], ['稼働中の契約', $counts['contracts']], ['今月の成功報酬（税別）', yen($revenue), this_month()]]" />
@if($unreviewedSkills)<div class="flash warn">案件などから自動で追加された、確認待ちの技術が {{ $unreviewedSkills }} 件あります。<a href="{{ url('/admin/skills') }}">技術マスタで分類を確かめる</a></div>@endif
<div class="cols"><div>
@if($pendingSes->count())
<x-card title="参加の申し込み（承認待ち）"><x-table :head="['会社', '担当', '申込日', '']">
@foreach($pendingSes as $s)<tr><td>{{ $s->name }}</td><td>{{ $s->contact_name }}</td><td>{{ fmt_date($s->created_at) }}</td><td><a href="{{ url('/admin/ses/'.$s->id) }}">確認する</a></td></tr>@endforeach
</x-table></x-card>
@endif
<x-card title="いま運営がやること"><x-table :head="['案件', 'エンジニア', '状態', '次の手順', '']" :count="$todo->count()" empty="ありません。">
@foreach($todo as $a)<tr><td>{{ $a->title }}</td><td>{{ $a->eg_name }}</td><td>{{ badge('appStatus', $a->status) }}</td><td>{{ $next[$a->status] }}</td><td><a href="{{ url('/applications/'.$a->id) }}">開く</a></td></tr>@endforeach
</x-table></x-card>
<x-card title="募集中の案件"><x-slot:actions><a class="btn small" href="{{ url('/cases/new') }}">案件を登録</a></x-slot:actions>
<x-table :head="['案件', '金額', '配信', '応募', '']" :count="$openCases->count()">
@foreach($openCases as $c)<tr><td>{{ $c->title }}</td><td>{{ man($c->price) }}</td><td>{{ $c->offers }}</td><td>{{ $c->apps }}</td><td><a href="{{ url('/cases/'.$c->id) }}">{{ $c->offers ? '開く' : 'マッチング' }}</a></td></tr>@endforeach
</x-table></x-card>
</div><div>
<x-card title="応募 → 成約（全体）"><x-funnel :rows="$funnel" /></x-card>
<x-card title="面談の予定"><x-table :head="['日時', '案件', 'エンジニア', '']" :count="$upcoming->count()" empty="ありません。">
@foreach($upcoming as $iv)<tr><td>{{ fmt_dt($iv->scheduled_at) }}</td><td>{{ $iv->title }}</td><td>{{ $iv->eg_name }}</td><td><a href="{{ url('/interviews/'.$iv->id) }}">Q&A</a></td></tr>@endforeach
</x-table></x-card>
@if($ending->count())
<x-card title="1か月以内に終わる契約（延長の確認）"><x-table :head="['終わり', '案件', 'エンジニア', '']">
@foreach($ending as $k)<tr><td>{{ fmt_date($k->end_date) }}</td><td>{{ $k->title }}</td><td>{{ $k->eg_name }}</td><td><a href="{{ url('/contracts/'.$k->id) }}">開く</a></td></tr>@endforeach
</x-table></x-card>
@endif
@if($unread->count())
<x-card title="未読のメッセージ"><x-table :head="['エンジニア', '件数', '']">
@foreach($unread as $m)<tr><td>{{ $m->name }}</td><td>{{ $m->n }}</td><td><a href="{{ url('/engineers/'.$m->engineer_id.'/messages') }}">開く</a></td></tr>@endforeach
</x-table></x-card>
@endif
</div></div>
@endsection
