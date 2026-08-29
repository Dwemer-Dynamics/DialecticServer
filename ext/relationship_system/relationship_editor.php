<?php

if (!isset($editItem) || !is_array($editItem)) {
    return;
}
require_once __DIR__ . '/../../lib/relationship_manager.php';

$relationshipExtended = json_decode((string)($editItem['extended_data'] ?? '{}'), true);
if (!is_array($relationshipExtended)) {
    $relationshipExtended = [];
}
$relationshipRows = RelationshipManager::normalizeRelationshipMap($relationshipExtended['relationships'] ?? []);
$relationshipTypes = RelationshipManager::TYPES;
$relationshipEndpoint = rtrim((string)($webRoot ?? ''), '/') . '/ext/relationship_system/analyze_relationships.php';
?>
<div class="form-item span-2 relationship-editor-section" id="relationship-editor-section">
    <details open>
        <summary>Relationship Affinities</summary>
        <div class="relationship-editor-body">
            <small class="hint relationship-intro">
                Tracked relationships with affinity scores from -100 to 100 and relationship types.
                Dynamic relationship data follows the game timeline; save the game to preserve new runtime changes.
            </small>
            <label class="relationship-lock">
                <input type="checkbox" id="rel_locked" <?= !empty($relationshipExtended['relationships_locked']) ? 'checked' : '' ?>>
                Lock these relationships so automatic evaluation and save pullback cannot overwrite manual edits.
                Editing an affinity here enables this automatically.
            </label>

            <div class="relationship-table-wrap">
                <table class="relationship-table">
                    <thead><tr><th>Target</th><th>Affinity</th><th>Tier</th><th>Type</th><th>Signals</th><th></th></tr></thead>
                    <tbody id="rel_rows"></tbody>
                </table>
            </div>
            <p id="rel_empty" class="relationship-empty">No relationships tracked yet. Build with AI or add one manually.</p>

            <div class="relationship-add-row">
                <input type="text" id="rel_new_target" placeholder="Target name (for example Player or Veronica)">
                <input type="number" id="rel_new_affinity" min="-100" max="100" value="0" aria-label="New relationship affinity">
                <select id="rel_new_type" aria-label="New relationship type"></select>
                <button type="button" id="rel_add" class="relationship-button">Add</button>
            </div>
            <div class="relationship-actions">
                <button type="button" id="rel_build" class="relationship-button positive">Build with AI</button>
                <button type="button" id="rel_custom_type" class="relationship-button">Add Custom Type</button>
                <button type="button" id="rel_clear" class="relationship-button danger">Clear All</button>
            </div>
            <div id="rel_status" class="relationship-editor-status" role="status"></div>

            <input type="hidden" name="relationships_jsonb" id="relationships_jsonb" value="<?= htmlspecialchars(json_encode((object)$relationshipRows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="relationships_locked" id="relationships_locked" value="<?= !empty($relationshipExtended['relationships_locked']) ? '1' : '0' ?>">
        </div>
    </details>

    <div class="relationship-modal" id="rel_build_modal" aria-hidden="true">
        <div class="relationship-modal-panel">
            <h3>Build Relationships with AI</h3>
            <p>Uses recent events involving this NPC to infer affinities, types, and relationship signals.</p>
            <div class="relationship-warning"><strong>Merge warning:</strong> AI results merge into the current table and may replace entries with the same target name.</div>
            <label for="rel_build_direction">Direction (optional)</label>
            <textarea id="rel_build_direction" placeholder="For example: focus on faction loyalties or family relationships"></textarea>
            <div class="relationship-modal-actions">
                <button type="button" class="relationship-button" data-rel-close="rel_build_modal">Cancel</button>
                <button type="button" id="rel_build_confirm" class="relationship-button positive">Build</button>
            </div>
        </div>
    </div>

    <div class="relationship-modal" id="rel_custom_modal" aria-hidden="true">
        <div class="relationship-modal-panel compact">
            <h3>Add Custom Relationship Type</h3>
            <p>Use a short lowercase type such as client, mentor, guardian, or debtor.</p>
            <label for="rel_custom_name">Type name</label>
            <input type="text" id="rel_custom_name" placeholder="custom type">
            <div class="relationship-modal-actions">
                <button type="button" class="relationship-button" data-rel-close="rel_custom_modal">Cancel</button>
                <button type="button" id="rel_custom_confirm" class="relationship-button positive">Add Type</button>
            </div>
        </div>
    </div>

    <div class="relationship-modal" id="rel_details_modal" aria-hidden="true">
        <div class="relationship-modal-panel" role="dialog" aria-modal="true" aria-labelledby="rel_details_heading">
            <h3 id="rel_details_heading">Relationship Details: <span id="rel_details_target"></span></h3>
            <p>Relationship detail and memories are injected into prompt context. Keep them concise and relevant.</p>
            <label for="rel_details_relation">Relationship detail</label>
            <input type="text" id="rel_details_relation" placeholder="friend, sibling, patient, commanding officer">
            <label for="rel_details_note">Recent interaction</label>
            <input type="text" id="rel_details_note" placeholder="shared supplies, argued about the NCR">
            <label for="rel_details_best">Best memory</label>
            <input type="text" id="rel_details_best" placeholder="saved their life near Goodsprings">
            <label for="rel_details_worst">Worst memory</label>
            <input type="text" id="rel_details_worst" placeholder="betrayed their trust">
            <label for="rel_details_custom">Custom Info</label>
            <textarea id="rel_details_custom" rows="3" aria-describedby="rel_details_custom_hint" placeholder="Your own notes about this relationship"></textarea>
            <small id="rel_details_custom_hint" class="relationship-private-hint">Player notes only. Not sent to the AI.</small>
            <div class="relationship-modal-actions">
                <button type="button" class="relationship-button" data-rel-close="rel_details_modal">Cancel</button>
                <button type="button" id="rel_details_save" class="relationship-button positive">Save Details</button>
            </div>
        </div>
    </div>
