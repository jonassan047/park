@extends('layouts.app', ['title' => '分析（成約率）', 'wide' => true])
@section('content')
<p class="muted">件数が少ないうちは率が大きくぶれます。1件の結果で判断しないでください。</p>
<x-stats :items="[['配信 → 応募', $offerRate[0], $offerRate[1]], ['応募 → 成約', pct($funnel[5][1], $funnel[0][1])],
    ['応募から成約までの日数', $avgDays === null ? '—' : $avgDays.'日', '平均'], ['延長した契約', $extRate[0], $extRate[1]]]" />
<div class="cols"><div>
<x-card title="応募 → 成約（全体）"><x-funnel :rows="$funnel" /></x-card>
<x-card title="SES ごと"><x-table :head="['SES', '応募', '書類提出', '面談', '成約', '成約率']" :count="$sesRows->count()">
@foreach($sesRows as $r)<tr>@foreach($r as $v)<td>{{ $v }}</td>@endforeach</tr>@endforeach
</x-table></x-card>
</div><div>
<x-card title="見送りの理由（選考）"><x-table :head="['理由', '件数']" :count="$reasons->count()">@foreach($reasons as $r)<tr><td>{{ $r->r ?: '未記入' }}</td><td>{{ $r->n }}</td></tr>@endforeach</x-table></x-card>
<x-card title="エンジニアが案件を見送った理由"><x-table :head="['理由', '件数']" :count="$declines->count()">@foreach($declines as $r)<tr><td>{{ $r->r ?: '未記入' }}</td><td>{{ $r->n }}</td></tr>@endforeach</x-table></x-card>
<x-card title="月ごとの成功報酬（税別・計算上）"><x-table :head="['月', '金額']" :count="$revenue->count()">@foreach($revenue as [$m, $v])<tr><td>{{ $m }}</td><td>{{ yen($v) }}</td></tr>@endforeach</x-table></x-card>
</div></div>
@endsection
