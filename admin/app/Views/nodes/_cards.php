<?php if ($nodes === []): ?>
    <div class="empty"><p class="empty-title">Noch keine Node</p><p class="empty-subtitle text-secondary">Legen Sie die erste Node an.</p>
        <div class="empty-action"><a href="/nodes/new" class="btn btn-primary">Node anlegen</a></div></div>
<?php else: ?>
<div class="row row-cards">
    <?php foreach ($nodes as $n): ?><div class="col-md-6 col-xl-4"><?= view('nodes/_card', ['n' => $n, 'detail' => false]) ?></div><?php endforeach ?>
</div>
<?php endif ?>
