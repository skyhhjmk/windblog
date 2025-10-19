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
    "postgres"
    "redis"
    "rabbitmq"
    "elasticsearch"
    "logstash"
    "kibana"
    "uploads"
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

# 设置 Elasticsearch 目录权限
ES_PATH="${DATA_DIR}/elasticsearch"
chmod 777 "$ES_PATH"
echo "✓ Elasticsearch 目录权限已设置 (777)"

# 设置 uploads 目录权限
UPLOADS_PATH="${DATA_DIR}/uploads"
chmod 777 "$UPLOADS_PATH"
echo "✓ Uploads 目录权限已设置 (777)"

echo ""
echo "=================================="
echo "目录结构创建完成！"
echo "=================================="
echo ""
echo "数据目录位置: $DATA_DIR"
echo ""
echo "目录说明:"
echo "  - data/postgres       PostgreSQL 数据库文件"
echo "  - data/redis          Redis 持久化数据"
echo "  - data/rabbitmq       RabbitMQ 数据"
echo "  - data/elasticsearch  Elasticsearch 索引数据"
echo "  - data/logstash       Logstash 数据"
echo "  - data/kibana         Kibana 数据"
echo "  - data/uploads        应用上传文件"
echo ""
echo "现在可以运行: docker-compose -f all-in-one.yml --env-file .env up -d"
echo ""
