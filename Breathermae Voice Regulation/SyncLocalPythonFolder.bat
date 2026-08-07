@echo off
setlocal

REM === EDIT THIS to your OneDrive repo root ===
set "SRC=C:\Users\jprocasky\OneDrive\z-Github\breathermae\Breathermae Voice Regulation"
set "DST=C:\BioVoice\breathermae"

if not exist "%SRC%" (
  echo Source not found: %SRC%
  pause
  exit /b 1
)
if not exist "%DST%" (
  echo Creating destination: %DST%
  mkdir "%DST%"
)

echo Syncing NEWER files only
echo   From: %SRC%
echo   To:   %DST%
echo.

REM /E   = include subfolders
REM /XO  = exclude older  (only copy if source is newer or dest missing)
REM /FFT = 2-second timestamp tolerance (helps with OneDrive)
REM /R:1 /W:1 = one retry, short wait
REM /NFL /NDL = quieter (comment out if you want full file list)
robocopy "%SRC%" "%DST%" /E /XO /FFT /R:1 /W:1 /NFL /NDL

echo.
echo Done. Exit code %ERRORLEVEL%
echo   (0-7 = success / partial; 8+ = failure)
pause