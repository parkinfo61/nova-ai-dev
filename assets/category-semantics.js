(function () {
  'use strict';

  var config = window.P24MSC || {};
  var schemas = config.schemas || {};
  var messages = config.messages || {};
  var state = {
    categorySelect: null,
    conditionSelect: null,
    initialCategory: '',
    initialFamily: '',
    activeFamily: '',
    observer: null
  };

  function normalize(value) {
    return String(value || '').toLowerCase().replace(/ё/g, 'е').trim();
  }

  function textOf(node) {
    return normalize(node && node.textContent ? node.textContent : '');
  }

  function fieldLabel(control) {
    if (!control) return '';
    var id = control.getAttribute('id');
    if (id) {
      var explicit = document.querySelector('label[for="' + cssEscape(id) + '"]');
      if (explicit) return textOf(explicit);
    }
    var wrapper = control.closest('label, .p24m-field, .p24m-form-field, .p24m-editor-field, .form-field, .p24m-card, section, article');
    if (!wrapper) return '';
    var label = wrapper.querySelector('label, legend, .p24m-field__label, .p24m-label, h3, h4');
    return label ? textOf(label) : textOf(wrapper).slice(0, 160);
  }

  function cssEscape(value) {
    if (window.CSS && typeof window.CSS.escape === 'function') return window.CSS.escape(value);
    return String(value).replace(/([ #;?%&,.+*~\':"!^$\[\]()=>|\/])/g, '\\$1');
  }

  function visible(control) {
    if (!control) return false;
    var style = window.getComputedStyle(control);
    return style.display !== 'none' && style.visibility !== 'hidden' && control.type !== 'hidden';
  }

  function findCategorySelect() {
    var candidates = Array.prototype.slice.call(document.querySelectorAll('select'));
    var scored = candidates.map(function (select) {
      var name = normalize(select.name + ' ' + select.id + ' ' + fieldLabel(select));
      var score = 0;
      if (/categor|category|rubric|rubrika|рубр|катег/.test(name)) score += 10;
      if (select.options && select.options.length > 4) score += 1;
      if (!visible(select)) score -= 3;
      return { select: select, score: score };
    }).sort(function (a, b) { return b.score - a.score; });
    return candidates.length && scored[0].score > 0 ? scored[0].select : null;
  }

  function findConditionSelect() {
    var candidates = Array.prototype.slice.call(document.querySelectorAll('select'));
    var scored = candidates.map(function (select) {
      var name = normalize(select.name + ' ' + select.id + ' ' + fieldLabel(select));
      var score = 0;
      if (/condition|item.condition|state|состояни/.test(name)) score += 10;
      if (select.options && select.options.length >= 3 && select.options.length <= 12) score += 1;
      if (!visible(select)) score -= 3;
      return { select: select, score: score };
    }).sort(function (a, b) { return b.score - a.score; });
    return candidates.length && scored[0].score > 0 ? scored[0].select : null;
  }

  function selectedText(select) {
    if (!select || !select.options || select.selectedIndex < 0) return '';
    return select.options[select.selectedIndex].textContent || '';
  }

  function detectFamilyFromText(value) {
    var haystack = normalize(value);
    var dictionary = {
      automobile: ['автомоб', 'машин', 'транспорт', 'грузовик', 'мото', 'auto', 'car'],
      clothing: ['личные вещи', 'одежд', 'обув', 'джинс', 'брюк', 'куртк', 'рубаш', 'плать', 'сумк', 'аксессуар', 'clothing'],
      real_estate: ['недвиж', 'квартир', 'дом', 'участ', 'комнат', 'гараж', 'property', 'real-estate'],
      service: ['услуг', 'работ', 'мастер', 'ваканс', 'service']
    };
    var family;
    for (family in dictionary) {
      if (!Object.prototype.hasOwnProperty.call(dictionary, family)) continue;
      if (dictionary[family].some(function (needle) { return haystack.indexOf(needle) !== -1; })) return family;
    }
    return 'generic';
  }

  function schemaFor(family) {
    return schemas[family] || schemas.generic || null;
  }

  function addHiddenFlag(form, name, value) {
    if (!form) return;
    var field = form.querySelector('input[name="' + cssEscape(name) + '"]');
    if (!field) {
      field = document.createElement('input');
      field.type = 'hidden';
      field.name = name;
      form.appendChild(field);
    }
    field.value = value;
  }

  function notice(message, tone) {
    var host = document.querySelector('[data-p24m-listing-form], form') || document.body;
    var existing = document.querySelector('.p24msc-inline-notice');
    if (existing) existing.remove();
    var box = document.createElement('div');
    box.className = 'p24msc-inline-notice is-' + (tone || 'info');
    box.setAttribute('role', 'status');
    box.textContent = message;
    host.insertBefore(box, host.firstChild);
    window.setTimeout(function () {
      if (box && box.parentNode) box.parentNode.removeChild(box);
    }, 7000);
  }

  function replaceConditionOptions(family, resetValue) {
    var select = state.conditionSelect || findConditionSelect();
    state.conditionSelect = select;
    if (!select) return;

    var schema = schemaFor(family);
    if (!schema) return;

    var wrapper = select.closest('.p24m-field, .p24m-form-field, .p24m-editor-field, label, section, article') || select.parentNode;
    if (family === 'service' || !schema.condition_options || !Object.keys(schema.condition_options).length) {
      select.disabled = true;
      select.setAttribute('data-p24msc-hidden-condition', '1');
      if (wrapper) wrapper.classList.add('p24msc-condition-disabled');
      return;
    }

    select.disabled = false;
    select.removeAttribute('data-p24msc-hidden-condition');
    if (wrapper) wrapper.classList.remove('p24msc-condition-disabled');

    var current = resetValue ? '' : select.value;
    var first = document.createElement('option');
    first.value = '';
    first.textContent = schema.condition_label || 'Укажите состояние';

    while (select.firstChild) select.removeChild(select.firstChild);
    select.appendChild(first);

    Object.keys(schema.condition_options).forEach(function (value) {
      var option = document.createElement('option');
      option.value = value;
      option.textContent = schema.condition_options[value];
      select.appendChild(option);
    });

    if (!resetValue && current && Object.prototype.hasOwnProperty.call(schema.condition_options, current)) {
      select.value = current;
    } else {
      select.value = '';
    }

    updateConditionLabel(select, schema.condition_label);
    toggleDefectField(family, select.value);
  }

  function updateConditionLabel(select, labelText) {
    var id = select.getAttribute('id');
    var label = id ? document.querySelector('label[for="' + cssEscape(id) + '"]') : null;
    if (!label) {
      var wrapper = select.closest('.p24m-field, .p24m-form-field, .p24m-editor-field, label, section, article');
      label = wrapper ? wrapper.querySelector('label, legend, .p24m-field__label, .p24m-label') : null;
    }
    if (label && labelText) {
      var required = label.textContent.indexOf('*') !== -1 ? ' *' : '';
      label.textContent = labelText + required;
    }
  }

  function toggleDefectField(family, value) {
    var form = state.conditionSelect ? state.conditionSelect.closest('form') : document.querySelector('form');
    if (!form) return;
    var candidates = Array.prototype.slice.call(form.querySelectorAll('textarea, input[type="text"]'));
    var defect = candidates.find(function (field) {
      var name = normalize(field.name + ' ' + field.id + ' ' + fieldLabel(field));
      return /defect|дефект|недостат/.test(name);
    });
    if (!defect) return;
    var wrapper = defect.closest('.p24m-field, .p24m-form-field, .p24m-editor-field, label, section, article') || defect.parentNode;
    var show = family === 'clothing' && value === 'repair';
    if (wrapper) wrapper.hidden = !show;
    defect.required = show;
  }

  function createDialog(oldFamily, newFamily, onConfirm, onCancel) {
    var dialog = document.createElement('dialog');
    dialog.className = 'p24msc-dialog';
    dialog.innerHTML = '' +
      '<form method="dialog">' +
        '<span class="p24msc-dialog__kicker">СМЕНА РУБРИКИ</span>' +
        '<h2>' + escapeHtml(messages.reclassifyTitle || 'Вы меняете рубрику объявления') + '</h2>' +
        '<p>' + escapeHtml(messages.reclassifyBody || 'Общие данные сохранятся. Несовместимые характеристики будут очищены после сохранения.') + '</p>' +
        '<div class="p24msc-dialog__flow"><code>' + escapeHtml(oldFamily || 'generic') + '</code><span>→</span><code>' + escapeHtml(newFamily || 'generic') + '</code></div>' +
        '<div class="p24msc-dialog__actions">' +
          '<button type="button" class="button p24msc-cancel">' + escapeHtml(messages.cancel || 'Отмена') + '</button>' +
          '<button type="button" class="button button-primary p24msc-confirm">' + escapeHtml(messages.confirm || 'Сменить рубрику') + '</button>' +
        '</div>' +
      '</form>';
    document.body.appendChild(dialog);

    function close() {
      if (dialog.open) dialog.close();
      dialog.remove();
    }

    dialog.querySelector('.p24msc-confirm').addEventListener('click', function () {
      close();
      onConfirm();
    });
    dialog.querySelector('.p24msc-cancel').addEventListener('click', function () {
      close();
      onCancel();
    });
    dialog.addEventListener('cancel', function (event) {
      event.preventDefault();
      close();
      onCancel();
    });

    if (typeof dialog.showModal === 'function') dialog.showModal();
    else dialog.setAttribute('open', 'open');
  }

  function escapeHtml(value) {
    return String(value || '').replace(/[&<>'"]/g, function (char) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;' }[char];
    });
  }

  function onCategoryChange(event) {
    var select = event.currentTarget;
    var newCategory = select.value;
    var newFamily = detectFamilyFromText(selectedText(select));
    var oldCategory = state.initialCategory;
    var oldFamily = state.activeFamily || state.initialFamily;

    if (!oldCategory || newCategory === oldCategory || newFamily === oldFamily) {
      state.activeFamily = newFamily;
      replaceConditionOptions(newFamily, false);
      return;
    }

    createDialog(oldFamily, newFamily, function () {
      var form = select.closest('form');
      addHiddenFlag(form, 'p24msc_confirm_reclassify', '1');
      addHiddenFlag(form, 'p24msc_previous_family', oldFamily);
      state.activeFamily = newFamily;
      state.initialCategory = newCategory;
      replaceConditionOptions(newFamily, true);
      notice(messages.conditionReset || 'Состояние сброшено для новой рубрики.', 'warning');
    }, function () {
      select.value = oldCategory;
      state.activeFamily = oldFamily;
      replaceConditionOptions(oldFamily, false);
    });
  }

  function bind() {
    var category = findCategorySelect();
    if (category && category !== state.categorySelect) {
      if (state.categorySelect) state.categorySelect.removeEventListener('change', onCategoryChange);
      state.categorySelect = category;
      state.initialCategory = category.value;
      state.initialFamily = detectFamilyFromText(selectedText(category));
      state.activeFamily = state.initialFamily;
      category.addEventListener('change', onCategoryChange);
    }

    var condition = findConditionSelect();
    if (condition && condition !== state.conditionSelect) {
      state.conditionSelect = condition;
      condition.addEventListener('change', function () {
        toggleDefectField(state.activeFamily || state.initialFamily || 'generic', condition.value);
      });
      replaceConditionOptions(state.activeFamily || state.initialFamily || 'generic', false);
    }

    markPageContract();
  }

  function markPageContract() {
    var meta = document.querySelector('meta[name="p24m-route-key"]');
    if (!meta) return;
    document.documentElement.setAttribute('data-p24m-route', meta.content || 'unknown');
    document.documentElement.setAttribute('data-p24m-system-contract', config.version || 'active');
  }

  function observe() {
    if (!window.MutationObserver || state.observer) return;
    state.observer = new MutationObserver(function (mutations) {
      var relevant = mutations.some(function (mutation) {
        return mutation.addedNodes && mutation.addedNodes.length;
      });
      if (relevant) window.requestAnimationFrame(bind);
    });
    state.observer.observe(document.documentElement, { childList: true, subtree: true });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { bind(); observe(); });
  } else {
    bind();
    observe();
  }
})();
