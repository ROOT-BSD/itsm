/*
 * Журнал робіт задачі: вставка зображень у текст запису та перегляд.
 * Вибрані/вставлені (Ctrl+V)/перетягнуті зображення додаються у форму і позначаються в тексті мітками [[file:N]];
 * сервер при збереженні замінює їх на [[img:ID]] завантажених вкладень. Без цього скрипта форма працює як звичайна
 * (текст, години, дата, категорія), просто без вставки зображень і перегляду.
 */
(function () {
    'use strict';

    document.querySelectorAll('form[data-journal-form]').forEach(function (form) {
        var ta = form.querySelector('textarea[name="body"]');
        var input = form.querySelector('[data-journal-files]');
        var pick = form.querySelector('[data-journal-pick]');
        var info = form.querySelector('[data-journal-picked]');
        var pane = form.querySelector('[data-journal-pane]');
        var previewBtn = form.querySelector('[data-journal-preview]');
        var existing = form.querySelector('[data-journal-existing]');
        if (!ta || !input) { return; }
        var dt = typeof DataTransfer !== 'undefined' ? new DataTransfer() : null;

        // Підпис під лічильником хвилин: 70 → «1 год 10 хв».
        var minInput = form.querySelector('[data-journal-minutes]');
        var minLabel = form.querySelector('[data-journal-minutes-label]');
        function showMinutes() {
            if (!minInput || !minLabel) { return; }
            var m = parseInt(minInput.value, 10);
            if (isNaN(m) || m <= 0) { minLabel.textContent = 'без часу'; return; }
            var h = Math.floor(m / 60), r = m % 60;
            minLabel.textContent = (h ? h + ' год' : '') + (h && r ? ' ' : '') + (r ? r + ' хв' : '');
        }
        if (minInput) { minInput.addEventListener('input', showMinutes); minInput.addEventListener('change', showMinutes); showMinutes(); }
        if (!dt) { if (pick) { pick.hidden = true; } return; } // старий браузер: без вставки зображень

        function insertAtCursor(text) {
            var s = ta.selectionStart == null ? ta.value.length : ta.selectionStart;
            var e = ta.selectionEnd == null ? ta.value.length : ta.selectionEnd;
            var before = ta.value.slice(0, s), after = ta.value.slice(e);
            var pre = before && !/\s$/.test(before) ? ' ' : '';
            ta.value = before + pre + text + ' ' + after;
            var pos = (before + pre + text + ' ').length;
            ta.focus();
            ta.setSelectionRange(pos, pos);
        }

        function refresh() {
            input.files = dt.files;
            info.textContent = dt.files.length ? 'Додаються зображення: ' + dt.files.length : '';
        }

        function addFiles(list) {
            Array.prototype.forEach.call(list, function (f) {
                if (!/^image\/(jpeg|png|gif|webp)$/.test(f.type)) { return; }
                if (dt.files.length >= 10) { return; }
                // Скриншот із буфера має ім'я «image.png» — даємо зрозуміле.
                var file = f.name && f.name !== 'image.png' ? f : new File([f], 'зображення-' + (dt.files.length + 1) + '.png', { type: f.type });
                dt.items.add(file);
                insertAtCursor('[[file:' + dt.files.length + ']]');
            });
            refresh();
        }

        pick.addEventListener('click', function () {
            var tmp = document.createElement('input');
            tmp.type = 'file'; tmp.accept = 'image/jpeg,image/png,image/gif,image/webp'; tmp.multiple = true;
            tmp.addEventListener('change', function () { addFiles(tmp.files); });
            tmp.click();
        });
        ta.addEventListener('paste', function (e) {
            var items = e.clipboardData && e.clipboardData.files;
            if (items && items.length) {
                var imgs = Array.prototype.filter.call(items, function (f) { return /^image\//.test(f.type); });
                if (imgs.length) { e.preventDefault(); addFiles(imgs); }
            }
        });
        ta.addEventListener('dragover', function (e) { e.preventDefault(); });
        ta.addEventListener('drop', function (e) {
            if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length) { e.preventDefault(); addFiles(e.dataTransfer.files); }
        });
        if (existing) {
            existing.addEventListener('change', function () {
                if (existing.value) { insertAtCursor('[[img:' + existing.value + ']]'); existing.value = ''; }
            });
        }
        if (previewBtn && pane) {
            var label = previewBtn.textContent;
            previewBtn.addEventListener('click', function () {
                if (!pane.hidden) { pane.hidden = true; pane.textContent = ''; previewBtn.textContent = label; return; }
                var tokenInput = form.querySelector('input[name="csrf_token"]');
                var data = new FormData();
                data.append('body', ta.value);
                data.append('pending', JSON.stringify(Array.prototype.map.call(dt.files, function (f) { return f.name; })));
                data.append('csrf_token', tokenInput ? tokenInput.value : '');
                previewBtn.disabled = true;
                fetch(form.getAttribute('data-preview-url'), { method: 'POST', body: data, credentials: 'same-origin' })
                    .then(function (r) { return r.text().then(function (html) { return { ok: r.ok, html: html }; }); })
                    .then(function (res) {
                        if (res.ok) { pane.innerHTML = res.html; } else { pane.textContent = 'Не вдалося побудувати перегляд.'; }
                        pane.hidden = false; previewBtn.textContent = 'Сховати перегляд';
                    })
                    .catch(function () { pane.textContent = 'Не вдалося з\'єднатися з сервером.'; pane.hidden = false; })
                    .then(function () { previewBtn.disabled = false; });
            });
        }
    });
})();

// Перехід за якорем #entry-N до запису, схованого у спойлері «Попередні записи», розкриває його.
(function () {
    function openHashed() {
        var h = location.hash;
        if (!/^#entry-\d+$/.test(h)) { return; }
        var el = document.querySelector(h);
        for (var p = el; p; p = p.parentElement) { if (p.tagName === 'DETAILS') { p.open = true; } }
        if (el) { el.scrollIntoView(); }
    }
    window.addEventListener('hashchange', openHashed);
    document.addEventListener('DOMContentLoaded', openHashed);
})();

// Видалення запису журналу: вікно, що спливає, з підтвердженням (Bootstrap-модальне; без нього — звичайний confirm).
(function () {
    var modalEl = null, textEl = null, pending = null, modal = null;
    function build() {
        modalEl = document.createElement('div');
        modalEl.className = 'modal fade';
        modalEl.tabIndex = -1;
        modalEl.setAttribute('aria-hidden', 'true');
        modalEl.innerHTML = '<div class="modal-dialog modal-dialog-centered"><div class="modal-content">' +
            '<div class="modal-header"><h5 class="modal-title">Підтвердіть видалення</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Закрити"></button></div>' +
            '<div class="modal-body"></div>' +
            '<div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Скасувати</button><button type="button" class="btn btn-danger" data-journal-delete-ok>Видалити</button></div>' +
            '</div></div>';
        document.body.appendChild(modalEl);
        textEl = modalEl.querySelector('.modal-body');
        modal = new window.bootstrap.Modal(modalEl);
        modalEl.querySelector('[data-journal-delete-ok]').addEventListener('click', function () {
            var f = pending; pending = null; modal.hide();
            if (f) { f.dataset.confirmed = '1'; f.submit(); }
        });
    }
    document.addEventListener('submit', function (e) {
        var f = e.target.closest ? e.target.closest('form[data-journal-delete]') : null;
        if (!f || f.dataset.confirmed === '1') { return; }
        e.preventDefault();
        if (!window.bootstrap || !window.bootstrap.Modal) {
            if (window.confirm(f.dataset.confirmText)) { f.dataset.confirmed = '1'; f.submit(); }
            return;
        }
        if (!modalEl) { build(); }
        pending = f;
        textEl.textContent = f.dataset.confirmText;
        modal.show();
    });
})();

// Панель форматування (Markdown) над полем тексту запису журналу: обгортає виділене або вставляє шаблон.
(function () {
    'use strict';
    function wrap(ta, before, after, placeholder) {
        var s = ta.selectionStart, e = ta.selectionEnd, sel = ta.value.slice(s, e) || placeholder;
        ta.value = ta.value.slice(0, s) + before + sel + after + ta.value.slice(e);
        ta.selectionStart = s + before.length; ta.selectionEnd = s + before.length + sel.length;
    }
    function lines(ta, fn) {
        var v = ta.value, s = v.lastIndexOf('\n', ta.selectionStart - 1) + 1;
        var e = v.indexOf('\n', ta.selectionEnd); if (e < 0) { e = v.length; }
        var block = v.slice(s, e).split('\n').map(fn).join('\n');
        ta.value = v.slice(0, s) + block + v.slice(e);
        ta.selectionStart = s; ta.selectionEnd = s + block.length;
    }
    function apply(ta, kind) {
        switch (kind) {
            case 'bold': wrap(ta, '**', '**', 'текст'); break;
            case 'italic': wrap(ta, '*', '*', 'текст'); break;
            case 'strike': wrap(ta, '~~', '~~', 'текст'); break;
            case 'code': wrap(ta, '`', '`', 'код'); break;
            case 'codeblock': wrap(ta, '```\n', '\n```', 'код'); break;
            case 'heading': lines(ta, function (l) { return '## ' + l.replace(/^#{1,6}\s+/, ''); }); break;
            case 'ul': lines(ta, function (l) { return '- ' + l.replace(/^(?:[-*+]|\d+\.)\s+/, ''); }); break;
            case 'ol': var n = 0; lines(ta, function (l) { n++; return n + '. ' + l.replace(/^(?:[-*+]|\d+\.)\s+/, ''); }); break;
            case 'quote': lines(ta, function (l) { return '> ' + l.replace(/^>\s?/, ''); }); break;
            case 'link':
                var s = ta.selectionStart, e = ta.selectionEnd, sel = ta.value.slice(s, e) || 'текст';
                ta.value = ta.value.slice(0, s) + '[' + sel + '](https://)' + ta.value.slice(e);
                ta.selectionStart = s + sel.length + 3; ta.selectionEnd = ta.selectionStart + 8; break;
            default: return;
        }
        ta.focus();
        ta.dispatchEvent(new Event('input', { bubbles: true }));
    }
    document.addEventListener('click', function (ev) {
        var btn = ev.target.closest ? ev.target.closest('[data-journal-md]') : null;
        if (!btn) { return; }
        var form = btn.closest('form'), ta = form ? form.querySelector('textarea[name="body"]') : null;
        if (ta) { ev.preventDefault(); apply(ta, btn.getAttribute('data-journal-md')); }
    });
    document.addEventListener('keydown', function (ev) {
        if (!(ev.ctrlKey || ev.metaKey) || ev.altKey || !ev.target.matches || !ev.target.matches('form[data-journal-form] textarea[name="body"]')) { return; }
        var k = ev.key.toLowerCase(), kind = k === 'b' ? 'bold' : k === 'i' ? 'italic' : k === 'k' ? 'link' : null;
        if (kind) { ev.preventDefault(); apply(ev.target, kind); }
    });
})();
