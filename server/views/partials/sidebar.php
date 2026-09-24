<?php
/** @var string $activePage */
$navItems = [
    'dashboard'    => ['index.php', 'bi-speedometer2', 'Tableau de bord'],
    'devices'      => ['devices.php', 'bi-pc-display', 'Postes'],
    'alerts'       => ['alerts.php', 'bi-exclamation-triangle', 'Alertes'],
    'availability' => ['availability.php', 'bi-graph-up', 'Disponibilite'],
    'ghosts'       => ['ghost_devices.php', 'bi-wifi-off', 'Appareils inconnus'],
    'settings'     => ['settings.php', 'bi-gear', 'Reglages'],
    'account'      => ['account.php', 'bi-person-gear', 'Mon compte'],
];
?>
<aside class="im-sidebar" id="imSidebar">
    <div class="brand">
        <i class="bi bi-hdd-network"></i>
        <span>Intranet Monitor <small class="text-primary">PRO</small></span>
    </div>
    <nav>
        <?php foreach ($navItems as $key => [$href, $icon, $label]): ?>
            <a href="<?= htmlspecialchars($href, ENT_QUOTES) ?>" class="<?= ($activePage ?? '') === $key ? 'active' : '' ?>">
                <i class="bi <?= $icon ?>"></i> <?= htmlspecialchars($label, ENT_QUOTES) ?>
            </a>
        <?php endforeach; ?>
    </nav>
</aside>
