' Car Rental - runs run-scheduled.bat without opening a window.
' Task Scheduler action:  wscript.exe "C:\xampp\htdocs\car_rental\cron\run-hidden.vbs"
' Use this when the task runs "only when the user is logged on" (no window flashing every hour).
Dim shell, folder
Set shell = CreateObject("WScript.Shell")
folder = CreateObject("Scripting.FileSystemObject").GetParentFolderName(WScript.ScriptFullName)
shell.Run """" & folder & "\run-scheduled.bat""", 0, True
