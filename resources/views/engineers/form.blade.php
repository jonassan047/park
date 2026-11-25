@php
    $isEg = auth()->user()->role === 'eg';
    // 姓・名の列がまだ空の人は、つないだ name・kana から分けて出す
    [$nameSei, $nameMei] = (($e->name_sei ?? null) || ($e->name_mei ?? null)) ? [$e->name_sei ?? null, $e->name_mei ?? null] : split_name($e->name ?? null);
    [$kanaSei, $kanaMei] = (($e->kana_sei ?? null) || ($e->kana_mei ?? null)) ? [$e->kana_sei ?? null, $e->kana_mei ?? null] : split_name($e->kana ?? null);
@endphp
@extends('layouts.app', ['title' => 'プロフィールの編集'])
@section('content')
@if(!$isEg && !($isNew ?? false) && ($e->status ?? '') !== 'left')
{{-- 紹介メールから取り込む（v0.18.0）。独立したフォーム。貼った本文は保存のフォームに入れず、どこにもしまわない --}}
<x-card title="紹介メールから取り込む" id="import-mail">
  <p>プロバイダー・ほかの SES から届いた候補者の紹介メールを、1人分だけ貼ってください。この PC（サーバー）の中だけで読み、外部のサービスには送りません。読んだ内容は、［保存する］を押すまで保存されません。</p>
  <form method="post" action="{{ url('/engineers/'.$e->id.'/import-mail') }}"
    onsubmit="if (this.elements['overwrite'].checked) return confirm('入っている欄も、メールの値で書き換えます（保存するまで変わりません）。よろしいですか？'); return true;">@csrf
    <label class="field"><span>メールの本文</span>
      <textarea name="mail_text" rows="10" maxlength="10000" placeholder="候補者紹介のメールの本文を、ここに貼り付けます（1人分・10000字まで）"></textarea></label>
    <label class="check"><input type="checkbox" name="overwrite" value="1"> 入っている欄も、メールの値で上書きする（氏名・メール・所属・状態は変えません）</label>
    <p><button class="btn">読み取る</button></p>
  </form>
</x-card>
@if(!empty($import))
<x-card title="読み取った結果">
  @foreach($import['warn'] as $w)<p class="bad-text"><b>{{ $w }}</b></p>@endforeach
  @if($import['rows'])
  <x-table :head="['項目', 'メールの値', '扱い']" :count="count($import['rows'])">
  @foreach($import['rows'] as $row)
    <tr><td>{{ $row['label'] }}</td><td>{{ $row['mail'] }}</td><td>{{ $row['status'] }}@if(!empty($row['note']))<div class="muted small">{{ $row['note'] }}</div>@endif</td></tr>
  @endforeach
  </x-table>
  @endif
  @if(!empty($import['ref']['affiliation']))
    <p class="muted">参考：メールの所属＝{{ $import['ref']['affiliation'] }}。PARK の所属（プロパー／パートナー）は変えません。変えるときは『所属』のカードで。</p>
  @endif
  @if($import['skills'])
    <h3>スキルに足す</h3>
    <p class="muted">チェックしたものが、［保存する］で今のスキルに足されます（今のスキルは消えず、年数も変わりません）。</p>
    @foreach($import['skills'] as $s)
      @if($s['registered'])<div>{{ $s['name'] }} <span class="muted">登録済み</span></div>
      @else<label class="check"><input type="checkbox" name="import_skills[]" value="{{ $s['name'] }}" form="engineer-form" checked> {{ $s['name'] }}</label>@endif
    @endforeach
  @endif
  @if($import['notes_added'])
    <h3>特記事項に足した行</h3>
    @foreach($import['notes_added'] as $line)<div>{{ $line }}</div>@endforeach
  @endif
  <h3>取り込めなかった部分</h3>
  @if($import['unread'] || $import['unknown_words'])
    @if($import['unread'])<pre>{{ implode("\n", $import['unread']) }}</pre>@endif
    @if($import['unknown_words'])<p>技術マスタに無い言葉：{{ implode('、', $import['unknown_words']) }}</p>@endif
  @else
    <p class="muted">ありません。</p>
  @endif
  <p><b>内容を確かめて、下の［保存する］を押してください。保存しないと何も変わりません。</b></p>
