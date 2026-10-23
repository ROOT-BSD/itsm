/* Форма активу: для типу «ПЗ / ліцензія» показує поле «Кількість місць» і підпис «Ліцензія до» замість «Гарантія до». */
(function () {
    'use strict';
    var type = document.querySelector('select[name="asset_type"]');
    var seats = document.querySelector('[data-asset-seats]');
    var label = document.querySelector('[data-asset-warranty-label]');
    if (!type || !seats) { return; }
    function sync() {
        var sw = type.value === 'software';
        seats.hidden = !sw;
        if (label) { label.textContent = sw ? 'Ліцензія до' : 'Гарантія до'; }
    }
    type.addEventListener('change', sync);
    sync();
})();
