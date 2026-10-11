param(
	[switch] $Contract,
	[switch] $E2E,
	[switch] $Release,
	[switch] $KeepCompose,
	[switch] $PreflightOnly
)

$ErrorActionPreference = 'Stop'

function Invoke-QACommand {
	param(
		[string] $Label,
		[string] $FilePath,
		[string[]] $ArgumentList
	)

	Write-Host "==> $Label"
	& $FilePath @ArgumentList
	if ($LASTEXITCODE -ne 0) {
		throw "$Label failed with exit code $LASTEXITCODE."
	}
}

function Test-QACommand {
	param([string] $Name)

	if (-not (Get-Command $Name -ErrorAction SilentlyContinue)) {
		$hint = switch ($Name) {
			'php' { 'Install PHP 8.0+ and restart the terminal so php is on PATH. On Windows, winget package PHP.PHP.8.2 is a supported option.' }
			'composer' { 'Install Composer and restart the terminal so composer is on PATH. See CONTRIBUTING.md > Local QA commands for Windows setup notes.' }
			'docker' { 'Install Docker Desktop and ensure docker is on PATH before running E2E.' }
			'bash' { 'Install Git for Windows or another Bash provider before running E2E.' }
			default { 'Install it and restart the terminal so it is available on PATH.' }
		}

		throw "Missing required command: $Name. $hint"
	}
}

Test-QACommand 'php'
Test-QACommand 'composer'
Test-QACommand 'git'

if ($Contract -or $E2E -or $Release) {
	Test-QACommand 'docker'
	Test-QACommand 'bash'
}

if ($PreflightOnly) {
	Write-Host 'Local QA prerequisites are available.'
	exit 0
}

if (-not (Test-Path -LiteralPath 'vendor\autoload.php' -PathType Leaf)) {
	Invoke-QACommand 'Install missing Composer dependencies' 'composer' @('install', '--no-interaction', '--prefer-dist', '--no-progress')
}
$previousComposerTimeout = [Environment]::GetEnvironmentVariable('COMPOSER_PROCESS_TIMEOUT', 'Process')
try {
	$env:COMPOSER_PROCESS_TIMEOUT = '0'
	Invoke-QACommand 'Run Composer QA checks' 'composer' @('qa')
} finally {
	if ($null -eq $previousComposerTimeout) {
		$env:COMPOSER_PROCESS_TIMEOUT = $null
	} else {
		[Environment]::SetEnvironmentVariable('COMPOSER_PROCESS_TIMEOUT', $previousComposerTimeout, 'Process')
	}
}

if ($Contract -or $E2E -or $Release) {
	Invoke-QACommand 'Check local Docker engine' 'docker' @('info', '--format', '{{.ServerVersion}}')
	$project = 'wstm-local-' + [guid]::NewGuid().ToString('N').Substring(0, 16)
	$names = @('COMPOSE_PROJECT_NAME', 'E2E_ARTIFACTS_DIR', 'E2E_MANAGE_COMPOSE', 'E2E_KEEP_COMPOSE', 'WSTM_QA_OWNER', 'MSYS_NO_PATHCONV')
	$previous = @{}
	foreach ($name in $names) {
		$previous[$name] = [Environment]::GetEnvironmentVariable($name, 'Process')
	}
	try {
		$env:MSYS_NO_PATHCONV = '1'
		$env:E2E_MANAGE_COMPOSE = '1'
		$env:E2E_KEEP_COMPOSE = if ($KeepCompose) { '1' } else { '0' }
		if ($Contract -or $E2E) {
			$mode = if ($Contract -and $E2E) { 'all' } elseif ($E2E) { 'e2e' } else { 'contract' }
			$env:WSTM_QA_OWNER = $null
			$env:COMPOSE_PROJECT_NAME = "$project-source"
			$env:E2E_ARTIFACTS_DIR = "e2e-artifacts-$($env:COMPOSE_PROJECT_NAME)"
			Invoke-QACommand 'Run owned Docker source QA' 'bash' @('scripts/e2e-test.sh', $mode)
		}
		if ($Release) {
			$env:WSTM_QA_OWNER = $null
			$env:COMPOSE_PROJECT_NAME = "$project-package"
			$env:E2E_ARTIFACTS_DIR = "e2e-artifacts-$($env:COMPOSE_PROJECT_NAME)"
			Invoke-QACommand 'Run owned original-ZIP Package QA' 'bash' @('scripts/release-qa.sh')
		}
	} finally {
		foreach ($name in $names) {
			[Environment]::SetEnvironmentVariable($name, $previous[$name], 'Process')
		}
	}
}

Write-Host 'Local QA completed successfully.'
