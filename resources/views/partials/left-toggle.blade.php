{{-- 退会済みを表示しない／表示するの選択（v0.20.0。既定は「表示しない」）。ページは1に戻る。
     今の表示・絞り込み（view・order・q・status・kind）は引き継ぐ。$orders を渡したときだけ、並びの選択も出す（v0.21.0） --}}
<form method="get" class="filters">
@foreach(['view', 'order', 'q', 'status', 'kind'] as $k)@if(query_str($k) !== '' && !($k === 'order' && !empty($orders)))<input type="hidden" name="{{ $k }}" value="{{ query_str($k) }}">@endif @endforeach
@if(!empty($orders))<x-field name="order" type="select" :options="$orders" :value="list_order(array_keys($orders))" />@endif
<x-field name="left" type="select" :options="['' => '退会済みを表示しない', '1' => '退会済みを表示する']" :value="show_left() ? '1' : ''" /><button class="btn ghost small">絞り込む</button></form>
