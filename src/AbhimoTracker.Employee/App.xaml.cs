using System.IO;
using System.Text.Json;
using System.Threading;
using System.Windows;
using AbhimoTracker.Employee.Models;
using AbhimoTracker.Employee.Services;
using AbhimoTracker.Employee.Views;
using Application = System.Windows.Application;
using MessageBox = System.Windows.MessageBox;
using MessageBoxButton = System.Windows.MessageBoxButton;
using MessageBoxImage = System.Windows.MessageBoxImage;
using MessageBoxResult = System.Windows.MessageBoxResult;

namespace AbhimoTracker.Employee;

public partial class App : Application
{
    private Mutex? _singleInstanceMutex;
    private AppConfig _config = new();
    private CredentialStore _store = null!;
    private ApiClient _api = null!;
    private LiveStatusPusher _pusher = null!;
    private TrayIconService _tray = null!;
    private MonitoringController _monitoring = null!;
    private DashboardWindow? _dashboard;
    private LoginWindow? _loginWindow;

    private static string UserDataDir => Path.Combine(
        Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "AbhimoTracker", "Employee");

    private static string AssetsDir => Path.Combine(AppContext.BaseDirectory, "Assets");

    protected override void OnStartup(StartupEventArgs e)
    {
        base.OnStartup(e);

        RegisterProtocol();

        // Without this, any unhandled exception on the UI thread kills the
        // process with no dialog and no trace -- exactly the "just closes"
        // symptom that's impossible to debug from the outside. Every crash
        // now writes to %LocalAppData%\AbhimoTracker\Employee\crash.log and
        // shows what actually broke instead of silently vanishing.
        DispatcherUnhandledException += (_, args) =>
        {
            LogCrash("DispatcherUnhandledException", args.Exception);
            MessageBox.Show($"Abhimo Tracker hit an unexpected error:\n\n{args.Exception.Message}\n\nDetails were written to crash.log next to your app data folder.",
                "Abhimo Tracker -- error", MessageBoxButton.OK, MessageBoxImage.Error);
            args.Handled = true; // keep the app alive instead of terminating
        };
        AppDomain.CurrentDomain.UnhandledException += (_, args) =>
        {
            if (args.ExceptionObject is Exception ex) LogCrash("AppDomain.UnhandledException", ex);
        };
        TaskScheduler.UnobservedTaskException += (_, args) =>
        {
            LogCrash("UnobservedTaskException", args.Exception);
            args.SetObserved();
        };

        // Single instance: a second launch (e.g. clicking the Start Menu
        // shortcut again) should focus the existing tray app's dashboard
        // instead of running a second copy, same as app.requestSingleInstanceLock()
        // in main.js.
        _singleInstanceMutex = new Mutex(true, "AbhimoTracker.Employee.SingleInstance", out var isNew);
        if (!isNew)
        {
            if (e.Args.Length > 0 && e.Args[0].StartsWith("abhimo://"))
            {
                try
                {
                    using var client = new System.IO.Pipes.NamedPipeClientStream(".", PipeName, System.IO.Pipes.PipeDirection.Out);
                    client.Connect(1000);
                    using var writer = new System.IO.StreamWriter(client);
                    writer.WriteLine(e.Args[0]);
                    writer.Flush();
                }
                catch
                {
                    // Named pipe write error
                }
            }
            else
            {
                MessageBox.Show("Abhimo Tracker is already running -- check your system tray.",
                    "Abhimo Tracker", MessageBoxButton.OK, MessageBoxImage.Information);
            }
            _singleInstanceMutex = null;
            Shutdown();
            return;
        }

        LoadConfig();
        Directory.CreateDirectory(UserDataDir);

        _store = new CredentialStore(UserDataDir);
        _api = new ApiClient(_config);
        _pusher = new LiveStatusPusher(_config.RelayHubUrl);
        _monitoring = new MonitoringController(_config, _store, _api, _pusher);
        _monitoring.StatusChanged += OnStatusChanged;

        _tray = new TrayIconService(AssetsDir);
        _tray.OpenDashboardRequested += OpenDashboard;
        _tray.TestSignInRequested += async () => await TestSignInAsync();
        _tray.SignInManuallyRequested += OpenLoginWindow;
        _tray.ForgetCredentialsRequested += ForgetCredentials;
        _tray.WithdrawConsentRequested += async () => await WithdrawConsentAsync();
        _tray.SignInRequested += async () => await DirectSignInAsync();
        _tray.SignOutRequested += async () => await DirectSignOutAsync();
        _tray.QuitRequested += () => Shutdown();

        StartNamedPipeServer();

        var hasDeepLink = e.Args.Length > 0 && e.Args[0].StartsWith("abhimo://");

        if (hasDeepLink)
        {
            _ = HandleDeepLinkAsync(e.Args[0]);
        }
        else
        {
            var saved = _store.Load();
            if (saved is not null)
            {
                _tray.UpdateStatus($"Signed in as {saved.Username}", "loading", paired: true);
                _monitoring.StartController(UserDataDir);
            }
            else
            {
                OpenLoginWindow();
            }
        }
    }

