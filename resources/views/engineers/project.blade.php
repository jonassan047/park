@extends('layouts.app', ['title' => isset($p->id) ? '案件の編集（経験票）' : '案件の追加（経験票）'])
@section('content')
<x-card><form method="post" action="{{ url($action) }}">@csrf<div class="grid2">
<x-field label="案件名（内容がわかる名前）" name="title" :value="$p->title ?? null" required cls="span2" placeholder="例：保険会社向け 契約管理システムの刷新" />
<x-field label="業種" name="industry" :value="$p->industry ?? null" placeholder="保険、物流、EC…" />
<x-field label="役割" name="role" :value="$p->role ?? null" placeholder="PG、SE、リーダー…" />
<x-field label="開始（年月）" name="period_from" type="month" :value="$p->period_from ?? null" />
<x-field label="終了（年月、空なら現在）" name="period_to" type="month" :value="$p->period_to ?? null" />
<x-field label="チームの人数" name="team_size" type="number" min="1" :value="$p->team_size ?? null" />
<x-field label="使った技術" name="tech" :value="$p->tech ?? null" placeholder="Java、Spring Boot、Oracle、AWS（「、」区切り）" />
</div>
@php $phases = parse_list($p->phases ?? ''); @endphp
<div class="field"><span>担当した工程</span><div class="checks">@foreach(PARK_PHASES as $ph)<label><input type="checkbox" name="phases[]" value="{{ $ph }}" @checked(in_array($ph, $phases))> {{ $ph }}</label>@endforeach</div></div>
<x-field label="作業内容" name="description" type="textarea" rows="4" :value="$p->description ?? null" hint="何を・どこまで担当したか。40字以上で具体的に。" />
<x-field label="成果（できれば数字で）" name="achievements" type="textarea" rows="2" :value="$p->achievements ?? null" placeholder="例：バッチの処理時間を 4時間→40分に短縮" />
<p class="muted">事実だけを書いてください。盛った内容は、面談や配属後のトラブルの元になります。</p>
<button class="btn">保存する</button></form></x-card>
@isset($p->id)<x-post :action="'/engineers/'.$e->id.'/projects/'.$p->id.'/delete'" label="この案件を削除" cls="danger small" confirm="削除しますか？" />@endisset
@endsection
