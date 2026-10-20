@php
    $u = auth()->user();
    $nav = [
        'admin' => [['/admin', 'ダッシュボード'], ['/admin/admins', '管理者'], ['/admin/ses', 'SES'], ['/admin/engineers', 'エンジニア'], ['/admin/cases', '案件'], ['/admin/applications', '応募・選考'],
            ['/admin/contracts', '契約'], ['/admin/invoices', '請求'], ['/admin/messages', 'やり取り'], ['/admin/mail', 'メール'], ['/admin/reports', '分析'], ['/admin/ai', 'AI'], ['/admin/settings', '設定'], ['/manual', '使い方']],
        'ses' => [['/ses', 'ダッシュボード'], ['/ses/company', 'SES'], ['/ses/engineers', 'エンジニア'], ['/ses/cases', '案件'], ['/ses/applications', '応募・選考'], ['/ses/contracts', '契約'], ['/ses/invoices', '請求'], ['/manual', '使い方']],
        'eg' => [['/eg', 'ホーム'], ['/eg/offers', '届いた案件'], ['/eg/applications', '応募・面談'], ['/eg/profile', 'プロフィール'], ['/eg/sheet', 'スキルシート'], ['/eg/messages', 'やり取り'], ['/manual', '使い方']],
    ][$u->role ?? ''] ?? [];
    // 2要素認証が済むまでは、メニューを出さない
    $passed = $u && (int) session(\App\Support\TwoFactor::SESSION_KEY) === (int) $u->id;
    // 兼任の人は、立場を選ぶまでもメニューを出さない
    $dual = $u && is_dual();
    $acting = $dual ? session('acting_role') : null;
    // 「兼任」の文は、2つの立場がどちらも使えるときだけ出す（片方が止まっていれば、その立場だけの人と同じ見え方）
    $dualBoth = $dual && count(\App\Services\Workflow::usableRoles(DB::table('users')->find($u->id))) === 2;
    if ($dual && !$acting) $passed = false;
    if ($u && !$passed) $nav = [];
    $path = '/'.trim(request()->path(), '/');
    // ダッシュボード（/admin・/ses・/eg）は、配下の画面を開いても「選択中」にしない（そのページ自身のときだけ）
    $homes = ['/admin', '/ses', '/eg'];
    // 共通の詳細画面（/cases/1 など）は、その種類のメニューを「選択中」にする
    $alias = [
        'admin' => ['/cases' => '/admin/cases', '/engineers' => '/admin/engineers', '/applications' => '/admin/applications', '/interviews' => '/admin/applications', '/contracts' => '/admin/contracts', '/invoices' => '/admin/invoices'],
        'ses' => ['/cases' => '/ses/cases', '/engineers' => '/ses/engineers', '/applications' => '/ses/applications', '/interviews' => '/ses/applications', '/contracts' => '/ses/contracts', '/invoices' => '/ses/invoices'],
        'eg' => ['/cases' => '/eg/offers', '/applications' => '/eg/applications', '/interviews' => '/eg/applications', '/contracts' => '/eg/applications'],
    ][$u->role ?? ''] ?? [];
    $current = $path;
    foreach ($alias as $from => $to) if (str_starts_with($path, $from.'/')) { $current = $to; break; }
    if (($u->role ?? '') === 'eg' && str_starts_with($path, '/engineers/')) $current = str_ends_with($path, '/sheet') ? '/eg/sheet' : (str_ends_with($path, '/messages') ? '/eg/messages' : '/eg/profile');
    if (str_starts_with($path, '/ses/')) $current = $path;
    // ベルの数：兼任の人は、いまの立場の分だけ。もう一方の立場あての未読は、帯に出す
    $unreadQ = $u ? DB::table('app_notifications')->where('user_id', $u->id)->whereNull('read_at') : null;
    $unread = $u ? ($dual ? (clone $unreadQ)->where(fn ($w) => $w->whereNull('for_role')->orWhere('for_role', $u->role))->count() : $unreadQ->count()) : 0;
    $otherUnread = ($dual && $acting) ? (clone $unreadQ)->whereNotNull('for_role')->where('for_role', '!=', $u->role)->count() : 0;
    $otherName = ['admin' => '管理者', 'ses' => 'SES 担当者', 'eg' => 'エンジニア'];
    $flash = session('flash');
    $print = $print ?? false;
