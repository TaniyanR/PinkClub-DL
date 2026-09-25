(() => {
  'use strict';

  if (!/\/admin\/analytics\.php$/i.test(window.location.pathname)) return;
  const params = new URLSearchParams(window.location.search);
  if ((params.get('tab') || 'graph') !== 'graph') return;

  const tables = Array.from(document.querySelectorAll('.admin-main .admin-table'));
  const dailyTable = tables.find((table) => {
    const headings = Array.from(table.querySelectorAll('thead th, tr:first-child th')).map((th) => (th.textContent || '').trim());
    return headings.includes('日付') && headings.includes('PV') && headings.some((value) => value.indexOf('UU') === 0);
  });
  if (!dailyTable || dailyTable.dataset.analyticsChartReady === '1') return;

  const rows = Array.from(dailyTable.querySelectorAll('tbody tr'))
    .map((row) => {
      const cells = row.querySelectorAll('td');
      if (cells.length < 3) return null;
      const date = (cells[0].textContent || '').trim();
      const pv = Number.parseInt((cells[1].textContent || '').replace(/[^0-9]/g, ''), 10) || 0;
      const uu = Number.parseInt((cells[2].textContent || '').replace(/[^0-9]/g, ''), 10) || 0;
      return date ? { date, pv, uu } : null;
    })
    .filter(Boolean);
  if (rows.length === 0) return;

  const maxValue = Math.max(1, ...rows.flatMap((row) => [row.pv, row.uu]));
  const section = document.createElement('section');
  section.className = 'analytics-chart-section';

  const heading = document.createElement('h2');
  heading.textContent = '日別PV / UU（棒グラフ）';
  section.appendChild(heading);

  const legend = document.createElement('p');
  legend.className = 'analytics-bars__legend';
  const pvLegend = document.createElement('span');
  pvLegend.className = 'analytics-bars__legend-pv';
  pvLegend.textContent = 'PV';
  const uuLegend = document.createElement('span');
  uuLegend.className = 'analytics-bars__legend-uu';
  uuLegend.textContent = 'UU';
  legend.append(pvLegend, uuLegend);
  section.appendChild(legend);

  const chart = document.createElement('div');
  chart.className = 'analytics-bars analytics-bars--vertical';
  rows.forEach((row) => {
    const col = document.createElement('div');
    col.className = 'analytics-bars__col';

    const values = document.createElement('div');
    values.className = 'analytics-bars__values';
    const pvValue = document.createElement('span');
    pvValue.className = 'analytics-bars__value';
    pvValue.textContent = `PV ${row.pv.toLocaleString()}`;
    const uuValue = document.createElement('span');
    uuValue.className = 'analytics-bars__value';
    uuValue.textContent = `UU ${row.uu.toLocaleString()}`;
    values.append(pvValue, uuValue);

    const pair = document.createElement('div');
    pair.className = 'analytics-bars__pair';
    const pvTrack = document.createElement('div');
    pvTrack.className = 'analytics-bars__track';
    const pvFill = document.createElement('span');
    pvFill.className = 'analytics-bars__fill';
    pvFill.style.height = `${Math.round((row.pv / maxValue) * 100)}%`;
    pvTrack.appendChild(pvFill);

    const uuTrack = document.createElement('div');
    uuTrack.className = 'analytics-bars__track';
    const uuFill = document.createElement('span');
    uuFill.className = 'analytics-bars__fill analytics-bars__fill--uu';
    uuFill.style.height = `${Math.round((row.uu / maxValue) * 100)}%`;
    uuTrack.appendChild(uuFill);
    pair.append(pvTrack, uuTrack);

    const date = document.createElement('span');
    date.className = 'analytics-bars__date';
    date.textContent = row.date.length >= 10 ? row.date.slice(5).replace('-', '/') : row.date;
    col.append(values, pair, date);
    chart.appendChild(col);
  });
  section.appendChild(chart);

  dailyTable.parentNode.insertBefore(section, dailyTable);
  dailyTable.dataset.analyticsChartReady = '1';
})();

