@php $isEg = auth()->user()->role === 'eg'; @endphp
@extends('layouts.app', ['title' => $isNew ? 'エンジニアの登録' : 'プロフィールの編集'])
@section('content')
@if($isNew)<p class="muted">基本情報を登録します。メールアドレスを入れると、ご本人に招待が届き、経験票（これまでの案件）をご本人が入れられます。</p>@endif
<x-card><form method="post" action="{{ url($action) }}">@csrf<div class="grid2">
@if($sesList)<x-field label="所属 SES" name="ses_id" type="select" :options="$sesList" :value="$e->ses_id ?? null" required />@endif
<x-field label="氏名" name="name" :value="$e->name ?? null" required />
<x-field label="ふりがな" name="kana" :value="$e->kana ?? null" placeholder="やまだ たろう" hint="スキルシートのイニシャル（T.Y）に使います" />
@unless($isEg)<x-field label="メールアドレス" name="email" type="email" :value="$e->email ?? null" :hint="$isNew ? '入れると、ご本人に招待メールが届きます' : null" />@endunless
<x-field label="生まれた年" name="birth_year" type="number" :value="$e->birth_year ?? null" placeholder="1985" />
<x-field label="最寄り駅" name="nearest_station" :value="$e->nearest_station ?? null" />
<x-field label="IT の経験年数" name="years_exp" type="number" step="0.5" :value="num_disp($e->years_exp ?? null)" />
<x-field label="希望単価（万円／月）" name="desired_price" type="number" step="0.5" :value="num_disp($e->desired_price ?? null)" />
<x-field label="下限の単価（万円／月）" name="min_price" type="number" step="0.5" :value="num_disp($e->min_price ?? null)" hint="マッチングにだけ使い、外には出しません" />
<x-field label="稼働できる日" name="available_from" type="date" :value="$e->available_from ?? null" />
<x-field label="希望の働き方" name="work_style" type="select" :options="PARK_LABELS['workStyle']" :value="$e->work_style ?? 'any'" />
<x-field label="週の稼働日数" name="days_per_week" type="number" min="1" :value="$e->days_per_week ?? 5" />
@if($isEg)
  @php $st = $e->status ?? 'available'; $opts = ['available' => '案件を探している', 'unavailable' => '今は休止'] + (in_array($st, ['working', 'proposing']) ? [$st => lbl('engStatus', $st)] : []); @endphp
  <x-field label="今の状態" name="status" type="select" :options="$opts" :value="$st" />
@else
  <x-field label="状態" name="status" type="select" :options="PARK_LABELS['engStatus']" :value="$e->status ?? 'available'" />
@endif
</div>
<x-field label="強み（ひとこと）" name="strengths" :value="$e->strengths ?? null" placeholder="例：業務系 Java の設計〜テストを1人で回せる" />
<x-field label="自己PR" name="summary" type="textarea" rows="5" :value="$e->summary ?? null" hint="得意分野と、どんな現場で力を出せるか。事実だけを書きます。" />
<button class="btn">{{ $isNew ? '登録する' : '保存する' }}</button></form></x-card>
@endsection
