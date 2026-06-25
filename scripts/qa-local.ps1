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

Invoke-QACommand 'Install Composer dependencies' 'composer' @('install', '--no-interaction', '--prefer-dist', '--no-progress')
Invoke-QACommand 'Run Composer QA checks' 'composer' @('qa')

if ($Contract) {
	$keepComposeValue = if ($KeepCompose) { '1' } else { '0' }

	Invoke-QACommand 'Run Docker Ability Contract QA' 'bash' @('-lc', "E2E_MANAGE_COMPOSE=1 E2E_KEEP_COMPOSE=$keepComposeValue bash scripts/e2e-test.sh contract")
}

if ($E2E) {
	$keepComposeValue = if ($KeepCompose) { '1' } else { '0' }

	Invoke-QACommand 'Run Docker E2E QA' 'bash' @('-lc', "E2E_MANAGE_COMPOSE=1 E2E_KEEP_COMPOSE=$keepComposeValue bash scripts/e2e-test.sh e2e")
}

if ($Release) {
	Invoke-QACommand 'Run Release Package QA' 'bash' @('-lc', 'bash scripts/release-qa.sh')
}

Write-Host 'Local QA completed successfully.'
