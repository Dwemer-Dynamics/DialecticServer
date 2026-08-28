/* Global Settings preset toolbar: save, overwrite and apply named setting snapshots. */
(function () {
 'use strict';

 var root = document.getElementById('gs-presets');
 var form = document.getElementById('gs_form');
 if (!root || !form) {
  return;
 }

 var endpoint = root.getAttribute('data-endpoint') || '';
 var MAX_PRESETS = 50;
 var MAX_NAME = 60;
 var CONTEXT_BUCKETS = [
  'enabled_sections',
  'enabled_character_subsections',
  'enabled_appearance_subsections',
  'enabled_general_subsections',
  'enabled_nearby_actor_subsections'
 ];

 var els = {
  select: document.getElementById('gs-preset-select'),
  apply: document.getElementById('gs-preset-apply'),
  save: document.getElementById('gs-preset-save'),
  overwrite: document.getElementById('gs-preset-overwrite'),
  retry: document.getElementById('gs-preset-retry'),
  help: document.getElementById('gs-preset-help'),
  helpText: document.getElementById('gs-preset-help-text'),
  desc: document.getElementById('gs-preset-desc'),
  status: document.getElementById('gs-preset-status'),
  error: document.getElementById('gs-preset-error')
 };

 var modal = {
  root: document.getElementById('gs-preset-modal'),
  title: document.getElementById('gs-preset-modal-title'),
  text: document.getElementById('gs-preset-modal-text'),
  field: document.getElementById('gs-preset-modal-field'),
  input: document.getElementById('gs-preset-name'),
  error: document.getElementById('gs-preset-modal-error'),
  cancel: document.getElementById('gs-preset-modal-cancel'),
  confirm: document.getElementById('gs-preset-modal-confirm'),
  lastFocus: null,
  onConfirm: null
 };

 var state = {
  presets: [],
  csrf: '',
  settingIds: [],
  loaded: false,
  busy: false,
  baseline: ''
 };

 /* ---------- form capture ---------- */

 function controlsByName() {
  var map = Object.create(null);
  var list = form.elements;
  for (var i = 0; i < list.length; i++) {
   var el = list[i];
   if (!el.name) continue;
   if (!map[el.name]) map[el.name] = [];
   map[el.name].push(el);
  }
  return map;
 }

 function capturePayload() {
  var map = controlsByName();
  var settings = {};
  state.settingIds.forEach(function (id) {
   var controls = map[id];
   if (!controls || !controls.length) return;
   var checkbox = null;
   for (var i = 0; i < controls.length; i++) {
    if (controls[i].type === 'checkbox') checkbox = controls[i];
   }
   if (checkbox) {
    settings[id] = !!checkbox.checked;
    return;
   }
   var el = controls[controls.length - 1];
   settings[id] = el.value == null ? '' : String(el.value);
  });

  var context = {};
  CONTEXT_BUCKETS.forEach(function (bucket) {
   var controls = map['prompt_context_' + bucket + '[]'] || [];
   var picked = [];
   controls.forEach(function (el) {
    if (el.type === 'checkbox' && el.checked) picked.push(String(el.value));
   });
   context[bucket] = picked;
  });

  return { settings: settings, prompt_context_options: context };
 }

 function formSignature() {
  var parts = [];
  new FormData(form).forEach(function (value, key) {
   if (typeof value === 'string') parts.push(key + '=' + value);
  });
  parts.sort();
  return parts.join('\n');
 }

 function hasUnsavedChanges() {
  return formSignature() !== state.baseline;
 }

 /* ---------- messaging ---------- */

 function setStatus(message) {
  els.status.textContent = message || '';
 }

 function setError(message) {
  els.error.textContent = message || '';
  els.error.hidden = !message;
 }

 function findPreset(id) {
  if (id === '' || id == null) return null;
  var wanted = String(id);
  for (var i = 0; i < state.presets.length; i++) {
   if (state.presets[i].id === wanted) return state.presets[i];
  }
  return null;
 }

 function selectedPreset() {
  return findPreset(els.select.value);
 }

 function customCount() {
  return state.presets.filter(function (preset) { return !preset.built_in; }).length;
 }

 function setControl(el, enabled, reason) {
  el.disabled = !enabled;
  if (!enabled && reason) {
   el.setAttribute('title', reason);
  } else {
   el.removeAttribute('title');
  }
 }

 function refreshControls() {
  if (state.busy) {
   [els.select, els.apply, els.save, els.overwrite, els.retry].forEach(function (el) {
    el.disabled = true;
   });
   return;
  }

  var preset = selectedPreset();
  var canCapture = state.loaded && state.csrf !== '' && state.settingIds.length > 0;

  els.select.disabled = !state.loaded || state.presets.length === 0;
  els.retry.disabled = false;

  setControl(els.apply, !!preset, state.loaded ? 'Choose a preset first.' : 'Presets are not loaded.');
  setControl(
   els.save,
   canCapture && customCount() < MAX_PRESETS,
   canCapture ? 'You already have ' + MAX_PRESETS + ' saved presets.' : 'Presets are not loaded.'
  );
  setControl(
   els.overwrite,
   canCapture && !!preset && !preset.built_in,
   preset && preset.built_in ? 'Built-in presets cannot be overwritten.' : 'Choose one of your saved presets first.'
  );
 }

 function setBusy(busy) {
  state.busy = busy;
  root.classList.toggle('is-busy', busy);
  refreshControls();
 }

 function updateDescription() {
  var preset = selectedPreset();
  els.desc.textContent = preset && preset.description ? preset.description : '';
 }

 /* ---------- catalog ---------- */

 function normalizePreset(raw) {
  if (!raw || typeof raw !== 'object' || raw.id == null) return null;
  return {
   id: String(raw.id),
   name: String(raw.name == null ? raw.id : raw.name),
   description: typeof raw.description === 'string' ? raw.description : '',
   built_in: raw.built_in === true
  };
 }

 function renderSelect(preferredId) {
  var wanted = preferredId != null ? String(preferredId) : els.select.value;
  els.select.textContent = '';

  var placeholder = document.createElement('option');
  placeholder.value = '';
  placeholder.textContent = state.presets.length ? 'Select a preset' : 'No presets available';
  els.select.appendChild(placeholder);

  [
   { label: 'Built-in', builtIn: true },
   { label: 'Saved', builtIn: false }
  ].forEach(function (group) {
   var members = state.presets.filter(function (preset) { return preset.built_in === group.builtIn; });
   if (!members.length) return;
   var optgroup = document.createElement('optgroup');
   optgroup.label = group.label;
   members.forEach(function (preset) {
    var option = document.createElement('option');
    option.value = preset.id;
    option.textContent = preset.name;
    if (preset.description) option.title = preset.description;
    optgroup.appendChild(option);
   });
   els.select.appendChild(optgroup);
  });

  els.select.value = findPreset(wanted) ? wanted : '';
  updateDescription();
 }

 function applyCatalog(rawPresets, preferredId) {
  if (Array.isArray(rawPresets)) {
   state.presets = rawPresets.map(normalizePreset).filter(Boolean);
  }
  renderSelect(preferredId);
  refreshControls();
 }

 /* ---------- transport ---------- */

 function readJson(response) {
  return response.text().then(function (text) {
   var data = null;
   try {
    data = text ? JSON.parse(text) : null;
   } catch (parseError) {
    data = null;
   }
   if (!data || typeof data !== 'object' || !response.ok || data.ok !== true) {
    throw new Error(
     data && typeof data.error === 'string' && data.error
      ? data.error
      : 'The preset service returned an unexpected response (' + response.status + ').'
    );
   }
   return data;
  });
 }

 function request(options) {
  if (state.busy) {
   return Promise.reject(new Error('Another preset request is still running.'));
  }
  setBusy(true);
  setError('');
  return fetch(endpoint, options)
   .then(readJson, function () {
    throw new Error('Could not reach the preset service.');
   })
   .then(function (data) {
    setBusy(false);
    return data;
   }, function (error) {
    setBusy(false);
    throw error;
   });
 }

 function postAction(payload) {
  return request({
   method: 'POST',
   credentials: 'same-origin',
   headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
   body: JSON.stringify(payload)
  });
 }

 function loadCatalog() {
  setStatus('Loading presets...');
  els.retry.hidden = true;
  return request({ credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
   .then(function (data) {
    state.csrf = typeof data.csrf_token === 'string' ? data.csrf_token : '';
    state.settingIds = (Array.isArray(data.setting_ids) ? data.setting_ids : [])
     .filter(function (id) { return typeof id === 'string' && id !== ''; });
    state.loaded = true;
    applyCatalog(data.presets, '');
    setStatus(state.presets.length ? '' : 'No presets yet. Save New stores the settings shown here.');
   })
   .catch(function (error) {
    state.loaded = false;
    els.retry.hidden = false;
    setStatus('');
    setError(error && error.message ? error.message : 'Could not load presets.');
    refreshControls();
   });
 }

 /* ---------- modal ---------- */

 function setModalError(message) {
  modal.error.textContent = message || '';
  modal.error.hidden = !message;
 }

 function modalFocusable() {
  return [modal.field.hidden ? null : modal.input, modal.cancel, modal.confirm].filter(function (el) {
   return el && !el.disabled;
  });
 }

 function openModal(options) {
  modal.onConfirm = options.onConfirm;
  modal.title.textContent = options.title;
  modal.text.textContent = options.text;
  modal.field.hidden = !options.withInput;
  modal.input.value = options.value || '';
  modal.confirm.textContent = options.confirmLabel || 'Confirm';
  modal.confirm.disabled = false;
  modal.cancel.disabled = false;
  setModalError('');
  modal.lastFocus = document.activeElement;
  modal.root.hidden = false;
  if (options.withInput) {
   modal.input.focus();
   modal.input.select();
  } else {
   modal.confirm.focus();
  }
 }

 function closeModal() {
  if (modal.root.hidden || (state.busy && modal.confirm.disabled)) return;
  modal.root.hidden = true;
  modal.onConfirm = null;
  if (modal.lastFocus && typeof modal.lastFocus.focus === 'function') {
   modal.lastFocus.focus();
  }
  modal.lastFocus = null;
 }

 function runModalConfirm() {
  if (!modal.onConfirm || modal.confirm.disabled) return;
  var handler = modal.onConfirm;
  var label = modal.confirm.textContent;
  modal.confirm.disabled = true;
  modal.cancel.disabled = true;
  setModalError('');
  Promise.resolve()
   .then(function () { return handler(); })
   .then(function () {
    closeModal();
   }, function (error) {
    modal.confirm.disabled = false;
    modal.cancel.disabled = false;
    modal.confirm.textContent = label;
    setModalError(error && error.message ? error.message : 'That did not work. Please try again.');
    modalFocusable()[0].focus();
   });
 }

 modal.cancel.addEventListener('click', closeModal);
 modal.confirm.addEventListener('click', runModalConfirm);
 modal.root.addEventListener('mousedown', function (event) {
  if (event.target === modal.root) closeModal();
 });
 modal.root.addEventListener('keydown', function (event) {
  if (event.key === 'Escape') {
   event.preventDefault();
   closeModal();
   return;
  }
  if (event.key === 'Enter' && event.target === modal.input) {
   event.preventDefault();
   runModalConfirm();
   return;
  }
  if (event.key !== 'Tab') return;
  var focusable = modalFocusable();
  if (!focusable.length) {
   event.preventDefault();
   return;
  }
  var first = focusable[0];
  var last = focusable[focusable.length - 1];
  if (event.shiftKey && document.activeElement === first) {
   event.preventDefault();
   last.focus();
  } else if (!event.shiftKey && document.activeElement === last) {
   event.preventDefault();
   first.focus();
  }
 });

 /* ---------- actions ---------- */

 function saveNew() {
  openModal({
   title: 'Save preset',
   text: 'Save the current global options and context selections? Prompts, connections and profiles are excluded. Active settings stay unchanged.',
   withInput: true,
   confirmLabel: 'Save',
   onConfirm: function () {
    var name = modal.input.value.trim();
    if (name === '') {
     return Promise.reject(new Error('Enter a preset name.'));
    }
    if (name.length > MAX_NAME) {
     return Promise.reject(new Error('Preset names are limited to ' + MAX_NAME + ' characters.'));
    }
    var payload = capturePayload();
    return postAction({
     action: 'save',
     csrf_token: state.csrf,
     name: name,
     settings: payload.settings,
     prompt_context_options: payload.prompt_context_options
    }).then(function (data) {
     var saved = normalizePreset(data.preset);
     applyCatalog(data.presets, saved ? saved.id : '');
     setStatus('Saved "' + (saved ? saved.name : name) + '". Active settings unchanged.');
    });
   }
  });
 }

 function overwriteSelected() {
  var preset = selectedPreset();
  if (!preset || preset.built_in) return;
  openModal({
   title: 'Overwrite preset',
   text: 'Replace "' + preset.name + '" with the current global options and context selections? Prompts, connections and profiles are excluded. Active settings stay unchanged.',
   confirmLabel: 'Overwrite',
   onConfirm: function () {
    var payload = capturePayload();
    return postAction({
     action: 'overwrite',
     csrf_token: state.csrf,
     preset_id: preset.id,
     settings: payload.settings,
     prompt_context_options: payload.prompt_context_options
    }).then(function (data) {
     var saved = normalizePreset(data.preset);
     applyCatalog(data.presets, saved ? saved.id : preset.id);
     setStatus('Updated "' + (saved ? saved.name : preset.name) + '". Active settings unchanged.');
    });
   }
  });
 }

 function applySelected() {
  var preset = selectedPreset();
  if (!preset) return;
  var text = 'Apply "' + preset.name + '" to your global settings and reload this page?';
  if (hasUnsavedChanges()) {
   text += ' Unsaved changes on this page will be discarded.';
  }
  openModal({
   title: 'Apply preset',
   text: text,
   confirmLabel: 'Apply',
   onConfirm: function () {
    return postAction({
     action: 'apply',
     csrf_token: state.csrf,
     preset_id: preset.id
    }).then(function (data) {
     var count = typeof data.settings_updated === 'number' ? data.settings_updated : 0;
     setStatus('Applied "' + preset.name + '" (' + count + ' settings). Reloading...');
     setBusy(true);
     window.location.assign(window.location.pathname + '?_saved=1');
    });
   }
  });
 }

 /* ---------- wiring ---------- */

 els.select.addEventListener('change', function () {
  setError('');
  setStatus('');
  updateDescription();
  refreshControls();
 });
 els.apply.addEventListener('click', applySelected);
 els.save.addEventListener('click', saveNew);
 els.overwrite.addEventListener('click', overwriteSelected);
 els.retry.addEventListener('click', function () {
  setError('');
  loadCatalog();
 });
 els.help.addEventListener('click', function () {
  var expanded = els.help.getAttribute('aria-expanded') === 'true';
  els.help.setAttribute('aria-expanded', expanded ? 'false' : 'true');
  els.helpText.hidden = expanded;
 });

 modal.input.setAttribute('maxlength', String(MAX_NAME));
 state.baseline = formSignature();
 refreshControls();
 loadCatalog();
}());
