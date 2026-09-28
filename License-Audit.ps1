<#
    License-Audit.ps1
    Έλεγχος αδειών Windows & Office ανά PC — εμφάνιση στην οθόνη.
    Εκτέλεση:  δεξί κλικ > Run with PowerShell
         ή:    powershell -ExecutionPolicy Bypass -File .\License-Audit.ps1
#>

$ErrorActionPreference = 'SilentlyContinue'

# ---------- Αυτόματο elevation (χρειάζεται για Defender / tasks / όλα τα profiles) ----------
$isAdmin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()
           ).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
if (-not $isAdmin -and $PSCommandPath) {
    try {
        Start-Process powershell.exe -Verb RunAs -ErrorAction Stop `
            -ArgumentList "-NoProfile -ExecutionPolicy Bypass -File `"$PSCommandPath`""
        exit
    } catch {
        Write-Host "Δεν δόθηκαν δικαιώματα διαχειριστή - συνεχίζω με περιορισμένους ελέγχους." -ForegroundColor Yellow
    }
}

$Host.UI.RawUI.WindowTitle = "License Audit - $env:COMPUTERNAME"

# ---------- Helpers ----------
$flags = New-Object System.Collections.Generic.List[string]
function Flag([string]$m) { $script:flags.Add($m) }

function Section([string]$t) {
    Write-Host ""
    Write-Host ("=" * 72) -ForegroundColor DarkCyan
    Write-Host " $t" -ForegroundColor Cyan
    Write-Host ("=" * 72) -ForegroundColor DarkCyan
}
function Row([string]$k, $v, [string]$c = 'White') {
    if ($null -eq $v -or "$v" -eq '') { $v = '-'; $c = 'DarkGray' }
    Write-Host ("  {0,-30}: " -f $k) -NoNewline -ForegroundColor Gray
    Write-Host $v -ForegroundColor $c
}

function Decode-ProductKey([byte[]]$dpid) {
    if (-not $dpid -or $dpid.Length -lt 67) { return $null }
    [byte[]]$key = $dpid[52..66]
    $isWin8 = ([int][math]::Floor($key[14] / 6)) -band 1
    $key[14] = ($key[14] -band 0xF7) -bor (($isWin8 -band 2) * 4)
    $chars = 'BCDFGHJKMPQRTVWXY2346789'
    $out = ''
    $last = 0
    for ($i = 24; $i -ge 0; $i--) {
        $cur = 0
        for ($j = 14; $j -ge 0; $j--) {
            $cur = $cur * 256 + $key[$j]
            $key[$j] = [math]::Floor($cur / 24)
            $cur = $cur % 24
        }
        $out = $chars.Substring($cur, 1) + $out
        $last = $cur
    }
    if ($isWin8 -eq 1) { $out = $out.Substring(1).Insert($last, 'N') }
    return ($out -replace '(.{5})(?!$)', '$1-')
}

$statusMap = @{
    0 = 'Χωρίς άδεια'
    1 = 'Ενεργοποιημένο'
    2 = 'Περίοδος χάριτος (OOB)'
    3 = 'Περίοδος χάριτος (OOT)'
    4 = 'Μη γνήσιο (grace)'
    5 = 'Notification - ΜΗ ενεργοποιημένο'
    6 = 'Εκτεταμένη περίοδος χάριτος'
}

# Γνωστά generic / GVLK κλειδιά Windows 10/11
$knownKeys = @{
    'VK7JG-NPHTM-C97JM-9MPGT-3V66T' = 'Generic Pro (digital license)'
    'YTMG3-N6DKC-DKB77-7M9GH-8HVX7' = 'Generic Home (digital license)'
    'BT79Q-G7N6G-PGBYW-4YWX6-6F4BT' = 'Generic Home Single Language (digital license)'
    'W269N-WFGWX-YVC9B-4J6C9-T83GX' = 'GVLK Pro (KMS client)'
    'MH37W-N47XK-V7XM9-C7227-GCQG9' = 'GVLK Pro N (KMS client)'
    'NRG8B-VKK3Q-CXVCJ-9G2XF-6Q84J' = 'GVLK Pro Workstation (KMS client)'
    'NPPR9-FWDCX-D2C8J-H872K-2YT43' = 'GVLK Enterprise (KMS client)'
    'NW6C2-QMPVW-D7KKK-3GKT6-VCFB2' = 'GVLK Education (KMS client)'
    '6TP4R-GNPTD-KYYHQ-7B7DP-J447Y' = 'GVLK Pro Education (KMS client)'
}

