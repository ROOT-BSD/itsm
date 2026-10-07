/*
 * Зображення у формах створення тікета: вибір файлів і ВСТАВКА зі скриншота (Ctrl+V).
 *
 * Прогресивне покращення блоку <div data-paste-images> (див. app/Views/attachments/_picker.php):
 * без цього скрипта це звичайне поле вибору файлу, яке працює як є. Скрипт додає:
 *   - вставку зображень з буфера обміну (скриншот Win+Shift+S / PrintScreen, «Копіювати зображення»);
 *   - спільний список вибраного (з мініатюрами) і видалення окремих файлів зі списку;
 *   - швидку перевірку типу/кількості/розміру ДО надсилання форми.
 * Сервер усе одно перевіряє все повторно за вмістом файлу — клієнтська перевірка лише зручність.
 *
 * Мініатюри — data:-URL (FileReader), а не blob:-URL: CSP сайту дозволяє зображення лише з 'self' та data:.
 * Тексти вставляються через textContent, тож імена файлів не можуть стати розміткою.
 */
(function () {
    'use strict';

    var ALLOWED = { 'image/png': 'png', 'image/jpeg': 'jpg' };

    function formatSize(bytes) {
        return bytes >= 1048576 ? (bytes / 1048576).toFixed(1).replace(/\.0$/, '') + ' МБ' : Math.max(1, Math.round(bytes / 1024)) + ' КБ';
    }

    function pad(n) {
        return (n < 10 ? '0' : '') + n;
    }

    function init(box) {
        var input = box.querySelector('[data-paste-input]');
        var list = box.querySelector('[data-paste-list]');
        var errorBox = box.querySelector('[data-paste-error]');
        var form = box.closest('form');
        if (!input || !list || !form || typeof DataTransfer === 'undefined') {
            return; // старий браузер — лишається звичайне поле файлу
        }

        var maxFiles = parseInt(box.dataset.maxFiles, 10) || 10;
        var maxBytes = parseInt(box.dataset.maxBytes, 10) || 10485760;
        var files = [];
        var pasteCounter = 0;

        function sameFile(a, b) {
            return a.name === b.name && a.size === b.size && a.lastModified === b.lastModified;
        }

        function showErrors(messages) {
            errorBox.textContent = messages.join(' ');
            errorBox.hidden = messages.length === 0;
        }

        // Єдине джерело правди — масив files; поле <input> завжди відображає його (через DataTransfer),
        // тому форма надсилає саме те, що видно у списку.
        function syncInput() {
            var transfer = new DataTransfer();
            files.forEach(function (f) { transfer.items.add(f); });
            input.files = transfer.files;
        }

        function render() {
            list.textContent = '';
            files.forEach(function (file, index) {
                var li = document.createElement('li');
                li.className = 'list-group-item d-flex align-items-center gap-3 py-2';

                var thumb = document.createElement('img');
                thumb.className = 'paste-thumb';
                thumb.alt = '';
                var reader = new FileReader();
                reader.onload = function () { thumb.src = reader.result; };
                reader.readAsDataURL(file);

                var name = document.createElement('div');
                name.className = 'flex-grow-1 text-break';
                name.textContent = file.name;
                var size = document.createElement('div');
                size.className = 'small text-muted';
                size.textContent = formatSize(file.size);
                name.appendChild(size);

                var remove = document.createElement('button');
                remove.type = 'button';
                remove.className = 'btn btn-sm btn-outline-danger';
                remove.textContent = 'Прибрати';
                remove.addEventListener('click', function () {
                    files.splice(index, 1);
                    syncInput();
                    render();
                    showErrors([]);
                });

                li.appendChild(thumb);
                li.appendChild(name);
                li.appendChild(remove);
                list.appendChild(li);
            });
            list.hidden = files.length === 0;
        }

        // Додає кандидатів у список, відсіюючи неприпустимі, і повідомляє, що саме відкинуто та чому.
        function addFiles(candidates, pasted) {
            var errors = [];
            candidates.forEach(function (file) {
                if (!ALLOWED[file.type]) {
                    errors.push('«' + file.name + '»: дозволені лише PNG та JPG.');
                    return;
                }
                if (file.size > maxBytes) {
                    errors.push('«' + file.name + '»: більше ' + formatSize(maxBytes) + '.');
                    return;
                }
                if (files.length >= maxFiles) {
                    errors.push('Не більше ' + maxFiles + ' файлів — «' + file.name + '» не додано.');
                    return;
                }
                if (files.some(function (f) { return sameFile(f, file); })) {
                    return; // той самий файл уже у списку
                }
                if (pasted) {
                    // Chrome називає будь-який вставлений скриншот «image.png» — даємо впізнавану унікальну назву.
                    pasteCounter += 1;
                    var d = new Date();
                    var stamp = d.getFullYear() + pad(d.getMonth() + 1) + pad(d.getDate()) + '-' + pad(d.getHours()) + pad(d.getMinutes()) + pad(d.getSeconds());
                    file = new File([file], 'скриншот-' + stamp + '-' + pasteCounter + '.' + ALLOWED[file.type], { type: file.type, lastModified: Date.now() });
                }
                files.push(file);
            });
            syncInput();
            render();
            showErrors(errors);
        }

        // Вибір через діалог: ДОДАЄМО до наявного списку (за замовчуванням браузер замінив би його новим вибором).
        input.addEventListener('change', function () {
            var chosen = Array.prototype.slice.call(input.files);
            addFiles(chosen, false);
        });

        // Вставка з буфера обміну. Перехоплюємо лише коли в буфері є зображення; якщо там водночас є й текст
        // (копіювання з Word, зі сторінки) — не втручаємось, щоб звичайна вставка тексту не зламалась.
        document.addEventListener('paste', function (e) {
            if (!form.contains(e.target) && !form.contains(document.activeElement)) {
                return;
            }
            var data = e.clipboardData;
            if (!data || !data.files || data.files.length === 0) {
                return;
            }
            var images = Array.prototype.filter.call(data.files, function (f) { return f.type.indexOf('image/') === 0; });
            if (images.length === 0) {
                return;
            }
            if (!data.types || Array.prototype.indexOf.call(data.types, 'text/plain') === -1) {
                e.preventDefault();
            }
            addFiles(images, true);
        });

        // Повернення на форму після помилки (кнопка «Назад») може лишити в <input> файли без нашого списку.
        if (input.files && input.files.length) {
            addFiles(Array.prototype.slice.call(input.files), false);
        }
    }

    function initAll() {
        Array.prototype.forEach.call(document.querySelectorAll('[data-paste-images]'), init);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAll);
    } else {
        initAll();
    }
})();
