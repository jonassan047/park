{{-- 企業代表者の入力（必須）。「窓口に同じ」か「記入」を選ぶ。$same：1 か 0（無ければ 1）、$name：記入の氏名。
     氏名の欄は常に出しておく（JavaScript なしでも送れる）。「窓口に同じ」のときは、保存で氏名を使わない --}}
@php
    $ceoSame = (string) old('ceo_same', $same ?? '1') !== '0';
    $ceoName = old('ceo_name', $name ?? null);
@endphp
<div class="field ceo-field">
  <span>企業代表者<i>必須</i></span>
  <div class="ceo-row">
    <label class="ceo-opt"><input type="radio" name="ceo_same" value="1" @checked($ceoSame)>窓口に同じ</label>
    <label class="ceo-opt"><input type="radio" name="ceo_same" value="0" @checked(!$ceoSame)>記入</label>
    <input type="text" name="ceo_name" value="{{ $ceoName }}" maxlength="50" placeholder="企業代表者の氏名" aria-label="企業代表者の氏名" class="ceo-name">
  </div>
  <small>「記入」を選んだら、右の欄に氏名を入れます（50文字まで）。「窓口に同じ」は、PARK窓口担当者の氏名が企業代表者になります。</small>
</div>
