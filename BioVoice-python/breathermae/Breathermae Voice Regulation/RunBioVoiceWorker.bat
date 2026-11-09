@echo off
set BIOVOICE_WP_URL=https://breathermae.com
set BIOVOICE_WP_API_KEY=c2d4650d1e86110049d116dcb763c4a3ffb458fd7dd1affa08dbca3d18e10a8bd81efc74
set BIOVOICE_ENGINE_DIR=C:\BioVoice\breathermae\Breathermae Voice Regulation

cd /d "%BIOVOICE_ENGINE_DIR%"
if not exist "worker_run.py" (
  echo worker_run.py not found in %CD%
  exit /b 1
)

C:\BioVoice\.venv\Scripts\python.exe worker_run.py --once


