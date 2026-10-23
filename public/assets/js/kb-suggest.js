/*
 * Портал /support: під полем «Тема звернення» показує статті «Бази знань» (публічні сторінки вікі), схожі на текст теми й опису.
 * Запит — GET /support/kb/suggest?q=…; відповідь — JSON [{title, url, excerpt}]. Без скрипта форма працює як раніше.
 */
(function () {
    'use strict';
    var subject = document.querySelector('[data-kb-subject]');
    var desc = document.querySelector('[data-kb-description]');
    var box = document.querySelector('[data-kb-box]');
    var list = document.querySelector('[data-kb-list]');
    if (!subject || !box || !list || !window.fetch) { return; }
    var timer = null, last = '', seq = 0;

    function render(items) {
        list.textContent = '';
        items.forEach(function (it) {
            var li = document.createElement('li');
            var a = document.createElement('a');
            a.href = it.url; a.target = '_blank'; a.rel = 'noopener'; a.textContent = it.title;
            li.appendChild(a);
            if (it.excerpt) {
                var s = document.createElement('div');
                s.className = 'text-muted'; s.textContent = it.excerpt;
                li.appendChild(s);
            }
            list.appendChild(li);
        });
        box.hidden = items.length === 0;
    }

    function ask() {
        var q = (subject.value + ' ' + (desc ? desc.value : '')).trim();
        if (q.length < 3 || q === last) { if (q.length < 3) { render([]); } return; }
        last = q;
        var mine = ++seq;
        fetch('/support/kb/suggest?q=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : []; })
            .then(function (items) { if (mine === seq && Array.isArray(items)) { render(items); } })
            .catch(function () {});
    }

    function later() { clearTimeout(timer); timer = setTimeout(ask, 450); }
    subject.addEventListener('input', later);
    if (desc) { desc.addEventListener('input', later); }
})();
