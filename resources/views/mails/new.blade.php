@extends('layouts.app', ['title' => '連絡メールを書く'])
@section('content')
@unless($canSend)<p class="note">いまはメールを「保存のみ」にしています。送った記録は残りますが、実際には届きません。</p>@endunless
@if($tooMany)
<x-card title="宛先"><p class="bad-text">一度に送れるのは {{ \App\Services\DirectMail::MAX_TO }} 名までです。一覧で減らしてください。</p>
<p><a class="btn ghost small" href="{{ url(auth()->user()->role === 'admin' ? '/admin/engineers' : '/ses/engineers') }}">エンジニアの一覧に戻る</a></p></x-card>
@elseif(!count($labels))
<x-card title="宛先"><p class="empty">今は送れる相手がいません。</p>
<p><a class="btn ghost small" href="{{ url('/eg') }}">ホームに戻る</a></p></x-card>
@else
<form method="post" action="{{ url('/mails') }}" onsubmit="return parkMailConfirm()">@csrf
<input type="hidden" name="send_key" value="{{ $sendKey }}">
<x-card :title="'宛先（'.count($labels).'名）'">
@if($skipped)<p class="muted">送れない {{ $skipped }} 名（停止中・退会・仮登録の人）は、宛先に入れていません。</p>@endif
@if($toEg)
<ul>@foreach($labels as $id => $label)<li>{{ $label }}<input type="hidden" name="{{ $field }}[]" value="{{ $id }}"></li>@endforeach</ul>
@else
<p class="muted">送る相手にチェックを入れてください。</p>
@foreach($labels as $id => $label)<label class="check"><input type="checkbox" name="{{ $field }}[]" value="{{ $id }}" @checked(in_array($id, $checked, true))> {{ $label }}</label>@endforeach
@endif
</x-card>
<x-card title="メールの内容">
<x-field label="件名" name="subject" required :hint="\App\Services\DirectMail::MAX_SUBJECT.' 文字までです。先頭に ['.setting('app_name').'] が付きます。'" />
<x-field label="本文" name="body" type="textarea" :rows="10" required :hint="\App\Services\DirectMail::MAX_BODY.' 文字までです。宛名（◯◯ 様）と、誰から送ったかの数行は自動で入ります。'" />
<p class="muted">@if($isAdmin)返信先は付けません。返信は、PARK の共通の差出人アドレスに届きます（あなたのアドレスは相手に見えません）。
@else返信は、あなたのメールアドレス（{{ $myEmail }}）に届きます。
@endif</p>
<p class="muted">管理者は、送った連絡メールを見ることができます。</p>
<button class="btn">送る</button> <a class="btn ghost" href="{{ url('/mails') }}">やめる</a>
</x-card>
</form>
<script>
function parkMailConfirm() {
    var n = document.querySelectorAll('input[type="checkbox"][name="to[]"]:checked, input[type="hidden"][name="eg[]"]').length;
    if (n === 0) { alert('送る相手を選んでください。'); return false; }
    return confirm(n + '名に送ります。よろしいですか？');
}
</script>
@endif
@endsection
