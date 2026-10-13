param([switch]$Download)

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$target = Join-Path $root 'wp-content/themes/nexxi-kids/assets/stitch'
$pages = Join-Path $target 'pages'
$images = Join-Path $target 'images'
New-Item -ItemType Directory -Force -Path $pages, $images | Out-Null

$files = Get-ChildItem -LiteralPath $root -Filter '*.html' -File
$urls = @($files | ForEach-Object {
    [regex]::Matches((Get-Content -Raw -LiteralPath $_.FullName), 'https://lh3\.googleusercontent\.com/[^"\s<>]+') | ForEach-Object Value
} | Sort-Object -Unique)

$map = @{}
for ($i = 0; $i -lt $urls.Count; $i++) {
    $name = 'stitch-{0:d3}.jpg' -f ($i + 1)
    $map[$urls[$i]] = $name
}

if ($Download) {
    $jobs = foreach ($url in $urls) {
        [pscustomobject]@{ Url = $url; Path = (Join-Path $images $map[$url]) }
    }
    $results = $jobs | ForEach-Object -Parallel {
        if (Test-Path -LiteralPath $_.Path) { return }
        & curl.exe -L --fail --silent --show-error --max-time 30 --retry 2 --output $_.Path $_.Url
        if ($LASTEXITCODE -ne 0) { throw "Could not download $($_.Url)" }
    } -ThrottleLimit 8
}

foreach ($file in $files) {
    $html = Get-Content -Raw -LiteralPath $file.FullName
    foreach ($url in $urls) {
        $html = $html.Replace($url, '{{STITCH_ASSET}}/images/' + $map[$url])
    }
    $html = $html.Replace('./stitch-export/logo.jpg', '{{STITCH_ASSET}}/images/logo.jpg')
    $html = $html.Replace('<script src="./site.js"></script>', '')
    [System.IO.File]::WriteAllText((Join-Path $pages $file.Name), $html, [System.Text.UTF8Encoding]::new($false))
}
Copy-Item -LiteralPath (Join-Path $root 'stitch-export/logo.jpg') -Destination (Join-Path $images 'logo.jpg') -Force
Write-Output "Prepared $($files.Count) Stitch pages and $($urls.Count) image references."