@endphp
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ $title }} | {{ setting('app_name') }}</title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=M+PLUS+Rounded+1c:wght@400;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="{{ asset('css/park.css') }}?v={{ filemtime(public_path('css/park.css')) }}">
</head>
<body class="{{ $print ? 'print' : '' }} role-{{ $u->role ?? 'guest' }}">
@unless($print)
<header class="top"><div class="wrap">
  <a class="logo" href="{{ url('/') }}"><span class="mark">🌳</span><span class="name">{{ setting('app_name') }}<small>SES とエンジニアが集う庭</small></span></a>
  @if($u)
    <nav>@foreach($nav as [$href, $label])<a href="{{ url($href) }}" class="{{ $current === $href || (!in_array($href, $homes, true) && str_starts_with($current, $href.'/')) ? 'on' : '' }}">{{ $label }}</a>@endforeach</nav>
    <div class="me">
      <a href="{{ url('/notifications') }}" class="bell" title="通知">🔔@if($unread)<b>{{ $unread }}</b>@endif</a>
      <a href="{{ url('/account') }}"><span class="who">{{ user_label($u) }}</span><small><span class="rolepill">{{ lbl('role', $u->role) }}</span>のアカウント</small></a>
      <form method="post" action="{{ url('/logout') }}" class="inline">@csrf<button class="link">ログアウト</button></form>
    </div>
  @else
    <nav></nav><div class="me"><a href="{{ url('/manual') }}">使い方</a><a href="{{ url('/apply') }}">参加のお申し込み</a><a class="btn small" href="{{ url('/login') }}">ログイン</a></div>
  @endif
</div></header>
{{-- ログイン中の立場（2要素認証が済んだ人だけ） --}}
@if($passed)
<div class="loginbar"><div class="wrap">
  @if($u->role === 'admin')管理者{{ $u->company_name ? '（'.$u->company_name.'）' : '' }}としてログインしています：{{ $u->name }}
  @elseif($u->role === 'ses')SES（{{ DB::table('ses_companies')->where('id', $u->ses_id)->value('name') }}）のご担当者としてログインしています
  @else エンジニアとしてログインしています
  @endif
  @if($dual)
    @if($dualBoth)<span class="muted">（{{ $u->role === 'eg' ? $otherName[base_role()] : 'エンジニア' }}も兼任。立場を変えるときは、ログアウトして入り直してください）</span>@endif
    @if($otherUnread)<a href="{{ url('/notifications') }}">{{ $otherName[$u->role === 'eg' ? base_role() : 'eg'] }}あてのお知らせが {{ $otherUnread }} 件あります</a>@endif
  @endif
</div></div>
@endif
@endunless
<main class="wrap {{ ($wide ?? false) ? 'wide' : '' }}">
@if($flash)<div class="flash {{ $flash['type'] }}">{{ $flash['msg'] }}</div>@endif
@if($errors->any())<div class="flash bad">@foreach($errors->all() as $err){{ $err }}<br>@endforeach</div>@endif
@unless($noTitle ?? false)<h1>{{ $title }}</h1>@endunless
@yield('content')
</main>
@unless($print)<footer class="foot"><p><span class="mark">P</span><b>{{ setting('app_name') }}</b></p><p>SES 企業のみなさまと、エンジニアが同じ木の下で。成約したときだけ、成功報酬をいただく仕組みです。</p><p>困ったときの相談先：<a href="mailto:{{ contact_email() }}">{{ contact_email() }}</a></p></footer>@endunless
<script src="{{ asset('js/park.js') }}?v={{ filemtime(public_path('js/park.js')) }}"></script>
</body>
</html>
