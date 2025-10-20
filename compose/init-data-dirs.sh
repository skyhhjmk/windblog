#!/bin/bash

# 初始化数据目录脚本
# 用于创建所有需要的数据持久化目录

echo "=================================="
echo "Windblog 数据目录初始化脚本"
echo "=================================="
echo ""

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DATA_DIR="${SCRIPT_DIR}/data"

# 创建主数据目录
if [ ! -d "$DATA_DIR" ]; then
    mkdir -p "$DATA_DIR"
    echo "✓ 创建主数据目录: $DATA_DIR"
else
    echo "✓ 主数据目录已存在: $DATA_DIR"
fi

# 需要创建的子目录列表
SUB_DIRS=(
    "windblog/runtime"
    "windblog/uploads"
    "postgres"
    "redis"
    "rabbitmq"
    "elasticsearch"
    "logstash"
    "kibana"
)

# 创建所有子目录
for dir in "${SUB_DIRS[@]}"; do
    FULL_PATH="${DATA_DIR}/${dir}"
    if [ ! -d "$FULL_PATH" ]; then
        mkdir -p "$FULL_PATH"
        echo "✓ 创建目录: data/${dir}"
    else
        echo "✓ 目录已存在: data/${dir}"
    fi
done

# 设置目录权限 - 遵循最小权限原则
echo ""
echo "设置目录权限..."

# Windblog 应用目录权限 (1000:1000 - 应用用户)
WINDBLOG_RUNTIME="${DATA_DIR}/windblog/runtime"
WINDBLOG_UPLOADS="${DATA_DIR}/windblog/uploads"

# Runtime 目录：应用运行时数据、日志、缓存等
chown -R 1000:1000 "$WINDBLOG_RUNTIME" 2>/dev/null || echo "⚠ Windblog runtime 权限设置失败 (可能需要 sudo)"
chmod 750 "$WINDBLOG_RUNTIME" 2>/dev/null || echo "⚠ Windblog runtime 权限设置失败 (可能需要 sudo)"
echo "✓ Windblog runtime 目录权限已设置 (1000:1000, 750)"

# Uploads 目录：用户上传文件
chown -R 1000:1000 "$WINDBLOG_UPLOADS" 2>/dev/null || echo "⚠ Windblog uploads 权限设置失败 (可能需要 sudo)"
chmod 750 "$WINDBLOG_UPLOADS" 2>/dev/null || echo "⚠ Windblog uploads 权限设置失败 (可能需要 sudo)"
echo "✓ Windblog uploads 目录权限已设置 (1000:1000, 750)"

# PostgreSQL 目录权限 (70:70 - postgres用户)
PG_PATH="${DATA_DIR}/postgres"
chown -R 70:70 "$PG_PATH" 2>/dev/null || echo "⚠ PostgreSQL 权限设置失败 (可能需要 sudo)"
chmod 700 "$PG_PATH" 2>/dev/null || echo "⚠ PostgreSQL 权限设置失败 (可能需要 sudo)"
echo "✓ PostgreSQL 目录权限已设置 (70:70, 700)"

# Redis 目录权限 (999:999 - redis用户)
REDIS_PATH="${DATA_DIR}/redis"
chown -R 999:999 "$REDIS_PATH" 2>/dev/null || echo "⚠ Redis 权限设置失败 (可能需要 sudo)"
chmod 750 "$REDIS_PATH" 2>/dev/null || echo "⚠ Redis 权限设置失败 (可能需要 sudo)"
echo "✓ Redis 目录权限已设置 (999:999, 750)"

# RabbitMQ 目录权限 (999:999 - rabbitmq用户)
RABBITMQ_PATH="${DATA_DIR}/rabbitmq"
chown -R 999:999 "$RABBITMQ_PATH" 2>/dev/null || echo "⚠ RabbitMQ 权限设置失败 (可能需要 sudo)"
chmod 750 "$RABBITMQ_PATH" 2>/dev/null || echo "⚠ RabbitMQ 权限设置失败 (可能需要 sudo)"
echo "✓ RabbitMQ 目录权限已设置 (999:999, 750)"

# Elasticsearch 目录权限 (1000:1000 - elasticsearch用户)
ES_PATH="${DATA_DIR}/elasticsearch"
chown -R 1000:1000 "$ES_PATH" 2>/dev/null || echo "⚠ Elasticsearch 权限设置失败 (可能需要 sudo)"
chmod 750 "$ES_PATH" 2>/dev/null || echo "⚠ Elasticsearch 权限设置失败 (可能需要 sudo)"
echo "✓ Elasticsearch 目录权限已设置 (1000:1000, 750)"

# Logstash 目录权限 (1000:1000 - logstash用户)
LOGSTASH_PATH="${DATA_DIR}/logstash"
chown -R 1000:1000 "$LOGSTASH_PATH" 2>/dev/null || echo "⚠ Logstash 权限设置失败 (可能需要 sudo)"
chmod 750 "$LOGSTASH_PATH" 2>/dev/null || echo "⚠ Logstash 权限设置失败 (可能需要 sudo)"
echo "✓ Logstash 目录权限已设置 (1000:1000, 750)"

# Kibana 目录权限 (1000:1000 - kibana用户)
KIBANA_PATH="${DATA_DIR}/kibana"
chown -R 1000:1000 "$KIBANA_PATH" 2>/dev/null || echo "⚠ Kibana 权限设置失败 (可能需要 sudo)"
chmod 750 "$KIBANA_PATH" 2>/dev/null || echo "⚠ Kibana 权限设置失败 (可能需要 sudo)"
echo "✓ Kibana 目录权限已设置 (1000:1000, 750)"


echo ""
echo "=================================="
echo "目录结构创建完成！"
echo "=================================="
echo ""
echo "数据目录位置: $DATA_DIR"
echo ""
echo "目录说明:"
echo "  - data/windblog/runtime   应用运行时数据、日志"
echo "  - data/windblog/uploads   应用上传文件"
echo "  - data/postgres           PostgreSQL 数据库文件"
echo "  - data/redis              Redis 持久化数据"
echo "  - data/rabbitmq           RabbitMQ 数据"
echo "  - data/elasticsearch      Elasticsearch 索引数据"
echo "  - data/logstash           Logstash 数据"
echo "  - data/kibana             Kibana 数据"
echo ""
echo "现在可以运行: docker-compose -f all-in-one.yml --env-file .env up -d"
echo ""