$channelHint = @{
    'Retail'      = 'Retail (αγορά ή digital license)'
    'OEM:DM'      = 'OEM - κλειδί από BIOS'
    'OEM:NONSLP'  = 'OEM (system builder / αυτοκόλλητο)'
    'OEM:COA'     = 'OEM (αυτοκόλλητο COA)'
    'Volume:MAK'  = 'Volume MAK (απαιτεί σύμβαση VL)'
    'Volume:GVLK' = 'Volume KMS client'
}

function Show-License($p, [string]$label, [bool]$inDomain) {
    $channel = $p.ProductKeyChannel
    if (-not $channel -and $p.Description -match '(\w+) channel') { $channel = $Matches[1] }
    $hint = $channelHint[$channel]
    Write-Host ""
    Write-Host "  > $($p.Name)" -ForegroundColor Yellow
    Row 'Κανάλι' $(if ($hint) { "$channel - $hint" } else { $channel })
    Row 'Partial key (5 τελευταία)' $p.PartialProductKey 'Green'
    $st = [int]$p.LicenseStatus
    Row 'Κατάσταση' $statusMap[$st] $(if ($st -eq 1) { 'Green' } else { 'Red' })
    if ($st -ne 1) { Flag "$label '$($p.Name)': δεν είναι ενεργοποιημένο ($($statusMap[$st]))" }
    if ($p.GracePeriodRemaining -gt 0) {
        Row 'Υπόλοιπο ενεργοποίησης' ("{0:N0} ημέρες" -f ($p.GracePeriodRemaining / 1440))
    }

    $isKms = ($channel -match 'GVLK|KMSCLIENT') -or ($p.Description -match 'KMSCLIENT')
    if ($isKms) {
        $kms = @($p.KeyManagementServiceMachine, $p.DiscoveredKeyManagementServiceMachineName) |
               Where-Object { $_ } | Select-Object -First 1
        $kmsIp = $p.DiscoveredKeyManagementServiceMachineIpAddress
        Row 'KMS server' "$kms $(if ($kmsIp) { "($kmsIp)" })" 'Magenta'
        if ("$kms $kmsIp" -match '(^|\s|\()(127\.|0\.0\.0\.0|localhost|::1)') {
            Flag "$label '$($p.Name)': KMS σε loopback ($kms $kmsIp) - σχεδόν σίγουρα activator"
        } elseif (-not $inDomain) {
            Flag "$label '$($p.Name)': KMS client σε μηχάνημα εκτός domain - υπάρχει σύμβαση Volume Licensing;"
        }
    }
    if ($channel -match 'MAK' -or $p.Description -match 'VOLUME_MAK') {
        Flag "$label '$($p.Name)': MAK άδεια - επιβεβαίωσε ότι υπάρχει σύμβαση Volume Licensing (συχνά leaked MAK)"
    }
}

