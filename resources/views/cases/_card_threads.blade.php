@if($caseThreads->count())
<x-card title="エンジニアとのやり取り" id="threads">
<x-table :head="array_values(array_filter(['エンジニア', $u->role === 'admin' ? '会社' : null, $u->role === 'admin' ? '相手' : null, '最後の発言', '未読', ''], fn ($h) => $h !== null))" :count="$caseThreads->count()">
@foreach($caseThreads as $t)<tr><td>{{ \App\Services\CaseThread::egNameRow($u->role === 'ses' ? (int) $u->ses_id : null, $t) }} @if(\App\Services\CaseThread::isOwnEg($u, $t))<x-code :v="$t->eg_code" />@endif</td>@if($u->role === 'admin')<td>{{ $t->ses_name }}</td><td>{{ $t->partner_name }}</td>@endif<td>{{ fmt_dt($t->last_post_at) }}</td>
<td>@if(\App\Services\CaseThread::isMine($u, $t))@if($t->unread)<b class="bad-text">{{ $t->unread }}</b>@endif @elseif($u->role === 'ses')<span class="muted">読むだけ（相手：{{ $t->partner_name }}）</span>@else<span class="muted">—</span>@endif</td>
<td><a class="btn small" href="{{ url('/cases/'.$c->id.'/threads/'.$t->engineer_id) }}">開く</a></td></tr>@endforeach
</x-table>
</x-card>
@endif
