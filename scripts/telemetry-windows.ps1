<#
TSF WordPress Benchmark
Copyright (C) 2026 Sybre Waaijer, CyberWire B.V. (https://cyberwire.nl/)
SPDX-License-Identifier: GPL-3.0-only
#>

<#
.SYNOPSIS
Samples read-only host counters without repeated CIM queries or elevation.
.DESCRIPTION
One persistent sampler records explicit missing readings, using run-owned output
and stop files. Windows processor-group counters are never labelled whole-host.
#>
param(
	[Parameter(Mandatory = $true)]
	[string]$OutputPath,
	[Parameter(Mandatory = $true)]
	[string]$StopPath,
	[ValidateRange(1, 60)]
	[int]$IntervalSeconds = 2
)

$ErrorActionPreference = 'Stop'

# Read-only OS calls avoid repeated CIM queries competing with the HTTP server.
Add-Type -TypeDefinition @'
using System;
using System.Runtime.InteropServices;

/// <summary>Native host counters requiring no elevated access.</summary>
public static class BenchmarkHostCounters
{
	[StructLayout(LayoutKind.Sequential)]
	public struct FileTime
	{
		public uint Low;
		public uint High;

		public ulong Value
		{
			get { return ((ulong)High << 32) | Low; }
		}
	}

	[StructLayout(LayoutKind.Sequential)]
	public class MemoryStatus
	{
		public uint Length = (uint)Marshal.SizeOf(typeof(MemoryStatus));
		public uint Load;
		public ulong TotalPhysical;
		public ulong AvailablePhysical;
		public ulong TotalPageFile;
		public ulong AvailablePageFile;
		public ulong TotalVirtual;
		public ulong AvailableVirtual;
		public ulong AvailableExtendedVirtual;
	}

	[StructLayout(LayoutKind.Sequential)]
	public struct PowerStatus
	{
		public byte ACLineStatus;
		public byte BatteryFlag;
		public byte BatteryLifePercent;
		public byte SystemStatusFlag;
		public uint BatteryLifeTime;
		public uint BatteryFullLifeTime;
	}

	[DllImport("kernel32.dll", SetLastError = true)]
	public static extern bool GetSystemTimes(
		out FileTime idle,
		out FileTime kernel,
		out FileTime user
	);

	[DllImport("kernel32.dll", SetLastError = true)]
	public static extern bool GlobalMemoryStatusEx([In, Out] MemoryStatus memory);

	[DllImport("kernel32.dll", SetLastError = true)]
	public static extern bool GetSystemPowerStatus(out PowerStatus power);

	[DllImport("kernel32.dll")]
	public static extern uint GetActiveProcessorCount(ushort groupNumber);

	[DllImport("kernel32.dll")]
	public static extern ushort GetActiveProcessorGroupCount();

	[DllImport("powrprof.dll")]
	public static extern uint PowerGetActiveScheme(
		IntPtr userRootPowerKey,
		out IntPtr activePolicyGuid
	);

	[DllImport("kernel32.dll")]
	public static extern IntPtr LocalFree(IntPtr value);
}
'@

<#
.SYNOPSIS
Builds a typed reading with source, unit, and explicit availability.
#>
function Metric($value, [string]$unit, [string]$source, [string]$status = 'available') {
	return [ordered]@{
		value  = $value
		unit   = $unit
		source = $source
		status = $status
	}
}

<#
.SYNOPSIS
Represents an unavailable sensor without inventing a zero-valued reading.
#>
function Missing([string]$unit, [string]$source, [string]$reason = 'sensor_unavailable') {
	return Metric $null $unit $source $reason
}

$writer           = [System.IO.StreamWriter]::new($OutputPath, $true, [System.Text.UTF8Encoding]::new($false))
$writer.AutoFlush = $true
$clock            = [System.Diagnostics.Stopwatch]::StartNew()
$lastCpu          = $null
$lastProcessTime  = [Diagnostics.Process]::GetCurrentProcess().TotalProcessorTime.TotalSeconds
$lastElapsed      = 0.0
$logical          = [BenchmarkHostCounters]::GetActiveProcessorCount(0xffff)
$groups           = [BenchmarkHostCounters]::GetActiveProcessorGroupCount()

try {
	while (-not [IO.File]::Exists($StopPath)) {
		$metrics = [ordered]@{}
		$idle    = [BenchmarkHostCounters+FileTime]::new()
		$kernel  = [BenchmarkHostCounters+FileTime]::new()
		$user    = [BenchmarkHostCounters+FileTime]::new()

		if ($groups -ne 1) {
			# GetSystemTimes only covers the calling group on multi-group hosts.
			$metrics.cpu_busy = Missing 'percent_of_host_capacity' 'Windows GetSystemTimes' 'whole_host_processor_group_coverage_unavailable'
		} elseif ([BenchmarkHostCounters]::GetSystemTimes([ref]$idle, [ref]$kernel, [ref]$user)) {
			$current = @{
				idle  = [double]$idle.Value
				total = [double]$kernel.Value + [double]$user.Value
			}

			if ($null -ne $lastCpu) {
				$duration     = $current.total - $lastCpu.total
				$idleDuration = $current.idle - $lastCpu.idle

				if ($duration -gt 0 -and $idleDuration -ge 0 -and $idleDuration -le $duration) {
					$metrics.cpu_busy = Metric ([Math]::Round(100 * (1 - $idleDuration / $duration), 4)) 'percent_of_host_capacity' 'Windows GetSystemTimes'
				} else {
					$metrics.cpu_busy = Missing 'percent_of_host_capacity' 'Windows GetSystemTimes' 'counter_reset_or_invalid_interval'
				}
			} else {
				$metrics.cpu_busy = Missing 'percent_of_host_capacity' 'Windows GetSystemTimes' 'warming_up'
			}

			$lastCpu = $current
		} else {
			$metrics.cpu_busy = Missing 'percent_of_host_capacity' 'Windows GetSystemTimes' 'provider_unavailable_or_permission_denied'
		}

		$memory = [BenchmarkHostCounters+MemoryStatus]::new()

		if ([BenchmarkHostCounters]::GlobalMemoryStatusEx($memory)) {
			$metrics.available_memory = Metric ([double]$memory.AvailablePhysical) 'bytes' 'GlobalMemoryStatusEx'
			$metrics.total_memory     = Metric ([double]$memory.TotalPhysical) 'bytes' 'GlobalMemoryStatusEx'
			$metrics.memory_load      = Metric ([double]$memory.Load) 'percent' 'GlobalMemoryStatusEx'
			$metrics.commit_available = Metric ([double]$memory.AvailablePageFile) 'bytes' 'GlobalMemoryStatusEx'
		} else {
			$metrics.available_memory = Missing 'bytes' 'GlobalMemoryStatusEx' 'provider_unavailable_or_permission_denied'
			$metrics.total_memory     = Missing 'bytes' 'GlobalMemoryStatusEx' 'provider_unavailable_or_permission_denied'
			$metrics.memory_load      = Missing 'percent' 'GlobalMemoryStatusEx' 'provider_unavailable_or_permission_denied'
			$metrics.commit_available = Missing 'bytes' 'GlobalMemoryStatusEx' 'provider_unavailable_or_permission_denied'
		}

		$metrics.paging_rate      = Missing 'pages_per_second' 'No low-overhead paging-rate collector configured' 'unsupported'
		$metrics.cpu_temperature  = Missing 'celsius' 'No approved CPU-specific sensor provider' 'unsupported'
		$metrics.thermal_pressure = Missing 'state' 'No standard Windows thermal-pressure provider' 'unsupported'
		$metrics.effective_clock  = Missing 'hertz' 'No approved effective-clock provider' 'unsupported'
		$power                   = [BenchmarkHostCounters+PowerStatus]::new()

		if ([BenchmarkHostCounters]::GetSystemPowerStatus([ref]$power)) {
			$source = switch ($power.ACLineStatus) {
				0 { 'battery' }
				1 { 'ac' }
				default { $null }
			}
			$sourceStatus = if ($null -eq $source) {
				'unknown'
			} else {
				'available'
			}
			$metrics.power_source  = Metric $source 'state' 'GetSystemPowerStatus' $sourceStatus
			$metrics.battery_saver = Metric ([bool]$power.SystemStatusFlag) 'boolean' 'GetSystemPowerStatus'
		} else {
			$metrics.power_source  = Missing 'state' 'GetSystemPowerStatus' 'provider_unavailable_or_permission_denied'
			$metrics.battery_saver = Missing 'boolean' 'GetSystemPowerStatus' 'provider_unavailable_or_permission_denied'
		}

		$policy = [IntPtr]::Zero

		try {
			if ([BenchmarkHostCounters]::PowerGetActiveScheme([IntPtr]::Zero, [ref]$policy) -eq 0) {
				$guid = [Runtime.InteropServices.Marshal]::PtrToStructure($policy, [type][Guid])
				$metrics.power_plan = Metric $guid.ToString() 'scheme_guid' 'PowerGetActiveScheme'
			} else {
				$metrics.power_plan = Missing 'scheme_guid' 'PowerGetActiveScheme' 'provider_unavailable_or_permission_denied'
			}
		} finally {
			if ($policy -ne [IntPtr]::Zero) {
				[BenchmarkHostCounters]::LocalFree($policy) | Out-Null
			}
		}

		$elapsed     = $clock.Elapsed.TotalSeconds
		$processTime = [Diagnostics.Process]::GetCurrentProcess().TotalProcessorTime.TotalSeconds

		if ($elapsed -gt $lastElapsed) {
			$metrics.collector_cpu = Metric (100 * ($processTime - $lastProcessTime) / ($elapsed - $lastElapsed)) 'percent_of_one_logical_cpu' 'collector process CPU time'
		} else {
			$metrics.collector_cpu = Missing 'percent_of_one_logical_cpu' 'collector process CPU time' 'warming_up'
		}

		$lastElapsed     = $elapsed
		$lastProcessTime = $processTime
		$row = [ordered]@{
			schema             = 1
			timestamp          = [DateTime]::UtcNow.ToString('o')
			monotonic_seconds  = $elapsed
			host_scope         = 'collector_host'
			logical_processors = $logical
			processor_groups   = $groups
			cpu_scope          = 'whole_host_only_when_one_processor_group'
			metrics            = $metrics
		}

		$writer.WriteLine(($row | ConvertTo-Json -Depth 8 -Compress))
		$next = $clock.Elapsed.TotalSeconds + $IntervalSeconds

		while ($clock.Elapsed.TotalSeconds -lt $next -and -not [IO.File]::Exists($StopPath)) {
			Start-Sleep -Milliseconds 100
		}
	}
} finally {
	$writer.Dispose()
}
