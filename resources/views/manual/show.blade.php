@extends('layouts.app', ['title' => $title, 'wide' => true])
@section('content')
<div class="manualwrap">
<nav class="toc noprint"><b>目次</b><ol>@foreach($toc as [$id, $t])<li><a href="#{{ $id }}">{{ $t }}</a></li>@endforeach</ol>
<p><button class="btn ghost small" onclick="print()">印刷・PDF に保存</button></p>
@if(auth()->user()?->role === 'admin')<p class="small"><a href="{{ url('/manual') }}">ほかのマニュアル</a></p>@endif</nav>
<article class="manual">{!! $html !!}</article>
</div>
@endsection
