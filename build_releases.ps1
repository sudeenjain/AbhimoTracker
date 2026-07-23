# Abhimo Tracker Release Packaging Script
# Compiles WPF applications, zips them as self-contained releases, and packages the Chrome Extension.

$ErrorActionPreference = "Stop"

Write-Host "===================================================" -ForegroundColor Cyan
Write-Host "Building and Packaging Abhimo Tracker Releases..." -ForegroundColor Cyan
Write-Host "===================================================" -ForegroundColor Cyan

# 1. Clean existing publish directories and destination files
Write-Host "[1/6] Cleaning build outputs..." -ForegroundColor Yellow
if (Test-Path "publish") { Remove-Item -Recurse -Force "publish" }
if (Test-Path "frontend/downloads/AbhimoTracker-Admin.zip") { Remove-Item -Force "frontend/downloads/AbhimoTracker-Admin.zip" }
if (Test-Path "frontend/downloads/AbhimoTracker-Employee.zip") { Remove-Item -Force "frontend/downloads/AbhimoTracker-Employee.zip" }
if (Test-Path "frontend/downloads/AbhimoTracker-Extension.zip") { Remove-Item -Force "frontend/downloads/AbhimoTracker-Extension.zip" }

# Ensure downloads directory exists
New-Item -ItemType Directory -Force -Path "frontend/downloads" | Out-Null

# 2. Build and Publish Employee desktop app
Write-Host "[2/6] Compiling Employee App (WPF)..." -ForegroundColor Yellow
dotnet publish src/AbhimoTracker.Employee -c Release -r win-x64 --self-contained true -p:PublishSingleFile=false -o publish/Employee

# 3. Build and Publish Admin desktop app
Write-Host "[3/6] Compiling Admin App (WPF)..." -ForegroundColor Yellow
dotnet publish src/AbhimoTracker.Admin -c Release -r win-x64 --self-contained true -p:PublishSingleFile=false -o publish/Admin

# 4. Zip Employee Release
Write-Host "[4/6] Packaging Employee App to ZIP..." -ForegroundColor Yellow
Compress-Archive -Path publish/Employee/* -DestinationPath frontend/downloads/AbhimoTracker-Employee.zip -Force

# 5. Zip Admin Release
Write-Host "[5/6] Packaging Admin App to ZIP..." -ForegroundColor Yellow
Compress-Archive -Path publish/Admin/* -DestinationPath frontend/downloads/AbhimoTracker-Admin.zip -Force

# 6. Zip Chrome Extension
Write-Host "[6/6] Packaging Chrome Extension to ZIP..." -ForegroundColor Yellow
# Exclude node_modules, .git, and test folders if any
Compress-Archive -Path browser-extension/* -DestinationPath frontend/downloads/AbhimoTracker-Extension.zip -Force

# 7. Build and Publish Self-Extracting Installers
Write-Host "[7/8] Compiling Self-Extracting Employee Installer (WPF)..." -ForegroundColor Yellow
dotnet publish src/AbhimoTracker.Installer -c Release -r win-x64 --self-contained true -p:PublishSingleFile=true -o publish/EmployeeInstaller
Copy-Item -Path publish/EmployeeInstaller/AbhimoTrackerSetup.exe -Destination frontend/downloads/AbhimoTracker-Employee-Setup.exe -Force

Write-Host "[8/8] Compiling Self-Extracting Admin Installer (WPF)..." -ForegroundColor Yellow
dotnet publish src/AbhimoTracker.AdminInstaller -c Release -r win-x64 --self-contained true -p:PublishSingleFile=true -o publish/AdminInstaller
Copy-Item -Path publish/AdminInstaller/AbhimoTrackerAdminSetup.exe -Destination frontend/downloads/AbhimoTracker-Admin-Setup.exe -Force

# Copy Admin Installer to P:\ root directory as requested by user
Write-Host "Copying Admin setup to P:\ drive..." -ForegroundColor Yellow
Copy-Item -Path publish/AdminInstaller/AbhimoTrackerAdminSetup.exe -Destination P:/AbhimoTrackerAdminSetup.exe -Force

Write-Host "===================================================" -ForegroundColor Green
Write-Host "All releases and self-extracting installers packaged successfully!" -ForegroundColor Green
Write-Host "Outputs placed in: frontend/downloads/" -ForegroundColor Green
Write-Host " - AbhimoTracker-Employee-Setup.exe (Self-Extracting Installer)" -ForegroundColor Green
Write-Host " - AbhimoTracker-Admin-Setup.exe (Self-Extracting Installer)" -ForegroundColor Green
Write-Host " - AbhimoTracker-Employee.zip" -ForegroundColor Green
Write-Host " - AbhimoTracker-Admin.zip" -ForegroundColor Green
Write-Host " - AbhimoTracker-Extension.zip" -ForegroundColor Green
Write-Host "And placed in P:\ root:" -ForegroundColor Green
Write-Host " - P:\AbhimoTrackerAdminSetup.exe" -ForegroundColor Green
Write-Host "===================================================" -ForegroundColor Green
