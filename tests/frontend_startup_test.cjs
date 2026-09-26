const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const root = path.resolve(process.argv[2] || path.join(__dirname, '../zfs.master'));
const page = fs.readFileSync(path.join(root, 'ZFSMaster.page'), 'utf8');
const frontend = fs.readFileSync(path.join(root, 'frontend/ZFSMFrontEnd.js'), 'utf8');
const inventory = fs.readFileSync(path.join(root, 'frontend/ZFSMInventory.js'), 'utf8');
const mainScript = [...page.matchAll(/<script\b[^>]*>([\s\S]*?)<\/script>/g)]
  .map(match => match[1]).find(script => script.includes('var zfsm_csrf_token'));

function launch({interval = 30, cache = null, storageThrows = false, missingSubscriber = false} = {}) {
  const ready = [], requests = [], handlers = {}, timers = new Map(), texts = new Map(), events = {};
  let timerId = 0, ajaxSuccess;
  const elements = new Map();
  const body = {innerHTML: ''};
  function $(selector) {
    if (typeof selector === 'function') { ready.push(selector); return; }
    if (!elements.has(selector)) {
      const element = {
        text(value) { if (value === undefined) return texts.get(selector); texts.set(selector, value); return this; },
        css() { return this; }, removeClass() { return this; }, addClass() { return this; },
        click() { return this; }, hide() { return this; }, attr() { return this; },
        ajaxSuccess(callback) { ajaxSuccess = callback; return this; }
      };
      elements.set(selector, element);
    }
    return elements.get(selector);
  }
  $.ajaxSetup = () => {};
  $.escapeSelector = value => value;
  $.ajax = options => {
    const request = {options, done(callback) { this.resolve = callback; return this; },
      fail(callback) { this.reject = callback; return this; }};
    requests.push(request);
    return request;
  };
  const storage = new Map(cache === null ? [] : [['zfsm-cache', cache]]);
  const context = vm.createContext({$, console: {error() {}, warn() {}}, csrf_token: 'fixture-token',
    document: {hidden: false, getElementById: id => id === 'zfs_master_body' ? body : null, getElementsByClassName: () => []},
    window: {addEventListener: (event, callback) => { events[event] = callback; }},
    localStorage: {
      getItem(key) { if (storageThrows) throw Error('Storage blocked'); return storage.get(key) ?? null; },
      setItem(key, value) { if (storageThrows) throw Error('Quota exceeded'); storage.set(key, value); },
      removeItem(key) { if (storageThrows) throw Error('Storage blocked'); storage.delete(key); }
    },
    setTimeout(callback, ms) { timers.set(++timerId, {callback, ms}); return timerId; },
    clearTimeout(id) { timers.delete(id); }
  });
  if (!missingSubscriber) context.NchanSubscriber = function() {
    this.on = (event, callback) => { handlers[event] = callback; };
    this.start = () => {};
  };
  vm.runInContext(frontend, context);
  vm.runInContext(inventory, context);
  const script = mainScript.replace(/<\?(?:php|=)[\s\S]*?\?>/g, php => {
    if (php.includes('json_encode($zfsm_cfg)')) return JSON.stringify({version: '7.3.2', refresh_interval: interval,
      destructive_mode: 0, snap_max_days_alert: 30, directory_listing: []});
    if (php.includes('json_encode($display)')) return JSON.stringify({text: 0, warning: 80, critical: 90});
    if (php.includes('$urlzmadmin')) return '/plugins/zfs.master/backend/ZFSMAdmin.php';
    return '';
  });
  vm.runInContext(script, context, {filename: 'rendered-ZFSMaster.page.js'});
  ready.forEach(callback => callback());
  return {context, requests, handlers, timers, texts, body, events, storage,
    action: answer => ajaxSuccess({}, {}, {url: '/plugins/zfs.master/backend/ZFSMAdmin.php'}, answer),
    status: () => texts.get('#zfsm-last-refresh') || '',
    expire: () => { const pending = [...timers.values()]; timers.clear(); pending.forEach(t => t.callback()); }};
}

const empty = () => ({op: 'getAll', data: {pools: {}, devices: {}, datasets: {}, errors: {}}});

// No subscription/daemon required: HTTP returns data even while publishing is paused.
let state = launch({missingSubscriber: true});
assert.equal(state.requests.length, 1);
assert.equal(state.requests[0].options.data.cmd, 'inventory');
assert.equal(state.requests[0].options.data.csrf_token, 'fixture-token');
state.requests[0].resolve({...empty(), publishing_paused: true});
assert.match(state.body.innerHTML, /No imported ZFS pools/);
assert.match(state.status(), /Last refresh.*push updates paused; inventory loaded directly/);
assert.equal([...state.timers.values()][0].ms, 30000);
state.expire();
assert.equal(state.requests.length, 2);

