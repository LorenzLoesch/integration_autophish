/* SPDX-License-Identifier: AGPL-3.0-or-later */
(() => {
    'use strict';
    const root = document.getElementById('autophish-settings');
    if (!root) return;
    const connection = document.getElementById('autophish-connection');
    const mapping = document.getElementById('autophish-mapping');
    const message = document.getElementById('autophish-message');
    const controls = () => root.querySelectorAll('button');
    const api = async (path = '', method = 'GET', body) => {
        const response = await fetch(OC.generateUrl('/apps/integration_autophish/settings' + path), {
            method, credentials: 'same-origin', headers: { 'Content-Type': 'application/json', requesttoken: OC.requestToken },
            body: body === undefined ? undefined : JSON.stringify(body),
        });
        const result = await response.json();
        if (!response.ok) throw new Error(result.error || 'The operation failed.');
        return result;
    };
    let busy = false;
    const run = async (action) => {
        if (busy) return;
        busy = true;
        controls().forEach(button => { button.disabled = true; });
        message.textContent = '';
        try { await action(); } catch (error) { message.textContent = error.message; }
        finally { busy = false; controls().forEach(button => { button.disabled = false; }); }
    };
    const render = (state) => {
        for (const field of ['baseUrl', 'companyId']) connection.elements[field].value = state.connection[field];
        connection.elements.interval.value = state.interval;
        connection.elements.apiKey.value = '';
        document.getElementById('autophish-key-status').textContent = state.connection.configured ? 'API key is saved and encrypted.' : 'No API key is configured.';
        const status = document.getElementById('autophish-status');
        status.replaceChildren();
        const entry = (label, value) => { const term = document.createElement('dt'); term.textContent = label; const detail = document.createElement('dd'); detail.textContent = value; status.append(term, detail); };
        const date = value => value ? new Date(value * 1000).toLocaleString() : 'Never';
        entry('State', state.status.state || 'Not run');
        entry('Last run', date(state.status.lastRun));
        entry('Last successful run', date(state.status.lastSuccess));
        for (const [key, label] of Object.entries({ added: 'Added', removed: 'Removed', skipped: 'Skipped recipients', duplicates: 'Duplicate emails', removalBlocked: 'Removal blocked' })) {
            entry(label, state.status[key] ?? 0);
        }
        if (state.status.errorCategory) entry('Error category', state.status.errorCategory);
        if (state.status.error) entry('Last error', state.status.error);
        const table = document.getElementById('autophish-mappings');
        table.replaceChildren();
        state.mappings.forEach(item => {
            const row = document.createElement('tr');
            [item.groupName || item.groupId, item.listName || item.listId, item.enabled ? 'Yes' : 'No'].forEach(value => {
                const cell = document.createElement('td'); cell.textContent = value; row.append(cell);
            });
            const cell = document.createElement('td');
            const toggle = document.createElement('button'); toggle.type = 'button'; toggle.textContent = item.enabled ? 'Disable' : 'Enable';
            toggle.addEventListener('click', () => run(async () => render(await api('/mappings', 'PUT', { groupId: item.groupId, enabled: !item.enabled }))));
            const remove = document.createElement('button'); remove.type = 'button'; remove.textContent = 'Remove mapping';
            remove.addEventListener('click', () => run(async () => render(await api('/mappings', 'DELETE', { groupId: item.groupId }))));
            cell.append(toggle, remove); row.append(cell); table.append(row);
        });
        document.getElementById('autophish-pending').hidden = !state.pendingCreation;
        if (state.pendingCreation) {
            const pending = state.pendingCreationDetails;
            message.textContent = 'List creation is unresolved for group ' + pending.groupId + ' and list “' + pending.name + '” since ' + date(pending.createdAt) + '. Retry these values, or inspect AutoPhish and attach the existing list.';
        }
    };
    document.getElementById('autophish-refresh').addEventListener('click', () => run(async () => render(await api())));
    document.getElementById('autophish-clear-pending').addEventListener('click', () => run(async () => render(await api('/pending-creation', 'DELETE', { verifiedAbsent: document.getElementById('autophish-verified-absent').checked }))));
    connection.addEventListener('submit', event => {
        event.preventDefault();
        const values = { baseUrl: connection.elements.baseUrl.value, companyId: connection.elements.companyId.value, apiKey: connection.elements.apiKey.value, interval: Number(connection.elements.interval.value) };
        connection.elements.apiKey.value = '';
        run(async () => { render(await api('', 'PUT', values)); mapping.hidden = true; message.textContent = 'Connection saved.'; });
    });
    document.getElementById('autophish-discover').addEventListener('click', () => run(async () => {
        const result = await api('/discover', 'POST');
        mapping.elements.groupId.replaceChildren();
        mapping.elements.listId.replaceChildren();
        const option = (select, value, label) => { const entry = document.createElement('option'); entry.value = value; entry.textContent = label; select.append(entry); };
        result.groups.forEach(group => option(mapping.elements.groupId, group.id, group.name));
        option(mapping.elements.listId, '', 'Create a new list');
        result.lists.forEach(list => option(mapping.elements.listId, list.id, list.name));
        mapping.elements.name.disabled = false;
        mapping.hidden = false; message.textContent = 'Connection verified. Groups and writable target lists loaded.';
    }));
    mapping.elements.listId.addEventListener('change', () => { mapping.elements.name.disabled = mapping.elements.listId.value !== ''; });
    mapping.addEventListener('submit', event => {
        event.preventDefault();
        run(async () => render(await api('/mappings', 'POST', { groupId: mapping.elements.groupId.value, listId: mapping.elements.listId.value, name: mapping.elements.listId.value === '' ? mapping.elements.name.value : '' })));
    });
    run(async () => render(await api()));
})();
