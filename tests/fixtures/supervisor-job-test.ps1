# TSF WordPress Benchmark
# Copyright (C) 2026 Sybre Waaijer, CyberWire B.V. (https://cyberwire.nl/)
# SPDX-License-Identifier: GPL-3.0-only
param([string]$Repository, [string]$Php, [string]$Receipt)
$ErrorActionPreference='Stop'
Add-Type -TypeDefinition ([IO.File]::ReadAllText((Join-Path $Repository 'scripts/supervisor-job.cs')))
$job=[TsfbSupervisorJob]::Create()
$rootHandle=[IntPtr]::Zero
$childHandle=[IntPtr]::Zero
$sentinel=$null
try {
	$rootId=[TsfbSupervisorJob]::Launch($job,$Php,('"' + (Join-Path $Repository 'tests/fixtures/supervisor-child.php') + '" "' + $Receipt + '"'),$Repository)
	$deadline=[DateTime]::UtcNow.AddSeconds(10)
	while (-not [IO.File]::Exists($Receipt) -and [DateTime]::UtcNow -lt $deadline) { Start-Sleep -Milliseconds 50 }
	$data=Get-Content -Raw -LiteralPath $Receipt | ConvertFrom-Json
	$rootHandle=[TsfbSupervisorJob]::OpenProcess(0x100000,$false,[int]$data.pid)
	$childHandle=[TsfbSupervisorJob]::OpenProcess(0x100000,$false,[int]$data.child_pid)
	if ($rootHandle -eq [IntPtr]::Zero -or $childHandle -eq [IntPtr]::Zero) { throw 'Expected original process handles.' }
	$sentinel=Start-Process -FilePath $Php -ArgumentList @('-r','"sleep(30);"') -WindowStyle Hidden -PassThru
	[TsfbSupervisorJob]::CloseHandle($job) | Out-Null
	$job=[IntPtr]::Zero
	$result=[ordered]@{root_exited=([TsfbSupervisorJob]::WaitForSingleObject($rootHandle,5000) -eq 0);descendant_exited=([TsfbSupervisorJob]::WaitForSingleObject($childHandle,5000) -eq 0);unowned_process_preserved=(-not $sentinel.HasExited)}
	$result | ConvertTo-Json -Compress
} finally {
	if ($job -ne [IntPtr]::Zero) { [TsfbSupervisorJob]::CloseHandle($job) | Out-Null }
	if ($rootHandle -ne [IntPtr]::Zero) { [TsfbSupervisorJob]::CloseHandle($rootHandle) | Out-Null }
	if ($childHandle -ne [IntPtr]::Zero) { [TsfbSupervisorJob]::CloseHandle($childHandle) | Out-Null }
	if ($sentinel -and -not $sentinel.HasExited) { $sentinel.Kill(); $sentinel.WaitForExit() }
}
