# 初始化数据目录脚本
# 用于创建所有需要的数据持久化目录

#Write-Host "==================================" -ForegroundColor Cyan
#Write-Host "Windblog 数据目录初始化脚本" -ForegroundColor Cyan
#Write-Host "==================================" -ForegroundColor Cyan
#Write-Host ""

$dataDir = Join-Path $PSScriptRoot "data"

# 创建主数据目录
if (-not (Test-Path $dataDir)) {
    New-Item -ItemType Directory -Path $dataDir -Force | Out-Null
#    Write-Host "✓ 创建主数据目录: $dataDir" -ForegroundColor Green
} else {
#    Write-Host "✓ 主数据目录已存在: $dataDir" -ForegroundColor Yellow
}

# 需要创建的子目录列表
$subDirs = @(
    "postgres"
    "redis"
    "rabbitmq"
    "elasticsearch"
    "logstash"
    "kibana"
    "uploads"
)

# 创建所有子目录
foreach ($dir in $subDirs) {
    $fullPath = Join-Path $dataDir $dir
    if (-not (Test-Path $fullPath)) {
        New-Item -ItemType Directory -Path $fullPath -Force | Out-Null
#        Write-Host "✓ 创建目录: data/$dir" -ForegroundColor Green
    } else {
#        Write-Host "✓ 目录已存在: data/$dir" -ForegroundColor Yellow
    }
}

#Write-Host ""
#Write-Host "==================================" -ForegroundColor Cyan
#Write-Host "目录结构创建完成！" -ForegroundColor Green
#Write-Host "==================================" -ForegroundColor Cyan
#Write-Host ""
#Write-Host "数据目录位置: $dataDir" -ForegroundColor White
#Write-Host ""
#Write-Host "目录说明:" -ForegroundColor White
#Write-Host "  - data/postgres       PostgreSQL 数据库文件" -ForegroundColor Gray
#Write-Host "  - data/redis          Redis 持久化数据" -ForegroundColor Gray
#Write-Host "  - data/rabbitmq       RabbitMQ 数据" -ForegroundColor Gray
#Write-Host "  - data/elasticsearch  Elasticsearch 索引数据" -ForegroundColor Gray
#Write-Host "  - data/logstash       Logstash 数据" -ForegroundColor Gray
#Write-Host "  - data/kibana         Kibana 数据" -ForegroundColor Gray
#Write-Host "  - data/uploads        应用上传文件" -ForegroundColor Gray
#Write-Host ""

# 设置 elasticsearch 和 logstash 目录权限（仅在 Linux/Mac 上需要）
if ($IsLinux -or $IsMacOS) {
#    Write-Host "检测到 Linux/Mac 系统，设置 Elasticsearch 和 Logstash 目录权限..." -ForegroundColor Yellow
    $esPath = Join-Path $dataDir "elasticsearch"
    chown -R 1000:1000 $esPath
#    Write-Host "✓ Elasticsearch 目录权限已设置 (1000:1000)" -ForegroundColor Green
    
    $logstashPath = Join-Path $dataDir "logstash"
    chown -R 1000:1000 $logstashPath
#    Write-Host "✓ Logstash 目录权限已设置 (1000:1000)" -ForegroundColor Green
}

#Write-Host "现在可以运行: docker-compose -f all-in-one.yml --env-file .env up -d" -ForegroundColor Cyan
#Write-Host ""