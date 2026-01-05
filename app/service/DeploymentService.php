<?php

namespace app\service;

use support\Log;

class DeploymentService
{
    private string $templateDir;

    private string $outputDir;

    public function __construct()
    {
        $this->templateDir = base_path('template');
        $this->outputDir = runtime_path('deployments');
        if (!is_dir($this->outputDir)) {
            mkdir($this->outputDir, 0o755, true);
        }
    }

    public function generateDeployment(string $nodeId, array $nodeInfo): array
    {
        try {
            $dockerComposeContent = $this->generateDockerCompose($nodeInfo);
            $envContent = $this->generateEnv($nodeInfo);
            $scriptContent = $this->generateScript($nodeInfo);

            $deploymentDir = $this->outputDir . DIRECTORY_SEPARATOR . $nodeId;
            if (!is_dir($deploymentDir)) {
                mkdir($deploymentDir, 0o755, true);
            }

            $dockerComposePath = $deploymentDir . DIRECTORY_SEPARATOR . 'docker-compose.yml';
            $envPath = $deploymentDir . DIRECTORY_SEPARATOR . '.env';
            $scriptPath = $deploymentDir . DIRECTORY_SEPARATOR . 'deploy.sh';

            file_put_contents($dockerComposePath, $dockerComposeContent);
            file_put_contents($envPath, $envContent);
            file_put_contents($scriptPath, $scriptContent);
            chmod($scriptPath, 0o755);

            return [
                'success' => true,
                'docker_compose' => $dockerComposeContent,
                'env' => $envContent,
                'script' => $scriptContent,
                'docker_compose_path' => $dockerComposePath,
                'env_path' => $envPath,
                'script_path' => $scriptPath,
                'deployment_dir' => $deploymentDir,
            ];
        } catch (\Throwable $e) {
            Log::error('[DeploymentService] Failed to generate deployment: ' . $e->getMessage());

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    private function generateDockerCompose(array $nodeInfo): string
    {
        $content = 'version: \'3.8\'

services:
  windblog:
    image: hhjmk/windblog:latest
    container_name: windblog_edge
    ports:
      - "8787:8787"
    volumes:
      - ./data/windblog/runtime:/app/runtime
      - ./data/windblog/uploads:/app/public/uploads
      - ./data/windblog/static_cache:/app/public/cache
    environment:
      - APP_ENV=production
      - DEPLOYMENT_TYPE=edge
      - DB_DEFAULT=edge
      - REDIS_HOST=redis
      - REDIS_PORT=6379
      - REDIS_PASSWORD=
      - REDIS_DATABASE=0
      - CACHE_DRIVER=redis
      - IN_CONTAINER=true
    depends_on:
      - redis
    restart: unless-stopped

  redis:
    image: redis:8-alpine
    container_name: windblog_redis
    ports:
      - "6379:6379"
    volumes:
      - redis_data:/data
    restart: unless-stopped

volumes:
  redis_data:';

        return $content;
    }

    private function generateEnv(array $nodeInfo): string
    {
        $content = "# WindBlog Edge Node Configuration\n# Generated at " . date('Y-m-d H:i:s') . "\n\n# Basic Settings\nAPP_DEBUG=false\nAPP_ENV=production\nDEPLOYMENT_TYPE=edge\nIN_CONTAINER=true\n\n# Database Settings\nDB_DEFAULT=edge\n\n# Redis Settings\nCACHE_DRIVER=redis\nREDIS_HOST=localhost\nREDIS_PORT=6379\nREDIS_PASSWORD=\nREDIS_DATABASE=0\n\n# Edge Node Settings\nEDGE_DATACENTER_URL=" . ($nodeInfo['datacenter_url'] ?? '') . "\nEDGE_SYNC_INTERVAL=300\nEDGE_DEGRADE_ENABLED=true\nEDGE_API_KEY=" . $nodeInfo['api_key'] . "\n\n# Node Information\nNODE_NAME=" . $nodeInfo['name'] . "\nNODE_ID=" . $nodeInfo['id'] . "\nNODE_URL=" . $nodeInfo['url'] . "\nNODE_BANDWIDTH=" . $nodeInfo['bandwidth'] . "\nNODE_CPU=" . $nodeInfo['cpu'] . "\nNODE_MEMORY=" . $nodeInfo['memory'] . "\n";

        return $content;
    }

    private function generateScript(array $nodeInfo): string
    {
        $content = '#!/bin/bash

set -e

echo "=================================="
echo "WindBlog Edge Node Deployment Script"
echo "Generated at ' . date('Y-m-d H:i:s') . '"
echo "=================================="
echo ""

# 检查 Docker 安装
echo "1. Checking Docker installation..."
if ! command -v docker &> /dev/null; then
    echo "ERROR: Docker is not installed. Please install Docker first."
    exit 1
fi

if ! command -v docker-compose &> /dev/null; then
    echo "ERROR: Docker Compose is not installed. Please install Docker Compose first."
    exit 1
fi

echo "✓ Docker and Docker Compose are installed."

# 创建数据目录
echo ""
echo "2. Creating data directories..."

DATA_DIR="./data"

# 创建主数据目录
if [ ! -d "$DATA_DIR" ]; then
    mkdir -p "$DATA_DIR"
    echo "✓ Created main data directory: $DATA_DIR"
else
    echo "✓ Main data directory already exists: $DATA_DIR"
fi

# 需要创建的子目录列表
SUB_DIRS=(
    "windblog/runtime"
    "windblog/uploads"
    "windblog/static_cache"
    "redis"
)

# 创建所有子目录
for dir in "${SUB_DIRS[@]}"; do
    FULL_PATH="${DATA_DIR}/${dir}"
    if [ ! -d "$FULL_PATH" ]; then
        mkdir -p "$FULL_PATH"
        echo "✓ Created directory: data/${dir}"
    else
        echo "✓ Directory already exists: data/${dir}"
    fi
done

# 设置目录权限
echo ""
echo "3. Setting directory permissions..."

# Windblog 应用目录权限 (1000:1000 - 应用用户)
WINDBLOG_RUNTIME="${DATA_DIR}/windblog/runtime"
WINDBLOG_UPLOADS="${DATA_DIR}/windblog/uploads"
WINDBLOG_CACHE="${DATA_DIR}/windblog/static_cache"

# Runtime 目录：应用运行时数据、日志、缓存等
chown -R 1000:1000 "$WINDBLOG_RUNTIME" 2>/dev/null || echo "⚠ Windblog runtime permission setting failed (may need sudo)"
chmod 750 "$WINDBLOG_RUNTIME" 2>/dev/null || echo "⚠ Windblog runtime permission setting failed (may need sudo)"
echo "✓ Windblog runtime directory permissions set (1000:1000, 750)"

# Uploads 目录：用户上传文件
chown -R 1000:1000 "$WINDBLOG_UPLOADS" 2>/dev/null || echo "⚠ Windblog uploads permission setting failed (may need sudo)"
chmod 750 "$WINDBLOG_UPLOADS" 2>/dev/null || echo "⚠ Windblog uploads permission setting failed (may need sudo)"
echo "✓ Windblog uploads directory permissions set (1000:1000, 750)"

# Cache 目录：静态缓存
mkdir -p "$WINDBLOG_CACHE"
chown -R 1000:1000 "$WINDBLOG_CACHE" 2>/dev/null || echo "⚠ Windblog static_cache permission setting failed (may need sudo)"
chmod 750 "$WINDBLOG_CACHE" 2>/dev/null || echo "⚠ Windblog static_cache permission setting failed (may need sudo)"
echo "✓ Windblog static_cache directory permissions set (1000:1000, 750)"

# Redis 目录权限 (999:999 - redis用户)
REDIS_PATH="${DATA_DIR}/redis"
chown -R 999:999 "$REDIS_PATH" 2>/dev/null || echo "⚠ Redis data permission setting failed (may need sudo)"
chmod 750 "$REDIS_PATH" 2>/dev/null || echo "⚠ Redis data permission setting failed (may need sudo)"
echo "✓ Redis data directory permissions set (999:999, 750)"

# 启动服务
echo ""
echo "4. Starting services..."
docker-compose up -d
echo "✓ Services started."

# 等待服务初始化
echo ""
echo "5. Waiting for services to initialize..."
sleep 10
echo "✓ Services initialized."

# 检查服务状态
echo ""
echo "6. Checking service status..."
docker-compose ps

# 检查应用健康状态
echo ""
echo "7. Checking application health..."
if command -v curl &> /dev/null; then
    if curl -s -f http://localhost:8787 > /dev/null; then
        echo "✓ Application is healthy!"
    else
        echo "⚠ Application might not be fully ready yet. Please check logs for details."
    fi
else
    echo "✓ Application health check skipped (curl not installed)."
fi

echo ""
echo "=================================="
echo "Deployment completed!"
echo "=================================="
echo ""
echo "Edge Node URL: http://localhost:8787"
echo "Node ID: ' . $nodeInfo['id'] . '"
echo "Node Name: ' . $nodeInfo['name'] . '"
echo ""
echo "Data directories created at: ./data"
echo "  - data/windblog/runtime   Application runtime data and logs"
echo "  - data/windblog/uploads   User uploaded files"
echo "  - data/windblog/static_cache   Static file cache"
echo "  - data/redis              Redis persistence data"
echo ""
echo "Common commands:"
echo "  Start services:  docker-compose up -d"
echo "  Stop services:   docker-compose down"
echo "  View logs:       docker-compose logs -f"
echo "  Restart services: docker-compose restart"
echo "  Check status:    docker-compose ps"
echo ""
echo "=================================="';

        return $content;
    }

    public function generateManualPackage(string $nodeId, array $nodeInfo): string
    {
        $deployment = $this->generateDeployment($nodeId, $nodeInfo);
        if (!$deployment['success']) {
            throw new \RuntimeException('Failed to generate deployment: ' . $deployment['message']);
        }

        $zipPath = $this->outputDir . DIRECTORY_SEPARATOR . $nodeId . '_deployment.zip';
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Failed to create zip archive');
        }

        $files = [
            $deployment['docker_compose_path'],
            $deployment['env_path'],
            $deployment['script_path'],
        ];

        foreach ($files as $file) {
            $zip->addFile($file, basename($file));
        }

        $zip->close();

        return $zipPath;
    }
}
