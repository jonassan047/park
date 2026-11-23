{{-- プロバイダーのタブ：受けた案件（原文）／プロバイダーの一覧。$on は 'cases' か 'list'（v0.17.0） --}}
<p class="tabs"><a href="{{ url($base.'/cases') }}" class="{{ $on === 'cases' ? 'on' : '' }}">受けた案件（原文）</a><a href="{{ url($base) }}" class="{{ $on === 'list' ? 'on' : '' }}">プロバイダーの一覧</a></p>
