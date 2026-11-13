{{-- エンジニアの一覧から、連絡メールを送る人を選ぶ部品。$part は 'top'（表の上）か 'bottom'（表の下）。$mailable は送れる人 --}}
@if(count($mailable))
@if($part === 'top')
{{-- 行の中に別の form があるので、チェックは表の外の form に form="dm" で結び付ける --}}
<form id="dm" method="get" action="{{ url('/mails/new') }}" onsubmit="if(!document.querySelector('input.dmpick:checked')){alert('送る相手を選んでください。');return false;}"></form>
<p><button type="button" class="btn ghost small" id="dmall">表示中の人を全部選ぶ</button> <button type="button" class="btn ghost small" id="dmnone">全部外す</button>
<span class="muted">（仮登録・停止中・退会の人は選べません）</span></p>
@else
<p><button class="btn" form="dm">選んだ人にメールを送る</button></p>
<script>
(function () {
    function setAll(on) { document.querySelectorAll('input.dmpick').forEach(function (b) { b.checked = on; }); }
    var a = document.getElementById('dmall'), z = document.getElementById('dmnone');
    if (a) a.addEventListener('click', function () { setAll(true); });
    if (z) z.addEventListener('click', function () { setAll(false); });
})();
</script>
@endif
@endif
