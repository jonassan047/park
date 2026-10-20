@extends('layouts.app', ['title' => '詳しいプロフィール（履歴書・業務経歴書の項目）：'.$e->name, 'wide' => true])
@section('content')
<p class="muted">ここに入れた内容から、履歴書・業務経歴書（Excel）を書き出します。案件とのマッチングにも使います。わかるところから入れてください。</p>
<form method="post" action="{{ url('/engineers/'.$e->id.'/profile') }}">@csrf
<div class="cols"><div>
<x-card title="本人の情報（履歴書）"><div class="grid2">
<x-field label="生年月日" name="birth_date" type="date" :value="$e->birth_date" />
<x-field label="性別（任意）" name="gender" type="select" :options="['' => '書かない', '男' => '男', '女' => '女']" :value="$e->gender" />
<x-field label="電話番号" name="phone" type="tel" :value="$e->phone" />
<x-field label="郵便番号" name="postal_code" :value="postal_norm($e->postal_code) ?: $e->postal_code" placeholder="123-4567" />
<x-field label="現住所" name="address" :value="$e->address" cls="span2" />
<x-field label="現住所のふりがな" name="address_kana" :value="$e->address_kana" cls="span2" />
<x-field label="最寄りの路線" name="nearest_line" :value="$e->nearest_line" placeholder="東急池上線" />
<x-field label="駅から徒歩（分）" name="walk_minutes" type="number" min="0" :value="$e->walk_minutes" />
<x-field label="最終学歴" name="education" :value="$e->education" placeholder="◯◯大学 文学部" />
<x-field label="卒業年月" name="graduated_ym" type="month" :value="$e->graduated_ym" />
</div>
<p class="muted">住所・電話・生年月日は、名前を伏せた業務経歴書には出しません。</p>
</x-card>
<x-card title="仕事の情報（業務経歴書）">
<x-field label="職務概要" name="career_summary" type="textarea" rows="5" :value="$e->career_summary" hint="これまでの経歴のまとめ。業務経歴書のいちばん上に出ます。" />
<div class="grid2">
<x-field label="希望業務" name="desired_roles" :value="$e->desired_roles" placeholder="PM・PL・SE" />
<x-field label="語学" name="english_level" :value="$e->english_level" placeholder="英語：会議・メールでのやり取り可" />
</div>
<x-field label="志望の動機・特技・アピールポイント（履歴書）" name="appeal" type="textarea" rows="4" :value="$e->appeal" />
<x-field label="本人希望（履歴書）" name="wishes" type="textarea" rows="2" :value="$e->wishes" placeholder="給料・職種・勤務時間・勤務地などの希望" />
</x-card>
</div><div>
<x-card title="学歴・職歴（履歴書の表）">
<table class="edit" id="hist"><thead><tr><th>区分</th><th>年月</th><th>内容</th><th></th></tr></thead><tbody>
@foreach($hist->concat(collect(range(1, 3))->map(fn () => (object) ['kind' => 'work', 'ym' => '', 'text' => ''])) as $h)
<tr><td><select name="h_kind[]"><option value="edu" @selected($h->kind === 'edu')>学歴</option><option value="work" @selected($h->kind === 'work')>職歴</option></select></td>
<td><input type="month" name="h_ym[]" value="{{ $h->ym }}"></td><td><input name="h_text[]" value="{{ $h->text }}"></td>
<td><button type="button" class="link" onclick="this.closest('tr').remove()">削除</button></td></tr>
@endforeach
</tbody></table>
<template id="histrow"><tr><td><select name="h_kind[]"><option value="edu">学歴</option><option value="work" selected>職歴</option></select></td><td><input type="month" name="h_ym[]"></td><td><input name="h_text[]"></td><td><button type="button" class="link" onclick="this.closest('tr').remove()">削除</button></td></tr></template>
<p><button type="button" class="btn ghost small" onclick="addRow('hist','histrow')">行を足す</button></p>
<p class="muted">上から順に並びます。学歴を先に、職歴をあとに入れてください。</p>
</x-card>
<x-card title="資格・免許">
<table class="edit" id="certs"><thead><tr><th>資格・免許</th><th>取得年月</th><th></th></tr></thead><tbody>
@foreach($certs->concat(collect(range(1, 2))->map(fn () => (object) ['name' => '', 'ym' => ''])) as $c)
<tr><td><input name="c_name[]" value="{{ $c->name }}"></td><td><input type="month" name="c_ym[]" value="{{ $c->ym }}"></td><td><button type="button" class="link" onclick="this.closest('tr').remove()">削除</button></td></tr>
@endforeach
</tbody></table>
<template id="certrow"><tr><td><input name="c_name[]"></td><td><input type="month" name="c_ym[]"></td><td><button type="button" class="link" onclick="this.closest('tr').remove()">削除</button></td></tr></template>
<p><button type="button" class="btn ghost small" onclick="addRow('certs','certrow')">行を足す</button></p>
</x-card>
</div></div>
<p><button class="btn">保存する</button> <a class="btn ghost" href="{{ url('/engineers/'.$e->id) }}">戻る</a></p>
</form>
@endsection