    private void LoadConfig()
    {
        try
        {
            var path = Path.Combine(AppContext.BaseDirectory, "appsettings.json");
            if (File.Exists(path))
            {
                var loaded = JsonSerializer.Deserialize<AppConfig>(File.ReadAllText(path),
                    new JsonSerializerOptions { PropertyNameCaseInsensitive = true });
                if (loaded is not null) _config = loaded;
            }
        }
        catch
        {
            // Fall back to AppConfig's built-in defaults.
        }
    }

    private void OnStatusChanged(string statusLine, string statusKey)
    {
        Dispatcher.Invoke(() =>
        {
            _tray.UpdateStatus(statusLine, statusKey, _monitoring.PendingQueueCount, paired: _store.Load() is not null);
            _dashboard?.UpdateStatus(statusLine, statusKey);
        });
    }

    private void OpenDashboard()
    {
        if (_dashboard is null)
        {
            _dashboard = new DashboardWindow(_config, _store, _api);
            _dashboard.Closed += (_, _) => _dashboard = null;
        }
        _dashboard.Show();
        _dashboard.Activate();
    }

    private void OpenLoginWindow()
    {
        if (_loginWindow is not null)
        {
            _loginWindow.Activate();
            return;
        }
        _loginWindow = new LoginWindow(_config, _api, _store);
        _loginWindow.Closed += (_, _) => _loginWindow = null;
        _loginWindow.PairedSuccessfully += () =>
        {
            _tray.UpdateStatus($"Signed in as {_store.Load()!.Username}", "loading", paired: true);
            _monitoring.StartController(UserDataDir);
        };
        _loginWindow.Show();
    }

    private async Task TestSignInAsync()
    {
        var saved = _store.Load();
        if (saved is null)
        {
            _tray.ShowBalloon("Abhimo Tracker", "Not paired yet -- use \"Sign in manually\".");
            return;
        }
        var result = await _api.LoginAsync(saved.ApiBase, saved.Username, saved.Password);
        _tray.ShowBalloon("Abhimo Tracker", result.Ok
            ? "Saved credentials are working."
            : $"Saved credentials failed: {result.Error ?? "sign-in rejected"}. Try \"Sign in manually\".");
    }

    private void ForgetCredentials()
    {
        _monitoring.StopController();
        _store.Clear();
        _api.InvalidateCachedToken();
        _tray.UpdateStatus("Not paired", "signedout", paired: false);
        _tray.ShowBalloon("Abhimo Tracker", "Saved credentials removed from this device.");
    }

    /// <summary>Lets the employee withdraw monitoring consent from the tray itself,
    /// mirroring main.js's withdrawConsent(). Stops tracking on this device immediately
    /// rather than waiting for the next status poll to notice the backend now returns 403.</summary>
    private async Task WithdrawConsentAsync()
    {
        var saved = _store.Load();
        if (saved is null)
        {
            _tray.ShowBalloon("Abhimo Tracker", "Not signed in yet.");
            return;
        }

        var confirm = MessageBox.Show(
            "Stop Abhimo Tracker from recording new activity? This stops new application, idle-time, and website tracking immediately. Activity already recorded is not deleted. You can accept the monitoring policy again at any time to resume.",
            "Withdraw monitoring consent",
            MessageBoxButton.YesNo, MessageBoxImage.Warning, MessageBoxResult.No);
        if (confirm != MessageBoxResult.Yes) return;

        var ok = await _api.WithdrawConsentAsync(saved);
        if (ok)
        {
            _tray.UpdateStatus($"Signed in as {saved.Username}", "signedout", paired: true);
            _tray.ShowBalloon("Abhimo Tracker", "Monitoring consent withdrawn. Tracking has stopped.");
        }
        else
        {
            _tray.ShowBalloon("Abhimo Tracker", "Could not withdraw consent -- please try again.");
        }
    }

    private static void LogCrash(string source, Exception ex)
    {
        try
        {
            Directory.CreateDirectory(UserDataDir);
            var path = Path.Combine(UserDataDir, "crash.log");
            File.AppendAllText(path, $"[{DateTime.Now:O}] {source}\n{ex}\n\n");
        }
        catch
        {
            // If we can't even write the crash log, there's nothing further to do here.
        }
    }

    private const string PipeName = "AbhimoTracker.Employee.Pipe";

