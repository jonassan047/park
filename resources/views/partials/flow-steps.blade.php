{{-- 仕事の流れの小さなメニュー（kind = ses / engineer）。そのページの立場が担当するステップを強調する --}}
@php
    // [ステップ名, [担当 => やること]]。担当は ses（PARK窓口担当者・一般担当者）・eg（エンジニア）・admin（管理者）
    $steps = [
        ['① 登録・準備', ['ses' => 'SES（PARK窓口担当者・一般担当者）：エンジニアの登録', 'eg' => 'エンジニア：プロフィール・スキル・経験票', 'admin' => '管理者：スキルシートの確認']],
        ['② 案件の登録・案内', ['ses' => 'SES（窓口・一般担当者）：登録と案内', 'admin' => '管理者：案件の登録']],
        ['③ 応募・確かめごと', ['eg' => 'エンジニア：返事・質問', 'ses' => 'SES（代表・一般担当者）：書類を返す']],
        ['④ 面談', ['ses' => 'SES（代表・一般担当者）：面談依頼・結果の記録', 'eg' => 'エンジニア：面談スクリプトで準備']],
        ['⑤ 成約・契約', ['ses' => 'SES（代表・一般担当者）：成約の記録', 'eg' => 'エンジニア：経験票の更新', 'admin' => '管理者：契約の延長・終了、請求書']],
    ];
    $mine = $kind === 'ses' ? 'ses' : 'eg';
@endphp
<nav class="flow-steps flow-{{ $kind }}" aria-label="仕事の流れ">
  <div class="flow-heads">
    <a href="{{ url('/start/ses') }}" class="{{ $kind === 'ses' ? 'on' : '' }}">SES の作業</a>
    <a href="{{ url('/start/engineer') }}" class="{{ $kind === 'engineer' ? 'on' : '' }}">エンジニアの作業</a>
  </div>
  <ol>
    @foreach($steps as [$name, $owners])
    <li class="{{ isset($owners[$mine]) ? 'on' : '' }}"><b>{{ $name }}</b>@foreach($owners as $role => $text)<span class="owner {{ $role }}">{{ $text }}</span>@endforeach</li>
    @endforeach
  </ol>
</nav>
