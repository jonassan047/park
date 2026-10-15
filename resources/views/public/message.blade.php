@extends('layouts.app', ['title' => $title])
@section('content')
<p>{{ $text }}</p><p><a href="{{ url('/') }}">トップへ</a></p>
@endsection
