@extends('layouts.app', ['title' => '管理者', 'wide' => true])
@section('content')
<p><a class="btn small" href="{{ url('/admin/admins/new') }}">管理者を登録</a></p>
<x-table :head="['名前', '企業名', 'メール', '状態', '最終ログイン', '']" :count="$admins->count()">
@foreach($admins as $u)<tr><td>{{ $u->name }}</td><td>{{ $u->company_name ?: '—' }}</td><td>{{ $u->email }}</td><td>{{ !$u->active ? '停止' : ($u->password ? '使用中' : '招待中') }}</td><td>{{ fmt_dt($u->last_login) }}</td>
<td><a href="{{ url('/admin/admins/'.$u->id) }}">修正</a>
@if($u->active && !$u->password)<form method="post" action="{{ url('/admin/admins/'.$u->id.'/reinvite') }}" class="inline">@csrf<button class="btn ghost small">招待を送り直す</button></form>@endif</td></tr>@endforeach
</x-table>
@endsection
