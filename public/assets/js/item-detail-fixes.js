(() => {
  'use strict';

  if (!/(^|\/)item\.php$/.test(window.location.pathname)) return;

  const rows = Array.from(document.querySelectorAll('.pcf-item-main__info tr'));
  const rowByLabel = (label) => rows.find((row) => {
    const heading = row.querySelector('th');
    return heading && heading.textContent.trim() === label;
  });

  const makerRow = rowByLabel('メーカー');
  if (makerRow) {
    const cell = makerRow.querySelector('td');
    const makerName = cell ? cell.textContent.trim() : '';
    if (cell && makerName && makerName !== '―' && !cell.querySelector('a')) {
      const link = document.createElement('a');
      link.href = `maker_resolve.php?name=${encodeURIComponent(makerName)}`;
      link.textContent = makerName;
      cell.replaceChildren(link);
    }
  }

  const labelRow = rowByLabel('レーベル');
  if (labelRow) {
    const cell = labelRow.querySelector('td');
    const labelName = cell ? cell.textContent.trim() : '';
    const invalid = labelName === ''
      || /^[\-‐‑‒–—―ーｰ_\s]+$/u.test(labelName)
      || /[�]|(?:Ã.|Â.|縺|繝|譁|螟)/u.test(labelName);
    if (cell && invalid) cell.textContent = '―';
  }
})();
