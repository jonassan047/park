@extends('layouts.app', ['title' => '会社情報の編集：'.$s->name])
@section('content')
<x-card>@include('admin.ses-form', ['action' => '/admin/ses/'.$s->id, 'back' => '/admin/ses/'.$s->id])</x-card>
@endsection
