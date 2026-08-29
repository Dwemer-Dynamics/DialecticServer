(function (global) {
    'use strict';

    async function requestJson(url, options) {
        const response = await fetch(url, options);
        let payload = null;
        try { payload = await response.json(); } catch (_error) {}
        if (!response.ok || !payload || !payload.success) {
            throw new Error((payload && payload.error) || ('HTTP ' + response.status));
        }
        return payload.data;
    }

    function element(tag, className, text) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
    }

    // Relationship rows are derived from NPC history snapshots: the concise summary stays
    // in the table and the full before/after breakdown is revealed on hover or focus.
    function relationshipCell(event, index) {
        const cell = element('td', 'npc-event-history-data');
        const detail = String(event.detail || '').trim();
        if (!detail) {
            cell.appendChild(element('span', 'rel-timeline-summary', event.data || ''));
            return cell;
        }

        const tooltipId = 'npc-rel-tip-' + (Number(event.history_id) || index);
        const tip = element('span', 'rel-timeline-tip');
        tip.tabIndex = 0;
        tip.setAttribute('role', 'note');
        tip.setAttribute('aria-describedby', tooltipId);
        tip.appendChild(element('span', 'rel-timeline-summary', event.data || ''));
        const body = element('span', 'rel-timeline-detail', detail);
        body.id = tooltipId;
        body.setAttribute('role', 'tooltip');
        tip.appendChild(body);
        cell.appendChild(tip);
        return cell;
    }

    function mount(root, options) {
        const npcId = Number(options.npcId || 0);
        const npcName = String(options.npcName || '').trim();
        const apiUrl = String(options.apiUrl || '../api/dialectic_npc_manager.php');
        const recipients = new Map([[npcId, npcName]]);
        let searchTimer = null;
        let searchGeneration = 0;
        let selectedEventType = '';

        root.innerHTML = `
            <div class="npc-event-history-shell span-2">
                <section class="npc-event-history-section">
                    <div class="npc-event-history-heading">
                        <div><h3>Inject Event</h3><p>Add roleplay context directly to this NPC, even while they are outside the current scene.</p></div>
                    </div>
                    <div class="npc-event-recipient-row" data-history-recipients></div>
                    <label class="npc-event-history-label">Also include another NPC</label>
                    <input type="search" class="npc-event-recipient-search" data-history-recipient-search placeholder="Search NPC profiles">
                    <div class="npc-event-search-results" data-history-search-results hidden></div>
                    <label class="npc-event-history-label" for="npc-event-history-text-${npcId}">Event</label>
                    <textarea id="npc-event-history-text-${npcId}" class="npc-event-history-text" data-history-event-text maxlength="4000" placeholder="A gave B a Sunset Sarsaparilla."></textarea>
                    <div class="npc-event-history-actions">
                        <span class="npc-event-history-status" data-history-status role="status" aria-live="polite"></span>
                        <button type="button" class="btn-cancel" data-history-inject>Inject Event</button>
                    </div>
                </section>
                <section class="npc-event-history-section">
                    <div class="npc-event-history-heading">
                        <div><h3>Recent Events</h3><p>The latest events routed to this NPC, plus read-only relationship changes from their history. Deleting a shared event removes it for every listed recipient.</p></div>
                        <button type="button" class="btn-cancel" data-history-refresh>Refresh</button>
                    </div>
                    <div class="npc-event-history-filterbar">
                        <label>Event type
                            <select data-history-event-type><option value="">All visible events</option></select>
                        </label>
                        <span class="npc-event-history-filter-note" data-history-filter-note>Using Event Log visibility filters.</span>
                    </div>
                    <div class="npc-event-history-list" data-history-list><p class="npc-event-history-empty">Open this tab to load recent events.</p></div>
                </section>
            </div>`;

        const recipientBox = root.querySelector('[data-history-recipients]');
        const recipientSearch = root.querySelector('[data-history-recipient-search]');
        const searchResults = root.querySelector('[data-history-search-results]');
        const eventText = root.querySelector('[data-history-event-text]');
        const status = root.querySelector('[data-history-status]');
        const list = root.querySelector('[data-history-list]');
        const injectButton = root.querySelector('[data-history-inject]');
        const refreshButton = root.querySelector('[data-history-refresh]');
        const eventTypeSelect = root.querySelector('[data-history-event-type]');
        const filterNote = root.querySelector('[data-history-filter-note]');

        function setStatus(message, isError) {
            status.textContent = message || '';
            status.classList.toggle('is-error', Boolean(isError));
        }

        function renderRecipients() {
            recipientBox.replaceChildren();
            recipients.forEach(function (name, id) {
                const chip = element('span', 'npc-event-recipient-chip');
                chip.appendChild(element('span', '', name));
                if (Number(id) !== npcId) {
                    const remove = element('button', '', 'x');
                    remove.type = 'button';
                    remove.setAttribute('aria-label', 'Remove ' + name);
                    remove.addEventListener('click', function () {
                        recipients.delete(id);
                        renderRecipients();
                    });
                    chip.appendChild(remove);
                }
                recipientBox.appendChild(chip);
            });
        }

        function renderSearchResults(npcs) {
            searchResults.replaceChildren();
            const available = npcs.filter(function (npc) { return !recipients.has(Number(npc.id)); });
            if (!available.length) {
                searchResults.hidden = true;
                return;
            }
            available.forEach(function (npc) {
                const button = element('button', 'npc-event-search-result', npc.name || 'Unknown NPC');
                button.type = 'button';
                button.addEventListener('click', function () {
                    recipients.set(Number(npc.id), String(npc.name || 'Unknown NPC'));
                    recipientSearch.value = '';
                    searchResults.hidden = true;
                    renderRecipients();
                });
                searchResults.appendChild(button);
            });
            searchResults.hidden = false;
        }

        async function searchNpcRecipients() {
            const search = recipientSearch.value.trim();
            if (search.length < 2) {
                searchResults.hidden = true;
                return;
            }
            const generation = ++searchGeneration;
            const query = new URLSearchParams({operation: 'list', search: search, page: '1', limit: '10'});
            try {
                const data = await requestJson(apiUrl + '?' + query.toString(), {cache: 'no-store'});
                if (generation === searchGeneration) renderSearchResults(Array.isArray(data.npcs) ? data.npcs : []);
            } catch (_error) {
                if (generation === searchGeneration) searchResults.hidden = true;
            }
        }

        function renderFilters(filters) {
            const types = Array.isArray(filters.event_types) ? filters.event_types : [];
            const hiddenTypes = Array.isArray(filters.hidden_event_types) ? filters.hidden_event_types : [];
            const selected = String(filters.selected_event_type || selectedEventType);
            eventTypeSelect.replaceChildren(new Option('All visible events', ''));
            types.forEach(function (entry) {
                const type = String(entry.type || '');
                if (!type) return;
                eventTypeSelect.appendChild(new Option(type + ' (' + Number(entry.total || 0) + ')', type));
            });
            eventTypeSelect.value = selected;
            selectedEventType = eventTypeSelect.value;
            filterNote.textContent = hiddenTypes.length
                ? 'Hidden by Event Log: ' + hiddenTypes.join(', ')
                : 'Using Event Log visibility filters.';
        }

        function renderEvents(events) {
            list.replaceChildren();
            if (!events.length) {
                list.appendChild(element('p', 'npc-event-history-empty', 'No events are recorded for this NPC yet.'));
                return;
            }
            const tableWrap = element('div', 'npc-event-history-table-wrap');
            const table = element('table', 'npc-event-history-table');
            const thead = document.createElement('thead');
            const headerRow = document.createElement('tr');
            ['Type', 'Event', 'People Present', 'Fallout Time', 'Time (UTC)', ''].forEach(function (label) {
                headerRow.appendChild(element('th', '', label));
            });
            thead.appendChild(headerRow);
            const tbody = document.createElement('tbody');
            events.forEach(function (event, index) {
                const row = document.createElement('tr');
                if (event.virtual) {
                    row.className = 'npc-event-history-virtual';
                    const typeCell = element('td', 'npc-event-history-type');
                    typeCell.appendChild(element('span', 'rel-timeline-type', event.type || 'relationship'));
                    row.appendChild(typeCell);
                    row.appendChild(relationshipCell(event, index));
                    row.appendChild(element(
                        'td',
                        'npc-event-history-audience',
                        Array.isArray(event.recipients) ? event.recipients.join(', ') : ''
                    ));
                    row.appendChild(element('td', '', event.fallout_time || ''));
                    row.appendChild(element('td', '', event.local_time || ''));
                    const readOnly = document.createElement('td');
                    const marker = element('span', 'rel-timeline-virtual-id', '—');
                    marker.title = 'Read-only relationship timeline entry';
                    marker.setAttribute('aria-label', 'Read-only relationship timeline entry');
                    readOnly.appendChild(marker);
                    row.appendChild(readOnly);
                    tbody.appendChild(row);
                    return;
                }
                row.appendChild(element('td', 'npc-event-history-type', event.type || 'Event'));
                row.appendChild(element('td', 'npc-event-history-data', event.data || ''));
                row.appendChild(element(
                    'td',
                    'npc-event-history-audience',
                    Array.isArray(event.recipients) ? event.recipients.join(', ') : ''
                ));
                row.appendChild(element('td', '', event.fallout_time || ''));
                row.appendChild(element('td', '', event.local_time || ''));
                const deleteButton = element('button', 'npc-event-history-delete', 'Delete');
                deleteButton.type = 'button';
                deleteButton.addEventListener('click', async function () {
                    const shared = Array.isArray(event.recipients) && event.recipients.length > 1;
                    const warning = shared ? ' This removes it from every listed NPC history.' : '';
                    if (!global.confirm('Delete this event?' + warning)) return;
                    deleteButton.disabled = true;
                    try {
                        await requestJson(apiUrl, {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify({operation: 'delete_event', id: npcId, rowid: Number(event.rowid)})
                        });
                        setStatus('Event deleted.', false);
                        await load();
                    } catch (error) {
                        setStatus('Delete failed: ' + (error.message || error), true);
                        deleteButton.disabled = false;
                    }
                });
                const actions = document.createElement('td');
                actions.appendChild(deleteButton);
                row.appendChild(actions);
                tbody.appendChild(row);
            });
            table.append(thead, tbody);
            tableWrap.appendChild(table);
            list.appendChild(tableWrap);
        }

        async function load() {
            if (npcId <= 0) return;
            refreshButton.disabled = true;
            list.replaceChildren(element('p', 'npc-event-history-empty', 'Loading recent events...'));
            try {
                const query = new URLSearchParams({operation: 'history', id: String(npcId), limit: '100'});
                if (selectedEventType) query.set('event_type', selectedEventType);
                const data = await requestJson(apiUrl + '?' + query.toString(), {cache: 'no-store'});
                renderFilters(data.filters || {});
                renderEvents(Array.isArray(data.events) ? data.events : []);
            } catch (error) {
                list.replaceChildren(element('p', 'npc-event-history-empty is-error', 'History failed to load: ' + (error.message || error)));
            } finally {
                refreshButton.disabled = false;
            }
        }

        recipientSearch.addEventListener('input', function () {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(searchNpcRecipients, 250);
        });
        refreshButton.addEventListener('click', load);
        eventTypeSelect.addEventListener('change', function () {
            selectedEventType = eventTypeSelect.value;
            load();
        });
        injectButton.addEventListener('click', async function () {
            const text = eventText.value.trim();
            if (!text) {
                setStatus('Enter an event before injecting it.', true);
                eventText.focus();
                return;
            }
            injectButton.disabled = true;
            setStatus('Injecting event...', false);
            try {
                const data = await requestJson(apiUrl, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({
                        operation: 'inject_event',
                        id: npcId,
                        event: text,
                        recipient_ids: Array.from(recipients.keys())
                    })
                });
                eventText.value = '';
                setStatus(data.message || 'Event injected.', false);
                await load();
            } catch (error) {
                setStatus('Injection failed: ' + (error.message || error), true);
            } finally {
                injectButton.disabled = false;
            }
        });

        renderRecipients();
        return {load: load};
    }

    global.dialecticNpcEventHistory = {mount: mount};
})(window);
