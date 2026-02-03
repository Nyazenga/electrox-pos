@echo off
echo Running backup on live server...
echo.
plink.exe -ssh 31.97.199.82 -pw "GRCAdmin123/" "timeout 30 php /tmp/backup_fiscal_quick.php" > backup_output.txt 2>&1
echo.
echo Backup output saved to backup_output.txt
type backup_output.txt
echo.
echo If backup succeeded, download the file with:
echo   pscp.exe -pw "GRCAdmin123/" root@31.97.199.82:/tmp/fiscal_backup_*.sql .
pause
