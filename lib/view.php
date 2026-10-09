<?php
declare(strict_types=1);

function layout_start(string $title, string $active = ''): void
{
    $u = current_user();
    $v = '?v=' . (string)@filemtime(APP_ROOT . '/assets/style.css');
    ?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#1f3a34">
<title><?= h($title) ?> · <?= h(APP_NAME) ?></title>
<link rel="stylesheet" href="assets/style.css<?= $v ?>">
<link rel="icon" href="assets/icon.svg" type="image/svg+xml">
</head>
<body>
<?php if ($u): ?>
<header class="top">
  <a class="brand" href="<?= url('dashboard') ?>"><?= h(APP_NAME) ?></a>
  <nav>
    <?php
    $links = [
        'dashboard' => 'Mois',
        'entries' => 'Recherche',
        'statement' => 'Relevé',
    ];
    if ($u['role'] === 'admin') {
        $links['admin'] = 'Admin';
    }
    foreach ($links as $p => $label) {
        $target = $p === 'admin' ? url('settings') : url($p);
        echo '<a href="' . $target . '"' . ($active === $p ? ' class="on"' : '') . '>' . h($label) . '</a>';
    }
    ?>
  </nav>
  <form method="post" action="<?= url('logout') ?>" class="logout"><?= csrf_field() ?><button type="submit" class="link">Déconnexion</button></form>
</header>
<?php endif; ?>
<main>
<?php foreach (take_flashes() as [$type, $msg]): ?>
  <div class="flash flash-<?= h($type) ?>"><?= h($msg) ?></div>
<?php endforeach;
}

function layout_end(): void
{
    $u = current_user();
    ?>
</main>
<?php if ($u): ?>
<a class="fab" href="<?= url('new') ?>" aria-label="Ajouter une dépense">+</a>
<footer class="foot">Connecté·e : <?= h($u['display_name']) ?> · <a href="<?= url('account') ?>">Mon compte</a> · <a href="<?= url('audit') ?>">Journal</a><br>Toutes les actions sont tracées dans le journal, visible par les deux.</footer>
<?php endif; ?>
<script src="assets/app.js?v=<?= (string)@filemtime(APP_ROOT . '/assets/app.js') ?>"></script>
</body>
</html>
<?php
}

function admin_tabs(string $active): void
{
    $tabs = ['settings' => 'Réglages & catégories', 'recurring' => 'Charges mensuelles', 'users' => 'Comptes'];
    echo '<div class="tabs">';
    foreach ($tabs as $p => $l) {
        echo '<a href="' . url($p) . '"' . ($p === $active ? ' class="on"' : '') . '>' . h($l) . '</a>';
    }
    echo '</div>';
}

function entry_row(array $e): string
{
    $isDep = $e['kind'] === 'depense';
    $who = $isDep
        ? ucfirst(paid_word($e)) . ' ' . h(user_name((int)$e['paid_by']))
        : h(user_name((int)$e['paid_by'])) . ' → ' . h(user_name((int)$e['beneficiary']));
    $meta = fdate($e['op_date']) . ' · ' . $who;
    if ($isDep) {
        $meta .= ' · ' . h(split_label($e));
    }
    $cat = $isDep ? '' : 'Remboursement';
    return '<a class="row tone-row tone-' . entry_tone($e) . ($e['cancelled'] ? ' cancelled' : '') . '" href="' . url('entry', ['id' => $e['id']]) . '">'
        . '<span class="row-main"><span class="row-title">' . h($e['label'])
        . ($e['receipt'] ? ' <span class="clip" title="Justificatif joint">📎</span>' : '') . '</span>'
        . '<span class="row-meta">' . $meta . ($cat ? ' · ' . h($cat) : '') . '</span></span>'
        . '<span class="row-side"><span class="amt' . ($isDep ? '' : ' amt-transfer') . '">' . money((int)$e['amount_cents']) . '</span>'
        . status_badge($e) . '</span></a>';
}

function legend(): string
{
    $A = user_name(party_a_id());
    $B = user_name((int)parties()['B']['id']);
    return '<p class="legend"><span class="tone tone-pa">Payé par ' . h($A) . '</span>'
        . '<span class="tone tone-pb">Payé par ' . h($B) . '</span>'
        . '<span class="tt tt-mensuel">Mensuel</span><span class="tt tt-ponctuel">Ponctuel</span>'
        . '<span class="tt tt-perso">Perso</span><span class="tt tt-recette">Recette</span><span class="tt tt-remb">Paiement</span></p>';
}
