/* SPDX-License-Identifier: AGPL-3.0-or-later */
const { test } = require('node:test');
const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const { resolve } = require('node:path');
const { JSDOM } = require('jsdom');

const app = resolve(__dirname, '../..');
const html = readFileSync(resolve(app, 'templates/admin.php'), 'utf8').replace(/<\?php[\s\S]*?\?>/, '');
const script = readFileSync(resolve(app, 'js/admin.js'), 'utf8');
const state = (overrides = {}) => ({ connection: { baseUrl: 'https://example.com', companyId: 'company', configured: true }, interval: 3600, mappings: [], status: {}, pendingCreation: false, pendingCreationDetails: null, ...overrides });
const flush = () => new Promise(resolve => setImmediate(resolve));

async function browser(t, initial = state()) {
    const dom = new JSDOM(html, { url: 'https://nextcloud.example/settings/admin/additional', runScripts: 'outside-only' });
    t.after(() => dom.window.close());
    const calls = [];
    const responses = [{ data: initial }];
    dom.window.OC = { generateUrl: path => path, requestToken: 'csrf-fixture' };
    dom.window.fetch = async (url, options) => {
        calls.push({ url, ...options, data: options.body === undefined ? undefined : JSON.parse(options.body) });
        const response = responses.shift();
        assert.ok(response, 'Unexpected fetch: ' + options.method + ' ' + url);
        return { ok: !response.error, json: async () => response.data };
    };
    dom.window.eval(script);
    await flush();
    const document = dom.window.document;
    const element = id => document.getElementById(id);
    const submit = async id => { element(id).dispatchEvent(new dom.window.Event('submit', { bubbles: true, cancelable: true })); await flush(); };
    const click = async id => { element(id).click(); await flush(); };
    const change = select => select.dispatchEvent(new dom.window.Event('change', { bubbles: true }));
    return { dom, document, calls, responses, element, submit, click, change };
}

test('saving a connection sends credentials once and clears its input on success and failure', async t => {
    const ui = await browser(t);
    const fields = ui.element('autophish-connection').elements;
    fields.baseUrl.value = 'https://new.example'; fields.companyId.value = 'new-company'; fields.apiKey.value = 'secret-fixture-key'; fields.interval.value = '600';
    ui.responses.push({ data: state({ connection: { baseUrl: 'https://new.example', companyId: 'new-company', configured: true }, interval: 600 }) });
    await ui.submit('autophish-connection');
    assert.deepEqual(ui.calls[1].data, { baseUrl: 'https://new.example', companyId: 'new-company', apiKey: 'secret-fixture-key', interval: 600 });
    assert.equal(ui.calls[1].headers.requesttoken, 'csrf-fixture');
    assert.equal(fields.apiKey.value, '');
    assert.match(ui.element('autophish-message').textContent, /Connection saved/);
    fields.apiKey.value = 'second-secret-fixture-key';
    ui.responses.push({ error: true, data: { error: 'Use an HTTPS server origin.' } });
    await ui.submit('autophish-connection');
    assert.equal(fields.apiKey.value, '');
    assert.equal(ui.element('autophish-message').textContent, 'Use an HTTPS server origin.');
    assert.doesNotMatch(ui.document.body.textContent, /secret-fixture-key/);
    assert.equal(ui.element('autophish-discover').disabled, false);
});

test('discovery and mapping support existing lists and new list creation with literal untrusted labels', async t => {
    const ui = await browser(t);
    const unsafe = '<img src=x onerror="alert(1)">';
    const discovery = { groups: [{ id: 'staff/team', name: unsafe }], lists: [{ id: 'list-one', name: unsafe }] };
    ui.responses.push({ data: discovery });
    await ui.click('autophish-discover');
    const fields = ui.element('autophish-mapping').elements;
    assert.equal(ui.element('autophish-mapping').hidden, false);
    assert.equal(fields.groupId.options[0].textContent, unsafe);
    assert.equal(fields.listId.options[1].textContent, unsafe);
    assert.equal(ui.document.querySelectorAll('img').length, 0);
    fields.listId.value = 'list-one'; ui.change(fields.listId);
    assert.equal(fields.name.disabled, true);
    const mapped = { groupId: 'staff/team', groupName: unsafe, listId: 'list-one', listName: unsafe, enabled: true };
    ui.responses.push({ data: state({ mappings: [mapped] }) });
    await ui.submit('autophish-mapping');
    assert.deepEqual(ui.calls.at(-1).data, { groupId: 'staff/team', listId: 'list-one', name: '' });
    assert.equal(ui.element('autophish-mappings').querySelector('td').textContent, unsafe);
    assert.equal(ui.document.querySelectorAll('img').length, 0);
    ui.responses.push({ data: discovery }); await ui.click('autophish-discover');
    assert.equal(fields.name.disabled, false, 'Rediscovery selects new list and enables its name');
    fields.name.value = 'New dedicated list';
    ui.responses.push({ data: state() }); await ui.submit('autophish-mapping');
    assert.deepEqual(ui.calls.at(-1).data, { groupId: 'staff/team', listId: '', name: 'New dedicated list' });
});

