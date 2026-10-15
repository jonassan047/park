@extends('layouts.app', ['title' => '2要素認証'])
@section('content')
<x-card><form method="post" action="{{ url('/two-factor/challenge') }}" class="narrow">@csrf
<p>認証アプリに出ている<b>6桁のコード</b>を入れてください。</p>
<x-field label="コード" name="code" placeholder="123456" required autocomplete="one-time-code" />
<button class="btn">ログイン</button>
<details><summary>スマホが手元にないとき</summary><p class="muted">設定のときに控えた「予備のコード」（例：ab12c-de34f）を、上の欄に入れてください。1つのコードは1回だけ使えます。予備のコードもないときは、運営にご連絡ください（2要素認証を解除して、設定し直してもらいます）。</p></details>
</form>
<form method="post" action="{{ url('/logout') }}">@csrf<button class="link">別のアカウントでログインする</button></form>
</x-card>
@endsection
