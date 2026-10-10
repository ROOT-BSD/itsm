/*
 * Форум: кнопка «Попередній перегляд». Надсилає текст на /forum/preview і показує HTML, який відрендерив сервер
 * (App\Core\Markdown) — те саме, що побачать читачі. Відповідь уже безпечна (увесь текст екранований на сервері).
 * Без цього скрипта форма працює як раніше: просто немає перегляду.
 */
(function () {
    'use strict';

    document.querySelectorAll('[data-forum-preview-button]').forEach(function (button) {
        var textarea = document.getElementById(button.getAttribute('data-forum-preview-button'));
        var pane = document.querySelector('[data-forum-preview-pane="' + button.getAttribute('data-forum-preview-button') + '"]');
        var form = button.closest('form');
        if (!textarea || !pane || !form) {
            return;
        }
        var label = button.textContent;
        var showing = false;

        function hide() {
            pane.hidden = true;
            pane.textContent = '';
            button.textContent = label;
            showing = false;
        }

        button.addEventListener('click', function () {
            if (showing) {
                hide();
                return;
            }
            var tokenInput = form.querySelector('input[name="csrf_token"]');
            var data = new FormData();
            data.append('body', textarea.value);
            data.append('csrf_token', tokenInput ? tokenInput.value : '');
            button.disabled = true;
            button.textContent = 'Завантаження…';

            fetch('/forum/preview', { method: 'POST', body: data, credentials: 'same-origin' })
                .then(function (response) {
                    return response.text().then(function (html) { return { ok: response.ok, html: html }; });
                })
                .then(function (result) {
                    if (result.ok) {
                        pane.innerHTML = result.html;
                    } else {
                        pane.textContent = 'Не вдалося побудувати перегляд (можливо, текст завеликий або сесія застаріла).';
                    }
                    pane.hidden = false;
                    button.textContent = 'Сховати перегляд';
                    showing = true;
                })
                .catch(function () {
                    pane.textContent = 'Не вдалося з\'єднатися з сервером.';
                    pane.hidden = false;
                    button.textContent = 'Сховати перегляд';
                    showing = true;
                })
                .then(function () { button.disabled = false; });
        });

        // Після зміни тексту застарілий перегляд ховаємо.
        textarea.addEventListener('input', function () {
            if (showing) {
                hide();
            }
        });
    });
})();
