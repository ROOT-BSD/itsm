-- Роль виконавця в пункті шаблону (напр., «Керівник проєкту»): при застосуванні шаблону замість ролі підставляють людину.
ALTER TABLE template_items ADD COLUMN IF NOT EXISTS assignee_role VARCHAR(60) NULL AFTER priority;
