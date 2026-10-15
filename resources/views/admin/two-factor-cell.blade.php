@if($u->two_factor_confirmed_at)設定済み
<x-post :action="'/admin/users/'.$u->id.'/reset-2fa'" label="解除" cls="ghost small" :confirm="$u->name.' さんの2要素認証を解除します。次のログインで設定し直してもらいます。よろしいですか？'" />
@else<span class="muted">未設定</span>@endif
