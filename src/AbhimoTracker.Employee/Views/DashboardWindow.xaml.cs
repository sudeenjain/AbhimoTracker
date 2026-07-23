using System.Windows;
using AbhimoTracker.Employee.Models;
using AbhimoTracker.Employee.Services;

namespace AbhimoTracker.Employee.Views;

/// <summary>
/// What the employee sees when they open the app -- sign in / sign out,
/// current status -- fulfilling the master prompt's "no browser opened at
/// any point" requirement natively instead of embedding attendance.html.
/// Idle/app tracking itself keeps running via MonitoringController
/// regardless of whether this window is open, exactly like the tray app.
/// </summary>
public partial class DashboardWindow : Window
{
    private readonly ApiClient _api;
    private readonly CredentialStore _store;

    public DashboardWindow(AppConfig config, CredentialStore store, ApiClient api)
    {
        InitializeComponent();
        _store = store;
        _api = api;

        var creds = _store.Load();
        UsernameText.Text = creds is not null ? $"Signed in as {creds.Username}" : "Not paired";
        _ = RefreshAsync();
    }

    public void UpdateStatus(string statusLine, string statusKey)
    {
        Dispatcher.Invoke(() =>
        {
            UsernameText.Text = statusLine;
            StatusText.Text = $"Status: {statusKey}";
        });
    }

    private async Task RefreshAsync()
    {
        var creds = _store.Load();
        if (creds is null) return;
        var (ok, signIn, signOut, _) = await _api.GetMonitoringStatusAsync(creds);
        if (!ok) return;

        if (signIn.HasValue && !signOut.HasValue)
        {
            SessionText.Text = $"Signed in at {signIn:t}";
            SignInButton.IsEnabled = false;
            SignOutButton.IsEnabled = true;
        }
        else
        {
            SessionText.Text = signOut.HasValue ? $"Signed out at {signOut:t}" : "Not signed in today";
            SignInButton.IsEnabled = true;
            SignOutButton.IsEnabled = false;
        }
    }

    private async void SignInButton_Click(object sender, RoutedEventArgs e)
    {
        var creds = _store.Load();
        if (creds is null) return;
        MessageText.Text = "";
        var (ok, error) = await _api.SignInAsync(creds);
        if (!ok) { MessageText.Text = error ?? "Could not sign in."; return; }
        await RefreshAsync();
    }

    private async void SignOutButton_Click(object sender, RoutedEventArgs e)
    {
        var creds = _store.Load();
        if (creds is null) return;
        MessageText.Text = "";
        var (ok, error) = await _api.SignOutAsync(creds);
        if (!ok) { MessageText.Text = error ?? "Could not sign out."; return; }
        await RefreshAsync();
    }

    /// <summary>Hide instead of close -- same "tray app stays alive" posture as
    /// main.js's 'window-all-closed' handler.</summary>
    protected override void OnClosing(System.ComponentModel.CancelEventArgs e)
    {
        e.Cancel = true;
        Hide();
    }
}
