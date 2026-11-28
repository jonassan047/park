@extends('layouts.app', ['title' => $title, 'ttlBack' => false])
@section('content')
<p>{{ $text }}</p><p><a href="{{ url('/') }}">トップへ</a></p>
@endsection