</x-card>
@endif
@endif
@if(!($isNew ?? false) && isset($sesName))
<x-card title="所属">
@if($isEg)
  <p>所属：{{ $sesName }}</p>
  <p class="muted">所属は、ご自身では変えられません。変えるときは、所属の会社か管理者にご相談ください。</p>
@else
  <p>今の所属：{{ $sesName }}</p>
  @if($affBlocker)
    <p class="muted">{{ $affBlocker }}</p>
  @elseif(!$affTargets)
    <p class="muted">移せる参加中の SES がありません。</p>
  @else
    {{-- 確認の文は、選んだ会社の名前を入れるため、ここで作る（JavaScript が無いときは確認なしで送られる。送る側の守りは Affiliation にある） --}}
    <form method="post" action="{{ url('/engineers/'.$e->id.'/affiliation') }}"
      onsubmit="var s = this.elements['ses_id']; if (!s.value) { alert('変更先を選んでください。'); return false; } return confirm('所属を「' + @js($sesName) + '」から「' + s.options[s.selectedIndex].text + '」に変えます。' + @js($sesName) + ' の SES・担当者からは、この方（スキル・経歴・書類を含む）が見えなくなります。よろしいですか？')">@csrf
      <div class="field"><label>変更先</label>
        <select name="ses_id">
          <option value="">変更先を選んでください</option>
          @foreach($affTargets as $sid => $sname)<option value="{{ $sid }}">{{ $sname }}</option>@endforeach
        </select></div>
      <button class="btn">所属を変える</button>
    </form>
    <p class="muted">変えると、今の会社の SES・担当者からは見えなくなります。過去の応募・契約・請求の記録は、今の会社のまま残ります。</p>
    @if(auth()->user()->role === 'ses')<p class="muted">移す先の会社にも、あらかじめお伝えください。</p>@endif
  @endif
@endif
</x-card>
@if(!$isEg && $e->status !== 'left')
<x-card title="担当者を付け替える" id="staff">
  <p>今の担当者：{{ $staffNow ? $staffNow->name : '—' }}</p>
  <p class="muted">同じ会社の担当者の中で付け替えられます。</p>
  <form method="post" action="{{ url('/engineers/'.$e->id.'/staff') }}" onsubmit="return confirm('担当者を付け替えます。新しい担当者と、前の担当者・ご本人にお知らせが届きます。')">@csrf
    <div class="field"><label>担当者</label>
      <select name="staff_user_id">
        @foreach($staffCandidates as $c)<option value="{{ $c->id }}" @selected($staffNow && (int) $staffNow->id === (int) $c->id)>{{ $c->name }}</option>@endforeach
      </select></div>
    <button class="btn">付け替える</button>
  </form>
  <h3>付け替えの記録</h3>
  @php $nameOf = DB::table('users')->whereIn('id', $staffLogs->flatMap(fn ($l) => [$l->from_user_id, $l->to_user_id, $l->changed_by])->filter()->unique()->all())->pluck('name', 'id'); @endphp
  <x-table :head="['日時', '前', '後', 'した人', '理由']" :count="$staffLogs->count()">
  @foreach($staffLogs as $l)
    <tr><td>{{ fmt_dt($l->created_at) }}</td><td>{{ $l->from_user_id ? ($nameOf[$l->from_user_id] ?? '—') : '—' }}</td><td>{{ $nameOf[$l->to_user_id] ?? '—' }}</td><td>{{ $l->changed_by ? ($nameOf[$l->changed_by] ?? '—') : '—' }}</td><td>{{ \App\Services\EgStaff::REASONS[$l->reason] ?? $l->reason }}</td></tr>
  @endforeach
  </x-table>
