<section id="page-plans" class="tab-page" hidden aria-labelledby="plans-title">
    <header class="page-header">
        <div><h2 id="plans-title">Service plans</h2><p>Bandwidth limits, contention ratios, and RouterOS burst policy.</p></div>
        <div class="page-actions"><button type="button" class="button button--secondary" onclick="App.loadPlans()">Sync from UISP</button></div>
    </header>
    <div class="panel"><div class="table-scroll"><table class="data-table"><thead><tr><th>ID</th><th>Plan</th><th>Download / upload</th><th>Contention</th><th>Burst down / up</th><th>Priority down / up</th><th class="align-right">Actions</th></tr></thead><tbody id="plans-table-body"></tbody></table></div></div>
</section>

<div id="plan-modal" class="dialog-backdrop is-hidden" role="presentation">
    <section class="dialog" role="dialog" aria-modal="true" aria-labelledby="plan-dialog-title">
        <header class="dialog__header"><div><h2 id="plan-dialog-title">QoS policy — <span id="plan-name"></span></h2><p>RouterOS simple queue limits and contention settings.</p></div><button type="button" class="dialog-close" onclick="App.closeModal('plan-modal')" aria-label="Close dialog">&times;</button></header>
        <form id="plan-form" onsubmit="App.savePlan(event)">
            <div class="dialog__body">
                <input type="hidden" id="plan-id">
                <div class="form-section"><h3 class="form-section__title">Contention and priority</h3><div class="form-grid">
                    <div class="field span-4"><label for="plan-ratio">Contention ratio (1:N)</label><input class="field__control numeric" type="number" id="plan-ratio" min="1" max="100" value="1" required><p class="field__hint">1:1 is dedicated; larger values create aggregation queues.</p></div>
                    <div class="field span-4"><label for="plan-priority-down">Download priority (1–8)</label><input class="field__control numeric" type="number" id="plan-priority-down" min="1" max="8" value="8"></div>
                    <div class="field span-4"><label for="plan-priority-up">Upload priority (1–8)</label><input class="field__control numeric" type="number" id="plan-priority-up" min="1" max="8" value="8"></div>
                </div></div>
                <div class="form-section"><h3 class="form-section__title">Burst policy</h3><div class="form-grid">
                    <div class="field span-6"><label for="plan-burst-down">Download burst (%)</label><input class="field__control numeric" type="number" id="plan-burst-down" min="0" max="500" value="0"></div>
                    <div class="field span-6"><label for="plan-burst-up">Upload burst (%)</label><input class="field__control numeric" type="number" id="plan-burst-up" min="0" max="500" value="0"></div>
                    <div class="field span-6"><label for="plan-thresh-down">Download threshold (%)</label><input class="field__control numeric" type="number" id="plan-thresh-down" min="0" max="500" value="0"></div>
                    <div class="field span-6"><label for="plan-thresh-up">Upload threshold (%)</label><input class="field__control numeric" type="number" id="plan-thresh-up" min="0" max="500" value="0"></div>
                    <div class="field span-6"><label for="plan-time-down">Download burst time (seconds)</label><input class="field__control numeric" type="number" id="plan-time-down" min="1" max="3600" value="1"></div>
                    <div class="field span-6"><label for="plan-time-up">Upload burst time (seconds)</label><input class="field__control numeric" type="number" id="plan-time-up" min="1" max="3600" value="1"></div>
                </div></div>
            </div>
            <footer class="dialog__footer"><button type="button" class="button button--secondary" onclick="App.closeModal('plan-modal')">Cancel</button><button type="submit" class="button button--primary">Save QoS policy</button></footer>
        </form>
    </section>
</div>
