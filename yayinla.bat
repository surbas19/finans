@echo off
chcp 65001 >nul
rem Canliya yayin (finans.yoklm.com): cift tiklayin. Commit'lenmis main dalini paketler, FTP ile sunucuya
rem gonderir, sunucunun paketi acmasini bekler. Ayni is: bash backend/deploy/yayinla.sh  (ayrinti: docs/CANLI.md)
rem   yayinla.bat            menu: yayinla ya da yalnizca denetle
rem   yayinla.bat yayinla    sormadan yayinla
rem   yayinla.bat denetle    yalnizca surum denetimi (hicbir sey gondermez)
rem Ilk kez: canli-paket\ftp.env Not Defteri'nde acilir; FTP bilgilerini doldurup kaydedin.

setlocal
set LARAGON=C:\laragon\bin
for /d %%d in ("%LARAGON%\php\php-8.3*") do set "PHP_DIR=%%d"
set "BASH=%ProgramFiles%\Git\bin\bash.exe"

if not defined PHP_DIR (echo PHP 8.3 bulunamadi: %LARAGON%\php & pause & exit /b 1)
if not exist "%BASH%" (echo Git Bash bulunamadi: %BASH% & pause & exit /b 1)

set "PATH=%PHP_DIR%;%LARAGON%\composer;%PATH%"
rem Node: PATH'teki (arayuz Node 22.18+ ister); yoksa Laragon'daki
where node >nul 2>nul
if errorlevel 1 for /d %%d in ("%LARAGON%\nodejs\node-v*") do set "PATH=%%d;%PATH%"
where node >nul 2>nul
if errorlevel 1 (echo Node bulunamadi. & pause & exit /b 1)

cd /d "%~dp0"

rem FTP bilgileri yoksa ya da sifre bossa: sablonu olustur, Not Defteri'nde ac
if not exist "canli-paket\ftp.env" "%BASH%" backend/deploy/yayinla.sh denetle >nul 2>nul
if not exist "canli-paket\ftp.env" (echo canli-paket\ftp.env olusturulamadi. & pause & exit /b 1)
findstr /r /c:"^FTP_SIFRE=''" /c:"^FTP_SIFRE=$" "canli-paket\ftp.env" >nul
if errorlevel 1 goto ayar_tamam
start "" notepad "canli-paket\ftp.env"
echo canli-paket\ftp.env Not Defteri'nde acildi: FTP_SIFRE ve diger bilgileri doldurup kaydedin,
echo sonra yayinla.bat'i yeniden calistirin. Okul sisteminin canli-paket\ftp.env dosyasindaki
echo yayin hesabi kullanilabilir. Bu dosya git'e girmez; kimseyle paylasmayin.
pause
exit /b 1
:ayar_tamam

set "ISLEM=%~1"
if defined ISLEM goto calistir
echo.
echo Finans - canliya yayin: https://finans.yoklm.com
echo   1 - Yayinla           [commit'lenmis main dalini paketle ve gonder]
echo   2 - Yalnizca denetle  [canlidaki surumla karsilastir, hicbir sey gondermez]
set /p SECIM="Seciminiz [1/2]: "
if "%SECIM%"=="1" set ISLEM=yayinla
if "%SECIM%"=="2" set ISLEM=denetle
if not defined ISLEM (echo Gecersiz secim. & pause & exit /b 1)

:calistir
if /i "%ISLEM%"=="yayinla" goto islem_tamam
if /i "%ISLEM%"=="denetle" goto islem_tamam
echo Kullanim: yayinla.bat [yayinla^|denetle]
pause
exit /b 1
:islem_tamam

rem Iki bilgisayarda calisiliyor: gonderilmemis commit varsa obur bilgisayar bu surumu bilmez (uyari, durdurmaz)
set ILERDE=0
for /f %%a in ('git rev-list --count "@{u}..HEAD" 2^>nul') do set ILERDE=%%a
if not "%ILERDE%"=="0" (
    echo UYARI: GitHub'a gonderilmemis %ILERDE% commit var. Yayindan sonra 'git push' yapin;
    echo        yoksa obur bilgisayardan yayin, canlidaki surumu tanimadigi icin durur.
    echo.
)

"%BASH%" backend/deploy/yayinla.sh %ISLEM%
set SONUC=%errorlevel%
echo.
if not "%SONUC%"=="0" (
    echo YAYIN BASARISIZ - yukaridaki mesaja bakin.
) else if /i "%ISLEM%"=="denetle" (
    echo DENETIM TAMAM - hicbir sey gonderilmedi.
) else (
    echo YAYIN TAMAM: https://finans.yoklm.com
)
pause
exit /b %SONUC%
