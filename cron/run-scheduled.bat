@echo off
REM Car Rental scheduled jobs (statuses, late interest, daily backup).
REM Add this file to Windows Task Scheduler to run every hour. See database\INSTALLATION_NOTES.txt.
set PHP=C:\xampp\php\php.exe
if not exist "%PHP%" set PHP=php
"%PHP%" "%~dp0run.php" >> "%~dp0..\storage\logs\scheduled.log" 2>&1
