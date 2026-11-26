@php
    $u = auth()->user();
    $nav = [
        'admin' => [['/admin', 'ダッシュボード'], ['/admin/admins', '管理者'], ['/admin/ses', 'SES・担当者一覧'], ['/admin/engineers', 'エンジニア一覧'], ['/admin/providers', 'プロバイダー一覧'], ['/admin/cases', '案件一覧'], ['/admin/applications', '応募・選考一覧'],
            ['/admin/contracts', '契約一覧'], ['/admin/invoices', '請求一覧'], ['/threads', '案件のやり取り'], ['/admin/messages', 'エンジニアとのやり取り'], ['/mails', '連絡メール'], ['/admin/mail', 'メール'], ['/admin/inbound', '受信メール'],['/admin/reports', '分析'], ['/admin/ai', 'AI'], ['/admin/settings', '設定'], ['/manual', '使い方']],
        'ses' => [['/ses', 'ダッシュボード'], ['/ses/company', 'SES'], ['/ses/engineers', 'エンジニア一覧'], ['/ses/providers', 'プロバイダー一覧'], ['/ses/cases', '案件一覧'], ['/ses/applications', '応募・選考一覧'], ['/ses/contracts', '契約一覧'], ['/ses/invoices', '請求一覧'], ['/threads', '案件のやり取り'], ['/inbound', '返信メール'], ['/mails', '連絡メール'], ['/manual', '使い方']],
        'eg' => [['/eg', 'ホーム'], ['/eg/offers', '届いた案件'], ['/eg/applications', '応募・面談'], ['/eg/profile', 'プロフィール'], ['/eg/sheet', 'スキルシート'], ['/eg/messages', '管理者とのやり取り'], ['/mails', '連絡メール'], ['/manual', '使い方']],
    ][$u->role ?? ''] ?? [];
    // 2要素認証が済むまでは、メニューを出さない
    $passed = $u && (int) session(\App\Support\TwoFactor::SESSION_KEY) === (int) $u->id;
    // 兼任の人は、立場を選ぶまでもメニューを出さない
    $dual = $u && is_dual();
    $acting = $dual ? session('acting_role') : null;
    // 「兼任」の文は、2つの立場がどちらも使えるときだけ出す（片方が止まっていれば、その立場だけの人と同じ見え方）
    $usableNow = $dual ? \App\Services\Workflow::usableRoles(DB::table('users')->find($u->id)) : [];
    $dualBoth = $dual && count($usableNow) >= 2;
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
    // 案件のやり取りの画面は、「案件のやり取り」のメニューを選択中にする
    if (in_array($u->role ?? '', ['admin', 'ses'], true) && preg_match('#^/cases/\d+/threads/#', $path)) $current = '/threads';
    // 受信メールの詳細（/inbound/1）は、管理者は「受信メール」を選択中にする
    if (($u->role ?? '') === 'admin' && str_starts_with($path, '/inbound')) $current = '/admin/inbound';
    if (($u->role ?? '') === 'eg' && str_starts_with($path, '/engineers/')) $current = str_ends_with($path, '/sheet') ? '/eg/sheet' : (str_ends_with($path, '/messages') ? '/eg/messages' : '/eg/profile');
    if (str_starts_with($path, '/ses/')) $current = $path;
    // ベルの数：兼任の人は、いまの立場の分だけ。もう一方の立場あての未読は、帯に出す
    $unreadQ = $u ? DB::table('app_notifications')->where('user_id', $u->id)->whereNull('read_at') : null;
    $unread = $u ? ($dual ? (clone $unreadQ)->where(fn ($w) => $w->whereNull('for_role')->orWhere('for_role', $u->role))->count() : $unreadQ->count()) : 0;
    $otherUnread = ($dual && $acting) ? (clone $unreadQ)->whereNotNull('for_role')->where('for_role', '!=', $u->role)->count() : 0;
    $otherName = ['admin' => '管理者', 'ses' => '担当者', 'eg' => 'エンジニア'];
    // 担当者の立場のとき：プロバイダーからの未読の返事（自動のものは除く）。件数・最小の id・案件名を 1 回の問い合わせで取る
    $provMail = null;
    if ($u && $passed && $u->role === 'ses') {
        $provMail = \App\Services\Inbound::query($u)->where('i.sender_kind', 'provider')->whereNull('i.ses_read_at')->whereNull('i.auto')
            ->select(DB::raw('count(*) as n, min(i.id) as first_id, min(c.title) as first_title'))->first();
        if (!$provMail || !(int) $provMail->n) $provMail = null;
    }
    $flash = session('flash');
    $print = $print ?? false;
    // 区切りのページは、名前・ID・会社名などを出さない（メニューとログアウトだけ残す）
    $noPersonal = $noPersonal ?? false;
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
  <a class="logo" href="{{ url('/') }}"><img class="mark sprig" src="{{ asset('img/sprig.jpg') }}" alt=""><span class="name">{{ setting('app_name') }}<small>SES とエンジニアが集う庭</small></span></a>
  @if($u)
    <nav>@foreach($nav as [$href, $label])<a href="{{ url($href) }}" class="{{ $current === $href || (!in_array($href, $homes, true) && str_starts_with($current, $href.'/')) ? 'on' : '' }}">{{ $label }}</a>@endforeach</nav>
    <div class="me">
      @unless($noPersonal)
      <a href="{{ url('/notifications') }}" class="bell" title="通知">🔔@if($unread)<b>{{ $unread }}</b>@endif</a>
      <a href="{{ url('/account') }}"><span class="who">{{ user_label($u) }}</span><small><span class="rolepill">{{ $u->role === 'ses' ? staff_kind($u) : lbl('role', $u->role) }}</span>のアカウント</small></a>
      @endunless
      <form method="post" action="{{ url('/logout') }}" class="inline">@csrf<button class="link">ログアウト</button></form>
    </div>
  @else
    <nav></nav><div class="me"><a href="{{ url('/manual') }}">使い方</a><a href="{{ url('/apply') }}">参加のお申し込み</a><a class="btn small" href="{{ url('/login') }}">ログイン</a></div>
  @endif
