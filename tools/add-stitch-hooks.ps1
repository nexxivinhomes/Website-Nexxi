#requires -Version 5
# Adds stable data-nx hook attributes to the Stitch page shells. Visual output is unchanged.
$ErrorActionPreference = 'Stop'
$base = Join-Path $PSScriptRoot '..\wp-content\themes\nexxi-kids\assets\stitch\pages'
$utf8 = New-Object System.Text.UTF8Encoding($false)

function Add-Hooks([string]$page, [array]$pairs) {
	$path = Resolve-Path (Join-Path $base "$page.html")
	$html = [IO.File]::ReadAllText($path, $utf8)
	foreach ($p in $pairs) {
		$needle = $p[0]; $marker = $p[1]
		if ($html.Contains("data-nx=`"$marker`"")) { continue }
		$count = ([regex]::Matches($html, [regex]::Escape($needle))).Count
		if ($count -ne 1) { throw "$page :: '$needle' matched $count times" }
		# Insert the hook right after the tag name of the anchored element.
		if ($needle.StartsWith('<')) {
			$tag = [regex]::Match($needle, '^<([a-zA-Z0-9]+)').Groups[1].Value
			$replacement = "<$tag data-nx=`"$marker`"" + $needle.Substring($tag.Length + 1)
		} else {
			$replacement = "data-nx=`"$marker`" $needle"
		}
		$html = $html.Replace($needle, $replacement)
	}
	[IO.File]::WriteAllText($path, $html, $utf8)
}

function Add-LineHooks([string]$page, [array]$pairs) {
	$path = Resolve-Path (Join-Path $base "$page.html")
	$lines = [IO.File]::ReadAllLines($path, $utf8)
	foreach ($p in $pairs) {
		$i = $p[0] - 1; $marker = $p[1]
		if ($lines[$i].Contains("data-nx=`"$marker`"")) { continue }
		if (-not $lines[$i].Contains('href="#"')) { throw "$page line $($p[0]) has no href=#" }
		$lines[$i] = ([regex]'href="#"').Replace($lines[$i], "data-nx=`"$marker`" href=`"#`"", 1)
	}
	[IO.File]::WriteAllLines($path, $lines, $utf8)
}

# Line hooks first (line numbers refer to the untouched file).
Add-LineHooks 'index' @(
	@(35, 'home-cta-shop'), @(39, 'home-cta-account'),
	@(111, 'home-cat-1'), @(123, 'home-cat-2'), @(135, 'home-cat-3'), @(147, 'home-cat-4'),
	@(381, 'home-lookbook-1'), @(408, 'home-lookbook-2')
)
Add-Hooks 'index' @(
	@('<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-space-md pt-space-lg">', 'home-flash-grid'),
	@('id="product-grid"', 'home-bestsellers'),
	@('id="filter-tab-group"', 'home-tabs')
)
Add-Hooks 'shop' @(
	@('<div class="relative z-10 flex items-center gap-space-xs font-label-sm text-label-sm text-on-surface-variant mb-space-md flex-wrap">', 'shop-breadcrumb'),
	@('<h1 class="font-display-lg text-display-lg text-primary tracking-tight">', 'shop-title'),
	@('<div class="bg-surface-container-low rounded-lg p-space-md shadow-sm flex flex-col gap-space-md">', 'shop-quickfilters'),
	@('<aside class="lg:col-span-3 space-y-space-lg">', 'shop-sidebar'),
	@('<div class="bg-surface-container-lowest rounded-lg px-space-lg py-space-sm shadow-[0_4px_16px_rgba(27,42,89,0.03)] flex flex-wrap items-center justify-between gap-space-sm">', 'shop-status'),
	@('<div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-gutter">', 'shop-grid'),
	@('<nav aria-label="Pagination"', 'shop-pagination'),
	@('<p class="font-body-lg text-body-lg text-on-surface-variant">', 'shop-intro'),
	@('<script>', 'shop-script')
)
Add-Hooks 'product' @(
	@('<section class="w-full px-margin py-space-md">', 'pdp-meta'),
	@('<div class="lg:col-span-7 flex flex-col gap-space-md">', 'pdp-gallery'),
	@('<div class="lg:col-span-5 flex flex-col gap-space-lg', 'pdp-panel'),
	@('<div class="bg-surface-container-lowest rounded-xl p-space-lg sm:p-space-xl shadow-[0_8px_24px_-4px_rgba(27,42,89,0.04)]">', 'pdp-tabs'),
	@('<div class="flex flex-col gap-space-lg">', 'pdp-related'),
	@('<script>', 'pdp-script')
)
Add-Hooks 'flash-sale' @(
	@('id="filter-container"', 'flash-filters'),
	@('<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-space-md">', 'flash-grid'),
	@('<span class="flex items-center gap-1 font-bold text-primary">', 'flash-claimed'),
	@('<span class="text-secondary font-bold">83%', 'flash-claimed-percent'),
	@('<div class="bg-gradient-to-r from-secondary-fixed-dim via-secondary-container to-secondary h-full rounded-full transition-all duration-700" style="width: 83%">', 'flash-claimed-bar'),
	@('<div class="hidden sm:flex items-center gap-space-xs bg-secondary-fixed text-on-secondary-fixed px-space-md py-1 rounded-full shadow-sm">', 'flash-ticker'),
	@('<span class="font-label-sm text-label-sm text-secondary bg-secondary-fixed px-space-sm py-0.5 rounded-full font-bold">', 'flash-slot'),
	@('<button class="bg-surface-container-lowest hover:bg-surface-container text-primary font-bold px-space-xl py-space-sm rounded-full shadow-sm hover:shadow-md transition-all flex items-center gap-2 font-label-lg text-label-lg">', 'flash-more')
)
Write-Output 'Stitch hooks are in place.'
