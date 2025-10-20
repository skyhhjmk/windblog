#!/bin/bash

# ELK 自动配置脚本
# 用于自动创建 Elasticsearch 用户和角色

# 移除 set -e，避免脚本在命令失败时立即退出
# set -e

ELASTICSEARCH_URL="${ELASTICSEARCH_URL:-http://elasticsearch:9200}"
ELASTIC_USER="${ELASTIC_USER:-elastic}"
ELASTIC_PASSWORD="${ELASTIC_PASSWORD:-changeme}"
KIBANA_PASSWORD="${KIBANA_PASSWORD:-changeme}"
LOGSTASH_PASSWORD="${LOGSTASH_PASSWORD:-changeme}"

echo "等待 Elasticsearch 启动..."
retries=30
until curl -s "$ELASTICSEARCH_URL" | grep -q "missing authentication credentials"; do
  sleep 5
  retries=$((retries-1))
  if [ $retries -le 0 ]; then
    echo "Elasticsearch 启动超时"
    exit 1
  fi
  echo "Elasticsearch 还未就绪,继续等待... (剩余重试次数: $retries)"
done

echo "Elasticsearch 已启动，开始配置用户和角色..."

# 设置 kibana_system 密码
echo "设置 kibana_system 密码..."
if curl -f -s -X POST -u "$ELASTIC_USER:$ELASTIC_PASSWORD" -H "Content-Type: application/json" \
  "$ELASTICSEARCH_URL/_security/user/kibana_system/_password" \
  -d "{\"password\":\"$KIBANA_PASSWORD\"}"; then
  echo "kibana_system 密码设置成功"
else
  echo "kibana_system 密码设置失败"
  # 不退出，继续执行其他配置
fi

# 设置 logstash_system 密码
echo "设置 logstash_system 密码..."
if curl -f -s -X POST -u "$ELASTIC_USER:$ELASTIC_PASSWORD" -H "Content-Type: application/json" \
  "$ELASTICSEARCH_URL/_security/user/logstash_system/_password" \
  -d "{\"password\":\"$LOGSTASH_PASSWORD\"}"; then
  echo "logstash_system 密码设置成功"
else
  echo "logstash_system 密码设置失败"
  # 不退出，继续执行其他配置
fi

# 创建 logstash writer 角色
echo "创建 logstash writer 角色..."
if curl -f -s -X POST -u "$ELASTIC_USER:$ELASTIC_PASSWORD" -H "Content-Type: application/json" \
  "$ELASTICSEARCH_URL/_security/role/logstash_writer" -d '{
    "cluster": ["manage_index_templates", "monitor", "manage_ilm"],
    "indices": [
      {
        "names": ["windblog-logs-*", "logstash-*"],
        "privileges": ["write", "create", "create_index", "manage", "manage_ilm"]
      }
    ]
  }'; then
  echo "logstash_writer 角色创建成功"
else
  echo "logstash_writer 角色创建失败"
  # 不退出，让服务完成
fi

echo ""
echo "======================================"
echo "ELK 配置完成!"
echo "======================================"
echo ""
echo "配置过程已完成，请检查上述输出确认各项配置是否成功"
echo ""
