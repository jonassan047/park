@props(['label' => '', 'name', 'value' => null, 'type' => 'text', 'required' => false, 'options' => [], 'blank' => null, 'hint' => null,
    'placeholder' => '', 'rows' => 4, 'step' => null, 'min' => null, 'cls' => '', 'autocomplete' => null, 'autofocus' => false])
@php $v = old($name, $value); @endphp
@if($type === 'checkbox')
<label class="check"><input type="checkbox" name="{{ $name }}" value="1" @checked($v) @required($required)> {{ $label }}</label>
@else
<label class="field {{ $cls }}"><span>{{ $label }}@if($required)<i>必須</i>@endif</span>
@if($type === 'textarea')
<textarea name="{{ $name }}" rows="{{ $rows }}" placeholder="{{ $placeholder }}" @required($required)>{{ $v }}</textarea>
@elseif($type === 'select')
<select name="{{ $name }}" @required($required)>@if($blank !== null)<option value="">{{ $blank }}</option>@endif
@foreach($options as $k => $t)<option value="{{ $k }}" @selected((string) $k === (string) $v)>{{ $t }}</option>@endforeach</select>
@else
<input type="{{ $type }}" name="{{ $name }}" value="{{ $v }}" placeholder="{{ $placeholder }}" @if($step) step="{{ $step }}" @endif @if($min !== null) min="{{ $min }}" @endif @if($autocomplete) autocomplete="{{ $autocomplete }}" @endif @if($autofocus) autofocus @endif @required($required)>
@endif
@if($hint)<small>{{ $hint }}</small>@endif
</label>
@endif
