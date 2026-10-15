@extends('layouts.app', ['title' => '2要素認証の設定'])
@section('content')
<p>PARK では、ログインのときにパスワードと、スマホの<b>認証アプリ</b>に出る6桁のコードの2つを確かめます（2要素認証）。最初に1回だけ設定してください。</p>
<div class="grid2">
<x-card title="1. 認証アプリで読み取る">
<p class="muted">スマホに認証アプリ（Google Authenticator、Microsoft Authenticator など）を入れて、「＋」から次の QR コードを読み取ります。</p>
<div class="qr">{!! $qr !!}</div>
<details><summary>読み取れないとき（キーを手で入れる）</summary><p><code class="secret">{{ trim(chunk_split($secret, 4, ' ')) }}</code></p><p class="muted">種類は「時間ベース」を選びます。</p></details>
</x-card>
<x-card title="2. 出てきたコードを入れる"><form method="post" action="{{ url('/two-factor/setup') }}" class="narrow">@csrf
<x-field label="認証アプリに出ている6桁のコード" name="code" placeholder="123456" required autocomplete="one-time-code" />
<button class="btn">設定する</button></form>
<p class="muted">コードは30秒ごとに変わります。設定が済むと、スマホをなくしたとき用の「予備のコード」が出ます。</p>
<form method="post" action="{{ url('/logout') }}">@csrf<button class="link">あとで設定する（ログアウト）</button></form>
</x-card>
</div>
@endsection
