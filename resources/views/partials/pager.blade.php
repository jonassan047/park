{{-- ページ送り（1ページ 20 件）。$pager は page_slice() の2つ目。絞り込みの条件はそのまま引き継ぐ --}}
@if($pager['last'] > 1)
@php $q = request()->query(); $href = fn ($p) => url(request()->path()).'?'.http_build_query(array_merge($q, ['page' => $p])); @endphp
<p class="pager">全 {{ $pager['total'] }} 件　
@if($pager['page'] > 1)<a class="btn ghost small" href="{{ $href($pager['page'] - 1) }}">前へ</a>@endif
@for($p = 1; $p <= $pager['last']; $p++)@if($p === $pager['page'])<span class="btn small">{{ $p }}</span>@else<a class="btn ghost small" href="{{ $href($p) }}">{{ $p }}</a>@endif @endfor
@if($pager['page'] < $pager['last'])<a class="btn ghost small" href="{{ $href($pager['page'] + 1) }}">次へ</a>@endif</p>
@endif
