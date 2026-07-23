using System;
using System.IO;
using System.IO.Compression;
using System.Linq;
using System.Reflection;
using System.Threading.Tasks;
using System.Windows;
using System.Windows.Controls;
using System.Windows.Input;
using System.Windows.Media;

namespace AbhimoTracker.AdminInstaller;

public class Program : Application
{
    [STAThread]
    public static void Main()
    {
        var app = new Program();
        app.Run(new InstallerWindow());
    }
}

public class InstallerWindow : Window
{
    private readonly string _appName = "Abhimo Admin";
    private readonly string _exeName = "AbhimoTracker.Admin.exe";
    private readonly bool _registerProtocol = false;

    private readonly TextBox _pathTextBox;
    private readonly ProgressBar _progressBar;
    private readonly TextBlock _statusText;
    private readonly Button _installButton;
    private readonly Button _browseButton;

    public InstallerWindow()
    {
        Title = $"{_appName} Setup";
        Width = 550;
        Height = 350;
        WindowStartupLocation = WindowStartupLocation.CenterScreen;
        WindowStyle = WindowStyle.None;
        AllowsTransparency = true;
        Background = new SolidColorBrush(Color.FromRgb(15, 23, 42)); // Slate-900
        BorderBrush = new SolidColorBrush(Color.FromRgb(59, 130, 246)); // Blue border
        BorderThickness = new Thickness(1);

        // Main Layout
        var mainGrid = new Grid();
        mainGrid.RowDefinitions.Add(new RowDefinition { Height = new GridLength(40) }); // Title Bar
        mainGrid.RowDefinitions.Add(new RowDefinition { Height = new GridLength(1, GridUnitType.Star) }); // Content

        // Custom Title Bar
        var titleBar = new Grid { Background = new SolidColorBrush(Color.FromRgb(30, 41, 59)) };
        titleBar.MouseLeftButtonDown += (s, e) => { if (e.LeftButton == MouseButtonState.Pressed) DragMove(); };

        var titleText = new TextBlock
        {
            Text = $"{_appName} Installer",
            Foreground = new SolidColorBrush(Color.FromRgb(248, 250, 252)),
            FontFamily = new FontFamily("Outfit, Segoe UI"),
            FontSize = 14,
            FontWeight = FontWeights.SemiBold,
            VerticalAlignment = VerticalAlignment.Center,
            Margin = new Thickness(15, 0, 0, 0)
        };
        titleBar.Children.Add(titleText);

        var closeButton = new Button
        {
            Content = "✕",
            Foreground = new SolidColorBrush(Color.FromRgb(148, 163, 184)),
            Background = Brushes.Transparent,
            BorderThickness = new Thickness(0),
            Width = 40,
            HorizontalAlignment = HorizontalAlignment.Right,
            Cursor = Cursors.Hand
        };
        closeButton.Click += (s, e) => Close();
        titleBar.Children.Add(closeButton);

        mainGrid.Children.Add(titleBar);
        Grid.SetRow(titleBar, 0);

        // Content Area
        var contentStack = new StackPanel { Margin = new Thickness(30, 25, 30, 25) };

        var headerText = new TextBlock
        {
            Text = $"Install {_appName}",
            Foreground = new SolidColorBrush(Color.FromRgb(248, 250, 252)),
            FontFamily = new FontFamily("Outfit, Segoe UI"),
            FontSize = 22,
            FontWeight = FontWeights.Bold,
            Margin = new Thickness(0, 0, 0, 8)
        };
        contentStack.Children.Add(headerText);

        var subtext = new TextBlock
        {
            Text = $"Choose the folder where you want to install and extract {_appName}.",
            Foreground = new SolidColorBrush(Color.FromRgb(148, 163, 184)),
            FontFamily = new FontFamily("Outfit, Segoe UI"),
            FontSize = 12,
            TextWrapping = TextWrapping.Wrap,
            Margin = new Thickness(0, 0, 0, 20)
        };
        contentStack.Children.Add(subtext);

        // Path selection row
        var pathGrid = new Grid();
        pathGrid.ColumnDefinitions.Add(new ColumnDefinition { Width = new GridLength(1, GridUnitType.Star) });
        pathGrid.ColumnDefinitions.Add(new ColumnDefinition { Width = new GridLength(90) });

        var defaultPath = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "Programs", "AbhimoAdmin");
        _pathTextBox = new TextBox
        {
            Text = defaultPath,
            Background = new SolidColorBrush(Color.FromRgb(30, 41, 59)),
            Foreground = new SolidColorBrush(Color.FromRgb(248, 250, 252)),
            BorderBrush = new SolidColorBrush(Color.FromRgb(71, 85, 105)),
            BorderThickness = new Thickness(1),
            Padding = new Thickness(8, 6, 8, 6),
            FontFamily = new FontFamily("Segoe UI"),
            FontSize = 12,
            VerticalContentAlignment = VerticalAlignment.Center
        };
        pathGrid.Children.Add(_pathTextBox);
        Grid.SetColumn(_pathTextBox, 0);

