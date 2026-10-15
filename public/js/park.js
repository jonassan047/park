// 表に行を足す（スキルの編集など）
function addRow(tableId, templateId) {
  const tpl = document.getElementById(templateId);
  document.querySelector('#' + tableId + ' tbody').appendChild(tpl.content.cloneNode(true));
}
