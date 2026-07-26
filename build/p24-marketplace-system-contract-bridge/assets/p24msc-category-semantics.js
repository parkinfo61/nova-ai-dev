(function () {
  'use strict';

  var cfg = window.P24MSCCategorySemantics || {};
  var schemas = cfg.schemas || {};
  var busy = false;

  function text(value) {
    return String(value || '').trim().toLowerCase();
  }

  function familyFromText(value) {
    var haystack = text(value);
    var keys = Object.keys(schemas);
    for (var i = 0; i < keys.length; i += 1) {
      var key = keys[i];
      if (key === 'generic') continue;
      var match = (schemas[key] && schemas[key].match) || [];
      for (var j = 0; j < match.length; j += 1) {
        if (match[j] && haystack.indexOf(text(match[j])) !== -1) return key;
      }
    }
    return 'generic';
  }

  function selectedText(select) {
    if (!select || !select.options || select.selectedIndex < 0) return '';
    var option = select.options[select.selectedIndex];
    return (option ? option.textContent : '') + ' ' + select.value;
  }

  function findCategorySelect(root) {
    var selectors = [
      'select[name="category"]',
      'select[name="listing_category"]',
      'select[name="p24m_category"]',
      'select[name*="listing_category"]',
      'select[name*="category_id"]',
      'select[name*="rubric"]',
      'select[id*="category"]',
      'select[id*="rubric"]'
    ];
    for (var i = 0; i < selectors.length; i += 1) {
      var found = root.querySelector(selectors[i]);
      if (found) return found;
    }
    return null;
  }

  function findConditionSelect(root) {
    var selectors = [
      'select[name="condition"]',
      'select[name="state"]',
      'select[name="item_condition"]',
      'select[name="vehicle_condition"]',
      'select[name*="condition"]',
      'select[id*="condition"]',
      'select[name*="sostoyanie"]'
    ];
    for (var i = 0; i < selectors.length; i += 1) {
      var found = root.querySelector(selectors[i]);
      if (found) return found;
    }
    return null;
  }

  function formFor(control) {
    return (control && control.closest('form')) || document.querySelector('form');
  }

  function hidden(form, name, value) {
    if (!form) return;
    var input = form.querySelector('input[type="hidden"][name="' + name + '"]');
    if (!input) {
      input = document.createElement('input');
      input.type = 'hidden';
      input.name = name;
      form.appendChild(input);
    }
    input.value = value;
  }

  function fieldWrapper(control) {
    if (!control) return null;
    return control.closest('[data-p24m-field], .p24m-field, .p24m-editor-field, .p24m-form-field, .form-field, .p24m-form-row, .p24m-editor-row, label') || control.parentElement;
  }

  function updateCondition(root, family) {
    var control = findConditionSelect(root);
    if (!control) return;
    var schema = schemas[family] || schemas.generic || {};
    var wrapper = fieldWrapper(control);

    if (schema.condition_enabled === false) {
      control.disabled = true;
      control.value = '';
      if (wrapper) {
        wrapper.hidden = true;
        wrapper.setAttribute('data-p24msc-disabled-condition', '1');
      }
      return;
    }

    control.disabled = false;
    if (wrapper) {
      wrapper.hidden = false;
      wrapper.removeAttribute('data-p24msc-disabled-condition');
    }

    var conditions = schema.conditions || {};
    var previous = text(control.value);
    while (control.firstChild) control.removeChild(control.firstChild);

    var placeholder = document.createElement('option');
    placeholder.value = '';
    placeholder.textContent = 'Выберите состояние';
    control.appendChild(placeholder);

    Object.keys(conditions).forEach(function (key) {
      var option = document.createElement('option');
      option.value = key;
      option.textContent = conditions[key];
      control.appendChild(option);
    });

    if (conditions[previous]) {
      control.value = previous;
    } else if (family === 'clothing' && ['needs_repair', 'repair', 'requires_repair', 'требует ремонта'].indexOf(previous) !== -1) {
      control.value = 'has_defects';
    } else {
      control.value = '';
    }
    control.dispatchEvent(new Event('change', { bubbles: true }));
  }

  var fieldNames = {
    vehicle: ['make', 'model', 'year', 'mileage', 'body', 'transmission', 'fuel', 'engine', 'drive', 'steering', 'vehicle_condition'],
    clothing: ['item_group', 'item_type', 'gender', 'size', 'brand', 'season', 'color', 'material', 'purchased_at', 'defect_note', 'item_condition'],
    realty: ['property_type', 'deal_type', 'rooms', 'area', 'floor', 'floors_total', 'realty_condition'],
    service: ['service_type', 'service_area', 'service_schedule', 'service_price_type', 'experience']
  };

  function clearOldFamilyFields(root, oldFamily, newFamily) {
    var oldFields = fieldNames[oldFamily] || [];
    var newFields = fieldNames[newFamily] || [];
    oldFields.forEach(function (name) {
      if (newFields.indexOf(name) !== -1 || name === 'brand') return;
      var selectors = [
        '[name="' + name + '"]',
        '[name="p24m_' + name + '"]',
        '[name="_' + name + '"]',
        '[name="_p24m_' + name + '"]',
        '[id="' + name + '"]',
        '[id="p24m_' + name + '"]'
      ];
      selectors.forEach(function (selector) {
        root.querySelectorAll(selector).forEach(function (control) {
          if (control.type === 'checkbox' || control.type === 'radio') control.checked = false;
          else control.value = '';
          control.dispatchEvent(new Event('change', { bubbles: true }));
        });
      });
    });
  }

  function attach(root) {
    var category = findCategorySelect(root);
    if (!category || category.dataset.p24mscBound === '1') return;
    category.dataset.p24mscBound = '1';

    var currentValue = category.value;
    var currentFamily = familyFromText(selectedText(category));
    category.dataset.p24mscPreviousValue = currentValue;
    category.dataset.p24mscPreviousFamily = currentFamily;
    updateCondition(root, currentFamily);

    category.addEventListener('change', function () {
      if (busy) return;
      var previousValue = category.dataset.p24mscPreviousValue || '';
      var previousFamily = category.dataset.p24mscPreviousFamily || 'generic';
      var nextFamily = familyFromText(selectedText(category));
      var nextValue = category.value;

      if (previousFamily !== nextFamily) {
        var approved = window.confirm(cfg.confirmText || 'Сменить тип объявления и очистить несовместимые характеристики?');
        if (!approved) {
          busy = true;
          category.value = previousValue;
          category.dispatchEvent(new Event('change', { bubbles: true }));
          busy = false;
          updateCondition(root, previousFamily);
          return;
        }
        var form = formFor(category);
        hidden(form, cfg.confirmedField || 'p24m_confirm_category_change', '1');
        hidden(form, cfg.familyField || 'p24m_category_family_client', nextFamily);
        clearOldFamilyFields(root, previousFamily, nextFamily);
      }

      category.dataset.p24mscPreviousValue = nextValue;
      category.dataset.p24mscPreviousFamily = nextFamily;
      updateCondition(root, nextFamily);
      document.dispatchEvent(new CustomEvent('p24msc:family-changed', {
        detail: { previousFamily: previousFamily, family: nextFamily, categoryValue: nextValue }
      }));
    });
  }

  function boot() {
    attach(document);
    var observer = new MutationObserver(function () {
      attach(document);
    });
    observer.observe(document.documentElement, { childList: true, subtree: true });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
}());
