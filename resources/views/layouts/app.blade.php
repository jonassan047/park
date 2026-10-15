@php
    $u = auth()->user();
    $nav = [
        'admin' => [['/admin', 'ダッシュボード'], ['/admin/ses', 'SES'], ['/admin/engineers', 'エンジニア'], ['/admin/cases', '案件'], ['/admin/applications', '応募・選考'],
            ['/admin/contracts', '契約'], ['/admin/invoices', '請求'], ['/admin/messages', 'やり取り'], ['/admin/mail', 'メール'], ['/admin/reports', '分析'], ['/admin/settings', '設定'], ['/manual', '使い方']],
        'ses' => [['/ses', 'ダッシュボード'], ['/ses/engineers', 'エンジニア'], ['/ses/cases', '案件'], ['/ses/applications', '応募・選考'], ['/ses/contracts', '契約'], ['/ses/invoices', '請求'], ['/ses/company', '会社情報'], ['/manual', '使い方']],
        'eg' => [['/eg', 'ホーム'], ['/eg/offers', '届いた案件'], ['/eg/applications', '応募・面談'], ['/eg/profile', 'プロフィール'], ['/eg/sheet', 'スキルシート'], ['/eg/messages', 'やり取り'], ['/manual', '使い方']],
    ][$u->role ?? ''] ?? [];
    // 2要素認証が済むまでは、メニューを出さない
    if ($u && (int) session(\App\Support\TwoFactor::SESSION_KEY) !== (int) $u->id) $nav = [];
    $path = '/'.trim(request()->path(), '/');
    $unread = $u ? DB::table('app_notifications')->where('user_id', $u->id)->whereNull('read_at')->count() : 0;
    $flash = session('flash');
    $print = $print ?? false;
@endphp
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ $title }} | {{ setting('app_name') }}</title>
<link rel="stylesheet" href="{{ asset('css/park.css') }}?v={{ filemtime(public_path('css/park.css')) }}">
</head>
<body class="{{ $print ? 'print' : '' }}">
@unless($print)
<header class="top"><div class="wrap">
  <a class="logo" href="{{ url('/') }}">{{ setting('app_name') }}<small>SES とエンジニアが集う庭</small></a>
  @if($u)
    <nav>@foreach($nav as [$href, $label])<a href="{{ url($href) }}" class="{{ $path === $href || (strlen($href) > 4 && str_starts_with($path, $href.'/')) ? 'on' : '' }}">{{ $label }}</a>@endforeach</nav>
    <div class="me">
      <a href="{{ url('/notifications') }}" class="bell" title="通知">🔔@if($unread)<b>{{ $unread }}</b>@endif</a>
      <a href="{{ url('/account') }}">{{ $u->name }}<small>{{ lbl('role', $u->role) }}</small></a>
      <form method="post" action="{{ url('/logout') }}" class="inline">@csrf<button class="link">ログアウト</button></form>
    </div>
  @else
    <nav></nav><div class="me"><a href="{{ url('/manual') }}">使い方</a><a href="{{ url('/apply') }}">参加のお申し込み</a><a class="btn small" href="{{ url('/login') }}">ログイン</a></div>
  @endif
</div></header>
@endunless
<main class="wrap {{ ($wide ?? false) ? 'wide' : '' }}">
@if($flash)<div class="flash {{ $flash['type'] }}">{{ $flash['msg'] }}</div>@endif
@if($errors->any())<div class="flash bad">@foreach($errors->all() as $err){{ $err }}<br>@endforeach</div>@endif
@unless($noTitle ?? false)<h1>{{ $title }}</h1>@endunless
@yield('content')
</main>
@unless($print)<footer class="wrap foot">{{ setting('app_name') }} — 成約したときだけ、成功報酬をいただく仕組みです。</footer>@endunless
<script src="{{ asset('js/park.js') }}?v={{ filemtime(public_path('js/park.js')) }}"></script>
</body>
</html>
