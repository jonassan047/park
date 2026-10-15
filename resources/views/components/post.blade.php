@props(['action', 'label', 'fields' => [], 'cls' => '', 'confirm' => null])
<form method="post" action="{{ url($action) }}" class="inline" @if($confirm) onsubmit="return confirm(@js($confirm))" @endif>@csrf
@foreach($fields as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
<button class="btn {{ $cls }}">{{ $label }}</button></form>
