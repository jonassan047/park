{{-- 本登録の画面と、足りない項目の画面で使う入力欄。$only が null なら全部、配列ならその項目だけ入力欄にする --}}
@php
    $fields = \App\Support\Requirements::fields($role, $isRep);
    $only = $only ?? null;
    $show = fn ($k) => $only === null || in_array($k, $only, true);
    $consent = \App\Support\Requirements::consent($role, $isRep);
    $consentText = \App\Support\RegistrationText::consent($role, $isRep);
    $hints = ['company_name' => '宛名やメールの差出人の名前に使います。違っていたら直してください。', 'ses_name' => '正式名称をお確かめください。違っていたら直してください。',
        'postal_code' => '例：123-4567', 'position' => '例：営業部 部長'];
@endphp
@foreach($fields as $key => $def)
    @continue(in_array($key, ['name_mei', 'kana_mei'], true))
    @if($def['mode'] === 'view')
        @if($only === null)<p class="muted">{{ $def['label'] }}：{{ $v[$key] ?? '' }}@if($key === 'email')（変えるときは、登録した方にご連絡ください）@else（変えるときは、上位にご連絡ください）@endif</p>@endif
    @elseif($key === 'name_sei')
        @if($show('name_sei') || $show('name_mei'))<div class="pair">
            <x-field label="氏名（姓）" name="name_sei" :value="$v['name_sei'] ?? null" required />
            <x-field label="氏名（名）" name="name_mei" :value="$v['name_mei'] ?? null" required />
        </div>@endif
    @elseif($key === 'kana_sei')
        @if($show('kana_sei') || $show('kana_mei'))<div class="pair">
            <x-field label="ふりがな（姓）" name="kana_sei" :value="$v['kana_sei'] ?? null" :required="$def['required']" placeholder="やまだ" />
            <x-field label="ふりがな（名）" name="kana_mei" :value="$v['kana_mei'] ?? null" :required="$def['required']" placeholder="たろう" />
        </div>@endif
    @elseif($show($key))
        <x-field :label="$def['label']" :name="$key" :type="$key === 'phone' ? 'tel' : 'text'" :value="$v[$key] ?? null" :required="$def['required']" :hint="$hints[$key] ?? null" />
    @endif
@endforeach
@if($consent && $show($consent['key']))
    @if($consent['key'] === 'consent')
        @include('partials.consent')
    @else
        <div class="note"><b>守秘義務</b><br>PARK で知った案件・エンジニアの情報は、グループの外に出さないでください。</div>
    @endif
    <x-field :label="$consentText['text']" :name="$consent['key']" type="checkbox" required />
@endif
