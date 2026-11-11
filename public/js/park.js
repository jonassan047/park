// 表に行を足す（スキルの編集など）
function addRow(tableId, templateId) {
  const tpl = document.getElementById(templateId);
  document.querySelector('#' + tableId + ' tbody').appendChild(tpl.content.cloneNode(true));
}

// スキルのチェック画面（engineers/skills.blade.php）
(function () {
  const OTHER_MAX = 10; // 1つの「その他」に入れられる名前の数（サーバー側の SkillForm::OTHER_COUNT_MAX と同じ）

  // 項目の行（.sf-row）の中の年数のプルダウンを、チェックの有無に合わせて有効・無効にする
  function syncYear(box) {
    const row = box.closest('.sf-row');
    const sel = row && row.querySelector(':scope > select.sf-yr');
    if (sel) sel.disabled = !box.checked;
  }

  // 「その他」の入れ物：欄が10個に達したら「＋」を押せなくする
  function syncAdd(other) {
    const add = other.querySelector('.sf-add');
    if (add) add.disabled = other.querySelectorAll('.sf-orow').length >= OTHER_MAX;
  }

  // 「その他」に名前の欄を1つ足す。年数のある節は、年数のプルダウンも付く（テンプレートを写して name を付ける）
  function addOther(other) {
    if (other.querySelectorAll('.sf-orow').length >= OTHER_MAX) return;
    const id = other.closest('.sf-otherrow').querySelector('input[type=checkbox]').dataset.sf;
    const tpl = document.getElementById(other.dataset.sfYears === '1' ? 'sf-tpl-y' : 'sf-tpl-n');
    const node = tpl.content.cloneNode(true);
    node.querySelector('input').name = 'oth[' + id + '][]';
    const sel = node.querySelector('select');
    if (sel) sel.name = 'othyr[' + id + '][]';
    other.insertBefore(node, other.querySelector('.sf-add'));
    syncAdd(other);
  }

  // 子の項目（.sf-gitems の中）にチェックが入ったら、同じグループの親（.sf-ghead）にもチェックを入れ、親の年数を有効にする
  function checkParent(box) {
    if (!box.closest('.sf-gitems')) return;
    const group = box.closest('.sf-group');
    const head = group && group.querySelector('.sf-ghead input[type=checkbox][data-sf]');
    if (head && !head.checked) { head.checked = true; syncYear(head); }
  }

  // 親のチェックを外したら、その親の子のチェックも外す。「その他」は、チェックを外して入力欄を閉じるだけ（書いた名前は消さない）
  function uncheckChildren(head) {
    const group = head.closest('.sf-group');
    if (!group) return;
    group.querySelectorAll('.sf-gitems input[type=checkbox][data-sf]').forEach(function (b) {
      if (!b.checked) return;
      b.checked = false;
      const other = b.closest('.sf-otherrow');
      if (other) other.querySelector('.sf-other').hidden = true;
      else syncYear(b);
    });
  }

  function init() {
    const form = document.querySelector('.sf-sec');
    if (!form) return;
    // ページを開いたとき、チェックの無い項目の年数を無効にする
    document.querySelectorAll('.sf-row input[type=checkbox][data-sf]').forEach(function (box) {
      if (!box.closest('.sf-otherrow')) syncYear(box);
    });
    document.querySelectorAll('.sf-other').forEach(syncAdd);

    document.addEventListener('change', function (ev) {
      const t = ev.target;
      if (!t.closest || !t.closest('.sf-sec')) return;
      // チェックを変えたとき
      if (t.matches('input[type=checkbox][data-sf]')) {
        const other = t.closest('.sf-otherrow');
        if (other) {
          const box = other.querySelector('.sf-other');
          box.hidden = !t.checked;
          if (t.checked && box.querySelectorAll('.sf-orow').length === 0) addOther(box);
        } else {
          syncYear(t);
        }
        // 子（Laravel など）を選んだら、親（PHP など）にもチェックを入れる。親を外したら、子も全部外す
        if (t.checked) checkParent(t);
        else if (t.closest('.sf-ghead')) uncheckChildren(t);
        return;
      }
      // 年数を選んだら、その項目のチェックを入れる（その他の欄の年数は対象外）
      if (t.matches('select.sf-yr') && t.parentElement.classList.contains('sf-row') && t.value !== '') {
        const box = t.parentElement.querySelector('input[type=checkbox][data-sf]');
        if (box && !box.checked) { box.checked = true; syncYear(box); checkParent(box); }
      }
    });

    document.addEventListener('click', function (ev) {
      const t = ev.target;
      if (t.classList.contains('sf-add')) { addOther(t.closest('.sf-other')); return; }
      if (t.classList.contains('sf-del')) {
        const other = t.closest('.sf-other');
        t.closest('.sf-orow').remove();
        syncAdd(other);
        return;
      }
      if (t.id === 'sf-open' || t.id === 'sf-close') {
        document.querySelectorAll('.sf-sec').forEach(function (d) { d.open = t.id === 'sf-open'; });
      }
    });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
