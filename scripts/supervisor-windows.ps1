# TSF WordPress Benchmark
# Copyright (C) 2026 Sybre Waaijer, CyberWire B.V. (https://cyberwire.nl/)
# SPDX-License-Identifier: GPL-3.0-only
param([Parameter(Mandatory=$true)][string]$ConfigPath)
$ErrorActionPreference = 'Stop'
$jobHandle = [IntPtr]::Zero
$parentHandle = [IntPtr]::Zero
$exitReason = 'startup_failed'
$ownedProcessIds = @()
$config = Get-Content -Raw -LiteralPath $ConfigPath -Encoding UTF8 | ConvertFrom-Json
$utf8 = New-Object Text.UTF8Encoding($false)
function Save-Receipt($Path, $Value) {
	[IO.File]::WriteAllText($Path, ($Value | ConvertTo-Json -Depth 8 -Compress) + "`n", $utf8)
}
try {
	Add-Type -TypeDefinition ([IO.File]::ReadAllText((Join-Path $PSScriptRoot 'supervisor-job.cs')))
	$parentHandle = [TsfbSupervisorJob]::OpenProcess(0x100000, $false, [int]$config.parent_pid)
	if ($parentHandle -eq [IntPtr]::Zero) { throw 'Cannot watch the owning runner.' }
	if ([TsfbSupervisorJob]::WaitForSingleObject($parentHandle, 0) -ne 258) { throw 'Owning runner already exited.' }
	$jobHandle = [TsfbSupervisorJob]::Create()
	$env:PATH = ($config.dll_paths -join ';') + ';' + $env:PATH
	$phpDirectory = Split-Path $config.php_cgi -Parent
	$imagickDirectory = Join-Path $phpDirectory 'ImageMagick'
	if (Test-Path -LiteralPath $imagickDirectory -PathType Container) { $env:MAGICK_CONFIGURE_PATH = $imagickDirectory }
	$ghostscriptDirectory = Join-Path $phpDirectory 'ghostscript/Resource/Init'
	if (Test-Path -LiteralPath $ghostscriptDirectory -PathType Container) { $env:GS_LIB = $ghostscriptDirectory }
	$env:PHP_FCGI_CHILDREN = [string]$config.workers
	$env:PHP_FCGI_MAX_REQUESTS = '500'
	$cgiArguments = '-b 127.0.0.1:' + $config.fastcgi_port
	if ($config.php_ini) { $cgiArguments += ' -c "' + $config.php_ini + '"' }
	$ownedProcessIds += [TsfbSupervisorJob]::Launch($jobHandle, $config.php_cgi, $cgiArguments, $config.directory)
	$ownedProcessIds += [TsfbSupervisorJob]::Launch($jobHandle, $config.nginx, ('-p "' + $config.prefix + '" -c "' + $config.nginx_config + '"'), $config.directory)
	$children = @($ownedProcessIds | ForEach-Object { [Diagnostics.Process]::GetProcessById($_) })
	Save-Receipt $config.ready ([ordered]@{started=$true;root_process_ids=$ownedProcessIds;workers=$config.workers;max_requests=500;parent_pid=$config.parent_pid;controller_pid=$PID;powershell_version=$PSVersionTable.PSVersion.ToString()})
	$exitReason = 'stop_requested'
	while (-not [IO.File]::Exists($config.stop)) {
		if ([TsfbSupervisorJob]::WaitForSingleObject($parentHandle, 200) -ne 258) { $exitReason='owning_runner_exited'; break }
		foreach ($child in $children) {
			if ($child.HasExited) { throw 'An owned server exited unexpectedly.' }
		}
	}
} catch {
	$exitReason = $_.Exception.Message
	Save-Receipt $config.failure ([ordered]@{failed=$true;reason=$exitReason})
} finally {
	if ($jobHandle -ne [IntPtr]::Zero) { [TsfbSupervisorJob]::CloseHandle($jobHandle) | Out-Null }
	foreach ($child in $children) {
		if (-not $child.WaitForExit(5000)) { $exitReason='owned_server_did_not_exit' }
	}
	if ($parentHandle -ne [IntPtr]::Zero) { [TsfbSupervisorJob]::CloseHandle($parentHandle) | Out-Null }
	Save-Receipt $config.stopped ([ordered]@{job_closed=$true;root_process_ids=$ownedProcessIds;reason=$exitReason})
}
