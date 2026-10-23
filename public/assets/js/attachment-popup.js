// Вкладення відкриваються у вікні, що спливає (по центру, ~70 % екрана), а не на новій сторінці: зображення (зі стрілками переходу між
// усіма зображеннями на сторінці) та PDF (у вбудованому переглядачі). Посилання з атрибутом data-popup="image"|"pdf"
// (необов'язково data-title). Ctrl/⌘/Shift-клік чи середня кнопка працюють як звичайне посилання (нова вкладка);
// без Bootstrap JS посилання теж просто відкривається як раніше. Стрілки ← → на клавіатурі гортають зображення.
(function () {
    'use strict';
    var modalEl = null, modal = null, bodyEl, titleEl, openEl, prevEl, nextEl, counterEl;
    var gallery = [], index = 0;

    function ensure() {
        if (modalEl) { return true; }
        if (!window.bootstrap || !window.bootstrap.Modal) { return false; }
        modalEl = document.createElement('div');
        modalEl.className = 'modal fade';
        modalEl.tabIndex = -1;
        modalEl.setAttribute('aria-hidden', 'true');
        var arrow = 'position:absolute;top:50%;transform:translateY(-50%);z-index:5;width:48px;height:64px;font-size:2rem;line-height:1;padding:0;opacity:.85';
        modalEl.innerHTML =
            '<div class="modal-dialog modal-dialog-centered" style="width:max(70vw,720px);max-width:95vw;margin-left:auto;margin-right:auto">' +
            '<div class="modal-content" style="height:min(max(70vh,480px),95vh)">' +
            '<div class="modal-header py-2" style="min-height:50px"><h6 class="modal-title text-truncate me-3"></h6>' +
            '<span class="small text-muted me-3 text-nowrap" data-role="counter"></span>' +
            '<a class="btn btn-sm btn-outline-secondary ms-auto me-2 text-nowrap" target="_blank" rel="noopener">Відкрити в новій вкладці</a>' +
            '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Закрити"></button></div>' +
            '<div class="modal-body p-0 text-center bg-light overflow-hidden position-relative" style="height:calc(100% - 50px)">' +
            '<button type="button" class="btn btn-dark d-none" data-role="prev" style="left:8px;' + arrow + '" aria-label="Попереднє">&#8249;</button>' +
            '<button type="button" class="btn btn-dark d-none" data-role="next" style="right:8px;' + arrow + '" aria-label="Наступне">&#8250;</button>' +
            '<div data-role="stage" class="d-flex align-items-center justify-content-center" style="height:100%"></div>' +
            '</div></div></div>';
        document.body.appendChild(modalEl);
        titleEl = modalEl.querySelector('.modal-title');
        openEl = modalEl.querySelector('.modal-header a');
        counterEl = modalEl.querySelector('[data-role=counter]');
        bodyEl = modalEl.querySelector('[data-role=stage]');
        prevEl = modalEl.querySelector('[data-role=prev]');
        nextEl = modalEl.querySelector('[data-role=next]');
        prevEl.addEventListener('click', function () { step(-1); });
        nextEl.addEventListener('click', function () { step(1); });
        modal = new window.bootstrap.Modal(modalEl);
        // Після закриття прибираємо вміст, щоб PDF/зображення не продовжували вантажитись.
        modalEl.addEventListener('hidden.bs.modal', function () { bodyEl.innerHTML = ''; gallery = []; });
        modalEl.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowLeft') { step(-1); } else if (e.key === 'ArrowRight') { step(1); }
        });
        return true;
    }

    function render(item) {
        titleEl.textContent = item.title || '';
        openEl.href = item.url;
        bodyEl.innerHTML = '';
        if (item.kind === 'pdf') {
            var f = document.createElement('iframe');
            f.src = item.url;
            f.title = item.title || 'PDF';
            f.style.cssText = 'width:100%;height:100%;border:0;background:#fff';
            bodyEl.appendChild(f);
        } else {
            var img = document.createElement('img');
            img.src = item.url;
            img.alt = item.title || '';
            img.style.cssText = 'max-width:100%;max-height:100%;object-fit:contain';
            bodyEl.appendChild(img);
        }
        var multi = item.kind === 'image' && gallery.length > 1;
        prevEl.classList.toggle('d-none', !multi);
        nextEl.classList.toggle('d-none', !multi);
        counterEl.textContent = multi ? (index + 1) + ' / ' + gallery.length : '';
    }

    function step(d) {
        if (gallery.length < 2) { return; }
        index = (index + d + gallery.length) % gallery.length;
        render(gallery[index]);
    }

    function itemOf(a) {
        var im = a.querySelector('img');
        return {
            url: a.getAttribute('href'),
            kind: a.getAttribute('data-popup'),
            title: a.getAttribute('data-title') || a.textContent.trim() || (im ? im.alt : '')
        };
    }

    // Усі зображення сторінки (мініатюра й назва одного вкладення дають одне зображення) — для гортання стрілками.
    function collectImages() {
        var seen = {}, list = [];
        document.querySelectorAll('a[data-popup="image"]').forEach(function (a) {
            var it = itemOf(a);
            if (!seen[it.url]) { seen[it.url] = true; list.push(it); }
        });
        return list;
    }

    document.addEventListener('click', function (e) {
        var a = e.target.closest ? e.target.closest('a[data-popup]') : null;
        if (!a || e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) { return; }
        if (!ensure()) { return; }
        e.preventDefault();
        var it = itemOf(a);
        if (it.kind === 'image') {
            gallery = collectImages();
            index = 0;
            gallery.forEach(function (g, i) { if (g.url === it.url) { index = i; it = g; } });
        } else {
            gallery = [];
        }
        render(it);
        modal.show();
    });
})();
