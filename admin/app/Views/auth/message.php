<!doctype html>
<html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= esc($title) ?> · MailRunner Admin</title><link rel="stylesheet" href="/assets/vendor/tabler/tabler.min.css"><link rel="stylesheet" href="/assets/admin.css"></head>
<body class="d-flex flex-column">
<div class="page page-center"><div class="container container-tight py-4">
    <div class="card card-md"><div class="card-body text-center">
        <h2 class="h2 mb-3"><?= esc($title) ?></h2>
        <p class="text-secondary"><?= esc($text) ?></p>
        <a href="/auth/login" class="btn btn-outline-primary mt-2">Zur Anmeldung</a>
    </div></div>
</div></div>
</body></html>
