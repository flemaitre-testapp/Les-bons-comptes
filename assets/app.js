(function () {
  'use strict';

  // Confirmation avant les actions sensibles
  document.querySelectorAll('form[data-confirm]').forEach(function (f) {
    f.addEventListener('submit', function (e) {
      if (!window.confirm(f.getAttribute('data-confirm'))) e.preventDefault();
    });
  });

  // Barres de répartition
  document.querySelectorAll('.bar span[data-w]').forEach(function (el) {
    el.style.width = el.getAttribute('data-w') + '%';
  });

  // Impression du relevé
  document.querySelectorAll('[data-print]').forEach(function (b) {
    b.addEventListener('click', function () { window.print(); });
  });

  // Formulaire de dépense : choix de répartition + aperçu des parts
  var form = document.getElementById('dep-form');
  if (!form) return;
  var amount = document.getElementById('amount');
  var partA = document.getElementById('part_a');
  var custom = form.querySelector('.custom-pct');
  var preview = document.getElementById('split-preview');
  var nameA = form.getAttribute('data-a');
  var nameB = form.getAttribute('data-b');

  function num(s) {
    var v = parseFloat(String(s).replace(/\s|€|%/g, '').replace(',', '.'));
    return isNaN(v) ? null : v;
  }
  function eur(c) {
    return (c / 100).toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €';
  }
  function update() {
    var checked = form.querySelector('input[name=mode]:checked');
    var mode = checked ? checked.value : 'half';
    if (custom) custom.hidden = mode !== 'custom';
    var p = mode === 'custom' ? num(partA.value) : num(checked ? checked.getAttribute('data-bp') : 50);
    var a = num(amount.value);
    if (a === null || p === null || p < 0 || p > 100) { preview.textContent = ''; return; }
    var cents = Math.round(a * 100);
    var ca = Math.round(cents * p / 100);
    preview.textContent = nameA + ' : ' + eur(ca) + '  ·  ' + nameB + ' : ' + eur(cents - ca);
  }
  form.querySelectorAll('input[name=mode]').forEach(function (r) { r.addEventListener('change', update); });
  amount.addEventListener('input', update);
  if (partA) partA.addEventListener('input', update);
  update();
})();
