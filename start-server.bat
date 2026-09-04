@echo off
echo Starting Tharimpepe FSMS Server...
echo.
echo Web:  http://localhost:8080
echo API:  http://localhost:8080/api
echo.
php -S 0.0.0.0:8080 -t "%~dp0public" "%~dp0router.php"
pause

