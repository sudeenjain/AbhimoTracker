// Central place to point the frontend at the backend API.
// Change this if the PHP app isn't running on localhost:8080.
// XAMPP/Apache users: this needs to be wherever backend/public actually
// resolves to under htdocs, e.g. 'http://localhost/attendance-system/backend/public'
//
// window.__ABHIMO_API_BASE__ lets the Admin app (AbhimoTracker.Admin) inject
// a real per-environment API base at runtime via WebView2's
// AddScriptToExecuteOnDocumentCreatedAsync, instead of needing a separate
function resolveApiBase() {
  if (window.__ABHIMO_API_BASE__) return window.__ABHIMO_API_BASE__;
  try {
    const urlParams = new URLSearchParams(window.location.search);
    const apiParam = urlParams.get('api');
    if (apiParam) return apiParam;
  } catch (e) {}

  if (window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1') {
    return 'http://localhost/attendance-system/backend/public';
  }

  return 'http://localhost/attendance-system/backend/public';
}

const API_BASE = resolveApiBase();

function authHeaders() {
  const token = localStorage.getItem('auth_token');
  return token ? { Authorization: `Bearer ${token}` } : {};
}

async function apiFetch(path, options = {}) {
  const response = await fetch(`${API_BASE}${path}`, {
    ...options,
    headers: {
      'Content-Type': 'application/json',
      ...authHeaders(),
      ...(options.headers || {}),
    },
  });
  const data = await response.json().catch(() => ({}));
  return { ok: response.ok, status: response.status, data };
}
