@echo off
title PulseMark - All-in-One System Launcher
color 0b

echo =========================================================================
echo               PulseMark Dual Portal & Biometric System
echo =========================================================================
echo.
echo [1/3] Checking MySQL Database connection...
echo (Make sure XAMPP / MySQL is started on port 3306)
echo.

echo [2/3] Starting ZKTeco Mini Hardware Bridge (Port 9000)...
start "PulseMark - ZKTeco Hardware Bridge" cmd /k "color 0a && python zk_bridge.py"

timeout /t 2 >nul

echo [3/3] Starting PHP Web Server (http://localhost:8000)...
start "PulseMark - Web Application Server" cmd /k "color 09 && php -S localhost:8000"

timeout /t 2 >nul

echo.
echo =========================================================================
echo   All services launched successfully!
echo   Opening PulseMark in your default browser...
echo =========================================================================
echo.

start http://localhost:8000

echo System running. You can minimize this window.
pause
