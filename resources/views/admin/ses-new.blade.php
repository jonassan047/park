@extends('layouts.app', ['title' => 'SES の登録'])
@section('content')
<p class="muted">登録すると「申込中」になります。詳細の画面で承認すると、担当の方に招待メールが届きます。</p>
<x-card>@include('admin.ses-form', ['s' => (object) [], 'action' => '/admin/ses'])</x-card>
@endsection