        _browseButton = new Button
        {
            Content = "Browse...",
            Background = new SolidColorBrush(Color.FromRgb(51, 65, 85)),
            Foreground = new SolidColorBrush(Color.FromRgb(248, 250, 252)),
            BorderThickness = new Thickness(0),
            Margin = new Thickness(10, 0, 0, 0),
            Cursor = Cursors.Hand
        };
        _browseButton.Click += (s, e) => BrowseFolder();
        pathGrid.Children.Add(_browseButton);
        Grid.SetColumn(_browseButton, 1);

        contentStack.Children.Add(pathGrid);

        // Progress and status
        _statusText = new TextBlock
        {
            Text = "Ready to install.",
            Foreground = new SolidColorBrush(Color.FromRgb(148, 163, 184)),
            FontFamily = new FontFamily("Outfit, Segoe UI"),
            FontSize = 11,
            Margin = new Thickness(0, 15, 0, 2)
        };
        contentStack.Children.Add(_statusText);

        _progressBar = new ProgressBar
        {
            Height = 6,
            Background = new SolidColorBrush(Color.FromRgb(30, 41, 59)),
            Foreground = new SolidColorBrush(Color.FromRgb(59, 130, 246)),
            BorderThickness = new Thickness(0),
            Minimum = 0,
            Maximum = 100,
            Visibility = Visibility.Collapsed,
            Margin = new Thickness(0, 0, 0, 15)
        };
        contentStack.Children.Add(_progressBar);

        // Action row
        var actionGrid = new Grid { Margin = new Thickness(0, 10, 0, 0) };
        actionGrid.ColumnDefinitions.Add(new ColumnDefinition { Width = new GridLength(1, GridUnitType.Star) });
        actionGrid.ColumnDefinitions.Add(new ColumnDefinition { Width = new GridLength(140) });

        _installButton = new Button
        {
            Content = "Install & Launch",
            Background = new SolidColorBrush(Color.FromRgb(59, 130, 246)),
            Foreground = Brushes.White,
            FontWeight = FontWeights.Bold,
            BorderThickness = new Thickness(0),
            Height = 35,
            Cursor = Cursors.Hand
        };
        _installButton.Click += async (s, e) => await StartInstallation();
        actionGrid.Children.Add(_installButton);
        Grid.SetColumn(_installButton, 1);

        contentStack.Children.Add(actionGrid);

        mainGrid.Children.Add(contentStack);
        Grid.SetRow(contentStack, 1);

