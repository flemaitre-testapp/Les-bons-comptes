<?php
// Installation : ne fonctionne qu'une seule fois, tant qu'aucun compte n'existe.
if (has_users()) {
    redirect('login');
}

$errors = [];
$f = [
    'a_user' => post('a_user'), 'a_name' => post('a_name'),
    'b_user' => post('b_user'), 'b_name' => post('b_name'),
    'inc_a' => post('inc_a'), 'inc_b' => post('inc_b'),
];

if (is_post()) {
    check_csrf();
    foreach (['a_user', 'a_name', 'b_user', 'b_name'] as $k) {
        if ($f[$k] === '') {
            $errors[] = 'Tous les champs sont obligatoires.';
            break;
        }
    }
    foreach (['a_user', 'b_user'] as $k) {
        if ($f[$k] !== '' && !preg_match('/^[a-zA-Z0-9._-]{3,40}$/', $f[$k])) {
            $errors[] = 'Identifiant invalide : 3 à 40 caractères, lettres, chiffres, point, tiret.';
            break;
        }
    }
    if (strcasecmp($f['a_user'], $f['b_user']) === 0) {
        $errors[] = 'Les deux identifiants doivent être différents.';
    }
    if ($p = password_problem(post('a_pw'), post('a_pw2'))) {
        $errors[] = 'Admin : ' . $p;
    }
    if ($p = password_problem(post('b_pw'), post('b_pw2'))) {
        $errors[] = 'Second compte : ' . $p;
    }
    $incA = parse_money($f['inc_a']);
    $incB = parse_money($f['inc_b']);
    if ($incA === null || $incB === null) {
        $errors[] = 'Indique les deux revenus mensuels (0 accepté).';
    }

    if (!$errors) {
        db()->beginTransaction();
        $ins = 'INSERT INTO users(username, display_name, password_hash, role, must_change_pw, created_at) VALUES(?, ?, ?, ?, ?, ?)';
        q($ins, [$f['a_user'], $f['a_name'], password_hash(post('a_pw'), PASSWORD_DEFAULT), 'admin', 0, now()]);
        q($ins, [$f['b_user'], $f['b_name'], password_hash(post('b_pw'), PASSWORD_DEFAULT), 'member', 1, now()]);
        set_setting('income_a', (string)$incA);
        set_setting('income_b', (string)$incB);
        set_setting('default_mode', 'half');
        foreach (DEFAULT_CATEGORIES as $i => $c) {
            q('INSERT INTO categories(name, sort) VALUES(?, ?)', [$c, $i]);
        }
        audit('setup', null, null, [
            'admin' => $f['a_name'] . ' (' . $f['a_user'] . ')',
            'second_compte' => $f['b_name'] . ' (' . $f['b_user'] . ')',
            'revenus' => $f['a_name'] . ' ' . money($incA) . ', ' . $f['b_name'] . ' ' . money($incB),
        ]);
        db()->commit();
        flash('ok', 'Installation terminée. Connecte-toi avec ton compte admin.');
        redirect('login');
    }
}

layout_start('Installation');
?>
<section class="card narrow">
  <h1>Installation</h1>
  <p class="muted">Cette page ne s'affiche qu'une seule fois. Elle crée les deux comptes. Le second compte devra choisir son propre mot de passe à sa première connexion : celui que tu mets ici est provisoire.</p>
  <?php foreach ($errors as $e): ?><div class="flash flash-err"><?= h($e) ?></div><?php endforeach; ?>
  <form method="post" class="form">
    <?= csrf_field() ?>
    <fieldset>
      <legend>Compte administrateur (toi)</legend>
      <label>Prénom affiché <input name="a_name" value="<?= h($f['a_name']) ?>" required></label>
      <label>Identifiant <input name="a_user" value="<?= h($f['a_user']) ?>" autocomplete="username" required></label>
      <label>Mot de passe (10 caractères min.) <input type="password" name="a_pw" autocomplete="new-password" required></label>
      <label>Confirmer <input type="password" name="a_pw2" autocomplete="new-password" required></label>
    </fieldset>
    <fieldset>
      <legend>Second compte</legend>
      <label>Prénom affiché <input name="b_name" value="<?= h($f['b_name']) ?>" required></label>
      <label>Identifiant <input name="b_user" value="<?= h($f['b_user']) ?>" autocomplete="off" required></label>
      <label>Mot de passe provisoire <input type="password" name="b_pw" autocomplete="new-password" required></label>
      <label>Confirmer <input type="password" name="b_pw2" autocomplete="new-password" required></label>
    </fieldset>
    <fieldset>
      <legend>Revenus mensuels nets</legend>
      <label>Revenu de l'admin (€) <input name="inc_a" value="<?= h($f['inc_a']) ?>" inputmode="decimal" required></label>
      <label>Revenu du second compte (€) <input name="inc_b" value="<?= h($f['inc_b']) ?>" inputmode="decimal" required></label>
      <p class="hint">Sert à calculer la part de chacun au prorata des revenus. Modifiable ensuite dans Admin.</p>
    </fieldset>
    <button class="btn">Créer les comptes</button>
  </form>
</section>
<?php layout_end();
