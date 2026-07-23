@echo off
title Attendance System Stack Runner
echo ===================================================
echo Starting Attendance System Services...
echo ===================================================

REM 1. Start MySQL database if not already running
echo [1/5] Checking MySQL process...
tasklist | findstr /i mysqld.exe > nul
if %errorlevel% neq 0 (
    echo Starting MySQL Server...
    start /min "MySQL Server" C:\xampp\mysql\bin\mysqld.exe --defaults-file=C:\xampp\mysql\bin\my.ini --standalone
    timeout /t 5 /nobreak > nul
) else (
    echo MySQL is already running.
)

REM 2. Run migrations
echo [2/5] Verifying and running migrations...
cd backend
php bin/migrate.php
if %errorlevel% neq 0 (
    echo ERROR: Database migration failed.
    cd ..
    pause
    exit /b %errorlevel%
)
cd ..

REM 3. Launch Backend API Server
echo [3/5] Starting PHP Backend API on http://127.0.0.1:8080...
start /min "Backend API" php -S 127.0.0.1:8080 -t backend/public

REM 4. Launch Frontend Web Server
echo [4/5] Starting PHP Frontend on http://127.0.0.1:8090...
start /min "Frontend Web" php -S 127.0.0.1:8090 -t frontend

REM 5. Open Web Page
timeout /t 2 /nobreak > nul
echo [5/5] Launching user interface in browser...
start http://127.0.0.1:8090/login.html

echo ===================================================
echo Services started successfully!
echo.
echo  - Backend API:  http://127.0.0.1:8080
echo  - Frontend UI:  http://127.0.0.1:8090
echo.
echo ===================================================
echo Press ANY KEY in this terminal to STOP all services.
echo ===================================================
pause > nul

echo.
echo Stopping services...
taskkill /f /fi "WINDOWTITLE eq Backend API" > nul 2>&1
taskkill /f /fi "WINDOWTITLE eq Frontend Web" > nul 2>&1
echo Done! All services stopped.
timeout /t 2 /nobreak > nul
