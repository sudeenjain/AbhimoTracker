using AbhimoTracker.Employee.Models;

namespace AbhimoTracker.Employee.Services;

/// <summary>
/// Port of the monitoring orchestration in desktop-tray/main.js: tracking
/// only ever runs while the backend says this employee is currently signed
/// in (GET /api/attendance/today), polled every MonitoringStatusPollMs.
/// Losing pairing, consent, or the work session all stop tracking within
/// one poll cycle.
/// </summary>
public sealed class MonitoringController : IDisposable
{
    public event Action<string /* statusLine */, string /* statusKey */>? StatusChanged;

    private readonly AppConfig _config;
    private readonly CredentialStore _store;
    private readonly ApiClient _api;
    private readonly LiveStatusPusher _pusher;

    private IdleMonitor? _idleMonitor;
    private ActivityTracker? _activityTracker;
    private UploadQueue? _uploadQueue;

    private System.Timers.Timer? _statusPollTimer;
    private bool _monitoringAllowed;

    public int PendingQueueCount => _uploadQueue?.PendingCount ?? 0;

    public MonitoringController(AppConfig config, CredentialStore store, ApiClient api, LiveStatusPusher pusher)
    {
        _config = config;
        _store = store;
        _api = api;
        _pusher = pusher;
    }

    public void StartController(string userDataDir)
    {
        InitMonitoring(userDataDir);
        if (_statusPollTimer is not null) return;

        _statusPollTimer = new System.Timers.Timer(_config.Tracking.MonitoringStatusPollMs) { AutoReset = true };
        _statusPollTimer.Elapsed += async (_, _) => await PollMonitoringStatusAsync();
        _ = PollMonitoringStatusAsync();
        _statusPollTimer.Start();
    }

    public void StopController()
    {
        _statusPollTimer?.Stop();
        _statusPollTimer?.Dispose();
        _statusPollTimer = null;
        SetMonitoringAllowed(false);
    }

    private void InitMonitoring(string userDataDir)
    {
        if (_idleMonitor is not null) return;

        _idleMonitor = new IdleMonitor(_config.Tracking.IdleThresholdSeconds, _config.Tracking.IdlePollIntervalMs);
        _activityTracker = new ActivityTracker(_config.Tracking.ActivePollIntervalMs, _config.Tracking.ChunkFlushMs);
        _uploadQueue = new UploadQueue(
            userDataDir,
            _config.Tracking.UploadFlushIntervalMs,
            _config.Tracking.MaxBatchSize,
            TimeSpan.FromHours(_config.Tracking.MaxQueueAgeHours));
        _uploadQueue.SetSender(SendActivityBatchAsync);

        _idleMonitor.IdleChanged += async isIdle =>
        {
            _activityTracker!.SetIdle(isIdle);
            if (_monitoringAllowed)
            {
                var username = _store.Load()?.Username ?? "";
                var key = isIdle ? "idle" : "active";
                StatusChanged?.Invoke($"Signed in as {username}", key);
                await PushLiveStatusAsync(key, isIdle ? null : _activityTracker.CurrentAppName);
            }
        };

        _activityTracker.EntryReady += entry => _uploadQueue.Enqueue(entry);
        _activityTracker.AppChanged += async appName =>
        {
            if (_monitoringAllowed && !_idleMonitor.IsIdle)
            {
                await PushLiveStatusAsync("active", appName);
            }
        };
    }

    public async Task ForcePollOnceAsync()
    {
        await PollMonitoringStatusAsync();
    }

    private async Task<bool> SendActivityBatchAsync(IReadOnlyList<ActivityEntry> items, CancellationToken ct)
    {
        var creds = _store.Load();
        if (creds is null) return false;
        return await _api.SendActivityBatchAsync(creds, items, ct);
    }

    private async Task PollMonitoringStatusAsync()
    {
        var creds = _store.Load();
        if (creds is null)
        {
            SetMonitoringAllowed(false);
            return;
        }

        var (ok, signIn, signOut, statusCode) = await _api.GetMonitoringStatusAsync(creds);
        if (statusCode == 403)
        {
            // ConsentRequiredMiddleware: temp password not changed yet, or the
            // current monitoring policy hasn't been accepted -- not an error,
            // just "not tracking yet".
            SetMonitoringAllowed(false);
            StatusChanged?.Invoke($"Signed in as {creds.Username}", "signedout");
            return;
        }
        if (!ok)
        {
            SetMonitoringAllowed(false);
            StatusChanged?.Invoke($"Signed in as {creds.Username}", "offline");
            return;
        }

        var allowed = signIn.HasValue && !signOut.HasValue;
        SetMonitoringAllowed(allowed);
        var key = allowed ? (_idleMonitor!.IsIdle ? "idle" : "active") : "signedout";
        StatusChanged?.Invoke($"Signed in as {creds.Username}", key);
        await PushLiveStatusAsync(key, allowed && !_idleMonitor!.IsIdle ? _activityTracker?.CurrentAppName : null);
    }

    private async Task PushLiveStatusAsync(string status, string? activeWindow)
    {
        var creds = _store.Load();
        if (creds is null) return;
        var token = await _api.GetAuthTokenAsync(creds);
        if (token is null) return;
        await _pusher.EnsureConnectedAsync(token);
        await _pusher.PushStatusAsync(status, activeWindow);
    }

    private void SetMonitoringAllowed(bool allowed)
    {
        if (allowed == _monitoringAllowed) return;
        _monitoringAllowed = allowed;
        if (allowed)
        {
            _idleMonitor!.Start();
            _activityTracker!.Start();
            _uploadQueue!.Start();
        }
        else
        {
            _idleMonitor?.Stop();
            _activityTracker?.Stop();
            _uploadQueue?.Stop();
        }
    }

    public void Dispose()
    {
        StopController();
        _idleMonitor?.Dispose();
        _activityTracker?.Dispose();
        _uploadQueue?.Dispose();
    }
}
