/*
 * Пошук у списках вибору людини (виконавець задачі, відповідальний за проєкт, оператор тікета й черги).
 *
 * Прогресивне покращення: кожен <select class="user-select"> перетворюється на текстове поле
 * з випадним списком, що фільтрується під час набору. Оригінальний <select> лишається в
 * формі (прихований) і є єдиним джерелом істини: саме він надсилається, зберігає значення за
 * замовчуванням, і саме на ньому спрацьовує подія change — тож авто-надсилання форми
 * (клас auto-submit-select) працює без жодних змін. Якщо цей скрипт не завантажився,
 * лишається звичайний список вибору.
 *
 * Скрипт підключається як зовнішній файл зі свого домену (CSP: script-src 'self'), без
 * inline-коду; текст у список вставляється через textContent, тому імена з будь-якими
 * символами не можуть стати розміткою.
 */
(function () {
    'use strict';

    // Нормалізація для пошуку без урахування регістру (укр. локаль: «І» ↔ «і», «Ї» ↔ «ї»).
    function norm(s) {
        return String(s).toLocaleLowerCase('uk').replace(/\s+/g, ' ').trim();
    }

    var uid = 0;

    function init(select) {
        if (select.dataset.userSelectReady) {
            return;
        }
        select.dataset.userSelectReady = '1';
        uid += 1;

        var listId = 'user-combobox-list-' + uid;
        var wrap = document.createElement('div');
        wrap.className = 'user-combobox' + (select.classList.contains('w-auto') ? ' user-combobox-inline' : '');

        var input = document.createElement('input');
        input.type = 'text';
        // form-select (а не form-control): поле має ту саму стрілку й вигляд, що й звичайний список вибору, —
        // для користувача це ОДИН елемент, у який можна і клікнути для вибору, і почати набирати для пошуку.
        input.className = 'form-select' + (select.classList.contains('form-select-sm') ? ' form-select-sm' : '');
        input.autocomplete = 'off';
        input.spellcheck = false;
        input.setAttribute('role', 'combobox');
        input.setAttribute('aria-autocomplete', 'list');
        input.setAttribute('aria-expanded', 'false');
        input.setAttribute('aria-controls', listId);
        input.setAttribute('aria-label', select.getAttribute('aria-label') || 'Пошук за іменем');
        input.disabled = select.disabled;

        var list = document.createElement('ul');
        list.id = listId;
        list.className = 'user-combobox-list list-group shadow';
        list.setAttribute('role', 'listbox');
        list.hidden = true;

        select.parentNode.insertBefore(wrap, select);
        wrap.appendChild(input);
        wrap.appendChild(list);
        wrap.appendChild(select);
        // Ховаємо оригінальний <select> самим скриптом, а не правилом у CSS-файлі: інакше з застарілим
        // (закешованим) app.css обидва елементи були б видимі одночасно — поле пошуку Й старий список.
        // hidden + display:none (через CSSOM, CSP це дозволяє) діють незалежно від будь-яких стилів;
        // у формі select лишається і надсилається як і раніше.
        select.hidden = true;
        select.style.display = 'none';
        select.tabIndex = -1;
        select.setAttribute('aria-hidden', 'true');

        var items = [];      // елементи <li> поточного відфільтрованого списку
        var active = -1;     // індекс виділеного елемента
        var isOpen = false;

        function selectedOption() {
            return select.options[select.selectedIndex] || null;
        }

        // Показуємо ім'я вибраної людини; для порожнього варіанту («— не призначено —») поле лишається
        // порожнім, а сам підпис показується як підказка.
        function syncInputFromSelect() {
            var opt = selectedOption();
            var isEmptyValue = !opt || opt.value === '';
            input.value = isEmptyValue ? '' : opt.text.trim();
            var emptyOption = Array.prototype.find.call(select.options, function (o) { return o.value === ''; });
            input.placeholder = isEmptyValue ? (opt ? opt.text.trim() : '') : (emptyOption ? emptyOption.text.trim() : '');
        }

        // Виводить текст з виділеними (<mark>) фрагментами, що збіглися із запитом — через вузли DOM, а не
        // innerHTML, тож імена з будь-якими символами не можуть стати розміткою.
        function appendHighlighted(li, text, tokens) {
            var lower = text.toLocaleLowerCase('uk');
            // Для окремих символів нижній регістр змінює довжину рядка — тоді індекси збігів не
            // відповідають оригіналу, і підсвітку краще не показувати, ніж показати зсунутою.
            if (!tokens.length || lower.length !== text.length) {
                li.textContent = text;
                return;
            }
            var marked = new Array(text.length).fill(false);
            tokens.forEach(function (token) {
                var from = 0;
                var at;
                while ((at = lower.indexOf(token, from)) !== -1) {
                    for (var k = at; k < at + token.length; k++) {
                        marked[k] = true;
                    }
                    from = at + token.length;
                }
            });
            var i = 0;
            while (i < text.length) {
                var j = i;
                while (j < text.length && marked[j] === marked[i]) {
                    j++;
                }
                var chunk = text.slice(i, j);
                if (marked[i]) {
                    var mark = document.createElement('mark');
                    mark.className = 'user-combobox-match';
                    mark.textContent = chunk;
                    li.appendChild(mark);
                } else {
                    li.appendChild(document.createTextNode(chunk));
                }
                i = j;
            }
        }

        function setActive(index) {
            if (active >= 0 && items[active]) {
                items[active].classList.remove('active');
                items[active].removeAttribute('aria-selected');
            }
            active = index;
            if (active >= 0 && items[active]) {
                items[active].classList.add('active');
                items[active].setAttribute('aria-selected', 'true');
                input.setAttribute('aria-activedescendant', items[active].id);
                items[active].scrollIntoView({ block: 'nearest' });
            } else {
                input.removeAttribute('aria-activedescendant');
            }
        }

        // query === '' — показати всі варіанти (відкриття списку без набору тексту).
        function render(query) {
            var tokens = norm(query).split(' ').filter(Boolean);
            list.textContent = '';
            items = [];
            var current = select.value;

            Array.prototype.forEach.call(select.options, function (opt, i) {
                if (opt.disabled) {
                    return;
                }
                var isEmptyValue = opt.value === '';
                var text = opt.text.trim();
                // Порожній варіант показуємо лише коли нічого не шукають; решта — якщо збігаються ВСІ слова запиту.
                if (isEmptyValue ? tokens.length > 0 : !tokens.every(function (t) { return norm(text).indexOf(t) !== -1; })) {
                    return;
                }
                var li = document.createElement('li');
                li.id = listId + '-' + i;
                li.className = 'list-group-item list-group-item-action user-combobox-item' + (isEmptyValue ? ' text-muted' : '');
                li.setAttribute('role', 'option');
                li.dataset.value = opt.value;
                appendHighlighted(li, text, tokens);
                if (opt.value === current) {
                    li.classList.add('user-combobox-current');
                }
                // mousedown, а не click: click настає ПІСЛЯ blur поля, яке б уже закрило список.
                li.addEventListener('mousedown', function (e) {
                    e.preventDefault();
                    choose(opt.value);
                });
                list.appendChild(li);
                items.push(li);
            });

            if (items.length === 0) {
                var empty = document.createElement('li');
                empty.className = 'list-group-item text-muted';
                empty.textContent = 'Нікого не знайдено';
                list.appendChild(empty);
                setActive(-1);
                return;
            }
            // Виділяємо першого, що збігся (для запиту) або поточного вибраного (для повного списку).
            var currentIndex = items.findIndex(function (li) { return li.classList.contains('user-combobox-current'); });
            setActive(tokens.length > 0 ? 0 : Math.max(currentIndex, 0));
        }

        function open(query) {
            render(query);
            list.hidden = false;
            isOpen = true;
            input.setAttribute('aria-expanded', 'true');
        }

        function close() {
            list.hidden = true;
            isOpen = false;
            input.setAttribute('aria-expanded', 'false');
            input.removeAttribute('aria-activedescendant');
        }

        function choose(value) {
            var changed = select.value !== value;
            select.value = value;
            syncInputFromSelect();
            close();
            if (changed) {
                // bubbles: тож делегований обробник auto-submit-select (layout/main.php) його бачить.
                select.dispatchEvent(new Event('change', { bubbles: true }));
            }
        }

        input.addEventListener('focus', function () {
            input.select();       // набір одразу замінює показане ім'я
            open('');
        });
        input.addEventListener('click', function () {
            if (!isOpen) {
                open('');
            }
        });
        input.addEventListener('input', function () {
            open(input.value);
        });
        input.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                if (!isOpen) {
                    open(input.value === (selectedOption() ? selectedOption().text.trim() : '') ? '' : input.value);
                    return;
                }
                if (items.length) {
                    var next = e.key === 'ArrowDown' ? active + 1 : active - 1;
                    setActive((next + items.length) % items.length);
                }
            } else if (e.key === 'Enter') {
                // Enter у відкритому списку обирає, а не надсилає форму.
                if (isOpen) {
                    e.preventDefault();
                    if (active >= 0 && items[active]) {
                        choose(items[active].dataset.value);
                    }
                }
            } else if (e.key === 'Escape') {
                if (isOpen) {
                    e.preventDefault();
                    syncInputFromSelect();
                    close();
                }
            }
        });
        // Втрата фокуса без вибору — повертаємо показане ім'я вибраної людини, щоб у полі
        // не лишився набраний уривок, який насправді ні до чого не прив'язаний.
        input.addEventListener('blur', function () {
            syncInputFromSelect();
            close();
        });

        syncInputFromSelect();
    }

    function initAll() {
        Array.prototype.forEach.call(document.querySelectorAll('select.user-select'), init);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAll);
    } else {
        initAll();
    }
})();
