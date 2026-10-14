
$folder = "Z:\RetailomaticsCloud\RetailomaticsArchive\YBC"
$barcode = "7290115180251"

$excel = New-Object -ComObject Excel.Application
$excel.Visible = $false
$excel.DisplayAlerts = $false
$excel.AskToUpdateLinks = $false

$results = @()

$files = @(Get-ChildItem -LiteralPath $folder -Recurse -File |
    Where-Object {
        $_.Extension -in @(".xlsx", ".xls", ".xlsm") -and
        $_.Name -notlike '~$*'
    })

Write-Host "Found $($files.Count) Excel files."

$i = 0

try {
    foreach ($file in $files) {
        $i++
        Write-Progress -Activity "Searching Excel files" `
            -Status "$i / $($files.Count): $($file.Name)" `
            -PercentComplete (($i / $files.Count) * 100)

        $wb = $null

        try {
            $wb = $excel.Workbooks.Open(
                $file.FullName, 0, $true
            )

            foreach ($sheet in $wb.Worksheets) {
                $range = $sheet.UsedRange

                # Search values using Excel Find
                $found = $range.Find(
                    $barcode,
                    [Type]::Missing,
                    -4163,
                    1,
                    1,
                    1,
                    $false,
                    [Type]::Missing,
                    $false
                )

                if ($null -ne $found) {
                    $result = [PSCustomObject]@{
                        File = $file.Name
                        Date = $file.LastWriteTime
                        Sheet = $sheet.Name
                        Cell = $found.Address()
                        Path = $file.FullName
                    }

                    $results += $result

                    Write-Host "FOUND: $($file.Name)" `
                        -ForegroundColor Green
                }
            }
        }
        catch {
            Write-Warning "$($file.Name): $($_.Exception.Message)"
        }
        finally {
            if ($null -ne $wb) {
                $wb.Close($false)
            }
        }
    }
}
finally {
    Write-Progress -Activity "Searching Excel files" -Completed
    $excel.Quit()
}

Write-Host "`nSEARCH RESULTS:"
$results | Format-Table -AutoSize

$output = Join-Path ([Environment]::GetFolderPath("Desktop")) `
    "BarcodeSearchResults.csv"

$results | Export-Csv -Path $output `
    -NoTypeInformation -Encoding UTF8

Write-Host "Results saved to: $output"
