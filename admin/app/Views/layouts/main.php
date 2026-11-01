<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= csrf_hash() ?>">
    <title><?= esc($title ?? 'MailRunner Admin') ?> · MailRunner Admin</title>
    <link rel="stylesheet" href="/assets/vendor/tabler/tabler.min.css">
    <link rel="stylesheet" href="/assets/admin.css">
</head>
<body hx-headers='{"<?= esc(config('Security')->headerName, 'attr') ?>": "<?= csrf_hash() ?>"}'>
<div class="page">
    <header class="navbar navbar-expand-md d-print-none">
        <div class="container-xl">
            <a href="/" class="navbar-brand navbar-brand-autodark pe-0 pe-md-3">
                <span class="brand-mark">MR</span> MailRunner <span class="text-secondary fw-normal ms-1">Admin</span>
            </a>
            <div class="navbar-nav flex-row order-md-last">
                <div class="nav-item text-secondary small me-3 d-none d-md-block"><?= esc(session('user_name')) ?></div>
                <a class="btn btn-sm btn-outline-secondary" href="/auth/logout">Abmelden</a>
            </div>
        </div>
    </header>
    <header class="navbar-expand-md">
        <div class="navbar">
            <div class="container-xl">
                <?php $nav = ['/' => 'Übersicht', '/nodes' => 'Nodes', '/customers' => 'Kunden', '/role-mails' => 'postmaster & abuse', '/audit' => 'Protokoll'];
                $path = '/' . trim(service('request')->getUri()->getPath(), '/'); ?>
                <ul class="navbar-nav">
                    <?php foreach ($nav as $href => $label): $active = $href === '/' ? $path === '/' : str_starts_with($path, $href); ?>
                        <li class="nav-item<?= $active ? ' active' : '' ?>"><a class="nav-link" href="<?= $href ?>"><span class="nav-link-title"><?= $label ?></span></a></li>
                    <?php endforeach ?>
                </ul>
            </div>
        </div>
    </header>
    <div class="page-wrapper">
        <div class="page-header d-print-none">
            <div class="container-xl">
                <div class="row g-2 align-items-center">
                    <div class="col">
                        <?php if (! empty($pretitle)): ?><div class="page-pretitle"><?= $pretitle ?></div><?php endif ?>
                        <h2 class="page-title"><?= esc($title ?? '') ?></h2>
                    </div>
                    <div class="col-auto ms-auto d-print-none"><?= $this->renderSection('actions') ?></div>
                </div>
            </div>
        </div>
        <div class="page-body">
            <div class="container-xl">
                <?php if ($m = session()->getFlashdata('ok')): ?><div class="alert alert-success"><?= esc($m) ?></div><?php endif ?>
                <?php if ($m = session()->getFlashdata('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif ?>
                <?= $this->renderSection('content') ?>
            </div>
        </div>
        <footer class="footer footer-transparent d-print-none">
            <div class="container-xl text-secondary small">MailRunner Admin · <a href="https://github.com/Skittel/MailRunner" class="link-secondary">github.com/Skittel/MailRunner</a></div>
        </footer>
    </div>
</div>
<script src="/assets/vendor/tabler/tabler.min.js" defer></script>
<script src="/assets/vendor/htmx/htmx.min.js" defer></script>
</body>
</html>
