@extends('layouts.app', ['title' => 'SES の登録'])
@section('content')
<p class="muted">登録すると参加中になり、担当の方に招待メール（7日間有効のリンク）が届きます。</p>
<x-card>@include('admin.ses-form', ['s' => (object) [], 'action' => '/admin/ses'])</x-card>
@endsection