</x-card>
@endif
@endif
<x-card><form id="engineer-form" method="post" action="{{ url($action) }}">@csrf<div class="grid2">
<div class="pair">
<x-field label="氏名（姓）" name="name_sei" :value="$nameSei" required />
<x-field label="氏名（名）" name="name_mei" :value="$nameMei" required />
</div>
<div class="pair">
<x-field label="ふりがな（姓）" name="kana_sei" :value="$kanaSei" placeholder="やまだ" required />
<x-field label="ふりがな（名）" name="kana_mei" :value="$kanaMei" placeholder="たろう" required hint="スキルシートのイニシャル（T.Y）に使います" />
</div>
<x-field label="メールで呼ぶ名前（愛称など）" name="nickname" :value="$e->nickname ?? null" placeholder="たろうさん" hint="案件の案内メールの宛名に使います。空なら氏名" />
@unless($isEg)<x-field label="メールアドレス" name="email" type="email" :value="$e->email ?? null" />@endunless
<x-field label="生まれた年" name="birth_year" type="number" :value="$e->birth_year ?? null" placeholder="1985" />
<x-field label="最寄り駅" name="nearest_station" :value="$e->nearest_station ?? null" />
<x-field label="IT の経験年数" name="years_exp" type="number" step="0.5" min="0" max="60":value="num_disp($e->years_exp ?? null)" />
<x-field label="希望単価（万円／月）" name="desired_price" type="number" step="0.5" :value="num_disp($e->desired_price ?? null)" />
<x-field label="下限の単価（万円／月）" name="min_price" type="number" step="0.5" :value="num_disp($e->min_price ?? null)" hint="マッチングにだけ使い、外には出しません" />
<x-field label="稼働できる日" name="available_from" type="date" :value="$e->available_from ?? null" />
<x-field label="希望の働き方" name="work_style" type="select" :options="PARK_LABELS['workStyle']" :value="$e->work_style ?? 'any'" />
<x-field label="週の稼働日数" name="days_per_week" type="number" min="1" :value="$e->days_per_week ?? 5" />
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
<x-field label="特記事項" name="special_notes" type="textarea" rows="4" :value="$e->special_notes ?? null" hint="ご家庭の事情など、案件を選ぶときに気をつけること。ご本人・貴社の SES・担当者・管理者だけが見られ、ほかの会社・案件のメール・スキルシートには出ません。" />
{{-- 保存が入力の誤りで戻ったときも、取り込んだ詳しいプロフィールの欄とスキルのチェックが消えないよう、前の入力（old）からも出す --}}
@php
    $impCols = array_values(array_filter(\App\Services\EngineerMailImport::PROFILE_COLS, fn ($c) => array_key_exists($c, $import['fill'] ?? []) || old($c) !== null));
    $oldSkills = (!$isEg && empty($import)) ? array_values(array_filter((array) old('import_skills'), 'is_string')) : [];
@endphp
@if($oldSkills)
<h3>スキルに足す（前の取り込み）</h3>
<p class="muted">保存できなかった前の入力です。チェックしたものが、［保存する］で今のスキルに足されます。</p>
@foreach($oldSkills as $sk)<label class="check"><input type="checkbox" name="import_skills[]" value="{{ $sk }}" checked> {{ $sk }}</label>@endforeach
@endif
@if($impCols)
<h3>メールから読んだ項目（詳しいプロフィール）</h3>
<p class="muted">読み取った値です。［保存する］で保存されます。あとから「詳しいプロフィール」でも直せます。</p>
<div class="grid2">
@foreach($impCols as $col)
  @if(in_array($col, ['career_summary', 'appeal'], true))<x-field :label="\App\Services\EngineerMailImport::LABELS[$col]" :name="$col" type="textarea" rows="5" :value="$e->$col ?? null" />
  @elseif($col === 'walk_minutes')<x-field :label="\App\Services\EngineerMailImport::LABELS[$col]" :name="$col" type="number" :value="$e->$col ?? null" />
  @else<x-field :label="\App\Services\EngineerMailImport::LABELS[$col]" :name="$col" :value="$e->$col ?? null" />@endif
@endforeach
</div>
@endif
<a class="btn ghost" href="{{ url('/engineers/'.$e->id) }}">戻る</a> <button class="btn">保存する</button></form></x-card>
@endsection
