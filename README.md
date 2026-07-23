# Patches to apply to your real frontend/ folder

These are the only changes made outside AbhimoTracker/ itself. All are
additive except the one-line DOWNLOAD_URL change.

- abhimo-tracker.html          -> replaces frontend/abhimo-tracker.html
                                   (DOWNLOAD_URL now points at
                                   AbhimoTracker-Employee-Setup.exe)
- js/api.js                    -> replaces frontend/js/api.js
                                   (API_BASE now checks
                                   window.__ABHIMO_API_BASE__ first, falls
                                   back to the original hardcoded value)
- abhimo_admin/attendance.html -> replaces frontend/abhimo_admin/attendance.html
                                   (adds SignalR CDN script + live-status-client.js
                                   before </body>, nothing else changed)
- abhimo_admin/employee-activity.html -> same addition as attendance.html
- js/live-status-client.js     -> new file, frontend/js/live-status-client.js

pending.html and login.html are untouched -- not included here.

These same four modified files (plus live-status-client.js) are already
copied into AbhimoTracker/src/AbhimoTracker.Admin/wwwroot/ for the Admin
app to serve locally -- you only need these if you also want the web
version of the admin pages (opened in a real browser) to get the instant
live-status refresh.