test('mapping toggle and removal carry slash-containing group IDs in the JSON body', async t => {
    const mapping = { groupId: 'staff/team', listId: 'list-one', enabled: true };
    const ui = await browser(t, state({ mappings: [mapping] }));
    ui.responses.push({ data: state({ mappings: [{ ...mapping, enabled: false }] }) });
    ui.element('autophish-mappings').querySelector('button').click(); await flush();
    assert.equal(ui.calls.at(-1).url, '/apps/integration_autophish/settings/mappings');
    assert.equal(ui.calls.at(-1).method, 'PUT');
    assert.deepEqual(ui.calls.at(-1).data, { groupId: 'staff/team', enabled: false });
    assert.equal(ui.element('autophish-mappings').querySelector('button').textContent, 'Enable');
    ui.responses.push({ data: state() });
    ui.element('autophish-mappings').querySelectorAll('button')[1].click(); await flush();
    assert.equal(ui.calls.at(-1).method, 'DELETE');
    assert.deepEqual(ui.calls.at(-1).data, { groupId: 'staff/team' });
    assert.equal(ui.element('autophish-mappings').children.length, 0);
});

test('status presents readable dates and counts and can refresh after cron runs', async t => {
    const ui = await browser(t, state({ status: { state: 'partial', lastRun: 1780000000, lastSuccess: 1779990000, added: 3, removed: 2, skipped: 1, duplicates: 0, removalBlocked: 1, errorCategory: 'permission', error: 'Permission is required.' } }));
    const entries = () => Object.fromEntries([...ui.element('autophish-status').querySelectorAll('dt')].map(term => [term.textContent, term.nextElementSibling.textContent]));
    assert.equal(entries()['State'], 'partial');
    assert.equal(entries()['Added'], '3');
    assert.equal(entries()['Removed'], '2');
    assert.equal(entries()['Error category'], 'permission');
    assert.equal(entries()['Last error'], 'Permission is required.');
    assert.equal(entries()['Last run'], new ui.dom.window.Date(1780000000 * 1000).toLocaleString());
    ui.responses.push({ data: state({ status: { state: 'success', added: 4 } }) });
    await ui.click('autophish-refresh');
    assert.equal(ui.calls.at(-1).method, 'GET');
    assert.equal(entries()['State'], 'success');
    assert.equal(entries()['Last run'], 'Never');
    assert.equal(entries()['Error category'], undefined);
});

test('pending creation recovery displays original context and requires inspection acknowledgement', async t => {
    const ui = await browser(t, state({ pendingCreation: true, pendingCreationDetails: { groupId: 'staff/team', name: 'Staff <script>alert(1)</script>', createdAt: 1780000000 } }));
    assert.equal(ui.element('autophish-pending').hidden, false);
    assert.match(ui.element('autophish-message').textContent, /staff\/team/);
    assert.equal(ui.element('autophish-message').querySelector('script'), null);
    ui.responses.push({ error: true, data: { error: 'Confirm that you inspected AutoPhish and verified no list was created.' } });
    await ui.click('autophish-clear-pending');
    assert.deepEqual(ui.calls.at(-1).data, { verifiedAbsent: false });
    assert.match(ui.element('autophish-message').textContent, /Confirm that you inspected/);
    ui.element('autophish-verified-absent').checked = true;
    ui.responses.push({ data: state() });
    await ui.click('autophish-clear-pending');
    assert.equal(ui.calls.at(-1).url, '/apps/integration_autophish/settings/pending-creation');
    assert.equal(ui.calls.at(-1).method, 'DELETE');
    assert.deepEqual(ui.calls.at(-1).data, { verifiedAbsent: true });
    assert.equal(ui.element('autophish-pending').hidden, true);
});
