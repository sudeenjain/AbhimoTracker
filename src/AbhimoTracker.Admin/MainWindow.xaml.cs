using System.IO;
using System.Windows;
using Microsoft.Web.WebView2.Core;

namespace AbhimoTracker.Admin;

/// <summary>
/// Hosts the existing frontend/abhimo_admin/*.html pages unmodified (plus one
/// small additive script, see wwwroot/js/live-status-client.js) inside a
/// native window -- no URL bar, no browser chrome, and "Download desktop
/// app"-style browser hand-offs never happen because NewWindowRequested is
/// intercepted below and kept inside this same WebView2 surface.
/// </summary>
public partial class MainWindow : Window
{
    private const string VirtualHost = "abhimo-admin.local";

    public MainWindow()
    {
        InitializeComponent();
        Loaded += async (_, _) => await InitializeWebViewAsync();
    }

    private async Task InitializeWebViewAsync()
    {
        // Persistent per-user profile (separate from any real browser's own
        // profile) so localStorage's auth_token survives across app
        // restarts -- login.html's existing logic already relies on this.
        var userDataFolder = Path.Combine(
            Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
            "AbhimoTracker", "Admin", "WebView2");
        Directory.CreateDirectory(userDataFolder);

        var env = await CoreWebView2Environment.CreateAsync(userDataFolder: userDataFolder);
        await Browser.EnsureCoreWebView2Async(env);

        var wwwroot = Path.Combine(AppContext.BaseDirectory, "wwwroot");
        Browser.CoreWebView2.SetVirtualHostNameToFolderMapping(
            VirtualHost, wwwroot, CoreWebView2HostResourceAccessKind.Allow);

        // Injects the same override hooks api.js and live-status-client.js
        // already check for, before any page script runs -- one place to
        // change per-environment values instead of a separate build per
        // deployment (matches the master prompt's "configurable/buildable
        // per environment, don't hardcode a dev-only value" requirement).
        var config = App.Config;
        await Browser.CoreWebView2.AddScriptToExecuteOnDocumentCreatedAsync(
            $"window.__ABHIMO_API_BASE__ = {JsonString(config.ApiBase)};" +
            $"window.__ABHIMO_HUB_URL__ = {JsonString(config.RelayHubUrl)};");

        // Keep every navigation inside this window -- an admin page opening
        // something with target="_blank" (or window.open) must not spawn an
        // actual browser window, which would violate "no browser opened at
        // any point".
        Browser.CoreWebView2.NewWindowRequested += (_, args) =>
        {
            args.Handled = true;
            Browser.CoreWebView2.Navigate(args.Uri);
        };

        Browser.CoreWebView2.Navigate($"https://{VirtualHost}/abhimo_admin/login.html");
    }

    private static string JsonString(string value) => System.Text.Json.JsonSerializer.Serialize(value);
}
