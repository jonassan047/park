{{-- ダッシュボードの先頭の帯（kind = admin / ses / eg）。名前・ID は出さない --}}
@php
    $words = ['admin' => 'グループの案件と、みなさんの進み具合を見守ります。', 'ses' => 'グループで、仕事を回す。', 'eg' => '合う案件が、届く。'];
@endphp
<div class="role-hero" style="background-image:url('{{ asset('img/hero.jpg') }}')">
  <img class="sprig" src="{{ asset('img/sprig.jpg') }}" alt="">
  <p>{{ $words[$kind] }}</p>
</div>
