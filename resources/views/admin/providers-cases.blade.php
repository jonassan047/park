@extends('layouts.app', ['title' => 'プロバイダー', 'wide' => true])
@section('content')
@include('partials.provider-tabs', ['on' => 'cases'])
<p class="muted">{{ $base === '/admin/providers' ? '全社の、プロバイダーから受けた案件の原文と金額です（読むだけ）。' : '自社が受注 SES の案件の、プロバイダーからの原文と金額です。パートナーの会社の人には見えません。' }}</p>
<form method="get" class="filters">
<x-field name="provider" type="select" :options="$providers" blank="すべてのプロバイダー" :value="$provider" />
<x-field name="status" type="select" :options="PARK_LABELS['caseStatus']" blank="すべての状態" :value="$st" />
<x-field name="q" :value="$q" placeholder="案件名・ID で探す" /><button class="btn ghost small">絞り込む</button></form>
@include('partials.provider-cases-table', ['list' => $list, 'withSes' => $base === '/admin/providers'])
@include('partials.pager', ['pager' => $pager])
@endsection
