using System.Drawing;
using System.IO;
using System.Windows.Forms;
using Application = System.Windows.Application;

namespace AbhimoTracker.Employee.Services;

/// <summary>
/// Tray icon + context menu, functionally identical to desktop-tray/main.js's
/// createTray()/updateTrayStatus(): Test saved sign-in / Sign in manually /
/// Forget saved credentials / Withdraw monitoring consent / Quit, plus an
/// icon that reflects active/idle/offline/signedout/loading state.
/// </summary>
public sealed class TrayIconService : IDisposable
{
    private static readonly Dictionary<string, string> StatusIconFile = new()
    {
        ["active"] = "icon-active-16.png",
        ["idle"] = "icon-idle-16.png",
        ["offline"] = "icon-offline-16.png",
        ["signedout"] = "icon-signedout-16.png",
        ["loading"] = "icon-loading-16.png",
    };

    private readonly NotifyIcon _notifyIcon;
    private readonly string _assetsDir;

    public event Action? OpenDashboardRequested;
    public event Action? TestSignInRequested;
    public event Action? SignInManuallyRequested;
    public event Action? ForgetCredentialsRequested;
    public event Action? WithdrawConsentRequested;
    public event Action? SignInRequested;
    public event Action? SignOutRequested;
    public event Action? QuitRequested;

    private ToolStripMenuItem? _statusItem;
    private ToolStripMenuItem? _pendingItem;
    private ToolStripMenuItem? _testSignInItem;
    private ToolStripMenuItem? _forgetItem;
    private ToolStripMenuItem? _withdrawItem;
    private ToolStripMenuItem? _signInItem;
    private ToolStripMenuItem? _signOutItem;

    public TrayIconService(string assetsDir)
    {
        _assetsDir = assetsDir;
        _notifyIcon = new NotifyIcon
        {
            Visible = true,
            Text = "Abhimo Tracker",
        };
        _notifyIcon.DoubleClick += (_, _) => OpenDashboardRequested?.Invoke();
        SetIcon("tray-icon.png");
        BuildMenu(paired: false);
        UpdateStatus("Not paired", "loading");
    }

    public void UpdateStatus(string statusLine, string statusKey, int? pendingCount = null, bool paired = true)
    {
        if (StatusIconFile.TryGetValue(statusKey, out var file)) SetIcon(file);
        _notifyIcon.Text = Truncate($"Abhimo Tracker -- {statusLine}", 127); // NotifyIcon.Text has a 127-char limit

        if (_statusItem is null) BuildMenu(paired);
        _statusItem!.Text = statusLine;
        _pendingItem!.Visible = pendingCount is > 0;
        if (pendingCount is > 0) _pendingItem.Text = $"{pendingCount} activity update(s) queued";
        _testSignInItem!.Enabled = paired;
        _forgetItem!.Enabled = paired;
        _withdrawItem!.Enabled = paired;

        var isSessionActive = statusKey == "active" || statusKey == "idle";
        _signInItem!.Enabled = paired && !isSessionActive;
        _signOutItem!.Enabled = paired && isSessionActive;
    }

    private void BuildMenu(bool paired)
    {
        var menu = new ContextMenuStrip();

        _statusItem = new ToolStripMenuItem("Not paired") { Enabled = false };
        menu.Items.Add(_statusItem);

        _pendingItem = new ToolStripMenuItem("") { Enabled = false, Visible = false };
        menu.Items.Add(_pendingItem);

        menu.Items.Add(new ToolStripSeparator());

        _signInItem = new ToolStripMenuItem("Sign In (Start Session)") { Enabled = paired };
        _signInItem.Click += (_, _) => SignInRequested?.Invoke();
        menu.Items.Add(_signInItem);

        _signOutItem = new ToolStripMenuItem("Sign Out (Stop Session)") { Enabled = paired };
        _signOutItem.Click += (_, _) => SignOutRequested?.Invoke();
        menu.Items.Add(_signOutItem);

        menu.Items.Add(new ToolStripSeparator());

        var openDashboard = new ToolStripMenuItem("Open Abhimo Tracker");
        openDashboard.Click += (_, _) => OpenDashboardRequested?.Invoke();
        menu.Items.Add(openDashboard);

        _testSignInItem = new ToolStripMenuItem("Test saved sign-in") { Enabled = paired };
        _testSignInItem.Click += (_, _) => TestSignInRequested?.Invoke();
        menu.Items.Add(_testSignInItem);

        var manualLogin = new ToolStripMenuItem("Sign in manually");
        manualLogin.Click += (_, _) => SignInManuallyRequested?.Invoke();
        menu.Items.Add(manualLogin);

        _forgetItem = new ToolStripMenuItem("Forget saved credentials") { Enabled = paired };
        _forgetItem.Click += (_, _) => ForgetCredentialsRequested?.Invoke();
        menu.Items.Add(_forgetItem);

        _withdrawItem = new ToolStripMenuItem("Withdraw monitoring consent...") { Enabled = paired };
        _withdrawItem.Click += (_, _) => WithdrawConsentRequested?.Invoke();
        menu.Items.Add(_withdrawItem);

        menu.Items.Add(new ToolStripSeparator());

        var quit = new ToolStripMenuItem("Quit Abhimo Tracker");
        quit.Click += (_, _) => QuitRequested?.Invoke();
        menu.Items.Add(quit);

        _notifyIcon.ContextMenuStrip = menu;
    }

    public void ShowBalloon(string title, string body)
    {
        _notifyIcon.BalloonTipTitle = title;
        _notifyIcon.BalloonTipText = body;
        _notifyIcon.ShowBalloonTip(4000);
    }

    private void SetIcon(string fileName)
    {
        try
        {
            var path = Path.Combine(_assetsDir, fileName);
            if (!File.Exists(path)) return;
            using var bmp = new Bitmap(path);
            var hIcon = bmp.GetHicon();
            _notifyIcon.Icon = Icon.FromHandle(hIcon);
        }
        catch
        {
            // Fall back silently to whatever icon is already showing.
        }
    }

    private static string Truncate(string s, int max) => s.Length <= max ? s : s[..max];

    public void Dispose()
    {
        _notifyIcon.Visible = false;
        _notifyIcon.Dispose();
    }
}