    private void RegisterProtocol()
    {
        try
        {
            var exePath = Environment.ProcessPath;
            if (string.IsNullOrEmpty(exePath)) return;

            using var key = Microsoft.Win32.Registry.CurrentUser.CreateSubKey(@"Software\Classes\abhimo");
            if (key is not null)
            {
                key.SetValue("", "URL:Abhimo Protocol");
                key.SetValue("URL Protocol", "");

                using var shellKey = key.CreateSubKey(@"shell\open\command");
                if (shellKey is not null)
                {
                    shellKey.SetValue("", $"\"{exePath}\" \"%1\"");
                }
            }
        }
        catch (Exception ex)
        {
            LogCrash("RegisterProtocolException", ex);
        }
    }

    private void StartNamedPipeServer()
    {
        Task.Run(async () =>
        {
            while (true)
            {
                try
                {
                    using var server = new System.IO.Pipes.NamedPipeServerStream(PipeName, System.IO.Pipes.PipeDirection.In);
                    await server.WaitForConnectionAsync();
                    using var reader = new System.IO.StreamReader(server);
                    var message = await reader.ReadLineAsync();
                    if (!string.IsNullOrEmpty(message))
                    {
                        Dispatcher.Invoke(async () =>
                        {
                            await HandleDeepLinkAsync(message);
                        });
                    }
                }
                catch
                {
                    // ignore named pipe server errors
                }
            }
        });
    }

    private async Task HandleDeepLinkAsync(string uriString)
    {
        try
        {
            var token = "";
            var api = "";
            var query = uriString.Split('?').LastOrDefault();
            if (!string.IsNullOrEmpty(query))
            {
                var parts = query.Split('&');
                foreach (var part in parts)
                {
                    var kv = part.Split('=');
                    if (kv.Length == 2)
                    {
                        var key = Uri.UnescapeDataString(kv[0]);
                        var value = Uri.UnescapeDataString(kv[1]);
                        if (key == "token") token = value;
                        else if (key == "api") api = value;
                    }
                }
            }

            if (!string.IsNullOrEmpty(token) && !string.IsNullOrEmpty(api))
            {
                await PairWithTokenAsync(token, api);
            }
        }
        catch (Exception ex)
        {
            LogCrash("HandleDeepLinkException", ex);
        }
    }

    private async Task PairWithTokenAsync(string token, string api)
    {
        _tray.UpdateStatus("Pairing...", "loading", paired: false);
        var result = await _api.ExchangePairingTokenAsync(api, token);
        if (!result.Ok || string.IsNullOrEmpty(result.Username) || string.IsNullOrEmpty(result.Password))
        {
            _tray.UpdateStatus("Pairing failed", "signedout", paired: false);
            _tray.ShowBalloon("Abhimo Tracker", $"Pairing failed: {result.Error ?? "invalid token"}");
            return;
        }

        _store.Save(new StoredCredentials { Username = result.Username, Password = result.Password, ApiBase = api });
        _api.InvalidateCachedToken();
        
        _tray.UpdateStatus($"Signed in as {result.Username}", "loading", paired: true);
        _monitoring.StartController(UserDataDir);
        _tray.ShowBalloon("Abhimo Tracker", "Successfully paired and signed in!");
        
        if (_loginWindow is not null)
        {
            _loginWindow.Close();
            _loginWindow = null;
        }
    }

    private async Task DirectSignInAsync()
    {
        var saved = _store.Load();
        if (saved is null) return;
        _tray.UpdateStatus($"Signed in as {saved.Username}", "loading", paired: true);
        var (ok, error) = await _api.SignInAsync(saved);
        if (!ok)
        {
            _tray.ShowBalloon("Abhimo Tracker", error ?? "Could not sign in.");
        }
        await PollMonitoringStatusOnceAsync();
    }

    private async Task DirectSignOutAsync()
    {
        var saved = _store.Load();
        if (saved is null) return;
        _tray.UpdateStatus($"Signed in as {saved.Username}", "loading", paired: true);
        var (ok, error) = await _api.SignOutAsync(saved);
        if (!ok)
        {
            _tray.ShowBalloon("Abhimo Tracker", error ?? "Could not sign out.");
        }
        await PollMonitoringStatusOnceAsync();
    }

    private async Task PollMonitoringStatusOnceAsync()
    {
        if (_monitoring is not null)
        {
            await _monitoring.ForcePollOnceAsync();
        }
    }

    protected override void OnExit(ExitEventArgs e)
    {
        _monitoring?.Dispose();
        _tray?.Dispose();
        _ = _pusher?.DisposeAsync();
        _singleInstanceMutex?.ReleaseMutex();
        base.OnExit(e);
    }
}
