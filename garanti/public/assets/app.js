(function () {
  if (!window.TX || !window.Chart) return;
  // Gune gore net (alacak - borc) topla
  const byDay = {};
  window.TX.forEach(function (t) {
    const d = t.tarih;
    byDay[d] = (byDay[d] || 0) + (t.ba === 'D' ? -t.tutar : t.tutar);
  });
  const labels = Object.keys(byDay).sort();
  const data = labels.map(function (d) { return byDay[d]; });
  new Chart(document.getElementById('trend'), {
    type: 'bar',
    data: { labels: labels, datasets: [{ label: 'Gunluk net (TL)', data: data,
      backgroundColor: data.map(function (v) { return v < 0 ? '#fca5a5' : '#6ee7b7'; }) }] },
    options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
  });
})();