        Content = mainGrid;
    }

    private void BrowseFolder()
    {
        var dialog = new Microsoft.Win32.OpenFolderDialog
        {
            Title = "Select Destination Directory",
            InitialDirectory = _pathTextBox.Text
        };
        if (dialog.ShowDialog() == true)
        {
            _pathTextBox.Text = dialog.FolderName;
        }
    }

    private async Task StartInstallation()
    {
        var destFolder = _pathTextBox.Text.Trim();
        if (string.IsNullOrEmpty(destFolder))
        {
            MessageBox.Show("Please specify a valid installation folder.", "Error", MessageBoxButton.OK, MessageBoxImage.Warning);
            return;
        }

        _pathTextBox.IsEnabled = false;
        _browseButton.IsEnabled = false;
        _installButton.IsEnabled = false;
        _progressBar.Visibility = Visibility.Visible;
        _progressBar.IsIndeterminate = true;
        _statusText.Text = "Preparing installation...";

        await Task.Run(() =>
        {
            try
            {
                // Create directory
                UpdateStatus("Creating destination folder...", 20);
                Directory.CreateDirectory(destFolder);

                // Get embedded resource stream
                UpdateStatus("Extracting files...", 40);
                var assembly = Assembly.GetExecutingAssembly();
                var resourceName = assembly.GetManifestResourceNames().FirstOrDefault(n => n.EndsWith("app.zip"));
                if (resourceName is null)
                {
                    throw new Exception("Embedded application payload was not found.");
                }

                using (var zipStream = assembly.GetManifestResourceStream(resourceName))
                {
                    if (zipStream is null) throw new Exception("Payload stream is empty.");
                    
                    // Unzip to folder
                    using var archive = new ZipArchive(zipStream);
                    
                    // Delete old files in dest folder if any
                    foreach (var file in Directory.GetFiles(destFolder))
                    {
                        try { File.Delete(file); } catch { }
                    }
                    foreach (var dir in Directory.GetDirectories(destFolder))
                    {
                        try { Directory.Delete(dir, true); } catch { }
                    }

                    var totalEntries = archive.Entries.Count;
                    var extractedCount = 0;
                    foreach (var entry in archive.Entries)
                    {
                        var entryPath = Path.Combine(destFolder, entry.FullName);
                        var entryDir = Path.GetDirectoryName(entryPath);
                        if (entryDir is not null) Directory.CreateDirectory(entryDir);

                        if (!string.IsNullOrEmpty(entry.Name))
                        {
                            entry.ExtractToFile(entryPath, overwrite: true);
                        }
                        
                        extractedCount++;
                        var progress = 40 + (int)((extractedCount / (double)totalEntries) * 40);
                        UpdateStatus($"Extracting: {entry.Name}", progress);
                    }
                }

                // Register protocol
                var targetExePath = Path.Combine(destFolder, _exeName);
                if (_registerProtocol)
                {
                    UpdateStatus("Registering URI protocol scheme...", 85);
                    RegisterProtocolSchema(targetExePath);
                }

                // Create Shortcut
                UpdateStatus("Creating desktop shortcut...", 95);
                var desktopFolder = Environment.GetFolderPath(Environment.SpecialFolder.DesktopDirectory);
                var shortcutPath = Path.Combine(desktopFolder, $"{_appName}.lnk");
                CreateDesktopShortcut(targetExePath, shortcutPath);

                // Complete
                UpdateStatus("Installation complete. Launching...", 100);
                
                System.Diagnostics.Process.Start(new System.Diagnostics.ProcessStartInfo
                {
                    FileName = targetExePath,
                    WorkingDirectory = destFolder,
                    UseShellExecute = true
                });

                Dispatcher.Invoke(() => Close());
            }
            catch (Exception ex)
            {
                Dispatcher.Invoke(() =>
                {
                    MessageBox.Show($"Installation failed:\n\n{ex.Message}", "Error", MessageBoxButton.OK, MessageBoxImage.Error);
                    _pathTextBox.IsEnabled = true;
                    _browseButton.IsEnabled = true;
                    _installButton.IsEnabled = true;
                    _progressBar.Visibility = Visibility.Collapsed;
                    _statusText.Text = "Installation failed.";
                });
            }
        });
    }

    private void UpdateStatus(string message, int progress)
    {
        Dispatcher.Invoke(() =>
        {
            _statusText.Text = message;
            _progressBar.IsIndeterminate = false;
            _progressBar.Value = progress;
        });
    }

    private static void RegisterProtocolSchema(string exePath)
    {
        try
        {
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
        catch { }
    }

    private static void CreateDesktopShortcut(string targetPath, string shortcutPath)
    {
        try
        {
            var command = $"$WshShell = New-Object -ComObject WScript.Shell; $Shortcut = $WshShell.CreateShortcut('{shortcutPath}'); $Shortcut.TargetPath = '{targetPath}'; $Shortcut.Save()";
            using var process = System.Diagnostics.Process.Start(new System.Diagnostics.ProcessStartInfo
            {
                FileName = "powershell",
                Arguments = $"-NoProfile -Command \"{command}\"",
                CreateNoWindow = true,
                UseShellExecute = false
            });
            process?.WaitForExit();
        }
        catch { }
    }
}
