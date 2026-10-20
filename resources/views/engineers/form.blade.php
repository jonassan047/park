@php
    $isEg = auth()->user()->role === 'eg';
    // 姓・名の列がまだ空の人は、つないだ name・kana から分けて出す
    [$nameSei, $nameMei] = (($e->name_sei ?? null) || ($e->name_mei ?? null)) ? [$e->name_sei ?? null, $e->name_mei ?? null] : split_name($e->name ?? null);
    [$kanaSei, $kanaMei] = (($e->kana_sei ?? null) || ($e->kana_mei ?? null)) ? [$e->kana_sei ?? null, $e->kana_mei ?? null] : split_name($e->kana ?? null);
@endphp
@extends('layouts.app', ['title' => $isNew ? 'エンジニアの登録' : 'プロフィールの編集'])
@section('content')
@if($isNew)<p class="muted">基本情報を登録します。登録すると、ご本人に招待メールが届き、経験票（これまでの案件）をご本人が入れられます。</p>@endif
<x-card><form method="post" action="{{ url($action) }}">@csrf<div class="grid2">
@if($sesFixed)<p class="span2">所属：{{ $sesFixed['name'] }}</p><input type="hidden" name="ses_id" value="{{ $sesFixed['id'] }}">@endif
<div class="pair">
<x-field label="氏名（姓）" name="name_sei" :value="$nameSei" required />
<x-field label="氏名（名）" name="name_mei" :value="$nameMei" :required="$isNew" />
</div>
<div class="pair">
<x-field label="ふりがな（姓）" name="kana_sei" :value="$kanaSei" placeholder="やまだ" />
<x-field label="ふりがな（名）" name="kana_mei" :value="$kanaMei" placeholder="たろう" hint="スキルシートのイニシャル（T.Y）に使います" />
</div>
<x-field label="メールで呼ぶ名前（愛称など）" name="nickname" :value="$e->nickname ?? null" placeholder="たろうさん" hint="案件の案内メールの宛名に使います。空なら氏名" />
@unless($isEg)<x-field label="メールアドレス" name="email" type="email" :value="$e->email ?? null" :required="$isNew" :hint="$isNew ? 'ご本人に招待メールが届きます' : null" />@endunless
<x-field label="生まれた年" name="birth_year" type="number" :value="$e->birth_year ?? null" placeholder="1985" />
<x-field label="最寄り駅" name="nearest_station" :value="$e->nearest_station ?? null" />
<x-field label="IT の経験年数" name="years_exp" type="number" step="0.5" :value="num_disp($e->years_exp ?? null)" />
<x-field label="希望単価（万円／月）" name="desired_price" type="number" step="0.5" :value="num_disp($e->desired_price ?? null)" />
<x-field label="下限の単価（万円／月）" name="min_price" type="number" step="0.5" :value="num_disp($e->min_price ?? null)" hint="マッチングにだけ使い、外には出しません" />
<x-field label="稼働できる日" name="available_from" type="date" :value="$e->available_from ?? null" />
<x-field label="希望の働き方" name="work_style" type="select" :options="PARK_LABELS['workStyle']" :value="$e->work_style ?? 'any'" />
<x-field label="週の稼働日数" name="days_per_week" type="number" min="1" :value="$e->days_per_week ?? 5" />
@if($isNew)<x-field label="郵便番号" name="postal_code" placeholder="123-4567" /><x-field label="現住所" name="address" hint="履歴書に使います。見られるのは、ご本人・所属の SES・管理者だけです。" />@endif
@if($isEg)
  @php $st = $e->status ?? 'available'; $opts = ['available' => '案件を探している', 'unavailable' => '今は休止'] + (in_array($st, ['working', 'proposing']) ? [$st => lbl('engStatus', $st)] : []); @endphp
  <x-field label="今の状態" name="status" type="select" :options="$opts" :value="$st" />
@elseif(($e->status ?? '') === 'left')
  <div class="field"><label>状態</label><p>退会済（退会は、詳細の画面の「退会」で行います。ここからは変えられません）</p></div>
@else
  <x-field label="状態" name="status" type="select" :options="array_diff_key(PARK_LABELS['engStatus'], ['left' => 1])" :value="$e->status ?? 'available'" />
@endif
</div>
<x-field label="強み（ひとこと）" name="strengths" :value="$e->strengths ?? null" placeholder="例：業務系 Java の設計〜テストを1人で回せる" />
<x-field label="自己PR" name="summary" type="textarea" rows="5" :value="$e->summary ?? null" hint="得意分野と、どんな現場で力を出せるか。事実だけを書きます。" />
<button class="btn">{{ $isNew ? '登録して招待メールを送る' : '保存する' }}</button> <a class="btn ghost" href="{{ $isNew ? ($sesFixed ? url('/admin/ses/'.$sesFixed['id']) : list_url('engineers')) : url('/engineers/'.$e->id) }}">戻る</a></form></x-card>
@endsection
