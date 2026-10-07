/*
 * Попередній перегляд у редакторі вікі: надсилає текст на /wiki/preview і показує відрендерений HTML.
 * Рендерить сервер (App\Core\Markdown) — те саме, що побачать читачі, а не окрема клієнтська копія з
 * іншою поведінкою. Відповідь уже безпечна (увесь текст екранований на сервері), тому вставляється як HTML.
 * Без цього скрипта редактор — звичайна форма; зберегти сторінку можна й без перегляду.
 */
(function () {
    'use strict';

    var button = document.querySelector('[data-wiki-preview-button]');
    var pane = document.querySelector('[data-wiki-preview]');
    var textarea = document.getElementById('wiki-content');
    if (!button || !pane || !textarea) {
        return;
    }
    var form = button.closest('form');
    var showing = false;

    function hidePreview() {
        pane.hidden = true;
        pane.textContent = '';
        button.textContent = 'Попередній перегляд';
        showing = false;
    }

    button.addEventListener('click', function () {
        if (showing) {
            hidePreview();
            return;
        }
        var data = new FormData();
        data.append('content', textarea.value);
        data.append('csrf_token', form.querySelector('input[name="csrf_token"]').value);
        button.disabled = true;
        button.textContent = 'Завантаження…';

        fetch('/wiki/preview', { method: 'POST', body: data, credentials: 'same-origin' })
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

    // Після будь-якої зміни тексту застарілий перегляд ховаємо — щоб не показувати непевний стан.
    textarea.addEventListener('input', function () {
        if (showing) {
            hidePreview();
        }
    });
})();
