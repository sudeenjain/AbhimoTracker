// Mocks the chrome.* extension APIs and global fetch, then imports
// background.js as a real ES module and drives it through the exact same
// listener callbacks Chrome itself would call. This is not a syntax check
// -- it actually runs the domain-tracking, idle, and upload-queue logic
// and asserts on the resulting state.

let assertions = 0;
let failures = 0;
function assertEqual(actual, expected, label) {
  assertions++;
  const a = JSON.stringify(actual);
  const e = JSON.stringify(expected);
  if (a !== e) {
    failures++;
    console.error(`FAIL ${label}: expected ${e}, got ${a}`);
  } else {
    console.log(`ok   ${label}`);
  }
}
function assertTrue(cond, label) {
  assertions++;
  if (!cond) {
    failures++;
    console.error(`FAIL ${label}`);
  } else {
    console.log(`ok   ${label}`);
  }
}

// ---- In-memory chrome.storage.local ----
const storageBacking = {};
const listeners = {};

globalThis.chrome = {
  storage: {
    local: {
      get: async (key) => ({ [key]: storageBacking[key] }),
      set: async (obj) => Object.assign(storageBacking, obj),
    },
  },
  alarms: {
    get: async () => undefined,
    create: () => {},
    onAlarm: { addListener: (fn) => { listeners.alarm = fn; } },
  },
  tabs: {
    onActivated: { addListener: (fn) => { listeners.tabsActivated = fn; } },
    onUpdated: { addListener: (fn) => { listeners.tabsUpdated = fn; } },
    query: async () => (globalThis.__mockTabs || []),
  },
  windows: {
    onFocusChanged: { addListener: (fn) => { listeners.windowsFocus = fn; } },
    getLastFocused: async () => globalThis.__mockFocusedWindow || null,
  },
  idle: {
    setDetectionInterval: () => {},
    onStateChanged: { addListener: (fn) => { listeners.idleState = fn; } },
  },
  runtime: {
    onInstalled: { addListener: (fn) => { listeners.installed = fn; } },
    onStartup: { addListener: () => {} },
    onMessage: { addListener: (fn) => { listeners.message = fn; } },
  },
};

// ---- fetch mock, keyed by which endpoint is hit ----
const fetchLog = [];
globalThis.fetch = async (url, opts) => {
  fetchLog.push({ url, body: opts && opts.body ? JSON.parse(opts.body) : null });
  if (url.endsWith('/api/login')) {
    return { ok: true, status: 200, json: async () => ({ token: makeFakeJwt(), must_change_password: false }) };
  }
  if (url.endsWith('/api/attendance/today')) {
    return { ok: true, status: 200, json: async () => ({ sign_in_time: '2026-07-19 09:00:00', sign_out_time: null }) };
  }
  if (url.endsWith('/api/activity/website')) {
    return { ok: true, status: 201, json: async () => ({ message: 'ok', entries_stored: (opts.body ? JSON.parse(opts.body).entries.length : 0) }) };
  }
  return { ok: false, status: 404, json: async () => ({ error: 'not found' }) };
};

function makeFakeJwt() {
  const header = Buffer.from(JSON.stringify({ alg: 'HS256', typ: 'JWT' })).toString('base64url');
  const payload = Buffer.from(JSON.stringify({ sub: 2, exp: Math.floor(Date.now() / 1000) + 3600 })).toString('base64url');
  return `${header}.${payload}.fakesig`;
}

function messageOnce(msg) {
  return new Promise((resolve) => {
    listeners.message(msg, {}, resolve);
  });
}