</div>
<style>
.relationship-editor-section details { border:1px solid #4a4a4a; border-radius:8px; background:#262626; overflow:hidden; }
.relationship-editor-section summary { cursor:pointer; padding:10px 12px; color:rgb(255, 182, 65); font-size:1rem; }
.relationship-editor-body { padding:0 12px 12px; }
.relationship-intro { display:block; margin:2px 0 9px; color:#999; }
.relationship-lock { display:flex; align-items:center; gap:7px; margin:4px 0 10px; color:#f6d49b; cursor:pointer; }
.relationship-table-wrap { overflow-x:auto; margin-top:8px; }
.relationship-table { width:100%; border-collapse:collapse; min-width:850px; }
.relationship-table th { text-align:left; color:#999; border-bottom:1px solid #555; padding:7px; }
.relationship-table td { border-bottom:1px solid #3a3a3a; padding:7px; vertical-align:middle; }
.relationship-table input, .relationship-table select, .relationship-add-row input, .relationship-add-row select,
.relationship-modal input, .relationship-modal textarea { box-sizing:border-box; background:#1d1d1d; color:#f4f4f4; border:1px solid #555; border-radius:4px; padding:6px; }
.relationship-table input, .relationship-table select { width:100%; }
.relationship-table .rel-affinity { width:76px; text-align:center; }
.relationship-table .rel-tier { white-space:nowrap; }
.relationship-signals { min-width:230px; font-size:0.82rem; line-height:1.35; }
.relationship-signal-note { color:#d1d5db; }
.relationship-signal-best { color:#86efac; }
.relationship-signal-worst { color:#fca5a5; }
.relationship-signal-private { color:#f6d49b; }
.relationship-no-signals { color:#666; }
.relationship-row-actions { display:flex; gap:4px; justify-content:flex-end; }
.relationship-icon-button { width:32px; height:32px; border:1px solid #555; background:#292929; color:#ddd; border-radius:4px; cursor:pointer; }
.relationship-icon-button.has-details { color:rgb(255, 182, 65); }
.relationship-icon-button.danger { border-color:#6c3434; color:#ffb0b0; }
.relationship-add-row { display:grid; grid-template-columns:minmax(180px,1fr) 90px minmax(140px,180px) auto; gap:8px; margin-top:12px; padding-top:12px; border-top:1px solid #3a3a3a; }
.relationship-actions { display:flex; gap:8px; flex-wrap:wrap; margin-top:10px; }
.relationship-button { border:1px solid #555; background:#2a2a2a; color:#ddd; border-radius:4px; padding:7px 12px; cursor:pointer; }
.relationship-button:hover { background:#343434; border-color:#777; }
.relationship-button.positive { color:#a8e6b8; border-color:#426a4d; }
.relationship-button.danger { color:#ffabab; border-color:#704040; }
.relationship-button:disabled { opacity:0.55; cursor:wait; }
.relationship-editor-status { display:none; margin-top:8px; padding:8px 10px; border:1px solid #555; border-radius:4px; color:#cfd9ea; }
.relationship-editor-status.visible { display:block; }
.relationship-editor-status.error { border-color:#8d4040; color:#ffb0b0; }
.relationship-empty { color:#777; font-style:italic; }
.relationship-modal { display:none; position:fixed; inset:0; z-index:10050; align-items:center; justify-content:center; background:rgba(0,0,0,0.76); padding:20px; }
.relationship-modal.open { display:flex; }
.relationship-modal-panel { width:min(520px, 94vw); max-height:90vh; overflow:auto; box-sizing:border-box; background:#1b1b1b; border:1px solid #555; border-radius:8px; padding:18px; color:#ddd; }
.relationship-modal-panel.compact { width:min(420px, 94vw); }
.relationship-modal-panel h3 { margin:0 0 8px; color:rgb(255, 182, 65); font-size:1.05rem; }
.relationship-modal-panel p { color:#999; margin:0 0 12px; }
.relationship-modal-panel label { display:block; color:#ccc; margin:10px 0 4px; }
.relationship-modal-panel input, .relationship-modal-panel textarea { width:100%; }
.relationship-modal-panel textarea { min-height:82px; resize:vertical; font-family:inherit; font-size:inherit; }
.relationship-modal-panel .relationship-private-hint { display:block; margin:5px 0 0; color:#999; font-size:0.8rem; line-height:1.35; }
.relationship-warning { padding:9px 10px; border:1px solid #7c6533; background:#3a2d13; color:#f5d88f; border-radius:4px; }
.relationship-modal-actions { display:flex; justify-content:flex-end; gap:8px; margin-top:16px; }
@media (max-width:760px) { .relationship-add-row { grid-template-columns:1fr 90px; } }
</style>
<script>
(function () {
    const section = document.getElementById('relationship-editor-section');
    const hidden = document.getElementById('relationships_jsonb');
    const locked = document.getElementById('rel_locked');
    const lockedHidden = document.getElementById('relationships_locked');
    const tbody = document.getElementById('rel_rows');
    const empty = document.getElementById('rel_empty');
    const status = document.getElementById('rel_status');
    const baseTypes = <?= json_encode(array_values($relationshipTypes), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    const types = baseTypes.slice();
    let relationships = {};
    let detailsTarget = '';
    let modalReturnFocus = null;
    try {
        const parsedRelationships = JSON.parse(hidden.value || '{}');
        relationships = parsedRelationships && typeof parsedRelationships === 'object' && !Array.isArray(parsedRelationships)
            ? parsedRelationships
            : {};
    } catch (_) { relationships = {}; }
    Object.values(relationships).forEach(data => {
        const type = String(data.type || '').toLowerCase();
        if (type && !types.includes(type)) types.push(type);
    });

    function tier(value) {
        if (value >= 91) return 'Bonded';
        if (value >= 76) return 'Devoted';
        if (value >= 56) return 'Fond';
        if (value >= 31) return 'Friendly';
        if (value >= 6) return 'Acquaintance';
        if (value >= -5) return 'Neutral';
        if (value >= -30) return 'Wary';
        if (value >= -55) return 'Cold';
        if (value >= -75) return 'Resentful';
        if (value >= -90) return 'Hateful';
        return 'Hostile';
    }
    function tierColor(value) {
        if (value >= 56) return '#86efac';
        if (value >= 6) return '#d9f99d';
        if (value >= -5) return '#e5e7eb';
        if (value >= -55) return '#fde68a';
        return '#fca5a5';
    }
    function truncate(value, limit) {
        const text = String(value || '');
        return text.length > limit ? text.slice(0, limit - 3) + '...' : text;
    }
    // Automatic merges preserve the exact player-authored text; only an explicit
    // details save trims it. Custom Info never comes from model output.
    function privateNote(data) {
        return data && typeof data.custom_info === 'string' ? data.custom_info : '';
    }
    function setStatus(message, isError) {
        status.textContent = message || '';
        status.classList.toggle('visible', Boolean(message));
        status.classList.toggle('error', Boolean(isError));
    }
    function sync() {
        hidden.value = JSON.stringify(relationships);
        lockedHidden.value = locked.checked ? '1' : '0';
        empty.style.display = Object.keys(relationships).length ? 'none' : 'block';
    }
    function protectManualEdit() {
        locked.checked = true;
        sync();
    }
    function fillTypeSelect(select, selected) {
        select.textContent = '';
        if (selected && !types.includes(selected)) types.push(selected);
        types.forEach(type => {
            const option = document.createElement('option');
            option.value = type;
            option.textContent = type.charAt(0).toUpperCase() + type.slice(1);
            option.selected = type === selected;
            select.appendChild(option);
        });
    }
    function signal(container, className, label, value) {
        if (!value) return false;
        const line = document.createElement('div');
        line.className = className;
        line.textContent = label + ': ' + truncate(value, 62);
        line.title = String(value);
        container.appendChild(line);
        return true;
    }
    function render() {
        tbody.textContent = '';
        Object.entries(relationships).forEach(([target, data]) => {
            const row = document.createElement('tr');
            const targetCell = document.createElement('td');
            const affinityCell = document.createElement('td');
            const tierCell = document.createElement('td');
            const typeCell = document.createElement('td');
            const signalsCell = document.createElement('td');
            const actionsCell = document.createElement('td');

            const targetInput = document.createElement('input');
            targetInput.value = target;
            targetInput.addEventListener('change', () => {
                const next = targetInput.value.trim();
                if (!next || next === target) return;
                relationships[next] = data;
                delete relationships[target];
                protectManualEdit();
                render();
            });
            targetCell.appendChild(targetInput);

            const affinity = document.createElement('input');
            affinity.className = 'rel-affinity';
            affinity.type = 'number'; affinity.min = '-100'; affinity.max = '100';
            affinity.value = Number(data.aff || 0);
            affinity.addEventListener('input', () => {
                data.aff = Math.max(-100, Math.min(100, Number(affinity.value || 0)));
                tierCell.textContent = tier(data.aff);
                tierCell.style.color = tierColor(data.aff);
                protectManualEdit();
            });
            affinityCell.appendChild(affinity);

            tierCell.className = 'rel-tier';
            tierCell.textContent = tier(Number(data.aff || 0));
            tierCell.style.color = tierColor(Number(data.aff || 0));

            const typeSelect = document.createElement('select');
            fillTypeSelect(typeSelect, String(data.type || 'neutral'));
            typeSelect.addEventListener('change', () => { data.type = typeSelect.value; protectManualEdit(); });
            typeCell.appendChild(typeSelect);

            signalsCell.className = 'relationship-signals';
            let hasSignals = signal(signalsCell, 'relationship-signal-note', 'Last', data.note);
            hasSignals = signal(signalsCell, 'relationship-signal-best', 'Best', data.best) || hasSignals;
            hasSignals = signal(signalsCell, 'relationship-signal-worst', 'Worst', data.worst) || hasSignals;
            if (!hasSignals && data.relation) hasSignals = signal(signalsCell, 'relationship-signal-note', 'Role', data.relation);
            const privateText = privateNote(data).trim();
            if (privateText) {
                const privateLine = document.createElement('div');
                privateLine.className = 'relationship-signal-private';
                privateLine.textContent = 'Custom Info: ' + truncate(privateText.replace(/\s+/g, ' '), 62);
                privateLine.title = 'Player notes only. Not sent to the AI.\n' + privateText;
                signalsCell.appendChild(privateLine);
                hasSignals = true;
            }
            if (!hasSignals) {
                const none = document.createElement('span');
                none.className = 'relationship-no-signals'; none.textContent = 'No signals';
                signalsCell.appendChild(none);
            }

            actionsCell.className = 'relationship-row-actions';
            const details = document.createElement('button');
            details.type = 'button'; details.className = 'relationship-icon-button'; details.textContent = '...'; details.title = 'Edit relationship details';
            details.dataset.relDetailsTarget = target;
            if (data.relation || data.note || data.best || data.worst || privateText) details.classList.add('has-details');
            details.addEventListener('click', () => openDetails(target));
            const remove = document.createElement('button');
            remove.type = 'button'; remove.className = 'relationship-icon-button danger'; remove.textContent = 'X'; remove.title = 'Remove relationship';
            remove.addEventListener('click', () => { delete relationships[target]; protectManualEdit(); render(); });
            actionsCell.append(details, remove);
            [targetCell, affinityCell, tierCell, typeCell, signalsCell, actionsCell].forEach(cell => row.appendChild(cell));
            tbody.appendChild(row);
        });
        fillTypeSelect(document.getElementById('rel_new_type'), document.getElementById('rel_new_type').value || 'neutral');
        sync();
    }
    function focusDetailsButton(target) {
        const buttons = tbody.querySelectorAll('button[data-rel-details-target]');
        for (let index = 0; index < buttons.length; index += 1) {
            if (buttons[index].dataset.relDetailsTarget === target) {
                buttons[index].focus();
                return;
            }
        }
    }
    function openModal(id) {
        const modal = document.getElementById(id);
        modalReturnFocus = document.activeElement;
        modal.classList.add('open');
        modal.setAttribute('aria-hidden', 'false');
        const first = modal.querySelector('input, textarea, select, button');
        if (first) first.focus();
    }
    function closeModal(id) {
        const modal = document.getElementById(id);
        modal.classList.remove('open');
        modal.setAttribute('aria-hidden', 'true');
        const previous = modalReturnFocus;
        modalReturnFocus = null;
        if (previous && previous.isConnected && typeof previous.focus === 'function') {
            previous.focus();
            return;
        }
        // Saving details re-renders the table, so the button that opened it is gone.
        if (id === 'rel_details_modal') focusDetailsButton(detailsTarget);
    }
    function openDetails(target) {
        detailsTarget = target;
        const data = relationships[target] || {};
        document.getElementById('rel_details_target').textContent = target;
        document.getElementById('rel_details_relation').value = data.relation || '';
        document.getElementById('rel_details_note').value = data.note || '';
        document.getElementById('rel_details_best').value = data.best || '';
        document.getElementById('rel_details_worst').value = data.worst || '';
        document.getElementById('rel_details_custom').value = typeof data.custom_info === 'string' ? data.custom_info : '';
        openModal('rel_details_modal');
    }

    document.querySelectorAll('[data-rel-close]').forEach(button => {
        button.addEventListener('click', () => closeModal(button.getAttribute('data-rel-close')));
    });
    // Capture phase so Escape closes only this modal. npc_master listens on document
    // in the bubble phase and would otherwise close the whole NPC modal underneath.
    section.addEventListener('keydown', event => {
        if (event.key !== 'Escape' && event.key !== 'Tab') return;
        const open = section.querySelector('.relationship-modal.open');
        if (!open) return;
        if (event.key === 'Escape') {
            event.preventDefault();
            event.stopPropagation();
            closeModal(open.id);
            return;
        }
        // Keep keyboard navigation inside the details dialog while it is open.
        if (open.id !== 'rel_details_modal') return;
        const controls = open.querySelectorAll('input, textarea, select, button:not(:disabled)');
        const first = controls[0];
        const last = controls[controls.length - 1];
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    }, true);
    document.getElementById('rel_details_save').addEventListener('click', () => {
        const data = relationships[detailsTarget];
        if (!data) return closeModal('rel_details_modal');
        let aiFieldsChanged = false;
        ['relation', 'note', 'best', 'worst'].forEach(field => {
            const value = document.getElementById('rel_details_' + field).value.trim();
            if (value === (data[field] || '')) return;
            aiFieldsChanged = true;
            if (value) data[field] = value; else delete data[field];
        });
        const note = document.getElementById('rel_details_custom').value.trim();
        const noteChanged = note !== privateNote(data);
        if (note) {
            data.custom_info = note;
        } else if ('custom_info' in data) {
            // Keep an explicit empty value so clearing reads as a clear instead of an
            // untouched field that server-side preservation could restore.
            data.custom_info = '';
        }
        // Custom Info never reaches the model, so it must not turn on the AI lock.
        if (aiFieldsChanged) protectManualEdit();
        // Render first so closeModal sees the stale opener button and restores focus
        // to the rebuilt row instead of dropping it on the page body.
        render();
        closeModal('rel_details_modal');
        if (!aiFieldsChanged && noteChanged) {
            setStatus('Custom Info updated. Save the NPC to keep it.', false);
        } else {
            setStatus('Relationship details updated. Save the NPC to keep them.', false);
        }
    });
    // Shared by the Add button and the save hooks below. Returns false when nothing is staged.
    function commitStagedRow() {
        const targetInput = document.getElementById('rel_new_target');
        const target = targetInput.value.trim();
        if (!target) return false;
        const stagedNote = privateNote(relationships[target]);
        relationships[target] = {
            aff: Math.max(-100, Math.min(100, Number(document.getElementById('rel_new_affinity').value || 0))),
            type: document.getElementById('rel_new_type').value || 'neutral'
        };
        // Re-adding a tracked target replaces its affinity and type, not the player note.
        if (stagedNote) relationships[target].custom_info = stagedNote;
        protectManualEdit();
        targetInput.value = '';
        document.getElementById('rel_new_affinity').value = '0';
        render();
        return true;
    }
    document.getElementById('rel_add').addEventListener('click', () => {
        if (!commitStagedRow()) return setStatus('Enter a target name first.', true);
        setStatus('Relationship added. Save the NPC to keep it.', false);
    });
    document.getElementById('rel_build').addEventListener('click', () => openModal('rel_build_modal'));
    document.getElementById('rel_build_confirm').addEventListener('click', async function () {
        const button = this;
        button.disabled = true;
        setStatus('Analyzing recent events...', false);
        try {
            const body = new FormData();
            body.append('npc_id', <?= json_encode((string)($editItem['id'] ?? '')) ?>);
            body.append('npc_name', <?= json_encode((string)($editItem['npc_name'] ?? '')) ?>);
            body.append('event_limit', '200');
            body.append('direction', document.getElementById('rel_build_direction').value.trim());
            body.append('custom_types', JSON.stringify(types.filter(type => !baseTypes.includes(type))));
            const response = await fetch(<?= json_encode($relationshipEndpoint) ?>, {method: 'POST', body});
            const result = await response.json();
            if (!response.ok || !result.ok) throw new Error(result.error || 'Relationship analysis failed.');
            const merged = Object.assign({}, relationships);
            Object.entries(result.relationships || {}).forEach(([target, incoming]) => {
                const next = Object.assign({}, incoming);
                delete next.custom_info;
                const keptNote = privateNote(relationships[target]);
                if (keptNote) next.custom_info = keptNote;
                merged[target] = next;
            });
            relationships = merged;
            closeModal('rel_build_modal');
            render();
            setStatus('Built ' + Number(result.count || 0) + ' relationship(s) from ' + Number(result.event_count || 0) + ' events. Save the NPC to keep them.', false);
        } catch (error) {
            setStatus(error.message || String(error), true);
        } finally {
            button.disabled = false;
        }
    });
    document.getElementById('rel_custom_type').addEventListener('click', () => openModal('rel_custom_modal'));
    document.getElementById('rel_custom_confirm').addEventListener('click', () => {
        const input = document.getElementById('rel_custom_name');
        const value = input.value.trim().toLowerCase().replace(/[^a-z0-9_-]+/g, '_').replace(/^_+|_+$/g, '');
        if (!value) return setStatus('Enter a valid custom type name.', true);
        if (!types.includes(value)) types.push(value);
        input.value = '';
        closeModal('rel_custom_modal');
        render();
        document.getElementById('rel_new_type').value = value;
        setStatus('Custom type added for this editor session.', false);
    });
    document.getElementById('rel_clear').addEventListener('click', () => {
        if (!window.confirm('Clear every relationship from this NPC? Changes are not permanent until you save.')) return;
        relationships = {};
        protectManualEdit();
        render();
        setStatus('All relationships cleared. Save the NPC to make this permanent.', false);
    });
    locked.addEventListener('change', sync);

    // The add row is not part of the submitted fields, so a filled target would be dropped
    // when the NPC is saved without pressing Add. Commit it first on both save paths:
    // the standalone form submits, while the modal posts FormData from a save button click.
    // Capture phase keeps this ahead of the handlers that read relationships_jsonb.
    const npcForm = section.closest('form');
    if (npcForm) npcForm.addEventListener('submit', () => { commitStagedRow(); }, true);
    document.addEventListener('click', event => {
        const save = event.target && event.target.closest ? event.target.closest('#npc_modal_save') : null;
        if (save) commitStagedRow();
    }, true);

    // npc_master currently emits several biography fields on one PHP line. Move the
    // component after render so its visible position still matches CHIM: below Backstory.
    const backstory = document.getElementById('npc_static_bio');
    const backstoryGroup = backstory ? backstory.closest('.form-item') : null;
    if (backstoryGroup && section && backstoryGroup.nextElementSibling !== section) {
        backstoryGroup.insertAdjacentElement('afterend', section);
    }
    render();
})();
</script>
