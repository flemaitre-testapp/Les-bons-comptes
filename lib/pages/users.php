<?php
require_admin();
$me = current_user();
$tempPw = null;
$tempFor = null;

if (is_post()) {
    check_csrf();
    $uid = (int)post('id');
    $u = q('SELECT * FROM users WHERE id = ?', [$uid])->fetch();
    if (!$u) {
        redirect('users');
    }
    $action = post('action');
    if ($action === 'rename') {
        $name = post('display_name');
        if ($name !== '' && mb_strlen($name) <= 40) {
            q('UPDATE users SET display_name = ? WHERE id = ?', [$name, $uid]);
            audit('user.update', 'user', $uid, ['prenom' => $u['display_name'] . ' → ' . $name]);
            flash('ok', 'Prénom mis à jour.');
        }
        redirect('users');
    } elseif ($action === 'reset' && $uid !== (int)$me['id']) {
        $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $tempPw = '';
        for ($i = 0; $i < 14; $i++) {
            $tempPw .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        set_password($uid, $tempPw, true);
        q('UPDATE users SET pw_reset_notice = 1 WHERE id = ?', [$uid]);
        audit('password.reset', 'user', $uid, ['compte' => $u['display_name']]);
        $tempFor = $u['display_name'];
    }
}

$users = q("SELECT * FROM users ORDER BY CASE role WHEN 'admin' THEN 0 ELSE 1 END, id")->fetchAll();
layout_start('Comptes', 'admin');
?>
<h1>Administration</h1>
<?php admin_tabs('users'); ?>
<?php if ($tempPw): ?>
  <div class="flash flash-ok">Mot de passe provisoire pour <?= h($tempFor) ?> : <code class="pw"><?= h($tempPw) ?></code><br>
    Il ne sera plus affiché. Transmets-le ; il faudra le changer à la première connexion. La réinitialisation est inscrite au journal et signalée à l'intéressé·e.</div>
<?php endif; ?>
<section class="card">
  <h2>Comptes</h2>
  <?php foreach ($users as $u): ?>
    <div class="user-card">
      <form method="post" class="inline-form">
        <?= csrf_field() ?><input type="hidden" name="action" value="rename"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
        <input name="display_name" value="<?= h($u['display_name']) ?>" maxlength="40" required>
        <button class="btn btn-ghost small">Renommer</button>
      </form>
      <p class="muted"><?= $u['role'] === 'admin' ? 'Administrateur' : 'Membre' ?>
        · Dernière connexion : <?= $u['last_login_at'] ? fdate($u['last_login_at'], true) : 'jamais' ?>
        <?= $u['must_change_pw'] ? ' · <em>mot de passe provisoire en attente de changement</em>' : '' ?></p>
      <?php if ((int)$u['id'] !== (int)$me['id']): ?>
        <form method="post" data-confirm="Générer un nouveau mot de passe provisoire pour <?= h($u['display_name']) ?> ? L'action sera visible dans le journal.">
          <?= csrf_field() ?><input type="hidden" name="action" value="reset"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
          <button class="btn btn-warn small">Réinitialiser son mot de passe</button>
        </form>
      <?php else: ?>
        <p><a href="<?= url('account') ?>">Changer mon mot de passe</a></p>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</section>
<?php layout_end();
