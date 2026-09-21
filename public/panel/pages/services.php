<section id="page-services" class="tab-page" hidden aria-labelledby="services-title">
    <header class="page-header"><div><h2 id="services-title">Services</h2><p>UISP subscriber services and their RouterOS provisioning state.</p></div></header>
    <div class="panel">
        <div class="panel__toolbar">
            <div class="toolbar-group">
                <label class="field" for="services-device-filter"><span class="is-hidden">Router</span><select class="field__control" id="services-device-filter" onchange="App.handleServicesFilter()"><option value="">All routers</option></select></label>
            </div>
            <div class="toolbar-group">
                <label class="input-icon" for="services-search"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg><input class="search-input" type="search" id="services-search" oninput="App.handleServicesSearch(this.value)" placeholder="Search client, IP, or user" autocomplete="off"></label>
                <button type="button" class="button button--secondary" onclick="App.loadServices()">Refresh</button>
            </div>
        </div>
        <div class="table-scroll"><table class="data-table"><thead><tr><th>ID</th><th>Client</th><th>User / MAC</th><th>Plan</th><th>IPv4</th><th>IPv6</th><th>Status</th><th class="align-right">Price</th></tr></thead><tbody id="services-table-body"></tbody></table></div>
        <div id="services-pagination" class="panel__footer"></div>
    </div>
</section>
