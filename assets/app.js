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

  // Fiches en carrés : ouverture du détail
  function openSheet(id) {
    var d = document.getElementById(id);
    if (d && d.showModal) { d.showModal(); }
    else if (d) { d.setAttribute('open', ''); }
  }
  document.querySelectorAll('[data-open]').forEach(function (b) {
    b.addEventListener('click', function () { openSheet(b.getAttribute('data-open')); });
  });
  document.querySelectorAll('dialog.sheet').forEach(function (d) {
    d.addEventListener('click', function (ev) { if (ev.target === d) d.close(); });
    d.querySelectorAll('[data-close]').forEach(function (c) { c.addEventListener('click', function () { d.close(); }); });
  });
  if (/^#[ea]\d+$/.test(location.hash)) {
    var sheetId = 'd' + location.hash.slice(2);
    openSheet(sheetId);
    if (location.hash.charAt(1) === 'a') {
      var edit = document.querySelector('#' + sheetId + ' .fiche-more');
      if (edit) edit.open = true;
    }
    history.replaceState(null, '', location.pathname + location.search);
  }

  // Validation en un geste : la ligne quitte la liste sans recharger la page
  var todo = document.getElementById('todo');
  var toastTimer;
  function refreshProgress() {
    if (!todo) return;
    var bar = document.getElementById('progress-bar');
    var total = +todo.getAttribute('data-total');
    var done = +todo.getAttribute('data-done');
    if (bar && total) bar.style.width = Math.round(done * 100 / total) + '%';
  }
  refreshProgress();
  function toast(label, onUndo) {
    var old = document.querySelector('.toast');
    if (old) old.remove();
    clearTimeout(toastTimer);
    var t = document.createElement('div');
    t.className = 'toast';
    t.setAttribute('role', 'status');
    t.textContent = label;
    var b = document.createElement('button');
    b.type = 'button';
    b.textContent = 'Annuler';
    b.addEventListener('click', onUndo);
    t.appendChild(b);
    document.body.appendChild(t);
    toastTimer = setTimeout(function () { t.remove(); }, 5000);
  }
  function send(form, on) {
    var data = new FormData(form);
    data.set('on', on);
    data.set('ajax', '1');
    return fetch(location.href, { method: 'POST', body: data, credentials: 'same-origin' }).then(function (r) {
      if (!r.ok) throw new Error('http');
      return r.json();
    });
  }
  document.querySelectorAll('form[data-tick]').forEach(function (f) {
    f.addEventListener('submit', function (ev) {
      if (!window.fetch) return;
      ev.preventDefault();
      var li = f.closest('.line');
      var btn = f.querySelector('.tick');
      var id = f.getAttribute('data-tick');
      btn.classList.add('done');
      btn.disabled = true;
      send(f, '1').then(function () {
        li.classList.add('leaving');
        setTimeout(function () {
          var doneList = document.getElementById('done-list');
          f.remove();
          li.classList.remove('leaving');
          doneList.insertBefore(li, doneList.firstChild);
          document.getElementById('done-fold').hidden = false;
          document.getElementById('done-count').textContent = doneList.children.length;
          var left = document.getElementById('todo-list').children.length;
          document.getElementById('todo-count').textContent = left;
          todo.setAttribute('data-done', +todo.getAttribute('data-done') + 1);
          refreshProgress();
          if (!left) {
            document.getElementById('all-done').hidden = false;
            var all = document.getElementById('ok-all');
            if (all) all.remove();
          }
          var sheet = document.querySelector('form[data-sheet-ok="' + id + '"]');
          if (sheet) {
            sheet.querySelector('input[name=on]').value = '0';
            var sb = sheet.querySelector('button');
            sb.textContent = 'Remettre à valider';
            sb.className = 'btn btn-ghost';
          }
        }, 320);
        toast('Validé', function () {
          send(f, '0').then(function () { location.reload(); });
        });
      }).catch(function () {
        btn.classList.remove('done');
        btn.disabled = false;
        window.alert('Ça n\'a pas marché. Recharge la page et réessaie.');
      });
    });
  });

  // Montant ou répartition modifiés : enregistrés tout seuls, sans bouton
  document.querySelectorAll('form[data-auto]').forEach(function (f) {
    var timer;
    var submit = f.querySelector('button');
    if (submit) submit.hidden = true;
    function save() {
      clearTimeout(timer);
      timer = setTimeout(function () { f.requestSubmit(); }, 250);
    }
    var pct = f.querySelector('input[name=part_a]');
    var customRadio = f.querySelector('input[name=mode][value=custom]');
    f.querySelectorAll('input[name=mode]').forEach(function (r) {
      r.addEventListener('change', function () {
        if (r.value === 'custom') { pct.focus(); return; }
        save();
      });
    });
    pct.addEventListener('input', function () { customRadio.checked = true; });
    pct.addEventListener('change', function () { if (pct.value.trim() !== '') save(); });
    f.querySelector('input[name=amount]').addEventListener('change', save);
    // Entrée dans un champ : même enregistrement, sans passer par le bouton masqué
    f.addEventListener('keydown', function (ev) {
      if (ev.key === 'Enter') { ev.preventDefault(); save(); }
    });
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
