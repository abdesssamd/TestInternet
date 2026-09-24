<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($pageTitle ?? APP_NAME, ENT_QUOTES) ?> — <?= htmlspecialchars(APP_NAME, ENT_QUOTES) ?></title>
<link rel="stylesheet" href="<?= APP_BASE_PATH ?>/assets/vendor/bootstrap/css/bootstrap.min.css">
<link rel="stylesheet" href="<?= APP_BASE_PATH ?>/assets/vendor/bootstrap-icons/bootstrap-icons.min.css">
<link rel="stylesheet" href="<?= APP_BASE_PATH ?>/assets/css/app.css">
<script>
    (function() {
        try {
            var theme = localStorage.getItem('im_theme');
            if (theme) document.documentElement.setAttribute('data-theme', theme);
        } catch (e) {}
    })();
    window.IM_API_BASE = "<?= APP_BASE_PATH ?>/api";
</script>
