<?php
// Journal : visible par les deux parties, non modifiable depuis l'application.
$integrity = verify_integrity();
$page = max(1, (int)($_GET['page'] ?? 1));
$per = 100;
$vis = audit_visibility_sql();
$total = (int)q("SELECT COUNT(*) FROM audit_log WHERE $vis")->fetchColumn();
$rows = q("SELECT * FROM audit_log WHERE $vis ORDER BY id DESC LIMIT ? OFFSET ?", [$per, ($page - 1) * $per])->fetchAll();

layout_start('Journal', 'audit');
?>
<div class="page-head">
  <h1>Journal</h1>
  <a class="btn btn-ghost small" href="<?= url('export', ['type' => 'journal']) ?>">Export CSV</a>
</div>
<div class="flash <?= $integrity['ok'] ? 'flash-ok' : 'flash-err' ?>">
  <?php if ($integrity['ok']): ?>
    Intégrité vérifiée : aucune modification ni suppression détectée (<?= (int)$integrity['lines'] ?> lignes).
  <?php else: ?>
    Anomalie détectée :
    <ul><?php foreach ($integrity['problems'] as $pb): ?><li><?= h($pb) ?></li><?php endforeach; ?></ul>
  <?php endif; ?>
</div>
<p class="muted">Chaque action (ajout, validation, contestation, annulation, connexion, changement de mot de passe...) est enregistrée ici avec sa date. Chaque ligne est scellée avec l'empreinte de la précédente : toute modification ultérieure de la base serait signalée ci-dessus.</p>
<section class="card flush">
  <ul class="timeline big-tl">
    <?php foreach ($rows as $r):
        $d = $r['details'] ? json_decode($r['details'], true) : []; ?>
      <li>
        <span class="t-date"><?= fdate($r['ts'], true) ?></span>
        <span><strong><?= h(AUDIT_LABELS[$r['action']] ?? $r['action']) ?></strong>
          <?php if ($r['user_id']): ?> · <?= h(user_name((int)$r['user_id'])) ?><?php endif; ?>
          <?php if ($r['entity'] === 'entry'): ?> · <a href="<?= url('entry', ['id' => $r['entity_id']]) ?>">opération #<?= (int)$r['entity_id'] ?></a><?php endif; ?>
        </span>
        <?php if ($d): ?>
          <span class="t-details"><?php
            $parts = [];
            foreach ($d as $k => $v) {
                if ($k === 'hash' || $v === null || $v === '') continue;
                $parts[] = h(str_replace('_', ' ', (string)$k)) . ' : ' . h(is_scalar($v) ? (string)$v : json_encode($v, JSON_UNESCAPED_UNICODE));
            }
            echo implode(' · ', $parts);
          ?></span>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ul>
</section>
<?php if ($total > $per): ?>
  <p class="pager">
    <?php if ($page > 1): ?><a href="<?= url('audit', ['page' => $page - 1]) ?>">← Plus récent</a><?php endif; ?>
    Page <?= $page ?> / <?= (int)ceil($total / $per) ?>
    <?php if ($page * $per < $total): ?><a href="<?= url('audit', ['page' => $page + 1]) ?>">Plus ancien →</a><?php endif; ?>
  </p>
<?php endif; ?>
<?php layout_end();
