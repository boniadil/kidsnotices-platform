# Reports AI - service de lecture des fichiers (PDF, .doc, .ppt) sur cet ordinateur.
# Lance le service (PHP + pdftotext) et un tunnel Cloudflare, puis affiche les deux lignes
# a mettre dans le fichier .env de l'hebergement : RAI_EXTRACT_URL et RAI_EXTRACT_KEY.
# Ce qui manque (PHP, poppler/pdftotext, cloudflared) est telecharge dans extractor\tools.
# Laissez la fenetre ouverte ; Ctrl+C ou fermeture = arret du service.

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

$here = Split-Path -Parent $MyInvocation.MyCommand.Path
$root = Split-Path -Parent $here
$port = 11436
$tools = Join-Path $here 'tools'
New-Item -ItemType Directory -Force -Path $tools | Out-Null

function Find-Exe([string]$name, [string[]]$places) {
    foreach ($p in $places) {
        if ($p -and (Test-Path $p)) {
            $f = Get-ChildItem -Path $p -Filter "$name.exe" -Recurse -ErrorAction SilentlyContinue | Select-Object -First 1
            if ($f) { return $f.FullName }
        }
    }
    $cmd = Get-Command $name -ErrorAction SilentlyContinue
    if ($cmd) { return $cmd.Source }
    return $null
}

function Get-Zip([string]$url, [string]$dest) {
    $zip = Join-Path $env:TEMP ([IO.Path]::GetRandomFileName() + '.zip')
    Invoke-WebRequest -Uri $url -OutFile $zip -UseBasicParsing
    Expand-Archive -Path $zip -DestinationPath $dest -Force
    Remove-Item $zip -Force
}

function Read-Shared([string]$path) {
    try {
        $fs = [IO.File]::Open($path, 'Open', 'Read', 'ReadWrite')
        $sr = New-Object IO.StreamReader($fs)
        $text = $sr.ReadToEnd()
        $sr.Close()
        return $text
    } catch { return '' }
}

# Runs PHP; its warnings (stderr) must not stop this script.
function Invoke-Php([string]$exe, [string[]]$arguments) {
    $old = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try { return (& $exe @arguments 2>$null) } catch { return $null } finally { $ErrorActionPreference = $old }
}

function Get-PhpVersionId([string]$exe) {
    $v = Invoke-Php $exe @('-n', '-r', 'echo PHP_VERSION_ID;')
    if ("$v" -match '^\d+$') { return [int]"$v" }
    return 0
}

# ---- 1. PHP 8.1+ ---------------------------------------------------------------------------
$php = Find-Exe 'php' @((Join-Path $tools 'php'), 'C:\php', 'C:\xampp\php')
if (-not $php -or (Get-PhpVersionId $php) -lt 80100) {
    Write-Host 'PHP 8.1+ introuvable : telechargement depuis windows.php.net ...'
    $releases = Invoke-RestMethod -Uri 'https://windows.php.net/downloads/releases/releases.json' -UseBasicParsing
    $version = $releases.PSObject.Properties.Name | Where-Object { $_ -match '^\d+\.\d+$' } | Sort-Object { [version]$_ } -Descending | Select-Object -First 1
    $build = $releases.$version.PSObject.Properties | Where-Object { $_.Name -match '^nts-vs\d+-x64$' } | Select-Object -First 1
    Get-Zip ('https://windows.php.net/downloads/releases/' + $build.Value.zip.path) (Join-Path $tools 'php')
    $php = Join-Path $tools 'php\php.exe'
    if ((Get-PhpVersionId $php) -lt 80100) {
        throw 'PHP ne demarre pas. Installez le runtime Visual C++ : https://aka.ms/vs/17/release/vc_redist.x64.exe puis relancez.'
    }
}
# Extensions needed by the service, loaded from PHP's ext folder when php.ini does not load them.
$phpArgs = @('-d', 'upload_max_filesize=100M', '-d', 'post_max_size=110M', '-d', 'memory_limit=1024M', '-d', 'max_execution_time=0')
$extDir = Join-Path (Split-Path -Parent $php) 'ext'
$extra = @()
foreach ($ext in @('mbstring', 'zip')) {
    $loaded = Invoke-Php $php @('-r', "echo extension_loaded('$ext') ? 1 : 0;")
    if ($loaded -ne '1' -and (Test-Path (Join-Path $extDir "php_$ext.dll"))) { $extra += @('-d', "extension=$ext") }
}
if ($extra.Count -gt 0) { $phpArgs += @('-d', ('extension_dir="' + $extDir + '"')) + $extra }
Write-Host "PHP        : $php"

# ---- 2. pdftotext (poppler) ------------------------------------------------------------------
$pdftotext = Find-Exe 'pdftotext' @((Join-Path $tools 'poppler'), 'C:\poppler', 'C:\Program Files\poppler')
if (-not $pdftotext) {
    Write-Host 'pdftotext introuvable : telechargement de poppler pour Windows ...'
    $release = Invoke-RestMethod -Uri 'https://api.github.com/repos/oschwartz10612/poppler-windows/releases/latest' -UseBasicParsing
    $asset = $release.assets | Where-Object { $_.name -like '*.zip' } | Select-Object -First 1
    Get-Zip $asset.browser_download_url (Join-Path $tools 'poppler')
    $pdftotext = Find-Exe 'pdftotext' @((Join-Path $tools 'poppler'))
    if (-not $pdftotext) { throw 'pdftotext introuvable apres le telechargement de poppler.' }
}
$env:RAI_POPPLER_DIR = Split-Path -Parent $pdftotext
Write-Host "pdftotext  : $pdftotext"

