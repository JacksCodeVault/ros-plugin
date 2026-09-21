<section id="page-attributes" class="tab-page" hidden aria-labelledby="attributes-title">
    <header class="page-header">
        <div><h2 id="attributes-title">Custom attributes</h2><p>UISP CRM fields used for RouterOS credentials and network assignments.</p></div>
        <div class="page-actions"><button type="button" class="button button--primary" onclick="App.autoCreateAttributes()">Create standard attributes</button></div>
    </header>
    <div class="panel">
        <div class="table-scroll"><table class="data-table"><thead><tr><th>Attribute</th><th>UISP key</th><th>Scope</th><th>RouterOS mapping</th></tr></thead><tbody id="attributes-table-body"></tbody></table></div>
        <div class="info-strip"><strong>How this works:</strong> the plugin reads these fields when UISP creates or activates a service. Automatic credential generation writes missing PPPoE values back to the same attributes.</div>
    </div>
</section>
