@echo off
setlocal enabledelayedexpansion

set "SCRIPT_DIR=%~dp0"
set "PS_SCRIPT=%SCRIPT_DIR%install-tools.ps1"
set "PS_ARGS=%*"

if not exist "%PS_SCRIPT%" (
    echo [Error] install-tools.ps1 was not found:
    echo         %PS_SCRIPT%
    pause
    exit /b 1
)

if "%~1"=="" (
    cls
    echo ================================================
    echo   Embedded Video Downloader - Installation
    echo ================================================
    echo.
    echo 1^) Install yt-dlp + ffmpeg
    echo 2^) Install yt-dlp only
    echo 3^) Install ffmpeg only
    echo 4^) Cancel
    echo.
    choice /C 1234 /N /M "Please choose [1-4]: "
    if errorlevel 4 exit /b 0
    if errorlevel 3 set "PS_ARGS=-OnlyFfmpeg"
    if errorlevel 2 set "PS_ARGS=-OnlyYtDlp"
    if errorlevel 1 set "PS_ARGS="
)

echo.
echo [Info] Starting installation...
echo [Info] PowerShell arguments: %PS_ARGS%
echo.

powershell -NoProfile -ExecutionPolicy Bypass -File "%PS_SCRIPT%" %PS_ARGS%
set "EXIT_CODE=%ERRORLEVEL%"

echo.
if not "%EXIT_CODE%"=="0" (
    echo [Error] Installation failed. Exit code: %EXIT_CODE%
    pause
    exit /b %EXIT_CODE%
)

echo [OK] Installation completed.
pause
exit /b 0