</div></header>
{{-- ログイン中の立場（2要素認証が済んだ人だけ） --}}
@if($passed && !$noPersonal)
<div class="loginbar"><div class="wrap">
  @if($u->role === 'admin')管理者{{ $u->company_name ? '（'.$u->company_name.'）' : '' }}としてログインしています：{{ $u->name }}
  @elseif($u->role === 'ses'){{ staff_kind($u) }}（{{ DB::table('ses_companies')->where('id', $u->ses_id)->value('name') }}）としてログインしています
  @else エンジニアとしてログインしています
  @endif
  <form method="get" action="{{ url('/find') }}" class="findbar"><input type="text" name="code" placeholder="ID で探す（例：CA-12）" aria-label="ID で探す" maxlength="30"><button type="submit">探す</button></form>
  @if($dual)
    @php $otherNames = collect($usableNow)->reject(fn ($r) => $r === $u->role)->map(fn ($r) => $otherName[$r])->implode('・'); @endphp
    @if($dualBoth)<span class="muted">（{{ $otherNames }}も兼任。立場を変えるときは、ログアウトして入り直してください）</span>@endif
    @if($otherUnread)<a href="{{ url('/notifications') }}">{{ $otherNames }}あてのお知らせが {{ $otherUnread }} 件あります</a>@endif
  @endif
</div></div>
@endif
@endunless
<main class="wrap {{ ($wide ?? false) ? 'wide' : '' }}">
@if($provMail && !$print && !$noPersonal)
  {{-- プロバイダーから返事が届いていて、まだ開いていないとき（開くと消える） --}}
  <div class="flash warn provmail">プロバイダーからメールが届きました。確認してください（{{ $provMail->n }} 件{{ (int) $provMail->n === 1 && $provMail->first_title ? '：'.$provMail->first_title : '' }}）
    <a class="btn small" href="{{ url((int) $provMail->n === 1 ? '/inbound/'.$provMail->first_id : '/inbound') }}">確認する</a></div>
@endif
@if($flash)<div class="flash toast {{ $flash['type'] }}" role="status">{{ $flash['msg'] }}</div>@endif
@if($errors->any())<div class="flash toast bad" role="alert">@foreach($errors->all() as $err){{ $err }}<br>@endforeach</div>@endif
@unless($noTitle ?? false)<div class="ttl">@unless($print)<img class="sprig" src="{{ asset('img/sprig.jpg') }}" alt="">@endunless<h1>{{ $title }}</h1></div>@endunless
@yield('content')
</main>
@unless($print)<footer class="foot"><p><span class="mark">P</span><b>{{ setting('app_name') }}</b></p><p>SES 企業のみなさまと、エンジニアが同じ木の下で。成約したときだけ、成功報酬をいただく仕組みです。</p><p>困ったときの相談先：<a href="mailto:{{ contact_email() }}">{{ contact_email() }}</a></p><p class="muted">{{ config('park.version') }}</p></footer>@endunless
<script src="{{ asset('js/park.js') }}?v={{ filemtime(public_path('js/park.js')) }}"></script>
</body>
</html>
