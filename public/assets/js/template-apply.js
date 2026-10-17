/*
 * Форма «Додати задачі за шаблоном» (сторінка проєкту): для шаблону ЗАДАЧІ показує поле назви,
 * заздалегідь заповнене назвою з шаблону, — її можна змінити перед створенням. Для шаблону проєкту поле приховане.
 * Прогресивне покращення: без скрипта задача створюється з назвою з шаблону.
 */
(function () {
  var select = document.getElementById('apply-template-select');
  var box = document.getElementById('apply-template-title-box');
  var input = document.getElementById('apply-template-title');
  if (!select || !box || !input) { return; }
  function sync() {
    var opt = select.options[select.selectedIndex];
    var isTask = opt && opt.getAttribute('data-kind') === 'task';
    box.classList.toggle('d-none', !isTask);
    input.value = isTask ? (opt.getAttribute('data-title') || '') : '';
  }
  select.addEventListener('change', sync);
  sync();
})();
