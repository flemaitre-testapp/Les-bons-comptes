<?php
if (is_post()) {
    check_csrf();
    audit('logout', 'user', (int)$_SESSION['uid']);
    $_SESSION = [];
    session_regenerate_id(true);
}
redirect('login');
