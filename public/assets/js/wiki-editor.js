/*
 * Редактор вікі.
 *  1) Попередній перегляд: надсилає текст на /wiki/preview і показує відрендерений HTML. Рендерить сервер
 *     (App\Core\Markdown) — те саме, що побачать читачі, а не окрема клієнтська копія з іншою поведінкою.
 *     Відповідь уже безпечна (увесь текст екранований на сервері), тому вставляється як HTML.
 *  2) Вкладення: завантаження файлів через fetch (без перезавантаження сторінки — незбережений текст не губиться),
 *     вставка розмітки на місці курсора, перетягування файлів і вставка зображень з буфера в поле тексту, видалення.
 * Без цього скрипта редактор — звичайна форма: зберегти сторінку можна й без перегляду, а файли завантажуються
 * окремою формою з перезавантаженням сторінки.
 */
(function () {
    'use strict';

    var textarea = document.getElementById('wiki-content');
    if (!textarea) {
        return;
    }
    var form = textarea.closest('form');
    var csrf = function () {
        var input = form.querySelector('input[name="csrf_token"]');
        return input ? input.value : '';
    };

    // ------------------------------------------------------------------ попередній перегляд

    var button = document.querySelector('[data-wiki-preview-button]');
    var pane = document.querySelector('[data-wiki-preview]');
    var showing = false;

    function hidePreview() {
        if (!pane) { return; }
        pane.hidden = true;
        pane.textContent = '';
        button.textContent = 'Попередній перегляд';
        showing = false;
    }

    if (button && pane) {
        button.addEventListener('click', function () {
            if (showing) {
                hidePreview();
                return;
            }
            var data = new FormData();
            data.append('content', textarea.value);
            data.append('slug', form.getAttribute('data-wiki-slug') || '');
            data.append('csrf_token', csrf());
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
    }

    // Після будь-якої зміни тексту застарілий перегляд ховаємо — щоб не показувати непевний стан.
    textarea.addEventListener('input', function () {
        if (showing) {
            hidePreview();
        }
    });

    // ------------------------------------------------------------------ вкладення

    var panel = document.querySelector('[data-wiki-attachments]');
    var uploadUrl = panel ? panel.getAttribute('data-upload-url') : null;
    var list = panel ? panel.querySelector('[data-wiki-attachment-list]') : null;
    var errorBox = panel ? panel.querySelector('[data-wiki-attach-error]') : null;
    if (!panel || !uploadUrl || !list) {
        return; // нова сторінка або вкладення не підготовлені: лишається звичайний редактор
    }
    var maxBytes = parseInt(panel.getAttribute('data-max-bytes') || '0', 10);

    function showError(message) {
        if (!errorBox) { return; }
        errorBox.textContent = message || '';
        errorBox.hidden = !message;
    }

    function insertAtCursor(text) {
        var start = textarea.selectionStart;
        var end = textarea.selectionEnd;
        var before = start > 0 ? textarea.value.charAt(start - 1) : '\n';
        var insert = (before === '\n' ? '' : '\n') + text + '\n';
        textarea.setRangeText(insert, start, end, 'end');
        textarea.dispatchEvent(new Event('input', { bubbles: true }));
        textarea.focus();
    }

    function humanSize(bytes) {
        return bytes >= 1048576 ? (Math.round(bytes / 104857.6) / 10) + ' МБ' : Math.max(1, Math.round(bytes / 1024)) + ' КБ';
    }

    function addRow(item) {
        var li = document.createElement('li');
        li.className = 'list-group-item d-flex justify-content-between align-items-center gap-2 flex-wrap';

        var info = document.createElement('span');
        var link = document.createElement('a');
        link.href = '/wiki/files/' + item.id;
        link.target = '_blank';
        link.rel = 'noopener noreferrer';
        link.textContent = item.name;
        var size = document.createElement('span');
        size.className = 'text-muted small ms-1';
        size.textContent = item.size;
        info.appendChild(link);
        info.appendChild(size);

        var actions = document.createElement('span');
        actions.className = 'd-flex gap-2';
        var insert = document.createElement('button');
        insert.type = 'button';
        insert.className = 'btn btn-sm btn-outline-secondary';
        insert.setAttribute('data-wiki-insert', item.markdown);
        insert.textContent = 'Вставити';

        var del = document.createElement('form');
        del.method = 'post';
        del.action = uploadUrl + '/' + item.id + '/delete';
        del.setAttribute('data-wiki-attachment-delete', '');
        del.setAttribute('data-confirm', 'Видалити файл «' + item.name + '»? Посилання на нього в тексті перестануть працювати.');
        var token = document.createElement('input');
        token.type = 'hidden';
        token.name = 'csrf_token';
        token.value = csrf();
        var submit = document.createElement('button');
        submit.type = 'submit';
        submit.className = 'btn btn-sm btn-outline-danger';
        submit.textContent = 'Видалити';
        del.appendChild(token);
        del.appendChild(submit);

        actions.appendChild(insert);
        actions.appendChild(del);
        li.appendChild(info);
        li.appendChild(actions);
        list.appendChild(li);
    }

    var queue = Promise.resolve();

    // Файли вантажаться по черзі: так розмітка вставляється в тому порядку, в якому файли обрано.
    function uploadFiles(files) {
        Array.prototype.forEach.call(files, function (file) {
            queue = queue.then(function () { return uploadOne(file); });
        });
    }

    function uploadOne(file) {
        showError('');
        if (maxBytes > 0 && file.size > maxBytes) {
            showError('Файл «' + file.name + '» завеликий (' + humanSize(file.size) + '), максимум — ' + humanSize(maxBytes) + '.');
            return Promise.resolve();
        }
        var data = new FormData();
        data.append('file', file, file.name);
        data.append('csrf_token', csrf());
        panel.classList.add('opacity-50');

        return fetch(uploadUrl, { method: 'POST', body: data, credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (response) {
                return response.json().catch(function () { return null; }).then(function (body) { return { status: response.status, body: body }; });
            })
            .then(function (result) {
                if (result.body && result.body.ok) {
                    addRow(result.body);
                    insertAtCursor(result.body.markdown);
                } else if (result.body && result.body.error) {
                    showError(result.body.error);
                } else if (result.status === 419) {
                    showError('Сесія застаріла — оновіть сторінку (спершу збережіть текст, скопіювавши його).');
                } else {
                    showError('Не вдалося завантажити «' + file.name + '» (код ' + result.status + '). Можливо, файл завеликий для сервера.');
                }
            })
            .catch(function () {
                showError('Не вдалося з\'єднатися з сервером.');
            })
            .then(function () { panel.classList.remove('opacity-50'); });
    }

    // Форма «Завантажити» (працює і без JS; з JS — без перезавантаження)
    var uploadForm = panel.querySelector('[data-wiki-upload-form]');
    if (uploadForm) {
        uploadForm.addEventListener('submit', function (event) {
            var input = uploadForm.querySelector('input[type="file"]');
            event.preventDefault();
            if (input.files.length > 0) {
                uploadFiles(input.files);
                input.value = '';
            }
        });
    }

    // «Вставити»
    panel.addEventListener('click', function (event) {
        var target = event.target.closest('[data-wiki-insert]');
        if (target) {
            insertAtCursor(target.getAttribute('data-wiki-insert'));
        }
    });

    // Видалення без перезавантаження. Підтвердження показує глобальний обробник (data-confirm) — він спрацьовує
    // раніше й скасовує подію, якщо користувач відмовився.
    document.addEventListener('submit', function (event) {
        var del = event.target.closest ? event.target.closest('form[data-wiki-attachment-delete]') : null;
        if (!del || event.defaultPrevented) {
            return;
        }
        event.preventDefault();
        showError('');
        fetch(del.action, { method: 'POST', body: new FormData(del), credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (response) { return response.json().catch(function () { return null; }); })
            .then(function (body) {
                if (body && body.ok) {
                    var row = del.closest('li');
                    if (row) { row.remove(); }
                } else {
                    showError('Не вдалося видалити файл — оновіть сторінку й спробуйте ще раз.');
                }
            })
            .catch(function () { showError('Не вдалося з\'єднатися з сервером.'); });
    });

    // Вставка з буфера: тільки якщо в ньому є файли (звичайний текст вставляється як завжди)
    textarea.addEventListener('paste', function (event) {
        var files = event.clipboardData && event.clipboardData.files;
        if (files && files.length > 0) {
            event.preventDefault();
            uploadFiles(files);
        }
    });

    // Перетягування файлів у поле тексту
    textarea.addEventListener('dragover', function (event) {
        if (event.dataTransfer && Array.prototype.indexOf.call(event.dataTransfer.types || [], 'Files') !== -1) {
            event.preventDefault();
        }
    });
    textarea.addEventListener('drop', function (event) {
        if (event.dataTransfer && event.dataTransfer.files && event.dataTransfer.files.length > 0) {
            event.preventDefault();
            uploadFiles(event.dataTransfer.files);
        }
    });
})();
