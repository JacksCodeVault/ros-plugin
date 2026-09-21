/**
 * ROS Plugin - Admin Dashboard Application JS
 * Lightweight vanilla JavaScript client for API communication and UI interactions
 */

const App = {
    currentTab: 'devices',
    apiUrl: window.location.href, // Posts directly to the UISP public.php handler
    state: {
        devices: [],
        plans: [],
        services: [],
        servicesCount: 0,
        servicesOffset: 0,
        servicesLimit: 25,
        servicesFilterDevice: '',
        servicesSearchQuery: '',
        config: {},
        attributes: [],
        backups: [],
        jobs: []
    },

    init() {
        this.syncTheme();
        this.setupDialogs();
        this.setupTabs();
        this.loadCurrentTab();
    },

    syncTheme() {
        let dark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
        try {
            const host = window.parent.document.documentElement;
            const marker = `${host.className} ${host.getAttribute('data-theme') || ''}`.toLowerCase();
            if (marker.includes('dark')) dark = true;
            if (marker.includes('light')) dark = false;
        } catch (e) {
            // The iframe may not be allowed to inspect its parent; system preference is the fallback.
        }
        document.documentElement.classList.toggle('theme-dark', dark);
    },

    setupDialogs() {
        document.addEventListener('keydown', (event) => {
            if (event.key !== 'Escape') return;
            const openDialog = document.querySelector('.dialog-backdrop:not(.is-hidden)');
            if (openDialog) this.closeModal(openDialog.id);
        });
        document.querySelectorAll('.dialog-backdrop').forEach(backdrop => {
            backdrop.addEventListener('mousedown', event => {
                if (event.target === backdrop && backdrop.id !== 'confirm-dialog') this.closeModal(backdrop.id);
            });
        });
    },

    // Tab Navigation
    setupTabs() {
        const hash = window.location.hash.replace('#', '');
        if (hash && ['devices', 'plans', 'services', 'settings', 'attributes', 'system'].includes(hash)) {
            this.currentTab = hash;
        }
        this.switchTab(this.currentTab, false);

        document.querySelectorAll('[data-tab]').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                const tab = btn.getAttribute('data-tab');
                this.switchTab(tab, true);
            });
        });
    },

    switchTab(tabName, updateHash = true) {
        this.currentTab = tabName;
        if (updateHash) {
            window.location.hash = tabName;
        }

        // Update tab buttons
        document.querySelectorAll('[data-tab]').forEach(btn => {
            const isCurrent = btn.getAttribute('data-tab') === tabName;
            if (isCurrent) {
                btn.setAttribute('aria-selected', 'true');
                btn.setAttribute('tabindex', '0');
            } else {
                btn.setAttribute('aria-selected', 'false');
                btn.setAttribute('tabindex', '-1');
            }
        });

        // Toggle page content visibility
        document.querySelectorAll('.tab-page').forEach(page => {
            if (page.id === `page-${tabName}`) {
                page.hidden = false;
            } else {
                page.hidden = true;
            }
        });

        this.loadCurrentTab();
    },

    loadCurrentTab() {
        switch (this.currentTab) {
            case 'devices':
                this.loadDevices();
                break;
            case 'plans':
                this.loadPlans();
                break;
            case 'services':
                this.loadServices();
                break;
            case 'settings':
                this.loadSettings();
                break;
            case 'attributes':
                this.loadAttributes();
                break;
            case 'system':
                this.loadSystem();
                break;
        }
    },

    // Central API Caller
    async api(changeType, target, action = null, data = null, extra = {}) {
        const payload = {
            changeType: changeType,
            target: target,
            ...extra
        };
        if (action) payload.action = action;
        if (data) payload.data = data;

        try {
            const res = await fetch(this.apiUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-ROS-CSRF': document.querySelector('meta[name="ros-csrf"]')?.content || ''
                },
                body: JSON.stringify(payload)
            });

            const json = await res.json();
            if (json.error || json.status === 'failed') {
                throw new Error(json.message || 'API request failed');
            }
            return json.data;
        } catch (err) {
            console.error('API Error:', err);
            this.toast(err.message || 'Request failed', 'error');
            throw err;
        }
    },

    // Toast Notifications
    toast(message, type = 'success') {
        const container = document.getElementById('toast-container');
        if (!container) return;

        const el = document.createElement('div');
        el.className = `toast${type === 'error' ? ' toast--error' : ''}`;
        el.setAttribute('role', type === 'error' ? 'alert' : 'status');
        el.innerHTML = `<span class="toast__message">${this.escapeHtml(message)}</span>`;

        container.appendChild(el);
        requestAnimationFrame(() => {
            el.classList.add('is-visible');
        });

        setTimeout(() => {
            el.classList.remove('is-visible');
            setTimeout(() => el.remove(), 250);
        }, 3500);
    },

    // Modals
    openModal(id) {
        const modal = document.getElementById(id);
        if (modal) {
            this.lastFocusedElement = document.activeElement;
            modal.classList.remove('is-hidden');
            document.body.style.overflow = 'hidden';
            requestAnimationFrame(() => {
                const target = modal.querySelector('input:not([type="hidden"]), select, button');
                if (target) target.focus();
            });
        }
    },

    closeModal(id) {
        const modal = document.getElementById(id);
        if (modal) {
            modal.classList.add('is-hidden');
            document.body.style.overflow = '';
            if (this.lastFocusedElement && this.lastFocusedElement.focus) this.lastFocusedElement.focus();
        }
    },

    confirmAction({ title, message, confirmLabel = 'Confirm', danger = true }) {
        const modal = document.getElementById('confirm-dialog');
        const titleEl = document.getElementById('confirm-title');
        const messageEl = document.getElementById('confirm-message');
        const accept = modal.querySelector('[data-confirm-accept]');
        const cancel = modal.querySelector('[data-confirm-cancel]');
        titleEl.textContent = title;
        messageEl.textContent = message;
        accept.textContent = confirmLabel;
        accept.className = `button ${danger ? 'button--danger' : 'button--primary'}`;
        this.openModal('confirm-dialog');
        return new Promise(resolve => {
            const finish = value => {
                accept.onclick = null;
                cancel.onclick = null;
                this.closeModal('confirm-dialog');
                resolve(value);
            };
            accept.onclick = () => finish(true);
            cancel.onclick = () => finish(false);
        });
    },

    escapeHtml(str) {
        if (!str && str !== 0) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    },

    // ==========================================
    // Devices Management
    // ==========================================
    async loadDevices() {
        const tbody = document.getElementById('devices-table-body');
        if (!tbody) return;
        tbody.innerHTML = `<tr><td colspan="8" class="loading-cell"><span class="loading-line"><span class="spinner"></span>Loading routers</span></td></tr>`;

        try {
            const devices = await this.api('list', 'devices');
            this.state.devices = devices || [];
            this.renderDevices();
        } catch (e) {
            tbody.innerHTML = `<tr><td colspan="8" class="error-cell"><div class="state-title">Routers could not be loaded</div><div class="state-copy">${this.escapeHtml(e.message)}</div><div class="state-action"><button class="button button--secondary" onclick="App.loadDevices()">Try again</button></div></td></tr>`;
        }
    },

    filterDevices(query) {
        this.deviceSearchQuery = (query || '').toLowerCase().trim();
        this.renderDevices();
    },

    renderDevices() {
        const tbody = document.getElementById('devices-table-body');
        const countText = document.getElementById('devices-count-text');
        if (!tbody) return;

        let list = this.state.devices;
        if (this.deviceSearchQuery) {
            list = list.filter(d => 
                (d.name && d.name.toLowerCase().includes(this.deviceSearchQuery)) ||
                (d.ip && d.ip.includes(this.deviceSearchQuery)) ||
                (d.type && d.type.toLowerCase().includes(this.deviceSearchQuery))
            );
        }

        if (countText) {
            countText.innerText = list.length > 0 
                ? `${list.length} of ${this.state.devices.length} routers` 
                : '0 routers';
        }

        if (list.length === 0) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="8" class="empty-cell"><div class="state-title">${this.deviceSearchQuery ? 'No matching routers' : 'No routers configured'}</div><div class="state-copy">${this.deviceSearchQuery ? 'Change or clear the search term.' : 'Add a router to begin provisioning subscriber services.'}</div>${this.deviceSearchQuery ? '' : '<div class="state-action"><button onclick="App.openAddDeviceModal()" class="button button--primary">Add router</button></div>'}</td>
                </tr>
            `;
            return;
        }

        tbody.innerHTML = list.map(d => `
            <tr>
                <td class="primary-cell">${this.escapeHtml(d.name || 'Unnamed')}</td>
                <td class="mono">${this.escapeHtml(d.ip || '—')}</td>
                <td>
                    ${d.mode === 'radius' 
                        ? '<span class="tag">RADIUS</span>' 
                        : '<span class="tag tag--info">Direct API</span>'}
                </td>
                <td>${this.escapeHtml(d.type === 'mikrotik' ? 'MikroTik' : d.type || '—')}</td>
                <td class="mono">${this.escapeHtml(d.port || 8728)}</td>
                <td class="numeric">${this.escapeHtml(d.users || 0)}</td>
                <td>
                    ${d.status 
                        ? '<span class="status status--online">Online</span>' 
                        : '<span class="status status--offline">Offline</span>'}
                </td>
                <td class="align-right"><span class="row-actions">
                    <button onclick="App.probeDevice(${Number(d.id)})" class="button button--secondary">Check API</button>
                    <button onclick="App.openEditDeviceModal(${d.id})" class="icon-button" title="Edit router" aria-label="Edit ${this.escapeHtml(d.name || 'router')}">
                        <svg aria-hidden="true" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M4 20h4l11-11a2.8 2.8 0 0 0-4-4L4 16v4Z"/><path d="m13.5 6.5 4 4"/></svg>
                    </button>
                    <button onclick="App.confirmDeleteDevice(${Number(d.id)}, this.dataset.name)" data-name="${this.escapeHtml(d.name)}" class="icon-button icon-button--danger" title="Remove router" aria-label="Remove ${this.escapeHtml(d.name || 'router')}">
                        <svg aria-hidden="true" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M4 7h16M9 7V4h6v3m3 0-1 13H7L6 7m4 4v5m4-5v5"/></svg>
                    </button>
                </span></td>
            </tr>
        `).join('');
    },

    openAddDeviceModal() {
        document.getElementById('device-modal-title').innerText = 'Add MikroTik Router';
        document.getElementById('device-form').reset();
        document.getElementById('device-id').value = '';
        document.getElementById('device-mode').value = 'api';
        document.getElementById('device-port').value = '8728';
        document.getElementById('device-transport').value = 'plain';
        document.getElementById('device-timeout').value = '5';
        document.getElementById('device-tls-peer').value = '';
        document.getElementById('device-tls-ca').value = '';
        document.getElementById('device-type').value = 'mikrotik';
        this.openModal('device-modal');
    },

    openEditDeviceModal(id) {
        const d = this.state.devices.find(item => item.id == id);
        if (!d) return;

        document.getElementById('device-modal-title').innerText = `Edit Router: ${d.name || 'Device ' + d.id}`;
        document.getElementById('device-id').value = d.id;
        document.getElementById('device-name').value = d.name || '';
        document.getElementById('device-mode').value = d.mode || 'api';
        document.getElementById('device-ip').value = d.ip || '';
        document.getElementById('device-port').value = d.port || 8728;
        document.getElementById('device-transport').value = d.api_transport || 'plain';
        document.getElementById('device-timeout').value = d.api_timeout || 5;
        document.getElementById('device-tls-peer').value = d.tls_peer_name || '';
        document.getElementById('device-tls-ca').value = d.tls_ca_file || '';
        document.getElementById('device-type').value = d.type || 'mikrotik';
        document.getElementById('device-user').value = d.user || '';
        document.getElementById('device-password').value = ''; // Don't prefill password
        document.getElementById('device-pool').value = d.pool || '';
        document.getElementById('device-pool6').value = d.pool6 || '';
        document.getElementById('device-pfx').value = d.pfxLength || 64;
        document.getElementById('device-qos').checked = !!d.qos;

        this.openModal('device-modal');
    },

    async saveDevice(e) {
        e.preventDefault();
        const id = document.getElementById('device-id').value;
        const isEdit = !!id;

        const data = {
            name: document.getElementById('device-name').value.trim(),
            mode: document.getElementById('device-mode').value,
            ip: document.getElementById('device-ip').value.trim(),
            port: parseInt(document.getElementById('device-port').value) || 8728,
            api_transport: document.getElementById('device-transport').value,
            api_timeout: Number(document.getElementById('device-timeout').value),
            tls_peer_name: document.getElementById('device-tls-peer').value.trim(),
            tls_ca_file: document.getElementById('device-tls-ca').value.trim(),
            type: document.getElementById('device-type').value,
            user: document.getElementById('device-user').value.trim(),
            pool: document.getElementById('device-pool').value.trim(),
            pool6: document.getElementById('device-pool6').value.trim(),
            pfxLength: parseInt(document.getElementById('device-pfx').value) || 64,
            qos: document.getElementById('device-qos').checked ? 1 : 0
        };

        const pass = document.getElementById('device-password').value;
        if (pass) {
            data.password = pass;
        }

        if (isEdit) {
            data.id = parseInt(id);
        }

        try {
            await this.api('update', 'devices', isEdit ? 'edit' : 'insert', data);
            this.toast(isEdit ? 'Device updated successfully' : 'Device created successfully');
            this.closeModal('device-modal');
            await this.loadDevices();
        } catch (err) {
            // error already toasted
        }
    },

    async confirmDeleteDevice(id, name) {
        if (!await this.confirmAction({ title: 'Remove router', message: `Remove "${name || 'Router ' + id}"? Subscriber provisioning to this router will stop. Existing RouterOS configuration is not removed.`, confirmLabel: 'Remove router' })) {
            return;
        }

        try {
            await this.api('update', 'devices', 'delete', { id: id });
            this.toast('Router removed');
            await this.loadDevices();
        } catch (err) {
            // error toasted
        }
    },

    // ==========================================
    // Plans Management
    // ==========================================
    async loadPlans() {
        const tbody = document.getElementById('plans-table-body');
        if (!tbody) return;
        tbody.innerHTML = `<tr><td colspan="7" class="loading-cell"><span class="loading-line"><span class="spinner"></span>Loading service plans</span></td></tr>`;

        try {
            const plans = await this.api('list', 'plans');
            this.state.plans = plans || [];
            this.renderPlans();
        } catch (e) {
            tbody.innerHTML = `<tr><td colspan="7" class="error-cell"><div class="state-title">Service plans could not be loaded</div><div class="state-copy">${this.escapeHtml(e.message)}</div><div class="state-action"><button class="button button--secondary" onclick="App.loadPlans()">Try again</button></div></td></tr>`;
        }
    },

    renderPlans() {
        const tbody = document.getElementById('plans-table-body');
        if (!tbody) return;

        if (this.state.plans.length === 0) {
            tbody.innerHTML = `<tr><td colspan="7" class="empty-cell"><div class="state-title">No internet service plans</div><div class="state-copy">Create a service plan in UISP, then sync this page.</div><div class="state-action"><button class="button button--secondary" onclick="App.loadPlans()">Sync from UISP</button></div></td></tr>`;
            return;
        }

        tbody.innerHTML = this.state.plans.map(p => `
            <tr>
                <td class="mono">${this.escapeHtml(p.id)}</td>
                <td class="primary-cell">
                    ${this.escapeHtml(p.name)}
                    ${p.archive ? ' <span class="status status--archived">Archived</span>' : ''}
                </td>
                <td class="mono">
                    ${this.escapeHtml(p.downloadSpeed)} Mbps / ${this.escapeHtml(p.uploadSpeed)} Mbps
                </td>
                <td class="mono">1:${this.escapeHtml(p.ratio || 1)}</td>
                <td class="mono">${p.burstDownload || p.burstUpload ? `${this.escapeHtml(p.burstDownload || 0)}% / ${this.escapeHtml(p.burstUpload || 0)}%` : 'None'}</td>
                <td class="mono">${this.escapeHtml(p.priorityDownload || 8)} / ${this.escapeHtml(p.priorityUpload || 8)}</td>
                <td class="align-right">
                    <button onclick="App.openEditPlanModal(${p.id})" class="icon-button" title="Edit QoS policy" aria-label="Edit QoS policy for ${this.escapeHtml(p.name)}">
                        <svg aria-hidden="true" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M4 20h4l11-11a2.8 2.8 0 0 0-4-4L4 16v4Z"/><path d="m13.5 6.5 4 4"/></svg>
                    </button>
                </td>
            </tr>
        `).join('');
    },

    openEditPlanModal(id) {
        const p = this.state.plans.find(item => item.id == id);
        if (!p) return;

        document.getElementById('plan-id').value = p.id;
        document.getElementById('plan-name').innerText = p.name;
        document.getElementById('plan-ratio').value = p.ratio || 1;
        document.getElementById('plan-priority-down').value = p.priorityDownload || 8;
        document.getElementById('plan-priority-up').value = p.priorityUpload || 8;
        document.getElementById('plan-burst-down').value = p.burstDownload || 0;
        document.getElementById('plan-burst-up').value = p.burstUpload || 0;
        document.getElementById('plan-thresh-down').value = p.threshDownload || 0;
        document.getElementById('plan-thresh-up').value = p.threshUpload || 0;
        document.getElementById('plan-time-down').value = p.timeDownload || 1;
        document.getElementById('plan-time-up').value = p.timeUpload || 1;

        this.openModal('plan-modal');
    },

    async savePlan(e) {
        e.preventDefault();
        const id = parseInt(document.getElementById('plan-id').value);
        const data = {
            id: id,
            ratio: parseInt(document.getElementById('plan-ratio').value) || 1,
            priorityDownload: parseInt(document.getElementById('plan-priority-down').value) || 8,
            priorityUpload: parseInt(document.getElementById('plan-priority-up').value) || 8,
            burstDownload: parseInt(document.getElementById('plan-burst-down').value) || 0,
            burstUpload: parseInt(document.getElementById('plan-burst-up').value) || 0,
            threshDownload: parseInt(document.getElementById('plan-thresh-down').value) || 0,
            threshUpload: parseInt(document.getElementById('plan-thresh-up').value) || 0,
            timeDownload: parseInt(document.getElementById('plan-time-down').value) || 1,
            timeUpload: parseInt(document.getElementById('plan-time-up').value) || 1
        };

        try {
            await this.api('update', 'plans', 'edit', data);
            this.toast('QoS policy saved');
            this.closeModal('plan-modal');
            await this.loadPlans();
        } catch (err) {}
    },

    // ==========================================
    // Services Management
    // ==========================================
    async loadServices() {
        const tbody = document.getElementById('services-table-body');
        if (!tbody) return;
        tbody.innerHTML = `<tr><td colspan="8" class="loading-cell"><span class="loading-line"><span class="spinner"></span>Loading services</span></td></tr>`;

        // Update device filter options
        if (this.state.devices.length === 0) {
            try {
                this.state.devices = await this.api('list', 'devices') || [];
            } catch (e) {}
        }
        const filterSelect = document.getElementById('services-device-filter');
        if (filterSelect && filterSelect.options.length <= 1) {
            this.state.devices.forEach(d => {
                const opt = document.createElement('option');
                opt.value = d.id;
                opt.textContent = `${d.name} (${d.ip})`;
                filterSelect.appendChild(opt);
            });
        }

        const extraData = {};
        if (this.state.servicesFilterDevice) {
            extraData.did = this.state.servicesFilterDevice;
        }
        if (this.state.servicesSearchQuery) {
            extraData.query = this.state.servicesSearchQuery;
        }

        try {
            const res = await this.api('list', 'services', null, null, {
                limit: this.state.servicesLimit,
                offset: this.state.servicesOffset,
                data: extraData
            });

            this.state.services = res.data || [];
            this.state.servicesCount = res.count || 0;
            this.renderServices();
        } catch (e) {
            tbody.innerHTML = `<tr><td colspan="8" class="error-cell"><div class="state-title">Services could not be loaded</div><div class="state-copy">${this.escapeHtml(e.message)}</div><div class="state-action"><button class="button button--secondary" onclick="App.loadServices()">Try again</button></div></td></tr>`;
        }
    },

    renderServices() {
        const tbody = document.getElementById('services-table-body');
        const pagination = document.getElementById('services-pagination');
        if (!tbody) return;

        if (this.state.services.length === 0) {
            tbody.innerHTML = `<tr><td colspan="8" class="empty-cell"><div class="state-title">No matching services</div><div class="state-copy">Change the router filter or search term.</div></td></tr>`;
            if (pagination) pagination.innerHTML = '';
            return;
        }

        const statusMap = {
            0: '<span class="status status--draft">Draft</span>',
            1: '<span class="status status--active">Active</span>',
            2: '<span class="status">Ended</span>',
            3: '<span class="status status--suspended">Suspended</span>',
            4: '<span class="status status--prepared">Prepared</span>',
            5: '<span class="status">Cancelled</span>',
            6: '<span class="status status--suspended">Suspended</span>',
            7: '<span class="status status--deferred">Deferred</span>',
            8: '<span class="status">Expired</span>'
        };

        tbody.innerHTML = this.state.services.map(s => `
            <tr>
                <td class="mono">${this.escapeHtml(s.id)}</td>
                <td class="primary-cell">${this.escapeHtml(s.client || 'Unknown')}</td>
                <td class="mono">${this.escapeHtml(s.username || s.mac || '—')}</td>
                <td>${this.escapeHtml(s.plan || '—')}</td>
                <td class="mono">${this.escapeHtml(s.address || '—')}</td>
                <td class="mono">${this.escapeHtml(s.address6 || '—')}</td>
                <td>${statusMap[s.status] || '<span class="status">Unknown</span>'}</td>
                <td class="align-right numeric">${this.escapeHtml(s.pricef || '0.00')}</td>
            </tr>
        `).join('');

        // Render pagination controls
        if (pagination) {
            const start = this.state.servicesOffset + 1;
            const end = Math.min(this.state.servicesOffset + this.state.servicesLimit, this.state.servicesCount);
            pagination.innerHTML = `
                <div>${start}–${end} of ${this.state.servicesCount} services</div>
                <div class="toolbar-group"><button class="button button--secondary" onclick="App.changeServicesPage(-1)" ${this.state.servicesOffset === 0 ? 'disabled' : ''}>Previous</button><button class="button button--secondary" onclick="App.changeServicesPage(1)" ${end >= this.state.servicesCount ? 'disabled' : ''}>Next</button></div>
            `;
        }
    },

    changeServicesPage(direction) {
        this.state.servicesOffset += direction * this.state.servicesLimit;
        if (this.state.servicesOffset < 0) this.state.servicesOffset = 0;
        this.loadServices();
    },

    handleServicesFilter() {
        const sel = document.getElementById('services-device-filter');
        this.state.servicesFilterDevice = sel ? sel.value : '';
        this.state.servicesOffset = 0;
        this.loadServices();
    },

    handleServicesSearch(val) {
        this.state.servicesSearchQuery = val.trim();
        this.state.servicesOffset = 0;
        this.loadServices();
    },

    // ==========================================
    // Settings Management
    // ==========================================
    async loadSettings() {
        try {
            const conf = await this.api('list', 'config');
            this.state.config = conf || {};
            this.renderSettings();
        } catch (e) {
            this.toast('Failed to load configuration', 'error');
        }
    },

    renderSettings() {
        const c = this.state.config;
        const setVal = (id, val) => {
            const el = document.getElementById(id);
            if (el) {
                if (el.type === 'checkbox') {
                    el.checked = !!val && val !== 'false';
                } else {
                    el.value = val || '';
                }
            }
        };

        setVal('cfg-ppp-pool', c.ppp_pool);
        setVal('cfg-router-ppp-pool', c.router_ppp_pool);
        setVal('cfg-excl-addr', c.excl_addr);
        setVal('cfg-lease-time', c.lease_time);
        setVal('cfg-disabled-list', c.disabled_list);
        setVal('cfg-disabled-profile', c.disabled_profile);
        setVal('cfg-disabled-rate', c.disabled_rate);
        setVal('cfg-auto-ppp-user', c.auto_ppp_user);
        setVal('cfg-disable-contention', c.disable_contention);
        setVal('cfg-hs-enable', c.hs_enable);
        setVal('cfg-auto-hs-user', c.auto_hs_user);

        // FreeRADIUS fields
        setVal('cfg-radius-enabled', c.radius_enabled);
        setVal('cfg-radius-host', c.radius_host || '127.0.0.1');
        setVal('cfg-radius-port', c.radius_port || 3306);
        setVal('cfg-radius-db', c.radius_db || 'radius');
        setVal('cfg-radius-user', c.radius_user || 'radius');
        setVal('cfg-radius-pass', c.radius_pass || '');
        setVal('cfg-radius-secret', c.radius_secret || 'testing123');
        setVal('cfg-radius-coa-port', c.radius_coa_port || 3799);
        setVal('cfg-radius-timeout', c.radius_timeout || 5);
    },

    async saveSettings(e) {
        e.preventDefault();
        const getVal = (id) => {
            const el = document.getElementById(id);
            if (!el) return null;
            return el.type === 'checkbox' ? el.checked : el.value;
        };

        const payload = {
            ppp_pool: getVal('cfg-ppp-pool'),
            router_ppp_pool: getVal('cfg-router-ppp-pool') ? 'true' : 'false',
            excl_addr: getVal('cfg-excl-addr'),
            lease_time: parseInt(getVal('cfg-lease-time')) || 60,
            disabled_list: getVal('cfg-disabled-list'),
            disabled_profile: getVal('cfg-disabled-profile'),
            disabled_rate: parseInt(getVal('cfg-disabled-rate')) || 0,
            auto_ppp_user: !!getVal('cfg-auto-ppp-user'),
            disable_contention: !!getVal('cfg-disable-contention'),
            hs_enable: !!getVal('cfg-hs-enable'),
            auto_hs_user: !!getVal('cfg-auto-hs-user'),
            radius_enabled: !!getVal('cfg-radius-enabled'),
            radius_host: getVal('cfg-radius-host') || '127.0.0.1',
            radius_port: parseInt(getVal('cfg-radius-port')) || 3306,
            radius_db: getVal('cfg-radius-db') || 'radius',
            radius_user: getVal('cfg-radius-user') || 'radius',
            radius_pass: getVal('cfg-radius-pass') || '',
            radius_secret: getVal('cfg-radius-secret') || 'testing123',
            radius_coa_port: parseInt(getVal('cfg-radius-coa-port')) || 3799,
            radius_timeout: parseInt(getVal('cfg-radius-timeout')) || 5
        };

        try {
            await this.api('update', 'config', 'edit', payload);
            this.toast('Settings saved');
            await this.loadSettings();
        } catch (err) {}
    },

    async testRadiusConnection() {
        const btn = document.getElementById('btn-test-radius');
        const resDiv = document.getElementById('radius-test-result');
        if (!btn || !resDiv) return;

        const getVal = (id) => {
            const el = document.getElementById(id);
            if (!el) return null;
            return el.type === 'checkbox' ? el.checked : el.value;
        };

        const config = {
            radius_host: getVal('cfg-radius-host') || '127.0.0.1',
            radius_port: parseInt(getVal('cfg-radius-port')) || 3306,
            radius_db: getVal('cfg-radius-db') || 'radius',
            radius_user: getVal('cfg-radius-user') || 'radius',
            radius_pass: getVal('cfg-radius-pass') || '',
            radius_timeout: parseInt(getVal('cfg-radius-timeout')) || 5
        };

        btn.disabled = true;
        btn.innerHTML = `<span class="spinner"></span>Testing connection`;
        resDiv.classList.add('is-hidden');

        try {
            const res = await this.api('update', 'system', 'test_radius', config);
            resDiv.classList.remove('is-hidden');
            if (res && res.success) {
                resDiv.className = 'callout callout--success space-top';
                resDiv.innerText = res.message || 'Database connection succeeded.';
            } else {
                resDiv.className = 'callout callout--danger space-top';
                resDiv.innerText = (res && res.message) ? res.message : 'Database connection failed';
            }
        } catch (err) {
            resDiv.classList.remove('is-hidden');
            resDiv.className = 'callout callout--danger space-top';
            resDiv.innerText = err.message || 'Connection test failed';
        } finally {
            btn.disabled = false;
            btn.textContent = 'Test connection';
        }
    },

    // ==========================================
    // Attributes Management
    // ==========================================
    async loadAttributes() {
        const tbody = document.getElementById('attributes-table-body');
        if (!tbody) return;
        tbody.innerHTML = `<tr><td colspan="4" class="loading-cell"><span class="loading-line"><span class="spinner"></span>Loading custom attributes</span></td></tr>`;

        try {
            const attrs = await this.api('list', 'attrs');
            this.state.attributes = attrs || [];
            this.renderAttributes();
        } catch (e) {
            tbody.innerHTML = `<tr><td colspan="4" class="error-cell"><div class="state-title">Attributes could not be loaded</div><div class="state-copy">${this.escapeHtml(e.message)}</div><div class="state-action"><button class="button button--secondary" onclick="App.loadAttributes()">Try again</button></div></td></tr>`;
        }
    },

    renderAttributes() {
        const tbody = document.getElementById('attributes-table-body');
        if (!tbody) return;

        if (this.state.attributes.length === 0) {
            tbody.innerHTML = `<tr><td colspan="4" class="empty-cell"><div class="state-title">No custom attributes</div><div class="state-copy">Create the standard UISP fields required for subscriber provisioning.</div><div class="state-action"><button class="button button--primary" onclick="App.autoCreateAttributes()">Create standard attributes</button></div></td></tr>`;
            return;
        }

        tbody.innerHTML = this.state.attributes.map(a => `
            <tr>
                <td class="primary-cell">${this.escapeHtml(a.name)}</td>
                <td class="mono">${this.escapeHtml(a.key)}</td>
                <td>${this.escapeHtml(a.attributeType || 'service')}</td>
                <td>
                    ${a.roskey 
                        ? '<span class="status status--mapped">Mapped · ' + this.escapeHtml(a.native_key) + '</span>' 
                        : '<span class="status">Unmapped</span>'}
                </td>
            </tr>
        `).join('');
    },

    async autoCreateAttributes() {
        const defaultAttrs = [
            { key: 'pppoe_user_attr', value: 'PPPoE Username' },
            { key: 'pppoe_pass_attr', value: 'PPPoE Password' },
            { key: 'device_name_attr', value: 'Router Device' },
            { key: 'mac_addr_attr', value: 'MAC Address' },
            { key: 'ip_addr_attr', value: 'Static IP' },
            { key: 'ip_addr6_attr', value: 'IPv6 Prefix' }
        ];

        if (!await this.confirmAction({ title: 'Create standard attributes', message: 'Create the UISP custom attributes used for PPPoE credentials, router assignment, MAC address, and static IP values?', confirmLabel: 'Create attributes', danger: false })) {
            return;
        }

        let created = 0;
        for (const item of defaultAttrs) {
            try {
                await this.api('update', 'attributes', 'insert', item);
                created++;
            } catch (e) {
                console.warn('Attribute create failed:', item.key, e.message);
            }
        }

        this.toast(`Processed attributes: ${created} configured`);
        await this.loadAttributes();
    },

    // ==========================================
    // System (Backups, Jobs, Logs)
    // ==========================================
    async loadSystem() {
        await Promise.all([this.loadBackups(), this.loadJobs()]);
    },

    async loadBackups() {
        const tbody = document.getElementById('backups-table-body');
        if (!tbody) return;
        tbody.innerHTML = `<tr><td colspan="3" class="loading-cell"><span class="loading-line"><span class="spinner"></span>Loading backups</span></td></tr>`;

        try {
            const backups = await this.api('list', 'backups');
            this.state.backups = backups || [];
            if (this.state.backups.length === 0) {
                tbody.innerHTML = `<tr><td colspan="3" class="empty-cell"><div class="state-title">No database backups</div><div class="state-copy">Create a backup before changing plugin data or configuration.</div></td></tr>`;
                return;
            }

            tbody.innerHTML = this.state.backups.map(b => `
                <tr>
                    <td class="primary-cell mono">${this.escapeHtml(b.name)}</td>
                    <td>${this.escapeHtml(b.date)}</td>
                    <td class="align-right"><button onclick="App.restoreBackup(decodeURIComponent('${encodeURIComponent(b.name)}'))" class="button button--text">Restore</button>
                    </td>
                </tr>
            `).join('');
        } catch (e) {
            tbody.innerHTML = `<tr><td colspan="3" class="error-cell"><div class="state-title">Backups could not be loaded</div><div class="state-action"><button class="button button--secondary" onclick="App.loadBackups()">Try again</button></div></td></tr>`;
        }
    },

    async createBackup() {
        try {
            await this.api('update', 'backups', 'backup');
            this.toast('Database backup created');
            await this.loadBackups();
        } catch (e) {}
    },

    async restoreBackup(name) {
        if (!await this.confirmAction({ title: 'Restore database backup', message: `Restore "${name}"? This replaces the current plugin database and cannot be undone from this screen.`, confirmLabel: 'Restore backup' })) {
            return;
        }

        try {
            await this.api('update', 'backups', 'restore', { name: name });
            this.toast('Database backup restored');
            await this.loadBackups();
        } catch (e) {}
    },

    async loadJobs() {
        const tbody = document.getElementById('jobs-table-body');
        if (!tbody) return;
        tbody.innerHTML = `<tr><td colspan="4" class="loading-cell"><span class="loading-line"><span class="spinner"></span>Loading retry queue</span></td></tr>`;

        try {
            const jobs = await this.api('list', 'jobs');
            this.state.jobs = jobs || [];
            if (this.state.jobs.length === 0) {
                tbody.innerHTML = `<tr><td colspan="4" class="empty-cell"><div class="state-title">Retry queue is clear</div><div class="state-copy">No failed provisioning commands are waiting.</div></td></tr>`;
                return;
            }

            tbody.innerHTML = this.state.jobs.map((j, idx) => `
                <tr>
                    <td class="mono">${idx + 1}</td>
                    <td class="primary-cell">${this.escapeHtml(j.action || 'set')}</td>
                    <td class="mono">${this.escapeHtml(j.id || '—')}</td>
                    <td>${this.escapeHtml(j.state || 'pending')} · ${Number(j.attempts || 0)} attempts<br>${this.escapeHtml(j.time || '—')}<br>${this.escapeHtml(j.error || '')}${j.action !== 'legacy review' ? `<br><button class="button button--secondary" onclick="App.retryJob(${Number(j.job_id)})">Retry on next scheduler run</button>` : ''}</td>
                </tr>
            `).join('');
        } catch (e) {
            tbody.innerHTML = `<tr><td colspan="4" class="error-cell"><div class="state-title">Retry queue could not be loaded</div><div class="state-action"><button class="button button--secondary" onclick="App.loadJobs()">Try again</button></div></td></tr>`;
        }
    },

    async clearJobs() {
        if (!await this.confirmAction({ title: 'Clear retry queue', message: 'Remove every queued provisioning command? Removed commands will not be retried.', confirmLabel: 'Clear queue' })) return;
        try {
            await this.api('update', 'jobs', 'delete');
            this.toast('Job queue cleared');
            await this.loadJobs();
        } catch (e) {}
    },

    async probeDevice(id) {
        const output = document.getElementById('router-diagnostics');
        output.classList.remove('is-hidden');
        output.textContent = 'Checking read-only API capabilities…';
        try { output.textContent = JSON.stringify(await this.api('update', 'devices', 'diagnostics', { id }), null, 2); }
        catch (error) { output.textContent = error.message; }
    },
    async retryJob(id) {
        await this.api('update', 'jobs', 'retry', { id });
        await this.loadJobs();
    },

    async clearLog() {
        if (!await this.confirmAction({ title: 'Clear plugin log', message: 'Delete the current operational log? This removes diagnostic history and cannot be undone.', confirmLabel: 'Clear log' })) return;
        try {
            await this.api('update', 'system', 'log_clear');
            this.toast('Plugin log cleared');
        } catch (e) {}
    },

    async rebuildCache() {
        try {
            this.toast('Rebuilding the UISP cache');
            await this.api('update', 'system', 'cache');
            this.toast('UISP cache rebuilt');
        } catch (e) {}
    }
};

document.addEventListener('DOMContentLoaded', () => {
    App.init();
});
