{{-- 「SES・担当者一覧」の表示の切り替え（担当者／会社）と、SES の仮登録。$view は今の表示（people・company）。引き継ぐのは「退会済みを表示する」だけ --}}
@php $keep = show_left() ? ['left' => '1'] : []; $tab = fn ($q) => url('/admin/ses').($q ? '?'.http_build_query($q) : ''); @endphp
<p><a class="btn small {{ $view === 'people' ? '' : 'ghost' }}" href="{{ $tab($keep) }}">担当者</a>
<a class="btn small {{ $view === 'company' ? '' : 'ghost' }}" href="{{ $tab(['view' => 'company'] + $keep) }}">企業</a></p>
<p class="right"><a class="btn small" href="{{ url('/admin/ses/new') }}">SES を仮登録</a></p>
