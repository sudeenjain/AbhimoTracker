'use strict';

// This popup never touches chrome.storage or the network directly -- every
// action goes through background.js via chrome.runtime.sendMessage, so
// there is exactly one place (background.js) that owns credentials, the
// token cache, and the tracking state. That keeps this file simple and
// avoids two contexts racing to write the same storage key.

const loginForm = document.getElementById('loginForm');
const statusPanel = document.getElementById('statusPanel');
const msg = document.getElementById('msg');

function showLoginError(text) {
  msg.textContent = text;
  msg.style.display = 'block';
}

function renderStatus(status) {
  loginForm.classList.add('hidden');
  statusPanel.classList.remove('hidden');

  document.getElementById('pairedAs').textContent = status.pairedAs || '-';

  const dot = document.getElementById('statusDot');
  const text = document.getElementById('statusText');
  if (!status.monitoringAllowed) {
    dot.className = 'dot off';
    text.textContent = 'Not tracking (sign in on the attendance site first)';
  } else if (status.isIdle) {
    dot.className = 'dot idle';
    text.textContent = 'Idle';
  } else {
    dot.className = 'dot active';
    text.textContent = 'Active';
  }

  document.getElementById('currentDomain').textContent = status.currentDomain || '-';
  document.getElementById('pendingCount').textContent = String(status.pendingCount);

  const errorEl = document.getElementById('lastError');
  if (status.lastError) {
    errorEl.textContent = status.lastError;
    errorEl.classList.remove('hidden');
  } else {
    errorEl.classList.add('hidden');
  }
}

async function refresh() {
  const status = await chrome.runtime.sendMessage({ type: 'getStatus' });
  if (status && status.ok && status.pairedAs) {
    renderStatus(status);
  } else {
    loginForm.classList.remove('hidden');
    statusPanel.classList.add('hidden');
  }
}

document.getElementById('loginBtn').addEventListener('click', async () => {
  msg.style.display = 'none';
  const apiBase = document.getElementById('apiBase').value.trim();
  const username = document.getElementById('username').value.trim();
  const password = document.getElementById('password').value;

  if (!username || !password) {
    showLoginError('Enter your username and password.');
    return;
  }

  const btn = document.getElementById('loginBtn');
  btn.disabled = true;
  btn.textContent = 'Connecting...';

  const result = await chrome.runtime.sendMessage({ type: 'login', apiBase, username, password });

  btn.disabled = false;
  btn.textContent = 'Connect';

  if (!result || !result.ok) {
    showLoginError((result && result.error) || 'Could not connect.');
    return;
  }
  await refresh();
});

document.getElementById('logoutBtn').addEventListener('click', async () => {
  await chrome.runtime.sendMessage({ type: 'logout' });
  document.getElementById('username').value = '';
  document.getElementById('password').value = '';
  await refresh();
});

refresh();
