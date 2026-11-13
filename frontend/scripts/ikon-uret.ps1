# Uygulama ikonlarını (PWA / ana ekran) public/favicon.svg tasarımıyla PNG olarak üretir.
# SVG çevirici gerekmez: aynı basit şekiller (yuvarlak köşeli zemin, yükselen çizgi, nokta) System.Drawing ile çizilir.
# Tasarım değişirse favicon.svg ile birlikte buradaki koordinatlar da güncellenir. Çalıştırma (Windows PowerShell):
#   powershell -ExecutionPolicy Bypass -File frontend/scripts/ikon-uret.ps1
Add-Type -AssemblyName System.Drawing

$hedef = Join-Path $PSScriptRoot '..\public'
$zemin = [System.Drawing.ColorTranslator]::FromHtml('#0f766e')
$nokta = [System.Drawing.ColorTranslator]::FromHtml('#fbbf24')

# favicon.svg koordinatları (viewBox 0 0 64 64)
$cizgi = @(@(14, 44), @(26, 32), @(34, 38), @(50, 20))

function Ciz([int]$boyut, [string]$ad, [bool]$yuvarlak, [double]$icerik) {
    $bmp = New-Object System.Drawing.Bitmap $boyut, $boyut
    $g = [System.Drawing.Graphics]::FromImage($bmp)
    $g.SmoothingMode = [System.Drawing.Drawing2D.SmoothingMode]::AntiAlias
    $g.PixelOffsetMode = [System.Drawing.Drawing2D.PixelOffsetMode]::HighQuality
    $g.Clear([System.Drawing.Color]::Transparent)

    $fircaZemin = New-Object System.Drawing.SolidBrush $zemin
    if ($yuvarlak) {
        $r = $boyut * 14 / 64 * 2
        $yol = New-Object System.Drawing.Drawing2D.GraphicsPath
        $yol.AddArc(0, 0, $r, $r, 180, 90)
        $yol.AddArc($boyut - $r, 0, $r, $r, 270, 90)
        $yol.AddArc($boyut - $r, $boyut - $r, $r, $r, 0, 90)
        $yol.AddArc(0, $boyut - $r, $r, $r, 90, 90)
        $yol.CloseFigure()
        $g.FillPath($fircaZemin, $yol)
    } else {
        $g.FillRectangle($fircaZemin, 0, 0, $boyut, $boyut)
    }

    # $icerik: çizimin kapladığı oran (maskable ikonda güvenli alan için küçültülür), ortalanır
    $olcek = $boyut / 64 * $icerik
    $kayma = ($boyut - 64 * $olcek) / 2
    $noktalar = $cizgi | ForEach-Object { New-Object System.Drawing.PointF ([float]($_[0] * $olcek + $kayma)), ([float]($_[1] * $olcek + $kayma)) }

    $kalem = New-Object System.Drawing.Pen ([System.Drawing.Color]::White), ([float](6 * $olcek))
    $kalem.StartCap = [System.Drawing.Drawing2D.LineCap]::Round
    $kalem.EndCap = [System.Drawing.Drawing2D.LineCap]::Round
    $kalem.LineJoin = [System.Drawing.Drawing2D.LineJoin]::Round
    $g.DrawLines($kalem, [System.Drawing.PointF[]]$noktalar)

    $nr = 4 * $olcek
    $fircaNokta = New-Object System.Drawing.SolidBrush $nokta
    $g.FillEllipse($fircaNokta, [float](50 * $olcek + $kayma - $nr), [float](20 * $olcek + $kayma - $nr), [float](2 * $nr), [float](2 * $nr))

    $dosya = Join-Path $hedef $ad
    $bmp.Save($dosya, [System.Drawing.Imaging.ImageFormat]::Png)
    $g.Dispose(); $bmp.Dispose()
    Write-Output "$ad ($boyut px)"
}

Ciz 192 'icon-192.png' $true 1.0
Ciz 512 'icon-512.png' $true 1.0
# Android şekil maskesi: zemin tam kare, çizim ortadaki güvenli alanda (%80 çember içinde kalsın)
Ciz 512 'icon-maskable-512.png' $false 0.72
# iOS kendisi yuvarlar; saydam köşe siyah görünür, bu yüzden tam kare
Ciz 180 'apple-touch-icon.png' $false 0.86
