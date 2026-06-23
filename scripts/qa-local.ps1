param(
	[switch] $E2E,
	[switch] $KeepCompose
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
		throw "Missing required command: $Name"
	}
}

Test-QACommand 'php'
Test-QACommand 'composer'
Test-QACommand 'git'

Invoke-QACommand 'Install Composer dependencies' 'composer' @('install', '--no-interaction')
Invoke-QACommand 'Run Composer QA checks' 'composer' @('qa')

if ($E2E) {
	Test-QACommand 'docker'
	Test-QACommand 'bash'

	$keepComposeValue = if ($KeepCompose) { '1' } else { '0' }

	Invoke-QACommand 'Run Docker E2E QA' 'bash' @('-lc', "E2E_MANAGE_COMPOSE=1 E2E_KEEP_COMPOSE=$keepComposeValue bash scripts/e2e-test.sh")
}

Write-Host 'Local QA completed successfully.'
