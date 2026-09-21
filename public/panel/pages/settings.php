<section id="page-settings" class="tab-page" hidden aria-labelledby="settings-title">
    <header class="page-header"><div><h2 id="settings-title">Settings</h2><p>Global address allocation, suspension, authentication, and automation policy.</p></div></header>
    <form id="settings-form" onsubmit="App.saveSettings(event)" class="section-stack">
        <section class="panel">
            <header class="section-header"><div><h3>Address allocation</h3><p>Defaults used when a router does not define its own pool.</p></div></header>
            <div class="section-body"><div class="form-grid">
                <div class="field span-6"><label for="cfg-ppp-pool">Subscriber address pools</label><input class="field__control mono" type="text" id="cfg-ppp-pool" placeholder="10.99.0.0/16, fd10:9900::/48"><p class="field__hint">Comma-separated IPv4 and IPv6 CIDR ranges.</p></div>
                <div class="field span-6"><label for="cfg-excl-addr">Excluded addresses</label><input class="field__control mono" type="text" id="cfg-excl-addr" placeholder="10.99.0.1, 10.99.0.2"><p class="field__hint">Addresses the allocator must never assign.</p></div>
                <div class="field span-4"><label for="cfg-lease-time">Reservation lease (minutes)</label><input class="field__control numeric" type="number" id="cfg-lease-time" min="1" value="60"></div>
                <div class="span-8 check-row"><input type="checkbox" id="cfg-router-ppp-pool"><label for="cfg-router-ppp-pool">Use RouterOS PPP pool<small>Let the router assign the remote address from its local PPP profile instead of plugin IPAM.</small></label></div>
            </div></div>
        </section>

        <section class="panel">
            <header class="section-header"><div><h3>Suspension policy</h3><p>RouterOS behavior when UISP suspends a subscriber service.</p></div></header>
            <div class="section-body"><div class="form-grid">
                <div class="field span-4"><label for="cfg-disabled-list">Firewall address list</label><input class="field__control mono" type="text" id="cfg-disabled-list" placeholder="disabled"><p class="field__hint">Address list used to block suspended subscribers.</p></div>
                <div class="field span-4"><label for="cfg-disabled-profile">PPPoE profile</label><input class="field__control mono" type="text" id="cfg-disabled-profile" placeholder="disabled"><p class="field__hint">Profile assigned to suspended PPPoE secrets.</p></div>
                <div class="field span-4"><label for="cfg-disabled-rate">Throttled rate (kbps)</label><input class="field__control numeric" type="number" id="cfg-disabled-rate" min="0" placeholder="0"><p class="field__hint">0 blocks traffic; a positive value throttles it.</p></div>
            </div></div>
        </section>

        <section class="panel">
            <header class="section-header"><div><h3>FreeRADIUS</h3><p>Optional centralized authentication database for routers in RADIUS mode.</p></div></header>
            <div class="section-body">
                <div class="check-row"><input type="checkbox" id="cfg-radius-enabled"><label for="cfg-radius-enabled">Enable FreeRADIUS provisioning<small>Provision radcheck, radreply, and radusergroup instead of direct RouterOS accounts.</small></label></div>
                <div class="form-grid space-top">
                    <div class="field span-6"><label for="cfg-radius-host">Database host</label><input class="field__control mono" type="text" id="cfg-radius-host" placeholder="127.0.0.1"></div>
                    <div class="field span-3"><label for="cfg-radius-port">Database port</label><input class="field__control mono" type="number" id="cfg-radius-port" min="1" max="65535" value="3306"></div>
                    <div class="field span-3"><label for="cfg-radius-timeout">Timeout (seconds)</label><input class="field__control numeric" type="number" id="cfg-radius-timeout" min="1" max="30" value="5"></div>
                    <div class="field span-4"><label for="cfg-radius-db">Database name</label><input class="field__control mono" type="text" id="cfg-radius-db" placeholder="radius"></div>
                    <div class="field span-4"><label for="cfg-radius-user">Database username</label><input class="field__control mono" type="text" id="cfg-radius-user" placeholder="radius" autocomplete="username"></div>
                    <div class="field span-4"><label for="cfg-radius-pass">Database password</label><input class="field__control" type="password" id="cfg-radius-pass" autocomplete="current-password"></div>
                    <div class="field span-8"><label for="cfg-radius-secret">RADIUS shared secret</label><input class="field__control mono" type="password" id="cfg-radius-secret" autocomplete="off"><p class="field__hint">Used by NAS clients and CoA packets.</p></div>
                    <div class="field span-4"><label for="cfg-radius-coa-port">CoA / disconnect port</label><input class="field__control mono" type="number" id="cfg-radius-coa-port" min="1" max="65535" value="3799"></div>
                </div>
                <div class="toolbar-group space-top"><button type="button" class="button button--secondary" onclick="App.testRadiusConnection()" id="btn-test-radius">Test connection</button></div>
                <div id="radius-test-result" class="callout is-hidden space-top" role="status"></div>
            </div>
        </section>

        <section class="panel">
            <header class="section-header"><div><h3>Automation</h3><p>Credentials and RouterOS account types created during provisioning.</p></div></header>
            <div class="section-body check-list">
                <div class="check-row"><input type="checkbox" id="cfg-auto-ppp-user"><label for="cfg-auto-ppp-user">Generate PPPoE credentials<small>Create secure missing credentials and store them in UISP custom attributes.</small></label></div>
                <div class="check-row"><input type="checkbox" id="cfg-disable-contention"><label for="cfg-disable-contention">Disable contention parent queues<small>Create flat simple queues without parent aggregation.</small></label></div>
                <div class="check-row"><input type="checkbox" id="cfg-hs-enable"><label for="cfg-hs-enable">Provision MikroTik Hotspot users<small>Manage hotspot accounts alongside PPPoE and DHCP.</small></label></div>
                <div class="check-row"><input type="checkbox" id="cfg-auto-hs-user"><label for="cfg-auto-hs-user">Generate Hotspot credentials<small>Create credentials automatically for new hotspot subscribers.</small></label></div>
            </div>
        </section>
        <div class="page-actions"><button type="submit" class="button button--primary">Save settings</button></div>
    </form>
</section>
