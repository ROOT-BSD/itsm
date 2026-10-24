/*
 * Форма застосування шаблону (сторінка проєкту, створення проєкту):
 *  - для шаблону ЗАДАЧІ показує поле назви, заздалегідь заповнене назвою з шаблону;
 *  - показує вибір людей для ролей виконавців саме вибраного шаблону (решта блоків приховані й вимкнені, тож не надсилаються).
 * Прогресивне покращення: без скрипта блоки ролей вимкнені, задачі створюються непризначеними.
 */
(function () {
  var select = document.getElementById('apply-template-select') || document.querySelector('form select[name="template_id"]');
  if (!select) { return; }
  var box = document.getElementById('apply-template-title-box');
  var input = document.getElementById('apply-template-title');
  var blocks = document.querySelectorAll('[data-role-tpl]');
  function sync() {
    var opt = select.options[select.selectedIndex];
    if (box && input) {
      var isTask = opt && opt.getAttribute('data-kind') === 'task';
      box.classList.toggle('d-none', !isTask);
      input.value = isTask ? (opt.getAttribute('data-title') || '') : '';
    }
    Array.prototype.forEach.call(blocks, function (b) {
      var on = b.getAttribute('data-role-tpl') === select.value;
      b.classList.toggle('d-none', !on);
      Array.prototype.forEach.call(b.querySelectorAll('select'), function (s) { s.disabled = !on; });
    });
  }
  select.addEventListener('change', sync);
  sync();
})();