try {
    # ================= ΜΗΧΑΝΗΜΑ =================
    $cs   = Get-CimInstance Win32_ComputerSystem
    $bios = Get-CimInstance Win32_BIOS
    $os   = Get-CimInstance Win32_OperatingSystem
    $cv   = Get-ItemProperty 'HKLM:\SOFTWARE\Microsoft\Windows NT\CurrentVersion'
    $inDomain = [bool]$cs.PartOfDomain

    Section "ΜΗΧΑΝΗΜΑ"
    Row 'Hostname' $env:COMPUTERNAME 'Green'
    Row 'Κατασκευαστής / Μοντέλο' "$($cs.Manufacturer) $($cs.Model)"
    Row 'Serial μηχανήματος' $bios.SerialNumber 'Green'
    Row 'Λειτουργικό' "$($os.Caption) $($cv.DisplayVersion) (build $($os.BuildNumber).$($cv.UBR))"
    Row 'Domain' $(if ($inDomain) { "Ναι ($($cs.Domain))" } else { "Όχι - Workgroup: $($cs.Workgroup)" })
    Row 'Ημερομηνία ελέγχου' (Get-Date -Format 'dd/MM/yyyy HH:mm')

    # ================= WINDOWS =================
    Section "WINDOWS"
    $sls     = Get-CimInstance SoftwareLicensingService
    $oemKey  = $sls.OA3xOriginalProductKey
    $backup  = (Get-ItemProperty 'HKLM:\SOFTWARE\Microsoft\Windows NT\CurrentVersion\SoftwareProtectionPlatform').BackupProductKeyDefault
    $decoded = Decode-ProductKey $cv.DigitalProductId
    $installedKey = if ($backup) { $backup } else { $decoded }

    Row 'Εγκατεστημένο κλειδί' $installedKey 'Green'
    if ($installedKey -and $knownKeys.ContainsKey($installedKey)) {
        Row 'Τύπος κλειδιού' $knownKeys[$installedKey] 'Magenta'
    }
    Row 'OEM κλειδί στο BIOS' $(if ($oemKey) { "$oemKey  ($($sls.OA3xOriginalProductKeyDescription))" } else { 'Δεν υπάρχει' })
    if ($oemKey -and $installedKey -and $oemKey -ne $installedKey) {
        Row 'Σημείωση' 'Το εγκατεστημένο κλειδί διαφέρει από το OEM του BIOS' 'Yellow'
    }

    $winApp    = '55c92734-d682-4d71-983e-d6ec3f16059f'
    $officeApp = '0ff1ce15-a989-479d-af46-f275c6370663'
    Write-Host "  (ανάγνωση αδειών, λίγα δευτερόλεπτα...)" -ForegroundColor DarkGray
    $slp = @(Get-CimInstance SoftwareLicensingProduct -Filter "PartialProductKey <> null")

    $winProducts = $slp | Where-Object { $_.ApplicationID -eq $winApp }
    if (-not $winProducts) { Row 'Άδεια' 'Δεν βρέθηκε εγκατεστημένο κλειδί Windows' 'Red'; Flag 'Windows: δεν βρέθηκε κλειδί' }
    foreach ($p in $winProducts) { Show-License $p 'Windows' $inDomain }

    # ================= OFFICE =================
    Section "OFFICE"
    $c2r = Get-ItemProperty 'HKLM:\SOFTWARE\Microsoft\Office\ClickToRun\Configuration'
    if ($c2r) {
        Row 'Εγκατάσταση' 'Click-to-Run'
        Row 'Προϊόντα' $c2r.ProductReleaseIds 'Green'
        Row 'Έκδοση / Αρχιτεκτονική' "$($c2r.VersionToReport) / $($c2r.Platform)"
        if ($c2r.ProductReleaseIds -match 'Volume' -and -not $inDomain) {
            Flag "Office: εγκατάσταση Volume ($($c2r.ProductReleaseIds)) σε μηχάνημα εκτός domain"
        }
    }

    $offProducts = @($slp | Where-Object { $_.ApplicationID -eq $officeApp })
    # Office 2010 (MSI) χρησιμοποιεί ξεχωριστή υπηρεσία
    $ospp = @(Get-CimInstance OfficeSoftwareProtectionProduct -Filter "PartialProductKey <> null")
    $offProducts += $ospp

    foreach ($p in $offProducts) { Show-License $p 'Office' $inDomain }

    # Microsoft 365 (συνδρομή) - άδειες ανά χρήστη
    $m365 = $false
    Get-ChildItem "$env:SystemDrive\Users" -Directory | ForEach-Object {
        $lic = Join-Path $_.FullName 'AppData\Local\Microsoft\Office\Licenses'
        if (Test-Path $lic) {
            $n = @(Get-ChildItem $lic -Recurse -File).Count
            if ($n -gt 0) {
                if (-not $m365) { Write-Host ""; Write-Host "  > Microsoft 365 / συνδρομή (άδειες χρήστη)" -ForegroundColor Yellow; $m365 = $true }
                Row "Profile $($_.Name)" "$n αρχεία άδειας"
            }
        }
    }
    $ids = Get-ChildItem 'HKCU:\Software\Microsoft\Office\16.0\Common\Identity\Identities' |
           Get-ItemProperty | Where-Object { $_.EmailAddress } | Select-Object -ExpandProperty EmailAddress -Unique
    if ($ids) { Row 'Συνδεδεμένοι λογαριασμοί' ($ids -join ', ') 'Green' }

    if (-not $c2r -and -not $offProducts -and -not $m365) { Row 'Office' 'Δεν βρέθηκε εγκατάσταση / άδεια' 'DarkGray' }
    elseif (-not $offProducts -and -not $m365) { Row 'Άδεια' 'Office εγκατεστημένο αλλά χωρίς κλειδί ή συνδρομή' 'Red'; Flag 'Office: εγκατεστημένο χωρίς άδεια' }

    # ================= ΕΝΔΕΙΞΕΙΣ ACTIVATOR =================
    Section "ΕΝΔΕΙΞΕΙΣ ACTIVATOR"
    $found = 0
    $paths = @(
        "$env:windir\System32\SppExtComObjHook.dll",
        "$env:windir\SysWOW64\SppExtComObjHook.dll",
        "$env:ProgramFiles\Microsoft Office\root\vfs\System\sppcs.dll",
        "$env:ProgramFiles\Microsoft Office\root\vfs\SystemX86\sppcs.dll",
        "${env:ProgramFiles(x86)}\Microsoft Office\root\vfs\System\sppcs.dll",
        "${env:ProgramFiles(x86)}\Microsoft Office\root\vfs\SystemX86\sppcs.dll",
        "$env:ProgramFiles\KMSpico", "${env:ProgramFiles(x86)}\KMSpico",
        "$env:ProgramData\KMSAutoS", "$env:ProgramData\KMSAuto", "$env:ProgramData\KMSAuto Net",
        "$env:windir\AAct_Tools", "$env:windir\KMSAutoS", "$env:windir\KMS",
        "$env:ProgramData\Online_KMS_Activation"
    )
    foreach ($p in $paths) {
        if (Test-Path $p) { Row 'Αρχείο/φάκελος' $p 'Red'; Flag "Activator: βρέθηκε $p"; $found++ }
    }

    foreach ($exe in 'SppExtComObj.exe', 'sppsvc.exe', 'osppsvc.exe') {
        $k = "HKLM:\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Image File Execution Options\$exe"
        $v = Get-ItemProperty $k
        if ($v -and ($v.VerifierDlls -or $v.Debugger)) {
            Row "IFEO hook ($exe)" "$($v.VerifierDlls)$($v.Debugger)" 'Red'
            Flag "Activator: IFEO hook στο $exe"; $found++
        }
    }

    $pattern = 'KMS|Pico|AAct|Ohook|Re-?Loader|HWIDGen'
    Get-Service | Where-Object { $_.Name -match $pattern -or $_.DisplayName -match $pattern } | ForEach-Object {
        Row 'Υπηρεσία' "$($_.Name) - $($_.DisplayName) [$($_.Status)]" 'Red'
        Flag "Activator: υπηρεσία $($_.Name)"; $found++
    }

    Get-ScheduledTask | Where-Object {
        $_.TaskPath -notlike '\Microsoft\*' -and
        ($_.TaskName -match "$pattern|Activat" -or (($_.Actions | ForEach-Object { "$($_.Execute) $($_.Arguments)" }) -join ' ') -match $pattern)
    } | ForEach-Object {
        Row 'Scheduled task' "$($_.TaskPath)$($_.TaskName)" 'Red'
        Flag "Activator: scheduled task $($_.TaskName)"; $found++
    }

    $uninst = 'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall\*',
              'HKLM:\SOFTWARE\WOW6432Node\Microsoft\Windows\CurrentVersion\Uninstall\*'
    Get-ItemProperty $uninst | Where-Object { $_.DisplayName -match $pattern } | ForEach-Object {
        Row 'Εγκατεστημένο πρόγραμμα' $_.DisplayName 'Red'
        Flag "Activator: πρόγραμμα $($_.DisplayName)"; $found++
    }

    $mp = Get-MpPreference
    if ($mp -and $mp.ExclusionPath) {
        foreach ($ex in $mp.ExclusionPath) {
            $bad = $ex -match "$pattern|SppExt|Office\\root|\\Windows\\?$|\\System32\\?$"
            Row 'Defender exclusion' $ex $(if ($bad) { 'Red' } else { 'Yellow' })
            if ($bad) { Flag "Activator: ύποπτο Defender exclusion $ex"; $found++ }
        }
    }

    if ($found -eq 0) { Row 'Αποτέλεσμα' 'Δεν βρέθηκαν γνωστά ίχνη activator' 'Green' }

    # ================= ΣΥΝΟΨΗ =================
    Section "ΣΥΝΟΨΗ"
    if ($flags.Count -eq 0) {
        Write-Host "  OK - δεν βρέθηκε κάτι ύποπτο." -ForegroundColor Green
    } else {
        foreach ($f in $flags) { Write-Host "  [!] $f" -ForegroundColor Red }
    }
    if (-not $isAdmin) {
        Write-Host ""
        Write-Host "  Σημείωση: εκτελέστηκε χωρίς admin - ορισμένοι έλεγχοι ίσως λείπουν." -ForegroundColor Yellow
    }
}
catch {
    Write-Host ""
    Write-Host "Σφάλμα: $($_.Exception.Message)" -ForegroundColor Red
}
finally {
    Write-Host ""
    Read-Host "Πατήστε Enter για κλείσιμο"
}
