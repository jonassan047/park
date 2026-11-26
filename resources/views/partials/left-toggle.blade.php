{{-- 退会済みを表示しない／表示するの選択（v0.20.0。既定は「表示しない」）。ページは1に戻る --}}
<form method="get" class="filters"><x-field name="left" type="select" :options="['' => '退会済みを表示しない', '1' => '退会済みを表示する']" :value="show_left() ? '1' : ''" /><button class="btn ghost small">絞り込む</button></form>
