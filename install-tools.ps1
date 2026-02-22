param(
    [switch]$OnlyYtDlp,
    [switch]$OnlyFfmpeg,
    [switch]$Force
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

if ($OnlyYtDlp -and $OnlyFfmpeg) {
    throw "Use either -OnlyYtDlp or -OnlyFfmpeg, but not both together."
}

try {
    [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
} catch {
}

$skriptOrdner = if ($PSScriptRoot) { $PSScriptRoot } else { Split-Path -Parent $MyInvocation.MyCommand.Path }
$binOrdner = Join-Path $skriptOrdner 'bin'
$ffmpegZielOrdner = Join-Path $binOrdner 'ffmpeg\bin'
$ytDlpZielDatei = Join-Path $binOrdner 'yt-dlp.exe'
$ffmpegZielDatei = Join-Path $ffmpegZielOrdner 'ffmpeg.exe'

function New-VerzeichnisFallsFehlt {
    param(
        [Parameter(Mandatory = $true)]
        [string]$Pfad
    )

    if (-not (Test-Path -LiteralPath $Pfad)) {
        New-Item -ItemType Directory -Path $Pfad -Force | Out-Null
    }
}

function Invoke-DateiDownload {
    param(
        [Parameter(Mandatory = $true)]
        [string]$Url,
        [Parameter(Mandatory = $true)]
        [string]$ZielPfad
    )

    $zielVerzeichnis = Split-Path -Parent $ZielPfad
    New-VerzeichnisFallsFehlt -Pfad $zielVerzeichnis

    Write-Host "Download: $Url"

    $fehlertexte = @()
    $downloadErfolgreich = $false

    try {
        Invoke-WebRequest -Uri $Url -OutFile $ZielPfad -UseBasicParsing
        $downloadErfolgreich = $true
    } catch {
        $fehlertexte += "Invoke-WebRequest: $($_.Exception.Message)"
    }

    if (-not $downloadErfolgreich -and (Get-Command Start-BitsTransfer -ErrorAction SilentlyContinue)) {
        try {
            Start-BitsTransfer -Source $Url -Destination $ZielPfad
            $downloadErfolgreich = $true
        } catch {
            $fehlertexte += "Start-BitsTransfer: $($_.Exception.Message)"
        }
    }

    if (-not $downloadErfolgreich) {
        try {
            $webClient = New-Object System.Net.WebClient
            $webClient.DownloadFile($Url, $ZielPfad)
            $downloadErfolgreich = $true
        } catch {
            $fehlertexte += "WebClient: $($_.Exception.Message)"
        } finally {
            if ($webClient) {
                $webClient.Dispose()
            }
        }
    }

    if (-not $downloadErfolgreich) {
        throw "Download failed. Details: $($fehlertexte -join ' | ')"
    }

    if (-not (Test-Path -LiteralPath $ZielPfad)) {
        throw "Download failed: $ZielPfad was not created."
    }
}

function Install-YtDlp {
    param(
        [switch]$Ueberschreiben
    )

    $ytDlpUrl = 'https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp.exe'

    if ((Test-Path -LiteralPath $ytDlpZielDatei) -and -not $Ueberschreiben) {
        Write-Host "yt-dlp is already present: $ytDlpZielDatei"
        return
    }

    Invoke-DateiDownload -Url $ytDlpUrl -ZielPfad $ytDlpZielDatei
    Write-Host "yt-dlp installed: $ytDlpZielDatei"
}

function Install-Ffmpeg {
    param(
        [switch]$Ueberschreiben
    )

    $ffmpegZipUrl = 'https://github.com/BtbN/FFmpeg-Builds/releases/latest/download/ffmpeg-master-latest-win64-gpl.zip'

    if ((Test-Path -LiteralPath $ffmpegZielDatei) -and -not $Ueberschreiben) {
        Write-Host "ffmpeg is already present: $ffmpegZielDatei"
        return
    }

    $tempOrdner = Join-Path ([System.IO.Path]::GetTempPath()) ("ffmpeg_install_" + [Guid]::NewGuid().ToString('N'))
    $zipPfad = Join-Path $tempOrdner 'ffmpeg.zip'
    $extractPfad = Join-Path $tempOrdner 'extract'

    New-VerzeichnisFallsFehlt -Pfad $tempOrdner
    New-VerzeichnisFallsFehlt -Pfad $extractPfad

    try {
        Invoke-DateiDownload -Url $ffmpegZipUrl -ZielPfad $zipPfad
        Expand-Archive -Path $zipPfad -DestinationPath $extractPfad -Force

        $ffmpegQuelle = Get-ChildItem -Path $extractPfad -Recurse -Filter 'ffmpeg.exe' -File |
            Select-Object -First 1 -ExpandProperty FullName

        if (-not $ffmpegQuelle) {
            throw "ffmpeg.exe was not found in the extracted archive."
        }

        New-VerzeichnisFallsFehlt -Pfad $ffmpegZielOrdner
        Copy-Item -Path $ffmpegQuelle -Destination $ffmpegZielDatei -Force
        Write-Host "ffmpeg installed: $ffmpegZielDatei"
    } finally {
        if (Test-Path -LiteralPath $tempOrdner) {
            Remove-Item -LiteralPath $tempOrdner -Recurse -Force -ErrorAction SilentlyContinue
        }
    }
}

function Show-Versionen {
    if (Test-Path -LiteralPath $ytDlpZielDatei) {
        $ytVersion = (& $ytDlpZielDatei --version 2>$null | Select-Object -First 1)
        if ($ytVersion) {
            Write-Host "yt-dlp version: $ytVersion"
        }
    }

    if (Test-Path -LiteralPath $ffmpegZielDatei) {
        $ffVersion = (& $ffmpegZielDatei -version 2>$null | Select-Object -First 1)
        if ($ffVersion) {
            Write-Host "ffmpeg version: $ffVersion"
        }
    }
}

New-VerzeichnisFallsFehlt -Pfad $binOrdner

$installiereYtDlp = $true
$installiereFfmpeg = $true

if ($OnlyYtDlp) {
    $installiereFfmpeg = $false
}
if ($OnlyFfmpeg) {
    $installiereYtDlp = $false
}

if ($installiereYtDlp) {
    Install-YtDlp -Ueberschreiben:$Force
}
if ($installiereFfmpeg) {
    Install-Ffmpeg -Ueberschreiben:$Force
}

Write-Host ""
Write-Host "Installation completed."
Show-Versionen
Write-Host "You can now reload the tool in your browser."
