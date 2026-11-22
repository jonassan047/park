@if($providerMails->count())
<x-card title="プロバイダーへの返信" id="provider-mails">
<x-table :head="['エンジニア', '本人の意向', '状態', '日時', '']" :count="$providerMails->count()" empty="まだありません。エンジニアの返信が届くと、下書きが自動でできます。">
@foreach($providerMails as $p)<tr><td>{{ $p->who }}</td><td>{{ \App\Services\CaseMail::REPLIES[$p->reply] ?? $p->reply }}</td><td>{{ \App\Services\ProviderReply::STATUSES[$p->status] ?? $p->status }}</td>
<td>{{ fmt_dt($p->sent_at ?: $p->updated_at) }}</td><td><a class="btn small" href="{{ url('/provider-mails/'.$p->id) }}">開く</a></td></tr>@endforeach
</x-table>
</x-card>
@endif
