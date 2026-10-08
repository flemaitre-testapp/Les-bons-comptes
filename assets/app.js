(function () {
  'use strict';

  // Confirmation avant les actions sensibles
  document.querySelectorAll('form[data-confirm]').forEach(function (f) {
    f.addEventListener('submit', function (e) {
      if (!window.confirm(f.getAttribute('data-confirm'))) e.preventDefault();
    });
  });

  // Impression du relevé
  document.querySelectorAll('[data-print]').forEach(function (b) {
    b.addEventListener('click', function () { window.print(); });
  });

  // Formulaire de dépense : boutons de répartition + aperçu des parts
  var form = document.getElementById('dep-form');
  if (!form) return;
  var amount = document.getElementById('amount');
  var partA = document.getElementById('part_a');
  var preview = document.getElementById('split-preview');
  var nameA = form.getAttribute('data-a');
  var nameB = form.getAttribute('data-b');

  function num(s) {
    var v = parseFloat(String(s).replace(/\s|€/g, '').replace(',', '.'));
    return isNaN(v) ? null : v;
  }
  function eur(c) {
    return (c / 100).toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €';
  }
  function update() {
    var a = num(amount.value), p = num(partA.value);
    form.querySelectorAll('.chips button').forEach(function (b) {
      b.classList.toggle('on', p !== null && num(b.getAttribute('data-pct')) === p);
    });
    if (a === null || p === null || p < 0 || p > 100) { preview.textContent = ''; return; }
    var cents = Math.round(a * 100);
    var ca = Math.round(cents * p / 100);
    preview.textContent = nameA + ' : ' + eur(ca) + '  ·  ' + nameB + ' : ' + eur(cents - ca);
  }
  form.querySelectorAll('.chips button').forEach(function (b) {
    b.addEventListener('click', function () {
      partA.value = b.getAttribute('data-pct').replace('.', ',');
      update();
    });
  });
  amount.addEventListener('input', update);
  partA.addEventListener('input', update);
  update();
})();
