@extends('layouts.app', ['title' => 'パスワードの設定'])
@section('content')
<p>{{ $u->name }} 様、PARKS へようこそ。パスワードを設定してください。</p>
<x-card><form method="post" action="{{ url('/invite/'.$token) }}" class="narrow">@csrf
<x-field label="パスワード（8文字以上）" name="password" type="password" required autocomplete="new-password" />
<x-field label="パスワード（確認）" name="password_confirmation" type="password" required autocomplete="new-password" />
@if($needConsent)
<div class="note"><b>個人情報の取り扱い</b><br>登録した経歴・スキルは、案件へのご提案（スキルシート）と面談の準備にだけ使います。
氏名はスキルシートではイニシャルで伏せることができます。外部のサービスには渡しません。事実と違う内容（盛った内容）は書きません。</div>
<x-field label="上の取り扱いに同意します" name="consent" type="checkbox" required />
@endif
<button class="btn">設定して始める</button></form></x-card>
@endsection
