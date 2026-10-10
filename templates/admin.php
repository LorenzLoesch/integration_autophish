<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
script('integration_autophish', 'admin');
style('integration_autophish', 'admin');
?>
<div id="autophish-settings" class="section">
    <h2>AutoPhish</h2>
    <p>Synchronize dedicated AutoPhish target lists with Nextcloud groups. Use a company-scoped API key with targets:read and targets:write permissions.</p>
    <p>Synchronization sends primary email addresses to AutoPhish. Display names are sent only when adding new recipients. Nextcloud user IDs remain local.</p>
    <form id="autophish-connection">
        <label>AutoPhish HTTPS URL<input name="baseUrl" type="url" required placeholder="https://autophish.example" /></label>
        <label>Company ID<input name="companyId" required autocomplete="off" /></label>
        <label>API key<input name="apiKey" type="password" autocomplete="new-password" placeholder="Leave blank to keep the saved key" /></label>
        <label>Synchronization interval (seconds)<input name="interval" type="number" min="300" max="86400" required value="3600" /></label>
        <button type="submit">Save connection</button>
        <button type="button" id="autophish-discover">Test connection and discover groups/lists</button>
    </form>
    <p id="autophish-key-status"></p>
    <p id="autophish-message" role="status" aria-live="polite"></p>
    <div id="autophish-pending" hidden>
        <p>If the uncertain creation is older than 24 hours, inspect AutoPhish before starting a new creation. Attach an existing list if it was created.</p>
        <label><input type="checkbox" id="autophish-verified-absent" /> I inspected AutoPhish and verified that no list was created.</label>
        <button type="button" id="autophish-clear-pending">Clear expired pending creation</button>
    </div>
    <form id="autophish-mapping" hidden>
        <h3>Add a dedicated list mapping</h3>
        <label>Nextcloud group<select name="groupId" required></select></label>
        <label>Existing dedicated target list<select name="listId"><option value="">Create a new list</option></select></label>
        <label>New target list name<input name="name" maxlength="200" /></label>
        <p>Existing lists must be dedicated to this group. Synchronization removes list memberships that do not match the group. Managed, masked, and pseudonymous lists are unavailable.</p>
        <button type="submit">Add mapping</button>
    </form>
    <table><thead><tr><th>Group</th><th>Target list</th><th>Enabled</th><th>Actions</th></tr></thead><tbody id="autophish-mappings"></tbody></table>
    <h3>Synchronization status</h3>
    <dl id="autophish-status"></dl>
    <button type="button" id="autophish-refresh">Refresh status</button>
    <p>Configure Nextcloud system cron for background synchronization. To synchronize immediately, run <code>occ integration_autophish:sync</code> as your Nextcloud web-server user.</p>
</div>