async function run() {
  await import('../background.js');
  // let the fire-and-forget init() promise settle
  await new Promise((r) => setTimeout(r, 20));

  // ---- 1. Login ----
  const loginResult = await messageOnce({
    type: 'login', apiBase: 'http://test-backend', username: 'testemp', password: 'Test1234!',
  });
  assertTrue(loginResult.ok, 'login succeeds against mocked backend');

  const status1 = await messageOnce({ type: 'getStatus' });
  assertEqual(status1.pairedAs, 'testemp', 'status reports paired username after login');
  assertEqual(status1.monitoringAllowed, true, 'monitoring allowed after mocked sign-in-today response');

  // ---- 2. Domain switch while focused + active tab on a real http(s) page ----
  globalThis.__mockFocusedWindow = { id: 1, focused: true };
  globalThis.__mockTabs = [{ url: 'https://github.com/some-org/private-repo/issues/42?x=1#frag' }];
  await listeners.tabsActivated({ tabId: 1 });

  const status2 = await messageOnce({ type: 'getStatus' });
  assertEqual(status2.currentDomain, 'github.com', 'active tab domain extracted as bare hostname (path/query/hash stripped)');

  // ---- 3. Non-http page should clear the tracked domain (not track chrome:// etc) ----
  globalThis.__mockTabs = [{ url: 'chrome://extensions' }];
  await listeners.tabsActivated({ tabId: 1 });
  const status3 = await messageOnce({ type: 'getStatus' });
  assertEqual(status3.currentDomain, null, 'chrome:// page is not tracked as a domain');

  // switch back to a trackable domain before the tick, so the tick has
  // something real to flush
  globalThis.__mockTabs = [{ url: 'https://stackoverflow.com/questions/1' }];
  await listeners.tabsActivated({ tabId: 1 });

  // ---- 4. Alarm tick: chunk-flush + upload ----
  // Force elapsed time by rewriting the stored segment's start time back
  // 90 seconds, simulating "90 seconds have passed since the last event".
  const before = storageBacking.abhimoState;
  before.currentSegment.startedAtMs -= 90000;
  await chrome.storage.local.set({ abhimoState: before });

  // chrome.alarms.onAlarm (unlike chrome.runtime.onMessage) has no
  // return-a-promise contract -- Chrome fires it and moves on, so
  // background.js's listener deliberately doesn't return onTick()'s
  // promise (that's correct/expected for an alarm listener, not a bug).
  // The test therefore has to wait for that fire-and-forget work to
  // finish rather than assume the call is synchronous.
  await listeners.alarm({ name: 'abhimo-tick' });
  await new Promise((r) => setTimeout(r, 20));

  const websiteUploadCalls = fetchLog.filter((c) => c.url.endsWith('/api/activity/website'));
  assertTrue(websiteUploadCalls.length >= 1, 'tick triggers a website-activity upload call');
  const uploadedEntry = websiteUploadCalls[0].body.entries[0];
  assertEqual(uploadedEntry.domain, 'stackoverflow.com', 'uploaded entry has the bare domain');
  assertEqual(uploadedEntry.is_idle, false, 'uploaded entry is not marked idle');
  assertTrue(uploadedEntry.duration_seconds >= 89 && uploadedEntry.duration_seconds <= 91, 'uploaded duration matches elapsed time (~90s)');
  assertTrue(!!uploadedEntry.client_batch_id, 'uploaded entry carries a client_batch_id for server-side dedup');

  const status4 = await messageOnce({ type: 'getStatus' });
  assertEqual(status4.pendingCount, 0, 'queue is drained after a successful upload');

  // ---- 5. Going OS-idle stops counting the domain and does not queue idle time as website usage ----
  await listeners.idleState('idle');
  const status5 = await messageOnce({ type: 'getStatus' });
  assertEqual(status5.isIdle, true, 'idle state is reflected in status');
  assertEqual(status5.currentDomain, null, 'no domain is reported as active while idle');

  const beforeTickQueueLen = storageBacking.abhimoState.queue.length;
  await listeners.alarm({ name: 'abhimo-tick' });
  await new Promise((r) => setTimeout(r, 20));
  const afterIdleTickState = storageBacking.abhimoState;
  assertEqual(afterIdleTickState.queue.length, beforeTickQueueLen, 'idle stretch produces no new queued website-usage entry');

  // ---- 6. Losing browser focus (another app in foreground) also stops tracking ----
  await listeners.idleState('active');
  globalThis.__mockTabs = [{ url: 'https://example.com' }];
  globalThis.__mockFocusedWindow = { id: 1, focused: true };
  await listeners.tabsActivated({ tabId: 1 });
  let status6 = await messageOnce({ type: 'getStatus' });
  assertEqual(status6.currentDomain, 'example.com', 'sanity: domain tracked again once active + focused');

  globalThis.__mockFocusedWindow = null; // browser window no longer OS-focused
  await listeners.windowsFocus(-1);
  status6 = await messageOnce({ type: 'getStatus' });
  assertEqual(status6.currentDomain, null, 'losing OS focus to another app clears the tracked domain');

  // ---- 7. Full URL rejected client-side is not applicable here (server
  // enforces it) -- but confirm domain extraction never leaks path/query
  // for a variety of shapes ----
  globalThis.__mockFocusedWindow = { id: 1, focused: true };
  globalThis.__mockTabs = [{ url: 'https://sub.example.co.uk:8443/a/b?c=d#e' }];
  await listeners.tabsActivated({ tabId: 1 });
  const status7 = await messageOnce({ type: 'getStatus' });
  assertEqual(status7.currentDomain, 'sub.example.co.uk', 'subdomain + port + path + query + fragment all reduce to the bare hostname');

  // ---- 8. Logout clears everything ----
  await messageOnce({ type: 'logout' });
  const status8 = await messageOnce({ type: 'getStatus' });
  assertEqual(status8.pairedAs, null, 'logout clears paired username');
  assertEqual(status8.monitoringAllowed, false, 'logout stops monitoring');

  console.log(`\n${assertions - failures}/${assertions} assertions passed.`);
  if (failures > 0) {
    console.error(`${failures} FAILURE(S)`);
    process.exit(1);
  }
}

run().catch((err) => {
  console.error('Test harness crashed:', err);
  process.exit(1);
});
