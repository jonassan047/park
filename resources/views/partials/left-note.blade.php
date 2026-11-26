{{-- 表の下の、退会済みの件数の文。$hidden は隠した件数、$shown は出している件数（filter_left() の2つ目）。名前は出さない --}}
@if($hidden)
@php $q = request()->query(); unset($q['page']); @endphp
<p class="muted">退会済み {{ $hidden }} 件は表示していません。<a href="{{ url(request()->path()).'?'.http_build_query(array_merge($q, ['left' => '1'])) }}">表示する</a></p>
@elseif($shown)
<p class="muted">退会済み {{ $shown }} 件を含みます（グレーの行）。</p>
@endif