// Manual mode never scans automatically; Refresh works without a connection.
state = launch({interval: 0, missingSubscriber: true});
assert.match(state.status(), /Automatic refresh is disabled/);
assert.equal(state.requests.length, 0);
state.context.requestRefresh();
assert.equal(state.requests.length, 1);
state.requests[0].resolve(empty());
assert.equal(state.timers.size, 0);
state.context.requestRefresh();
state.requests[1].reject({status: 403}, 'error');
assert.match(state.status(), /HTTP 403.*stale/);
assert.match(state.body.innerHTML, /No imported ZFS pools/);

state = launch({cache: '{bad json', interval: 0});
assert.match(state.status(), /no cached data/);
state = launch({storageThrows: true});
state.requests[0].resolve(empty());
assert.match(state.status(), /Last refresh/);

// Retained push data may not overwrite HTTP state.
state.handlers.message(JSON.stringify({op: 'getAll', data: {}}));
state.handlers.message(JSON.stringify({op: 'stop_refresh'}));
state.handlers.message('not json');
assert.match(state.status(), /Last refresh/);

// Single-flight requests coalesce repeated manual/action refreshes.
state = launch();
state.context.requestRefresh();
state.context.requestRefresh();
assert.equal(state.requests.length, 1);
state.requests[0].resolve(empty());
assert.equal(state.requests.length, 2);
state.requests[1].resolve(empty());
assert.equal(state.timers.size, 1);
state.context.document.hidden = true;
state.expire();
assert.equal(state.requests.length, 2);
state.context.document.hidden = false;
state.expire();
assert.equal(state.requests.length, 3);

state = launch();
state.requests[0].resolve({failed: {request: 'zpool failed'}});
assert.match(state.status(), /zpool failed.*stale/);
state = launch();
state.requests[0].reject({status: 0}, 'timeout');
assert.match(state.status(), /timeout.*stale/);

// Actions update manual mode; errors and read-only results do not.
state = launch({interval: 0});
state.action({succeeded: {}, failed: {dataset: 'blocked'}});
state.action(empty());
assert.equal(state.requests.length, 0);
state.action({succeeded: {'tank/data': 'Success'}, failed: {}});
assert.equal(state.requests.length, 1);
state.events.pagehide();
state.requests[0].resolve(empty());
assert.equal(state.timers.size, 0);
assert.doesNotMatch(state.status(), /Last refresh/);

// Progressive loading is serialized by pool; only complete data is cached.
state = launch();
const renders = [];
state.context.updateFullBodyTable = data => { renders.push(JSON.parse(JSON.stringify(data))); };
const partial = () => ({op: 'getDatasets', config: {refresh_interval: 0}, data: {
  pools: {tank: {Pool: 'tank', Snapshots: null}, cache: {Pool: 'cache', Snapshots: null}},
  datasets: {tank: {name: 'tank'}, cache: {name: 'cache'}}, devices: {tank: '', cache: ''}, errors: {}
}});
state.requests[0].resolve(partial());
assert.equal(state.requests[1].options.data.pool, 'tank');
assert.equal(state.storage.has('zfsm-cache'), false);
function poolResult(pool, count) { return {op: 'getAll', data: {
  pools: {[pool]: {Pool: pool, Snapshots: count}}, datasets: {[pool]: {name: pool, snapshots: []}},
  devices: {[pool]: ''}, errors: {}
}}; }
state.requests[1].resolve(poolResult('tank', 39));
assert.equal(state.requests[2].options.data.pool, 'cache');
state.requests[2].resolve(poolResult('cache', 181));
const cached = JSON.parse(state.storage.get('zfsm-cache'));
assert.equal(cached.pools.tank.Snapshots, 39);
assert.equal(cached.pools.cache.Snapshots, 181);
assert.equal(state.timers.size, 0);
assert.match(state.status(), /Last refresh/);

state = launch();
state.context.updateFullBodyTable = () => {};
state.requests[0].resolve(partial());
state.requests[1].reject({status: 504}, 'error');
assert.match(state.status(), /504.*stale/);
assert.equal(state.storage.has('zfsm-cache'), false);
assert.equal(state.requests.length, 2);

assert.doesNotMatch(page, /^Nchan=/m, 'Main page must not start the obsolete inventory daemon');
console.log('HTTP inventory and frontend startup regression tests passed');
