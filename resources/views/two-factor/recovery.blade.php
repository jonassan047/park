@extends('layouts.app', ['title' => '予備のコード'])
@section('content')
<x-card>
<p><b>この画面は1回だけ出ます。</b>次のコードを、紙に書くなどしてスマホとは別の場所に控えてください。スマホをなくしたとき、認証アプリのコードの代わりに使えます（1つのコードは1回だけ）。</p>
<ul class="codes">@foreach($codes as $c)<li><code>{{ $c }}</code></li>@endforeach</ul>
<p><a class="btn" href="{{ url(auth()->user()->home()) }}">控えたので、始める</a></p>
</x-card>
@endsection
