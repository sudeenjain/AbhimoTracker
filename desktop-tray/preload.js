'use strict';

const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('trayApp', {
  defaultApiBase: () => ipcRenderer.invoke('get-default-api-base'),
  // Renderer only ever hands back a username/password it already collected
  // via its own form -- main process still verifies via /api/login before
  // persisting anything, in savePairedCredentials (main.js).
  submitManualLogin: (username, password, apiBase) =>
    ipcRenderer.invoke('manual-login', { username, password, apiBase }),
});