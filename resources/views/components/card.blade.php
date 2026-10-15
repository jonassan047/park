@props(['title' => null])
<section class="card">
@if($title || isset($actions))<header><h2>{{ $title }}</h2>@isset($actions)<div class="actions">{{ $actions }}</div>@endisset</header>@endif
<div class="body">{{ $slot }}</div>
</section>
