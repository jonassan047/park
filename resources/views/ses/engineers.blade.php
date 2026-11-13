@extends('layouts.app', ['title' => 'エンジニア', 'wide' => true])
@section('content')
<p class="right"><a class="btn small" href="{{ url('/engineers/new') }}">エンジニアを登録</a></p>
@include('partials.mail-pick', ['part' => 'top'])
<x-table :head="['選ぶ', '名前', '状態', 'スキル', '経験票', '希望', '稼働できる日', 'アカウント', '']" :count="$list->count()">
@foreach($list as $e)@php $egBase = '/engineers/'.$e->id; $back = ['back' => 'list', 'back_query' => request()->getQueryString()]; @endphp<tr><td>@if(isset($mailable[$e->id]))<input type="checkbox" class="dmpick" name="eg[]" value="{{ $e->id }}" form="dm" aria-label="{{ $e->name }} にメールを送る">@endif</td><td>{{ $e->name }}</td><td>{{ badge('engStatus', $e->status) }}@if($e->suspended_at) <span class="badge mute">停止中</span>@endif</td><td><small>{{ mb_substr($e->skills, 0, 50) }}</small></td><td>{{ $e->projs }}件</td>
<td>{{ man($e->desired_price) }}</td><td>{{ fmt_date($e->available_from) }}</td><td>{{ $e->account }}<br><small class="muted">{{ $e->mail }}</small></td><td>@if(is_provisional_state($e->account) && $e->status !== 'left')<x-post :action="$egBase.'/invite'" label="送り直し" cls="ghost small" />
@if($e->login_id)<a class="btn ghost small" href="{{ url('/people/'.$e->login_id.'/edit') }}">仮登録の内容を直す</a>
<x-post :action="'/people/'.$e->login_id.'/cancel'" label="取り消し" cls="danger small" confirm="この仮登録を取り消します。エンジニアの行も消え、元に戻せません。よろしいですか？" />@endif @endif
@if($e->status !== 'left')@if($e->suspended_at)<x-post :action="$egBase.'/resume'" label="再開" cls="small" :fields="$back" confirm="このエンジニアを再開します。ログインでき、案件の配信の候補にも出るようになります。" />
@else<x-post :action="$egBase.'/suspend'" label="停止" cls="ghost small" :fields="$back" :confirm="engineer_stop_confirm(isset($working[$e->id]), (int) ($inSel[$e->id] ?? 0))" />@endif
<x-post :action="$egBase.'/delete'" label="退会" cls="danger small" :fields="$back" confirm="このエンジニアを退会済にします。ログインできなくなり、元に戻せません（記録は残ります）。よろしいですか？" />@endif
<a class="btn small" href="{{ url($egBase) }}">詳細</a></td></tr>@endforeach
</x-table>
@include('partials.mail-pick', ['part' => 'bottom'])
@endsection
