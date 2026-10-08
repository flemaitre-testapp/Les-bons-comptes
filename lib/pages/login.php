<?php
if (current_user()) {
    redirect('dashboard');
}
$error = null;
if (is_post()) {
    check_csrf();
    if (login_blocked()) {
        $error = 'Trop de tentatives. Réessaie dans 15 minutes.';
    } elseif (attempt_login(post('password'))) {
        redirect('dashboard');
    } else {
        $error = 'Mot de passe incorrect.';
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
    <label>Mot de passe <input type="password" name="password" autocomplete="current-password" required autofocus></label>
    <button class="btn">Se connecter</button>
  </form>
</section>
<?php layout_end();
