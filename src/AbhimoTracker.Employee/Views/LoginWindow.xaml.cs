using System.Windows;
using AbhimoTracker.Employee.Models;
using AbhimoTracker.Employee.Services;

namespace AbhimoTracker.Employee.Views;

/// <summary>
/// In-app login screen, replacing the browser-based abhimo:// deep-link
/// hand-off with a manual username/password form -- same job as
/// login-window.html + preload.js's manual-login IPC call, and same
/// backend call (POST /api/login) that persistCredentials() in main.js used.
/// </summary>
public partial class LoginWindow : Window
{
    private readonly AppConfig _config;
    private readonly ApiClient _api;
    private readonly CredentialStore _store;

    public event Action? PairedSuccessfully;

    public LoginWindow(AppConfig config, ApiClient api, CredentialStore store)
    {
        InitializeComponent();
        _config = config;
        _api = api;
        _store = store;
    }

    private async void SignInButton_Click(object sender, RoutedEventArgs e)
    {
        var username = UsernameBox.Text.Trim();
        var password = PasswordBox.Password;
        if (username == "" || password == "")
        {
            ShowError("Enter your username and password.");
            return;
        }

        SignInButton.IsEnabled = false;
        ErrorText.Visibility = Visibility.Collapsed;

        var apiBase = _store.Load()?.ApiBase ?? _config.DefaultApiBase;
        var result = await _api.LoginAsync(apiBase, username, password);

        SignInButton.IsEnabled = true;

        if (!result.Ok)
        {
            ShowError(result.Error ?? "Invalid credentials.");
            return;
        }

        _store.Save(new StoredCredentials { Username = username, Password = password, ApiBase = apiBase });
        PairedSuccessfully?.Invoke();
        Close();
    }

    private void ShowError(string message)
    {
        ErrorText.Text = message;
        ErrorText.Visibility = Visibility.Visible;
    }

    private void CancelButton_Click(object sender, RoutedEventArgs e) => Close();
}
