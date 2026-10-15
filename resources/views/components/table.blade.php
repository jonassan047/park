@props(['head' => [], 'empty' => 'まだありません。', 'count' => 1])
@if($count)
<div class="tablewrap"><table><thead><tr>@foreach($head as $h)<th>{{ $h }}</th>@endforeach</tr></thead><tbody>{{ $slot }}</tbody></table></div>
@else
<p class="empty">{{ $empty }}</p>
@endif
