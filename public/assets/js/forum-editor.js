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
            if (button.getAttribute('data-forum-post-id')) {
                data.append('post_id', button.getAttribute('data-forum-post-id'));
            }
            var pickedInput = document.querySelector('[data-forum-files="' + button.getAttribute('data-forum-preview-button') + '"]');
            if (pickedInput && pickedInput.files && pickedInput.files.length) {
                data.append('pending', JSON.stringify(Array.prototype.map.call(pickedInput.files, function (f) { return f.name; })));
            }
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

    // ------------------------------------------------------------------ вставка розмітки вкладення в текст

    function insertAtCursor(textarea, markup) {
        var start = textarea.selectionStart != null ? textarea.selectionStart : textarea.value.length;
        var end = textarea.selectionEnd != null ? textarea.selectionEnd : start;
        textarea.value = textarea.value.slice(0, start) + markup + textarea.value.slice(end);
        var pos = start + markup.length;
        textarea.focus();
        textarea.setSelectionRange(pos, pos);
        textarea.dispatchEvent(new Event('input', { bubbles: true }));
    }

    // Кнопки «Вставити» біля вже прикріплених файлів (форма редагування).
    document.querySelectorAll('[data-forum-insert]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var target = document.getElementById(btn.getAttribute('data-forum-insert-target'));
            if (target) {
                insertAtCursor(target, btn.getAttribute('data-forum-insert'));
            }
        });
    });

    // Кнопки «Вставити» для файлів, щойно вибраних у полі «Файли» (номер = після вже прикріплених).
    document.querySelectorAll('[data-forum-files]').forEach(function (input) {
        var target = document.getElementById(input.getAttribute('data-forum-files'));
        var list = input.parentNode.querySelector('[data-forum-insert-list]');
        var offset = parseInt(input.getAttribute('data-forum-offset') || '0', 10);
        if (!target || !list) {
            return;
        }
        input.addEventListener('change', function () {
            list.textContent = '';
            Array.prototype.forEach.call(input.files, function (file, i) {
                var n = offset + i + 1;
                var isImage = /\.(jpe?g|png)$/i.test(file.name);
                var label = file.name.replace(/\.[^.]*$/, '').replace(/[\[\]\\\n]/g, ' ').trim() || 'файл';
                var markup = (isImage ? '!' : '') + '[' + label + '](file:' + n + ')';
                var b = document.createElement('button');
                b.type = 'button';
                b.className = 'btn btn-sm btn-outline-secondary';
                b.textContent = 'Вставити «' + file.name + '» (file:' + n + ')';
                b.addEventListener('click', function () { insertAtCursor(target, markup); });
                list.appendChild(b);
            });
        });
    });
})();
