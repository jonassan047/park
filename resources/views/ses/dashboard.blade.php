@extends('layouts.app', ['title' => id_title($s->code, $s->name).' さま', 'wide' => true])
@section('content')
@include('partials.role-hero', ['kind' => 'ses'])
<x-todo />
@if(!$engs->count())<x-card title="エンジニアを登録しましょう"><p>貴社のエンジニアを登録すると、合う案件が届くようになります。</p><a class="btn small" href="{{ url('/engineers/new') }}">エンジニアを登録する</a></x-card>@endif
{{-- 構成は TEZZO の SESダッシュボード.xlsx（2026-11-27）：上に企業内・グループ内の数、下に成約に近い順の5つの表 --}}
<x-card title="エンジニアと成約の状況"><x-table :head="['', '所属エンジニア', '稼働中', '提案可', '選考中', '直近実績（今月）', '年度累計（4月から）']">
@foreach($rows as $label => $n)<tr><th>{{ $label }}</th>@foreach($n as $i => $v)<td>{{ $v }}{{ $i < 3 ? '名' : '件' }}</td>@endforeach</tr>@endforeach
</x-table><p class="muted">グループ内は、PARK に参加している SES 企業全体の合計です。実績は成約した件数です。</p></x-card>
@php $head = ['案件', 'エンジニア', '状態', '更新', '']; @endphp
@foreach([['合否決定待ちの案件', $decide, '面談が済み、プロバイダーの合否を待っています。'],
    ['面談予定の案件', $meet, '面談の日程を調整中です。3日以内に動きがあったものに「新着」が付きます。'],
    ['プロバイダーの回答待ちの案件', $pv, '提案（書類）を出し、プロバイダーの回答を待っています。']] as [$title, $list, $note])
<x-card :title="$title.'（'.$list->count().'件）'"><p class="muted">{{ $note }}</p><x-table :head="$head" :count="$list->count()" empty="ありません。">
@foreach($list as $a)<tr><td><x-code :v="$a->case_code" />　{{ $a->title }}</td><td><x-code :v="$a->eg_code" />　{{ $a->eg_name }}</td><td>{{ badge('appStatus', $a->status) }}@if($a->status === 'interview' && $a->updated_at && strtotime($a->updated_at) >= strtotime('-3 days')) <span class="badge bad">新着</span>@endif</td><td>{{ fmt_date($a->updated_at) }}</td><td><a class="btn small" href="{{ url('/applications/'.$a->id) }}">開く</a></td></tr>@endforeach
</x-table></x-card>
@endforeach
<x-card :title="'エンジニアの回答待ちの案件（'.$offers->count().'件）'"><p class="muted">案件を配信し、ご本人の返事を待っています。貴社から一声かけていただくと、応募が早くなります。</p>
<x-table :head="$head" :count="$offers->count()" empty="ありません。">
@foreach($offers as $o)<tr><td><x-code :v="$o->case_code" />　{{ $o->title }}</td><td><x-code :v="$o->eg_code" />　{{ $o->eg_name }}</td><td>{{ badge('offerStatus', $o->status) }}</td><td>{{ fmt_date($o->sent_at) }}</td><td><a class="btn small" href="{{ url('/cases/'.$o->case_id) }}">開く</a></td></tr>@endforeach
</x-table></x-card>
<x-card :title="'提案を作成中の案件（'.$making->count().'件）'"><p class="muted">エンジニアが応募し、貴社がプロバイダーへの提案を作っている案件です。</p><x-table :head="$head" :count="$making->count()" empty="ありません。">
@foreach($making as $a)<tr><td><x-code :v="$a->case_code" />　{{ $a->title }}</td><td><x-code :v="$a->eg_code" />　{{ $a->eg_name }}</td><td>{{ badge('appStatus', $a->status) }}</td><td>{{ fmt_date($a->updated_at) }}</td><td><a class="btn small" href="{{ url('/applications/'.$a->id) }}">開く</a></td></tr>@endforeach
</x-table></x-card>
@php $unconf = $engs->whereNull('self_confirmed_at'); @endphp
@if($unconf->count())<x-card title="ご本人の確認がまだのエンジニア"><p>登録の内容は、エンジニアご本人が責任を持つものです。ご本人がホームで「確認しました」を押すまで、案件に応募できません。御社で書き換えたときも、もう一度確認が必要になります。</p>
<p>@foreach($unconf as $x)<x-code :v="$x->code" />　<a href="{{ url('/engineers/'.$x->id) }}">{{ $x->name }}</a>@if(!$loop->last)、@endif @endforeach</p></x-card>@endif
@if($noSheet->count())
<x-card title="経験票がまだのエンジニア"><p class="muted">経験票が入っていないと、合う案件が届きません。ご本人に声をかけてください。</p>
<ul>@foreach($noSheet as $e)<li><x-code :v="$e->code" />　<a href="{{ url('/engineers/'.$e->id) }}">{{ $e->name }}</a></li>@endforeach</ul></x-card>
@endif
@endsection
