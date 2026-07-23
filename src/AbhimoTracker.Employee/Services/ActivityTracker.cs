using System.Diagnostics;
using System.Runtime.InteropServices;
using System.Timers;
using AbhimoTracker.Employee.Models;
using Timer = System.Timers.Timer;

namespace AbhimoTracker.Employee.Services;

/// <summary>
/// Tracks foreground application usage and emits entries matching the
/// backend's exact contract (see ActivityController::ingest). Direct port
/// of desktop-tray/activity-tracker.js's logic, using Win32
/// GetForegroundWindow + GetWindowThreadProcessId instead of the
/// active-win npm package.
///
/// Two things this deliberately does, same as the original:
///
/// 1. ActiveWindow is the app/process name ONLY, never a window title --
///    confirmed by AdminActivityController::appUsage's docblock ("never a
///    window title"). We never read the window title at all.
///
/// 2. A single continuous stretch in one app (or one idle stretch) is
///    chunked into ~90s slices (ChunkFlushMs) instead of one entry that
///    only closes on the next switch -- see AppConfig.TrackingConfig for
///    why (180s "online" window, 3600s per-entry cap).
///
/// Collects only: process name and duration. Never window titles,
/// keystrokes, mouse position, clipboard, or screenshots.
/// </summary>
public sealed class ActivityTracker : IDisposable
{
    public const int MaxEntryDurationSeconds = 3600; // hard server-side cap in ActivityController::ingest

    [DllImport("user32.dll")]
    private static extern IntPtr GetForegroundWindow();

    [DllImport("user32.dll")]
    private static extern uint GetWindowThreadProcessId(IntPtr hWnd, out uint lpdwProcessId);

    private readonly Timer _pollTimer;
    private readonly Timer _chunkTimer;
    private bool _isIdle;

    private sealed record Segment(string? AppName, bool IsIdle, DateTime SegmentStart);
    private Segment? _current;

    public event Action<ActivityEntry>? EntryReady;
    public event Action<string?>? AppChanged;

    public string? CurrentAppName => _current?.AppName;

    public ActivityTracker(int pollIntervalMs, int chunkFlushMs)
    {
        _pollTimer = new Timer(pollIntervalMs) { AutoReset = true };
        _pollTimer.Elapsed += (_, _) => PollApp();
        _chunkTimer = new Timer(chunkFlushMs) { AutoReset = true };
        _chunkTimer.Elapsed += (_, _) => FlushChunk();
    }

    public void Start()
    {
        _current = new Segment(null, _isIdle, DateTime.UtcNow);
        _pollTimer.Start();
        _chunkTimer.Start();
        if (!_isIdle) PollApp();
    }

    public void Stop()
    {
        _pollTimer.Stop();
        _chunkTimer.Stop();
        FlushChunk();
        _current = null;
    }

    /// <summary>Called when the IdleMonitor's state changes. Closes out whatever segment
    /// was running and starts a fresh one under the new idle/active state, so an idle
    /// stretch and the app usage before it are never merged into one misleading entry.</summary>
    public void SetIdle(bool isIdle)
    {
        _isIdle = isIdle;
        if (_current is null || isIdle == _current.IsIdle) return;
        FlushChunk();
        _current = new Segment(null, isIdle, DateTime.UtcNow);
    }

    private void PollApp()
    {
        if (_current is null || _current.IsIdle) return; // no app polling while idle

        string? appName;
        try
        {
            appName = GetForegroundAppName();
        }
        catch
        {
            return; // transient OS hiccup -- try again next tick
        }

        if (appName != _current.AppName)
        {
            FlushChunk();
            _current = new Segment(appName, false, DateTime.UtcNow);
            AppChanged?.Invoke(appName);
        }
    }

    private static string? GetForegroundAppName()
    {
        var hwnd = GetForegroundWindow();
        if (hwnd == IntPtr.Zero) return null;
        GetWindowThreadProcessId(hwnd, out var pid);
        if (pid == 0) return null;
        try
        {
            using var process = Process.GetProcessById((int)pid);
            return process.ProcessName; // process name only -- see class docblock
        }
        catch
        {
            return null; // process may have exited between the two calls
        }
    }

    /// <summary>Emits an entry for elapsed time in the current segment, then keeps the
    /// segment open (same app/idle state) starting from now -- turns one long stretch
    /// into periodic chunks instead of closing the segment.</summary>
    private void FlushChunk()
    {
        if (_current is null) return;
        var now = DateTime.UtcNow;
        var elapsedSeconds = (int)Math.Round((now - _current.SegmentStart).TotalSeconds);
        var closedSegment = _current;
        _current = _current with { SegmentStart = now };
        if (elapsedSeconds <= 0) return;

        EntryReady?.Invoke(new ActivityEntry
        {
            Timestamp = now.AddSeconds(-elapsedSeconds).ToLocalTime(),
            ActiveWindow = closedSegment.AppName,
            IsIdle = closedSegment.IsIdle,
            DurationSeconds = Math.Min(elapsedSeconds, MaxEntryDurationSeconds),
        });
    }

    public void Dispose()
    {
        _pollTimer.Dispose();
        _chunkTimer.Dispose();
    }
}
