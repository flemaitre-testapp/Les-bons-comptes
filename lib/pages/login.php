<?php
if (current_user()) {
    redirect('dashboard');
}
$error = null;
$username = post('username');
if (is_post()) {
    check_csrf();
    if (login_blocked($username)) {
        $error = 'Trop de tentatives. Réessaie dans 15 minutes.';
    } elseif (attempt_login($username, post('password'))) {
        redirect('dashboard');
    } else {
        $error = 'Identifiant ou mot de passe incorrect.';
    }
}
layout_start('Connexion');
?>
<section class="card narrow login">
  <h1><?= h(APP_NAME) ?></h1>
  <p class="muted">Accès réservé.</p>
  <?php if ($error): ?><div class="flash flash-err"><?= h($error) ?></div><?php endif; ?>
  <form method="post" class="form">
    <?= csrf_field() ?>
    <label>Identifiant <input name="username" value="<?= h($username) ?>" autocomplete="username" autocapitalize="none" required autofocus></label>
    <label>Mot de passe <input type="password" name="password" autocomplete="current-password" required></label>
    <button class="btn">Se connecter</button>
  </form>
</section>
<?php layout_end();