# ---- 3. LibreOffice (optional: .doc .ppt .odt .odp) -------------------------------------------
$soffice = Find-Exe 'soffice' @('C:\Program Files\LibreOffice\program', 'C:\Program Files (x86)\LibreOffice\program')
if ($soffice) { Write-Host "LibreOffice: $soffice" } else { Write-Host 'LibreOffice: absent (seuls PDF, Word, Excel et PowerPoint recents seront lus)' }

# ---- 4. cloudflared --------------------------------------------------------------------------
$cloudflared = Find-Exe 'cloudflared' @($tools, 'C:\Program Files (x86)\cloudflared', 'C:\Program Files\cloudflared')
if (-not $cloudflared) {
    Write-Host 'cloudflared introuvable : telechargement ...'
    $cloudflared = Join-Path $tools 'cloudflared.exe'
    Invoke-WebRequest -Uri 'https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-windows-amd64.exe' -OutFile $cloudflared -UseBasicParsing
}

# ---- 5. access key (created once, kept in extractor\key.txt) ---------------------------------
$keyFile = Join-Path $here 'key.txt'
if (-not (Test-Path $keyFile)) {
    $bytes = New-Object byte[] 32
    [Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($bytes)
    $newKey = [Convert]::ToBase64String($bytes).TrimEnd('=').Replace('+', '-').Replace('/', '_')
    [IO.File]::WriteAllText($keyFile, $newKey)
}
$key = ([IO.File]::ReadAllText($keyFile)).Trim()

# ---- 6. service + tunnel ---------------------------------------------------------------------
$phpArgs += @('-S', "127.0.0.1:$port", ('"' + (Join-Path $here 'server.php') + '"'))
$server = Start-Process -FilePath $php -ArgumentList $phpArgs -WorkingDirectory $root -NoNewWindow -PassThru `
    -RedirectStandardError (Join-Path $here 'server.log') -RedirectStandardOutput (Join-Path $here 'server.out.log')
$tunnel = $null
try {
    $ready = $false
    for ($i = 0; $i -lt 30 -and -not $ready; $i++) {
        Start-Sleep -Milliseconds 500
        try {
            $health = Invoke-RestMethod -Uri "http://127.0.0.1:$port/health" -Headers @{ Authorization = "Bearer $key" } -UseBasicParsing
            $ready = $health.ok
        } catch { }
    }
    if (-not $ready) { throw "Le service ne repond pas. Voir $(Join-Path $here 'server.log')" }
    Write-Host ("Service    : OK (PDF : " + $health.pdf + ", LibreOffice : " + $health.office + ")")

    Write-Host 'Creation du tunnel... (10 a 30 secondes)'
    $tunnelLog = Join-Path $here 'tunnel.log'
    Remove-Item $tunnelLog -ErrorAction SilentlyContinue
    $tunnel = Start-Process -FilePath $cloudflared -ArgumentList @('tunnel', '--no-autoupdate', '--url', "http://127.0.0.1:$port") -NoNewWindow -PassThru `
        -RedirectStandardError $tunnelLog -RedirectStandardOutput (Join-Path $here 'tunnel.out.log')
    $url = $null
    for ($i = 0; $i -lt 90 -and -not $url; $i++) {
        Start-Sleep -Seconds 1
        $m = [regex]::Match((Read-Shared $tunnelLog), 'https://[a-z0-9-]+\.trycloudflare\.com')
        if ($m.Success) { $url = $m.Value }
    }
    if (-not $url) { throw "Le tunnel n'a pas demarre. Voir $tunnelLog" }

    $lines = "RAI_EXTRACT_URL=$url`r`nRAI_EXTRACT_KEY=$key"
    Write-Host ''
    Write-Host '============ A METTRE DANS LE FICHIER .env DE L''HEBERGEMENT ============'
    Write-Host " RAI_EXTRACT_URL=$url"
    Write-Host " RAI_EXTRACT_KEY=$key"
    Write-Host '=========================================================================='
    try { Set-Clipboard -Value $lines; Write-Host '(Les deux lignes ont ete copiees dans le presse-papiers.)' } catch { }
    Write-Host 'Laissez cette fenetre OUVERTE ; Ctrl+C ou fermeture = arret du service.'
    Write-Host 'L''adresse change a chaque lancement : mettez a jour RAI_EXTRACT_URL dans .env.'

    while (-not $server.HasExited -and -not $tunnel.HasExited) { Start-Sleep -Seconds 2 }
    Write-Host 'Le service ou le tunnel s''est arrete.'
} finally {
    foreach ($p in @($tunnel, $server)) { if ($p -and -not $p.HasExited) { Stop-Process -Id $p.Id -Force -ErrorAction SilentlyContinue } }
}
