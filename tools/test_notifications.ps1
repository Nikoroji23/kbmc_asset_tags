<#
PowerShell helper: test_notifications.ps1

Purpose:
- Hit notification-related endpoints on a local KBMC instance and log responses.

Usage examples:
# Basic run (no auth):
#   .\test_notifications.ps1
# Provide base URL and optionally a PHP session cookie (to test authenticated endpoints):
#   .\test_notifications.ps1 -BaseUrl "http://localhost/kbmc_new_asset" -PhpSession "PHPSESSID=abcd1234"
# Provide an attachment file for endpoints that accept file uploads:
#   .\test_notifications.ps1 -AttachmentPath ".\\assets\\sample.png"
#
Parameters:
 -BaseUrl (string) - base URL to the app (default: http://localhost/kbmc_new_asset)
 -PhpSession (string) - optional cookie header value (e.g. "PHPSESSID=...; other=...")
 -AttachmentPath (string) - optional path to a file for endpoints that accept uploads
 -OutLog (string) - log file path (default: logs\notification_test_results.log)
#>

param(
    [string]$BaseUrl = "http://localhost/kbmc_new_asset",
    [string]$PhpSession = "",
    [string]$AttachmentPath = "",
    [string]$OutLog = "logs\notification_test_results.log"
)

if (!(Test-Path -Path (Split-Path $OutLog))) {
    New-Item -ItemType Directory -Path (Split-Path $OutLog) -Force | Out-Null
}

Function Log-Result {
    param($Endpoint, $Method, $Status, $Body)
    $time = (Get-Date).ToString('yyyy-MM-dd HH:mm:ss')
    $entry = "[$time] $Method $Endpoint => $Status`n$Body`n" + ('-'*80) + "`n"
    Add-Content -Path $OutLog -Value $entry -Encoding UTF8
    Write-Host "$Method $Endpoint => $Status"
}

$endpoints = @(
    @{ path = "notifications.php"; method = "GET"; desc = "UI notifications page (GET)" },
    @{ path = "api_voluntary_device_return.php"; method = "POST_JSON"; desc = "Voluntary device return (requires assignment_id)"; sample = @{ assignment_id = 1; return_reason = "Test run" } },
    @{ path = "api_report_device_issue.php"; method = "POST_FORM"; desc = "Report device issue (requires device_id & issue_description)"; sample = @{ device_id = 1; issue_description = "Test issue from script"; severity = "low" } },
    @{ path = "api_send_repair_notification.php"; method = "POST_JSON"; desc = "Send repair notification (requires repair_id)"; sample = @{ repair_id = 1 } },
    @{ path = "api_mark_repair_done.php"; method = "POST_JSON"; desc = "Mark repair done (requires repair_id)"; sample = @{ repair_id = 1; completion_notes = "Completed via test script" } },
    @{ path = "api_send_inspection_notification.php"; method = "POST_JSON"; desc = "Send inspection notification (varies)"; sample = @{} },
    @{ path = "api_send_maintenance_reminder.php"; method = "POST_JSON"; desc = "Maintenance reminder (varies)"; sample = @{} },
    @{ path = "api_send_admin_new_user_notification.php"; method = "POST_JSON"; desc = "Admin new user notification"; sample = @{} },
    @{ path = "api_user_details.php"; method = "GET"; desc = "User details (may require query id)" }
)

Write-Host "Starting notification endpoint checks against $BaseUrl"
Add-Content -Path $OutLog -Value "`n=== Notification test run at $(Get-Date) against $BaseUrl ===`n" -Encoding UTF8

foreach ($e in $endpoints) {
    $url = "$BaseUrl/" + $e.path
    try {
        switch ($e.method) {
            'GET' {
                $resp = Invoke-WebRequest -Uri $url -Method GET -Headers @{ Cookie = $PhpSession } -UseBasicParsing -ErrorAction Stop
                Log-Result $e.path "GET" $resp.StatusCode $resp.Content
            }
            'POST_JSON' {
                $body = $e.sample | ConvertTo-Json -Depth 5
                $headers = @{ 'Content-Type' = 'application/json' }
                if ($PhpSession) { $headers['Cookie'] = $PhpSession }
                $resp = Invoke-WebRequest -Uri $url -Method POST -Body $body -Headers $headers -UseBasicParsing -ErrorAction Stop
                Log-Result $e.path "POST_JSON" $resp.StatusCode $resp.Content
            }
            'POST_FORM' {
                $form = @{}
                foreach ($k in $e.sample.Keys) { $form[$k] = $e.sample[$k] }
                if ($AttachmentPath -and (Test-Path $AttachmentPath)) {
                    # Multipart/form-data with file - use Invoke-WebRequest's -InFile for a single file field named 'attachment'
                    $fileName = Split-Path $AttachmentPath -Leaf
                    $fileField = @{ attachment = Get-Item $AttachmentPath }
                    $headers = @{}
                    if ($PhpSession) { $headers['Cookie'] = $PhpSession }
                    $resp = Invoke-WebRequest -Uri $url -Method POST -Headers $headers -Form $form -InFile $AttachmentPath -ErrorAction Stop
                    Log-Result $e.path "POST_FORM_WITH_FILE" $resp.StatusCode $resp.Content
                } else {
                    $headers = @{}
                    if ($PhpSession) { $headers['Cookie'] = $PhpSession }
                    $resp = Invoke-WebRequest -Uri $url -Method POST -Body $form -Headers $headers -UseBasicParsing -ErrorAction Stop
                    Log-Result $e.path "POST_FORM" $resp.StatusCode $resp.Content
                }
            }
            default {
                Write-Host "Skipping unknown method for $($e.path)"
            }
        }
    } catch [System.Net.WebException] {
        $err = $_.Exception.Response
        if ($err -ne $null) {
            $status = $err.StatusCode.value__
            $stream = $err.GetResponseStream()
            $reader = New-Object System.IO.StreamReader($stream)
            $body = $reader.ReadToEnd()
            Log-Result $e.path $e.method $status $body
        } else {
            Log-Result $e.path $e.method 'NO_RESPONSE' ($_.Exception.Message)
        }
    } catch {
        Log-Result $e.path $e.method 'ERROR' ($_.Exception.Message)
    }
}

Write-Host "Test run complete. Results appended to $OutLog"
