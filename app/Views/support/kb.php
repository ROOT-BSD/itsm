<div class="row justify-content-center">
    <div class="col-lg-8">
        <p class="small"><a href="/support/kb">&larr; Уся база знань</a> · <a href="/support">Подати звернення</a></p>
        <h2 class="mb-1"><?= \App\Core\View::e($page['title']) ?></h2>
        <div class="text-muted small mb-3">База знань · оновлено <?= \App\Core\View::e(date('d.m.Y', strtotime((string) $page['updated_at']))) ?></div>
        <div class="card"><div class="card-body wiki-content"><?= $html /* HTML від App\Core\Markdown: увесь текст екранований, посилання проходять білий список */ ?></div></div>
        <p class="mt-3 text-muted">Не допомогло? <a href="/support">Подайте звернення</a> — ми розберемося.</p>
    </div>
</div>
