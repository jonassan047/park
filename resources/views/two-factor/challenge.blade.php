@extends('layouts.app', ['title' => '確認コード'])
@section('content')
<x-card><form method="post" action="{{ url('/two-factor/challenge') }}" class="narrow">@csrf
<p><b>{{ $email }}</b> に、6桁の<b>確認コード</b>をメールで送りました。メールを開いて、コードを入れてください（10分間有効）。</p>
@if($devCode)<p class="muted">（手元で試しているのでメールは送っていません。コード：<b>{{ $devCode }}</b>）</p>@endif
<x-field label="確認コード" name="code" placeholder="123456" required autocomplete="one-time-code" />
<button class="btn">ログイン</button>
</form>
<form method="post" action="{{ url('/two-factor/resend') }}">@csrf<button class="btn ghost small">コードを送り直す</button></form>
<details><summary>メールが届かないとき</summary><p class="muted">迷惑メールのフォルダも見てください。それでも無いときは、上の「コードを送り直す」を押してください。登録のメールアドレスが使えなくなったときは、運営にご連絡ください。</p></details>
<form method="post" action="{{ url('/logout') }}">@csrf<button class="link">別のアカウントでログインする</button></form>
</x-card>
@endsection
