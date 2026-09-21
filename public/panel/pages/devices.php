<section id="page-devices" class="tab-page" aria-labelledby="devices-title">
    <header class="page-header">
        <div><h2 id="devices-title">Routers</h2><p>Routers available for direct API or RADIUS subscriber provisioning.</p></div>
        <div class="page-actions"><button type="button" class="button button--primary" onclick="App.openAddDeviceModal()">Add router</button></div>
    </header>
    <pre id="router-diagnostics" class="is-hidden" role="status" style="white-space:pre-wrap;overflow-wrap:anywhere"></pre>
    <div class="panel">
        <div class="panel__toolbar">
            <div class="toolbar-group"><span id="devices-count-text">0 routers</span></div>
            <div class="toolbar-group">
                <label class="input-icon" for="devices-search">
                    <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg>
                    <input class="search-input" type="search" id="devices-search" oninput="App.filterDevices(this.value)" placeholder="Search routers" autocomplete="off">
                </label>
            </div>
        </div>
        <div class="table-scroll">
            <table class="data-table">
                <thead><tr><th>Name</th><th>IP address</th><th>Provisioning</th><th>Type</th><th>Port</th><th>Users</th><th>Status</th><th class="align-right">Actions</th></tr></thead>
                <tbody id="devices-table-body"></tbody>
            </table>
        </div>
    </div>
</section>

<div id="device-modal" class="dialog-backdrop is-hidden" role="presentation">
    <section class="dialog" role="dialog" aria-modal="true" aria-labelledby="device-modal-title">
        <header class="dialog__header">
            <div><h2 id="device-modal-title">Add router</h2><p>Connection details and address allocation overrides.</p></div>
            <button type="button" class="dialog-close" onclick="App.closeModal('device-modal')" aria-label="Close dialog">&times;</button>
        </header>
        <form id="device-form" onsubmit="App.saveDevice(event)">
            <div class="dialog__body">
                <input type="hidden" id="device-id">
                <div class="form-section">
                    <h3 class="form-section__title">Connection</h3>
                    <div class="form-grid">
                        <div class="field span-4"><label for="device-name">Router name</label><input class="field__control" type="text" id="device-name" required placeholder="Core CCR2004" autocomplete="off"></div>
                        <div class="field span-4"><label for="device-mode">Provisioning mode</label><select class="field__control" id="device-mode"><option value="api">Direct API</option><option value="radius">RADIUS</option></select></div>
                        <div class="field span-4"><label for="device-type">Device type</label><select class="field__control" id="device-type"><option value="mikrotik">MikroTik RouterOS</option><option value="edgeos">Ubiquiti EdgeOS</option><option value="cisco">Cisco IOS</option></select></div>
                        <div class="field span-8"><label for="device-ip">IP address or host</label><input class="field__control mono" type="text" id="device-ip" required placeholder="192.168.88.1" autocomplete="off"></div>
                        <div class="field span-4"><label for="device-port">API port</label><input class="field__control mono" type="number" id="device-port" min="1" max="65535" value="8728" required></div>
                        <div class="field span-4"><label for="device-transport">API transport</label><select class="field__control" id="device-transport"><option value="plain">Plain TCP (trusted VPN only)</option><option value="tls">Verified TLS (usually 8729)</option></select></div>
                        <div class="field span-4"><label for="device-timeout">API timeout (seconds)</label><input class="field__control" id="device-timeout" type="number" min="1" max="60" value="5"></div>
                        <div class="field span-4"><label for="device-tls-peer">Certificate hostname (optional)</label><input class="field__control" id="device-tls-peer"></div>
                        <div class="field span-12"><label for="device-tls-ca">CA certificate path on UISP server (optional)</label><input class="field__control" id="device-tls-ca" placeholder="System trust store used when blank"></div>
                        <div class="field span-6"><label for="device-user">API username</label><input class="field__control" type="text" id="device-user" required placeholder="uisp" autocomplete="username"></div>
                        <div class="field span-6"><label for="device-password">API password</label><input class="field__control" type="password" id="device-password" placeholder="Unchanged when blank" autocomplete="new-password"><p class="field__hint">Required for a new router; leave blank when editing to keep the stored password.</p></div>
                    </div>
                </div>
                <div class="form-section">
                    <h3 class="form-section__title">Address allocation</h3>
                    <div class="form-grid">
                        <div class="field span-12"><label for="device-pool">IPv4 subnet pool</label><input class="field__control mono" type="text" id="device-pool" placeholder="10.100.0.0/16"><p class="field__hint">Optional. Overrides the global pool for this router.</p></div>
                        <div class="field span-8"><label for="device-pool6">IPv6 pool</label><input class="field__control mono" type="text" id="device-pool6" placeholder="fd10:100::/48"></div>
                        <div class="field span-4"><label for="device-pfx">IPv6 prefix length</label><input class="field__control mono" type="number" id="device-pfx" min="1" max="128" value="64"></div>
                        <div class="span-12 check-row"><input type="checkbox" id="device-qos" checked><label for="device-qos">Enable QoS rate limiting<small>Create RouterOS simple queues for provisioned services.</small></label></div>
                    </div>
                </div>
            </div>
            <footer class="dialog__footer"><button type="button" class="button button--secondary" onclick="App.closeModal('device-modal')">Cancel</button><button type="submit" class="button button--primary">Save router</button></footer>
        </form>
    </section>
</div>
