// Additive only -- does not replace or rewrite any existing polling logic
// in attendance.html/employee-activity.html. Every page that includes this
// script keeps working exactly as it does today even if the relay hub is
// completely unreachable; this only makes the *next* refresh happen the
// instant an employee's status changes, instead of waiting out the normal
// poll interval (LIVE_STATUS_POLL_MS / APP_USAGE_POLL_MS).
//
// window.__ABHIMO_HUB_URL__ is injected at runtime by AbhimoTracker.Admin's
// MainWindow.xaml.cs, same mechanism as window.__ABHIMO_API_BASE__ in api.js.
(function () {
  const hubUrl = window.__ABHIMO_HUB_URL__;
  if (!hubUrl || typeof signalR === 'undefined') return;

  const token = localStorage.getItem('auth_token');
  if (!token) return;

  const connection = new signalR.HubConnectionBuilder()
    .withUrl(hubUrl, { accessTokenFactory: () => localStorage.getItem('auth_token') || '' })
    .withAutomaticReconnect()
    .build();

  // Every admin page that pulls this script in defines its own refresh
  // function(s) under one of these names where relevant -- call whichever
  // exist on this page rather than assuming every page has every function.
  function refreshWhatThisPageHas() {
    if (typeof loadLiveStatus === 'function') loadLiveStatus();
    if (typeof loadAppUsageAndAlerts === 'function') loadAppUsageAndAlerts();
  }

  connection.on('StatusUpdated', refreshWhatThisPageHas);

  connection.on('NewEmployeeRegistered', (info) => {
    if (typeof loadPendingEmployees === 'function') loadPendingEmployees();
    if (typeof showLiveNotification === 'function') {
      showLiveNotification(`New employee registration: ${info.name}`);
    } else {
      console.log('New registration notification received:', info);
    }
  });

  connection.start().catch(() => {
    // Relay unreachable -- this page's existing poll interval is the
    // fallback, so there is nothing else to do here.
  });
})();
