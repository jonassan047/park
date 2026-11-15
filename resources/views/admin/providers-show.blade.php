@extends('layouts.app', ['title' => $p->name])
@section('content')
<x-card title="プロバイダー"><x-slot:actions>
<x-post :action="'/admin/providers/'.$p->id.'/delete'" label="削除" cls="danger small" confirm="このプロバイダーを削除します。元に戻せません。よろしいですか？" />
<a class="btn small" href="{{ url('/admin/providers/'.$p->id.'/edit') }}">編集</a>
</x-slot:actions><dl class="dl">
<dt>ID</dt><dd><x-code :v="$p->code" /></dd><dt>企業名</dt><dd>{{ $p->name }}</dd><dt>氏名</dt><dd>{{ $p->sei }} {{ $p->mei }}</dd>
<dt>メール</dt><dd>{{ $p->email }}</dd><dt>電話</dt><dd>{{ $p->phone ?: '—' }}</dd><dt>所在地</dt><dd>{{ postal_address($p->postal_code, $p->address) }}</dd>
<dt>人数</dt><dd>{{ $p->employees ?? '—' }}</dd><dt>得意な分野</dt><dd>{{ $p->strengths ?: '—' }}</dd>
<dt>登録日</dt><dd>{{ fmt_date($p->created_at) }}</dd><dt>メモ</dt><dd>{!! nl2br(e($p->notes)) ?: '—' !!}</dd>
</dl>
<p><a class="btn ghost" href="{{ url('/admin/providers') }}">プロバイダーの一覧に戻る</a></p>
</x-card>
@endsection
