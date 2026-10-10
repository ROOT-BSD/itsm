<?php
/**
 * Панель «Вкладення» — спільна для сторінок тікета й задачі.
 * Очікує від сторінки, що її підключає:
 *   $attachmentOwnerType — 'ticket' або 'task'
 *   $attachmentOwnerId   — id тікета/задачі
 *   $attachments         — список вкладень (Attachment::forOwner)
 */
$ownerPath = $attachmentOwnerType === 'ticket' ? 'tickets' : 'tasks';
$fileMax = \App\Core\UploadLimits::effectiveFileMax();
$postMax = \App\Core\UploadLimits::postMax();
$maxPerRequest = (int) \App\Core\Config::get('attachments.max_per_request', 10);
$isAdminUser = \App\Core\Auth::hasRole(['admin']);
?>
<div class="card mb-4">
    <div class="card-header">Вкладення (<?= count($attachments) ?>)</div>
    <div class="card-body">
        <?php if (empty($attachments)): ?>
            <p class="text-muted mb-3">Файлів ще не прикріплено.</p>
        <?php else: ?>
            <ul class="list-group mb-3">
                <?php foreach ($attachments as $a): ?>
                    <?php $isImage = str_starts_with($a['mime_type'], 'image/'); ?>
                    <li class="list-group-item d-flex align-items-center gap-3">
                        <?php if ($isImage): ?>
                            <a href="/attachments/<?= (int)$a['id'] ?>" target="_blank" rel="noopener">
                                <img src="/attachments/<?= (int)$a['id'] ?>?thumb=1" alt="<?= \App\Core\View::e($a['original_name']) ?>" class="attachment-thumb" loading="lazy">
                            </a>
                        <?php else: ?>
                            <span class="attachment-icon badge bg-danger align-self-start">PDF</span>
                        <?php endif; ?>
                        <div class="flex-grow-1">
                            <a href="/attachments/<?= (int)$a['id'] ?>" target="_blank" rel="noopener"><?= \App\Core\View::e($a['original_name']) ?></a>
                            <div class="small text-muted">
                                <?= \App\Core\View::e(\App\Core\UploadLimits::human((int)$a['size_bytes'])) ?>
                                · <?= \App\Core\View::e($a['uploader_name'] ?? (['portal' => 'заявник (портал)', 'email' => 'з електронного листа'][$a['source'] ?? 'web'] ?? 'користувача видалено')) ?>
                                · <?= \App\Core\View::e(date('d.m.Y H:i', strtotime($a['created_at']))) ?>
                            </div>
                        </div>
                        <a href="/attachments/<?= (int)$a['id'] ?>?download=1" class="btn btn-sm btn-outline-secondary">Завантажити</a>
                        <?php if ($isAdminUser || (int)$a['uploaded_by'] === \App\Core\Auth::id()): ?>
                            <form method="post" action="/attachments/<?= (int)$a['id'] ?>/delete"
                                  data-confirm="Видалити вкладення «<?= \App\Core\View::e($a['original_name']) ?>»? Це незворотно.">
                                <?= \App\Core\Csrf::field() ?>
                                <button type="submit" class="btn btn-sm btn-outline-danger">Видалити</button>
                            </form>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <form method="post" action="/<?= $ownerPath ?>/<?= (int)$attachmentOwnerId ?>/attachments" enctype="multipart/form-data">
            <?= \App\Core\Csrf::field() ?>
            <div class="input-group">
                <input type="file" name="files[]" class="form-control" multiple required
                       accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf">
                <button class="btn btn-outline-primary" type="submit">Прикріпити</button>
            </div>
            <div class="form-text">
                Дозволено JPG, PNG та PDF. До <?= \App\Core\View::e(\App\Core\UploadLimits::human($fileMax)) ?> на файл,
                до <?= $maxPerRequest ?> файлів за раз (разом не більше <?= \App\Core\View::e(\App\Core\UploadLimits::human($postMax)) ?>).
            </div>
        </form>
    </div>
</div>
