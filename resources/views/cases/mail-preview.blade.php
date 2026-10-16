@extends('layouts.app', ['title' => '送る前に確かめる：'.$c->title, 'wide' => true])
@section('content')
<form method="post" action="{{ url('/cases/'.$c->id.'/mail/send') }}">@csrf
<input type="hidden" name="scope" value="{{ $scope }}"><input type="hidden" name="mode" value="{{ $mode }}">
<p>{{ count($rows) }}名に送ります。@if($mode === 'each')一人ずつ、件名・本文（条件）を直せます。@else本文は同じで、宛名などが一人ずつ変わります。@endif 「提案」を見て、送らない人はチェックを外してください。</p>
@unless($canSend)<p class="note">いまはメールを「保存のみ」にしています。送った記録は残りますが、実際には届きません。</p>@endunless
@foreach($rows as $row)@php $e = $row['m']['engineer']; @endphp
<x-card :title="$row['name'].'（'.$row['m']['score'].'点）'">
<label class="check"><input type="checkbox" name="to[{{ $e->id }}][send]" value="1" checked> この人に送る</label>
@if($row['suggest'])<div class="note"><b>提案</b><ul>@foreach($row['suggest'] as $s)<li>{{ $s }}</li>@endforeach</ul></div>@endif
@if($mode === 'each')
<x-field label="件名" :name="'to['.$e->id.'][subject]'" :value="$row['subject']" />
<x-field label="本文" :name="'to['.$e->id.'][body]'" type="textarea" rows="14" :value="$row['body']" />
@else
<input type="hidden" name="to[{{ $e->id }}][subject]" value="{{ $row['subject'] }}"><input type="hidden" name="to[{{ $e->id }}][body]" value="{{ $row['body'] }}">
<p><b>{{ $row['subject'] }}</b></p><pre class="mailbody">{{ $row['body'] }}</pre>
@endif
</x-card>
@endforeach
<button class="btn">送る</button> <a class="btn ghost" href="{{ url('/cases/'.$c->id.'/mail?scope='.$scope) }}">戻る</a>
</form>
@endsection
