; Builds AbhimoTracker-Admin-Setup.exe -- see employee.iss for the general
; approach. Build order:
;   1. dotnet publish ..\src\AbhimoTracker.Admin\AbhimoTracker.Admin.csproj ^
;        -c Release -r win-x64 --self-contained true ^
;        -p:PublishSingleFile=false -o publish\Admin
;   2. iscc installer\admin.iss
;
; Requires the WebView2 Runtime on the target machine (preinstalled on
; current Windows 10/11; the [Files]/[Run] section below can optionally
; bundle the Evergreen Bootstrapper if you need to support older images --
; see https://developer.microsoft.com/microsoft-edge/webview2/).

#define MyAppName "Abhimo Admin"
#define MyAppVersion "1.0.0"
#define MyAppPublisher "Abhimo Technologies"
#define MyAppExeName "AbhimoTracker.Admin.exe"
#define PublishDir "..\publish\Admin"

[Setup]
AppId={{9F3B2E7D-1A44-4C8B-9E2F-ABHIMOADM002}
AppName={#MyAppName}
AppVersion={#MyAppVersion}
AppPublisher={#MyAppPublisher}
DefaultDirName={autopf}\AbhimoTracker\Admin
DefaultGroupName=Abhimo Admin
DisableProgramGroupPage=yes
OutputDir=..\dist
OutputBaseFilename=AbhimoTracker-Admin-Setup
Compression=lzma
SolidCompression=yes
ArchitecturesInstallIn64BitMode=x64
SignTool=

[Languages]
Name: "english"; MessagesFile: "compiler:Default.isl"

[Tasks]
Name: "desktopicon"; Description: "Create a &desktop shortcut"; GroupDescription: "Additional shortcuts:"; Flags: unchecked

[Files]
Source: "{#PublishDir}\*"; DestDir: "{app}"; Flags: ignoreversion recursesubdirs createallsubdirs

[Icons]
Name: "{group}\Abhimo Admin"; Filename: "{app}\{#MyAppExeName}"
Name: "{group}\Uninstall Abhimo Admin"; Filename: "{uninstallexe}"
Name: "{autodesktop}\Abhimo Admin"; Filename: "{app}\{#MyAppExeName}"; Tasks: desktopicon

[Run]
Filename: "{app}\{#MyAppExeName}"; Description: "Launch Abhimo Admin now"; Flags: nowait postinstall skipifsilent
