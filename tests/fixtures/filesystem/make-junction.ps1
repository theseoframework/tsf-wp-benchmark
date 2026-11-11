<#
TSF WordPress Benchmark
Copyright (C) 2026 Sybre Waaijer, CyberWire B.V. (https://cyberwire.nl/)
SPDX-License-Identifier: GPL-3.0-only

Creates one directory junction inside the test's disposable directory.
#>

param(
	[Parameter(Mandatory = $true)][string] $LinkPath,
	[Parameter(Mandatory = $true)][string] $TargetPath
)

$ErrorActionPreference = 'Stop'
New-Item -ItemType Junction -Path $LinkPath -Target $TargetPath | Out-Null
