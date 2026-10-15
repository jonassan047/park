@props(['n'])
<span class="score"><span style="width:{{ max(0, min(100, (int) $n)) }}%"></span><b>{{ $n }}</b></span>
