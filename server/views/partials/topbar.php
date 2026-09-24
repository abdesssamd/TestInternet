<header class="im-topbar">
    <div class="d-flex align-items-center gap-2">
        <button id="sidebarToggle" class="btn btn-sm btn-outline-secondary d-md-none"><i class="bi bi-list"></i></button>
        <h5 class="mb-0"><?= htmlspecialchars($pageTitle ?? '', ENT_QUOTES) ?></h5>
    </div>
    <div class="d-flex align-items-center gap-3">
        <button id="themeToggle" class="im-theme-toggle" title="Basculer le theme">
            <i class="bi bi-moon-stars"></i>
        </button>
        <a href="account.php" class="text-muted small text-decoration-none" title="Mon compte">
            <i class="bi bi-person-circle"></i> <?= htmlspecialchars($_SESSION['username'] ?? '', ENT_QUOTES) ?>
        </a>
        <a href="logout.php" class="btn btn-sm btn-outline-danger">
            <i class="bi bi-box-arrow-right"></i> Deconnexion
        </a>
    </div>
</header>
