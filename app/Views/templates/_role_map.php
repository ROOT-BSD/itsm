<?php
/** Вибір людей для ролей виконавців шаблону. Очікує $roleTemplates: [template_id => [ролі]], $roleUsers: активні користувачі. Показується для вибраного шаблону (template-apply.js). */
$__e = static fn($v) => \App\Core\View::e((string) $v);
if (!empty($roleTemplates)): ?>
<div class="col-12" data-role-map>
    <?php foreach ($roleTemplates as $tid => $roles): ?>
        <div class="row g-2 mb-1 d-none" data-role-tpl="<?= (int) $tid ?>">
            <div class="col-12 small text-muted">Виконавці за ролями шаблону (необов'язково — без вибору задача лишається непризначеною):</div>
            <?php foreach ($roles as $role): ?>
                <div class="col-md-4">
                    <label class="form-label small mb-1"><?= $__e($role) ?></label>
                    <select name="role_user[<?= $__e($role) ?>]" class="form-select form-select-sm" disabled>
                        <option value="">— не призначати —</option>
                        <?php foreach ($roleUsers as $u): ?><option value="<?= (int) $u['id'] ?>"><?= $__e($u['full_name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
