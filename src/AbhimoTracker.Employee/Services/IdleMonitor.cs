using System.Runtime.InteropServices;
using System.Timers;
using Microsoft.Win32;
using Timer = System.Timers.Timer;

namespace AbhimoTracker.Employee.Services;

/// <summary>
/// OS-wide idle detection, port of desktop-tray/idle-monitor.js. Electron's
/// powerMonitor.getSystemIdleTime() wraps the same underlying Win32 call
/// this uses directly: GetLastInputInfo, which sees real system-wide
/// mouse/keyboard activity (not just this app's window).
///
/// Only ever exposes a boolean (idle/active) via events -- the raw idle
/// seconds are used internally for the threshold check only, matching the
/// "no keystrokes, no mouse data" privacy constraint from the original.
///
/// Session lock counts as idle immediately (via SystemEvents.SessionSwitch)
/// rather than waiting out the full idle threshold, same as the
/// 'lock-screen' handling in the Electron version.
/// </summary>
public sealed class IdleMonitor : IDisposable
{
    [StructLayout(LayoutKind.Sequential)]
    private struct LASTINPUTINFO
    {
        public uint cbSize;
        public uint dwTime;
    }

    [DllImport("user32.dll")]
    private static extern bool GetLastInputInfo(ref LASTINPUTINFO plii);

    private readonly int _idleThresholdSeconds;
    private readonly Timer _timer;
    private bool _isIdle;
    private bool _locked;

    public event Action<bool>? IdleChanged; // true = now idle, false = now active

    public bool IsIdle => _isIdle;

    public IdleMonitor(int idleThresholdSeconds, int pollIntervalMs)
    {
        _idleThresholdSeconds = idleThresholdSeconds;
        _timer = new Timer(pollIntervalMs) { AutoReset = true };
        _timer.Elapsed += (_, _) => Poll();
        SystemEvents.SessionSwitch += OnSessionSwitch;
    }

    public void Start()
    {
        Poll();
        _timer.Start();
    }

    public void Stop() => _timer.Stop();

    private void OnSessionSwitch(object sender, SessionSwitchEventArgs e)
    {
        switch (e.Reason)
        {
            case SessionSwitchReason.SessionLock:
                _locked = true;
                SetIdle(true);
                break;
            case SessionSwitchReason.SessionUnlock:
                _locked = false;
                // Don't force back to active on unlock -- mirrors idle-monitor.js:
                // the person may have unlocked and stepped away again. Let the
                // next poll decide.
                Poll();
                break;
        }
    }

    private void Poll()
    {
        if (_locked) return; // lock handler already forced idle=true

        var lii = new LASTINPUTINFO();
        lii.cbSize = (uint)Marshal.SizeOf<LASTINPUTINFO>();
        if (!GetLastInputInfo(ref lii)) return; // keep previous value on failure

        var idleMs = (uint)Environment.TickCount - lii.dwTime;
        var idleSeconds = idleMs / 1000;
        SetIdle(idleSeconds >= _idleThresholdSeconds);
    }

    private void SetIdle(bool nowIdle)
    {
        if (nowIdle == _isIdle) return;
        _isIdle = nowIdle;
        IdleChanged?.Invoke(nowIdle);
    }

    public void Dispose()
    {
        _timer.Stop();
        _timer.Dispose();
        SystemEvents.SessionSwitch -= OnSessionSwitch;
    }
}
