<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../middleware/Session.php';
require_once __DIR__ . '/../middleware/Csrf.php';
require_once __DIR__ . '/../controllers/AuthController.php';

Session::start();

if (Session::isAuthenticated()) {
    header('Location: index.php');
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $error = 'Veuillez renseigner identifiant et mot de passe.';
    } elseif (AuthController::attemptLogin($username, $password)) {
        header('Location: index.php');
        exit;
    } else {
        $error = 'Identifiants incorrects.';
    }
}

$pageTitle = 'Connexion';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <?php include __DIR__ . '/../views/partials/head.php'; ?>
</head>
<body>
<div class="d-flex align-items-center justify-content-center" style="min-height:100vh;">
    <div class="im-card" style="width:100%; max-width:380px;">
        <div class="text-center mb-3">
            <i class="bi bi-hdd-network" style="font-size:2.2rem; color:var(--im-primary);"></i>
            <h4 class="mt-2 mb-0"><?= htmlspecialchars(APP_NAME, ENT_QUOTES) ?></h4>
            <p class="text-muted small mb-0">Connexion administrateur</p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger py-2 small"><?= htmlspecialchars($error, ENT_QUOTES) ?></div>
        <?php endif; ?>

        <form method="post" autocomplete="off">
            <?= Csrf::field() ?>
            <div class="mb-3">
                <label class="form-label small">Identifiant</label>
                <input type="text" name="username" class="form-control" required autofocus>
            </div>
            <div class="mb-3">
                <label class="form-label small">Mot de passe</label>
                <input type="password" name="password" class="form-control" required>
            </div>
            <button type="submit" class="btn btn-primary w-100">Se connecter</button>
        </form>
    </div>
</div>
</body>
</html>
