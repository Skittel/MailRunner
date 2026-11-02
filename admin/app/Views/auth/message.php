<!doctype html>
<html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= esc($title) ?> · MailRunner Admin</title><link rel="stylesheet" href="/assets/vendor/tabler/tabler.min.css"><link rel="stylesheet" href="/assets/admin.css"><link rel="icon" href="/favicon.ico" sizes="any"><link rel="apple-touch-icon" href="/assets/img/mailrunner-icon.png"></head>
<body class="d-flex flex-column">
<div class="page page-center"><div class="container container-tight py-4">
    <div class="text-center mb-4"><img src="/assets/img/mailrunner-icon.png" alt="MailRunner" width="72" height="72"></div>
    <div class="card card-md"><div class="card-body text-center">
        <h2 class="h2 mb-3"><?= esc($title) ?></h2>
        <p class="text-secondary"><?= esc($text) ?></p>
        <a href="/auth/login" class="btn btn-outline-primary mt-2">Zur Anmeldung</a>
    </div></div>
</div></div>
</body></html>
