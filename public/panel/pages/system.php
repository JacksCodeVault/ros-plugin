<section id="page-system" class="tab-page" hidden aria-labelledby="system-title">
    <header class="page-header"><div><h2 id="system-title">System</h2><p>Plugin data protection, retry queue, cache, and operational log.</p></div></header>
    <div class="section-stack">
        <section class="panel">
            <header class="section-header"><div><h3>Database backups</h3><p>Rolling snapshots of the local plugin database.</p></div><div class="page-actions"><button type="button" class="button button--primary" onclick="App.createBackup()">Create backup</button></div></header>
            <div class="table-scroll"><table class="data-table"><thead><tr><th>Backup file</th><th>Created</th><th class="align-right">Actions</th></tr></thead><tbody id="backups-table-body"></tbody></table></div>
        </section>
        <section class="panel">
            <header class="section-header"><div><h3>Failed provisioning queue</h3><p>Router commands waiting for background retry after a connection failure.</p></div><div class="page-actions"><button type="button" class="button button--secondary" onclick="App.loadJobs()">Refresh</button><button type="button" class="button button--danger-outline" onclick="App.clearJobs()">Clear queue</button></div></header>
            <div class="table-scroll"><table class="data-table"><thead><tr><th>#</th><th>Action</th><th>Target ID</th><th>Queued</th></tr></thead><tbody id="jobs-table-body"></tbody></table></div>
        </section>
        <section class="panel">
            <header class="section-header"><div><h3>Maintenance</h3><p>Synchronize cached UISP data or clear the plugin log.</p></div></header>
            <div class="section-body toolbar-group"><button type="button" class="button button--secondary" onclick="App.rebuildCache()">Rebuild UISP cache</button><button type="button" class="button button--danger-outline" onclick="App.clearLog()">Clear plugin log</button></div>
        </section>
    </div>
</section>
