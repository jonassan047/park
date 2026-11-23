{{-- 受けた案件（原文）の表。プロバイダーのタブと、プロバイダーの詳細で使う。$withSes：「受注 SES」の列を出すか（管理者だけ）。v0.17.0 --}}
<x-table :head="array_merge(['ID', '案件名', 'プロバイダー', '金額'], $withSes ? ['受注 SES'] : [], ['状態', '登録日', '原文', ''])" :count="$list->count()" empty="まだありません。">
@foreach($list as $c)<tr><td><x-code :v="$c->code" /></td><td>{{ $c->title }}</td>
<td>@if($c->provider_id && $c->provider_name)<a href="{{ url($base.'/'.$c->provider_id) }}">{{ $c->provider_name }}</a>@else—@endif</td>
<td>{{ man($c->price) }}@if($c->price_note) <span class="muted">{{ $c->price_note }}</span>@endif</td>
@if($withSes)<td>{{ $c->ses_name ?: '—' }}</td>@endif
<td>{{ badge('caseStatus', $c->status) }}</td><td>{{ fmt_date($c->created_at) }}</td>
<td>@if(trim((string) $c->raw_text) !== '')<details><summary>原文</summary><pre class="mailbody">{{ $c->raw_text }}</pre></details>@else—@endif</td>
<td><a class="btn small" href="{{ url('/cases/'.$c->id) }}">開く</a></td></tr>@endforeach
</x-table>
