<?php
$me = current_user();
$errors = [];
if (is_post()) {
    check_csrf();
    if (!password_verify(post('current'), $me['password_hash'])) {
        $errors[] = 'Mot de passe actuel incorrect.';
    } elseif ($p = password_problem(post('new'), post('new2'))) {
        $errors[] = $p;
    } elseif (post('new') === post('current')) {
        $errors[] = 'Le nouveau mot de passe doit être différent de l\'actuel.';
    } else {
        set_password((int)$me['id'], post('new'));
        audit('password.change', 'user', (int)$me['id']);
        flash('ok', 'Mot de passe mis à jour.');
        redirect('dashboard');
    }
}
layout_start('Mon compte', 'account');
?>
<section class="card narrow">
  <h1>Mon compte</h1>
  <ul class="kv">
    <li><span>Prénom</span><strong><?= h($me['display_name']) ?></strong></li>
    <li><span>Identifiant</span><strong><?= h($me['username']) ?></strong></li>
    <li><span>Rôle</span><strong><?= $me['role'] === 'admin' ? 'Administrateur' : 'Membre' ?></strong></li>
  </ul>
  <h2><?= $me['must_change_pw'] ? 'Choisis ton mot de passe' : 'Changer de mot de passe' ?></h2>
  <?php if ($me['must_change_pw']): ?>
    <p class="muted">Le mot de passe que tu as reçu est provisoire. Choisis-en un que toi seul·e connais (<?= PW_MIN ?> caractères minimum).</p>
  <?php endif; ?>
  <?php foreach ($errors as $e): ?><div class="flash flash-err"><?= h($e) ?></div><?php endforeach; ?>
  <form method="post" class="form">
    <?= csrf_field() ?>
    <label>Mot de passe actuel <input type="password" name="current" autocomplete="current-password" required></label>
    <label>Nouveau mot de passe <input type="password" name="new" autocomplete="new-password" minlength="<?= PW_MIN ?>" required></label>
    <label>Confirmer <input type="password" name="new2" autocomplete="new-password" minlength="<?= PW_MIN ?>" required></label>
    <button class="btn">Enregistrer</button>
  </form>
</section>
<?php layout_end();
