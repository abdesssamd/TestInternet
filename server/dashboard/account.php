<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../middleware/Session.php';
require_once __DIR__ . '/../middleware/Csrf.php';
require_once __DIR__ . '/../models/User.php';

Session::start();
Session::requireAuth();

// Longueur minimale du nouveau mot de passe. Volontairement explicite ici :
// c'est la seule regle appliquee, autant qu'elle soit lisible d'un coup d'oeil.
const PASSWORD_MIN_LENGTH = 10;

$error   = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();

    $current = (string) ($_POST['current_password'] ?? '');
    $new     = (string) ($_POST['new_password'] ?? '');
    $confirm = (string) ($_POST['confirm_password'] ?? '');

    $user = UserModel::findById((int) $_SESSION['user_id']);

    if (!$user) {
        // La session pointe sur un compte supprime entre-temps : on la coupe.
        Session::destroy();
        header('Location: login.php');
        exit;
    }

    if ($current === '' || $new === '' || $confirm === '') {
        $error = 'Tous les champs sont obligatoires.';
    } elseif (!password_verify($current, $user['password_hash'])) {
        $error = 'Le mot de passe actuel est incorrect.';
    } elseif (mb_strlen($new) < PASSWORD_MIN_LENGTH) {
        $error = 'Le nouveau mot de passe doit contenir au moins ' . PASSWORD_MIN_LENGTH . ' caracteres.';
    } elseif ($new !== $confirm) {
        $error = 'La confirmation ne correspond pas au nouveau mot de passe.';
    } elseif (password_verify($new, $user['password_hash'])) {
        $error = 'Le nouveau mot de passe doit etre different de l\'actuel.';
    } else {
        UserModel::updatePassword((int) $user['id'], $new);
        // Nouvel identifiant de session apres un changement d'identifiants :
        // une session eventuellement volee avant le changement devient inutile.
        Session::regenerate();
        $success = 'Mot de passe modifie. Il sera demande a votre prochaine connexion.';
    }
}

$pageTitle  = 'Mon compte';
$activePage = 'account';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <?php include __DIR__ . '/../views/partials/head.php'; ?>
</head>
<body>
<div class="im-layout">
    <?php include __DIR__ . '/../views/partials/sidebar.php'; ?>
    <div class="im-main">
        <?php include __DIR__ . '/../views/partials/topbar.php'; ?>
        <main class="im-content">

            <div class="im-card mb-4" style="max-width:560px;">
                <h6 class="mb-3"><i class="bi bi-key"></i> Changer mon mot de passe</h6>

                <?php if ($error): ?>
                    <div class="alert alert-danger py-2 small"><?= htmlspecialchars($error, ENT_QUOTES) ?></div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="alert alert-success py-2 small"><?= htmlspecialchars($success, ENT_QUOTES) ?></div>
                <?php endif; ?>

                <form method="post" autocomplete="off">
                    <?= Csrf::field() ?>

                    <div class="mb-3">
                        <label class="form-label small" for="current_password">Mot de passe actuel</label>
                        <input type="password" id="current_password" name="current_password"
                               class="form-control" required autofocus autocomplete="current-password">
                    </div>

                    <div class="mb-3">
                        <label class="form-label small" for="new_password">Nouveau mot de passe</label>
                        <input type="password" id="new_password" name="new_password"
                               class="form-control" required minlength="<?= PASSWORD_MIN_LENGTH ?>"
                               autocomplete="new-password">
                        <div class="form-text"><?= PASSWORD_MIN_LENGTH ?> caracteres minimum.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small" for="confirm_password">Confirmer le nouveau mot de passe</label>
                        <input type="password" id="confirm_password" name="confirm_password"
                               class="form-control" required minlength="<?= PASSWORD_MIN_LENGTH ?>"
                               autocomplete="new-password">
                    </div>

                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg"></i> Enregistrer
                    </button>
                </form>
            </div>

            <div class="im-card" style="max-width:560px;">
                <h6 class="mb-3"><i class="bi bi-person-circle"></i> Compte</h6>
                <table class="im-table">
                    <tbody>
                        <tr>
                            <th style="width:45%;">Identifiant</th>
                            <td><?= htmlspecialchars($_SESSION['username'] ?? '', ENT_QUOTES) ?></td>
                        </tr>
                        <tr>
                            <th>Role</th>
                            <td><?= htmlspecialchars($_SESSION['role'] ?? '', ENT_QUOTES) ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>

        </main>
    </div>
</div>

<script src="<?= APP_BASE_PATH ?>/assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="<?= APP_BASE_PATH ?>/assets/js/app.js"></script>
<script src="<?= APP_BASE_PATH ?>/assets/js/notifications.js"></script>
</body>
</html>
