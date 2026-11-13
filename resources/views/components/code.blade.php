@props(['v' => null])
{{-- 表示 ID（C-000012 など）。空なら —。書き方をここでそろえる --}}
@if($v)<span class="code">{{ $v }}</span>@else<span class="muted">—</span>@endif
