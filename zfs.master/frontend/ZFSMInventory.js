/* Inventory uses authenticated HTTP; Nchan is optional copy-progress only. */
function createZFSMInventoryController(options) {
    let config = options.config;
    let busy = false;
    let pending = false;
    let timer = null;
    let stopped = false;

    function schedule() {
        clearTimeout(timer);
        const interval = Number(config.refresh_interval);
        if (!stopped && interval > 0) {
            timer = setTimeout(function() {
                if (document.hidden) schedule();
                else refresh();
            }, Math.max(interval, 1) * 1000);
        }
    }

    function finish() {
        busy = false;
        options.spinner(false);
        if (pending && !stopped) {
            pending = false;
            refresh();
        } else schedule();
    }

    function failed(message) {
        if (stopped) return;
        options.status(message + ' Existing rows may be stale.', true);
        finish();
    }

    function validate(answer) {
        if (!answer || !['getAll', 'getDatasets'].includes(answer.op) || !answer.data ||
            !answer.data.pools || !answer.data.datasets || !answer.data.devices) {
            const errors = answer && answer.failed ? Object.values(answer.failed).join('; ') : 'Invalid inventory response';
            throw new Error(errors);
        }
        for (const pool of Object.keys(answer.data.pools)) {
            if (!answer.data.datasets[pool] || !Object.prototype.hasOwnProperty.call(answer.data.devices, pool)) {
                throw new Error('Incomplete inventory for ' + pool);
            }
        }
    }

    function request(data, done) {
        $.ajax({url: options.url, method: 'POST', dataType: 'json', timeout: 60000,
            data: Object.assign({csrf_token: options.csrf}, data)
        }).done(function(answer) {
            if (stopped) return;
            try { validate(answer); done(answer); }
            catch (error) { failed('Unable to load ZFS inventory: ' + error.message); }
        }).fail(function(xhr, status) {
            failed('ZFS inventory request failed (HTTP ' + xhr.status + ', ' + status + ').');
        });
    }

    function refresh() {
        if (stopped) return;
        if (busy) { pending = true; return; }
        clearTimeout(timer);
        busy = true;
        options.spinner(true);
        options.status('Reading ZFS datasets and snapshots…', false);
        request({cmd: 'inventory'}, function(answer) {
            config = Object.assign({}, config, answer.config || {});
            options.configChanged(config);
            const data = answer.data;
            const pools = answer.op === 'getDatasets' ? Object.keys(data.pools) : [];
            options.render(data, false);

            function complete() {
                options.render(data, true);
                const errors = Object.values(data.errors || {});
                let message = 'Last refresh at ' + new Date().toLocaleString('en-US', {hour12: false});
                if (answer.publishing_paused) message += ' — Unraid push updates paused; inventory loaded directly.';
                if (errors.length) message += ' — Query errors: ' + errors.join('; ');
                options.status(message, errors.length > 0);
                finish();
            }

            function nextPool() {
                if (!pools.length) { complete(); return; }
                const pool = pools.shift();
                options.status('Reading snapshots for ' + pool + '…', false);
                request({cmd: 'inventorysnapshots', pool: pool}, function(part) {
                    if (!part.data.pools[pool] || !part.data.datasets[pool]) throw new Error('Missing snapshot inventory for ' + pool);
                    ['pools', 'datasets', 'devices'].forEach(key => { data[key][pool] = part.data[key][pool]; });
                    data.errors = data.errors || {};
                    delete data.errors[pool];
                    Object.assign(data.errors, part.data.errors || {});
                    options.render(data, false);
                    nextPool();
                });
            }
            nextPool();
        });
    }

    return {refresh: refresh, stop: function() { stopped = true; pending = false; clearTimeout(timer); }};
}

// Called in the main page and both popup documents, so even manual-refresh mode
// updates after a successful mutation. Failed/read-only requests do not rescan.
function installZFSMActionRefresh(refresh) {
    $(document).ajaxSuccess(function(event, xhr, settings, answer) {
        if (!settings.url || !settings.url.includes('/plugins/zfs.master/backend/ZFSMAdmin.php')) return;
        if (typeof answer === 'string') {
            try { answer = JSON.parse(answer); } catch (_) { return; }
        }
        if (answer && answer.succeeded && Object.keys(answer.succeeded).some(key => key !== 'refresh')) refresh();
    });
}
