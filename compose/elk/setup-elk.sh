#!/bin/bash

# ELK 自动配置脚本
# 用于自动创建 Elasticsearch 用户和角色

set -e

ELASTICSEARCH_URL="${ELASTICSEARCH_URL:-http://elasticsearch:9200}"
ELASTIC_USER="${ELASTIC_USER:-elastic}"
ELASTIC_PASSWORD="${ELASTIC_PASSWORD:-changeme}"
KIBANA_PASSWORD="${KIBANA_PASSWORD:-changeme}"
LOGSTASH_PASSWORD="${LOGSTASH_PASSWORD:-changeme}"

echo "等待 Elasticsearch 启动..."
until curl -s "$ELASTICSEARCH_URL" | grep -q "missing authentication credentials"; do
  sleep 5
  echo "Elasticsearch 还未就绪,继续等待..."
done

echo "Elasticsearch 已启动，开始配置用户和角色..."

# 设置 kibana_system 密码
echo "设置 kibana_system 密码..."
until curl -s -X POST -u "$ELASTIC_USER:$ELASTIC_PASSWORD" -H "Content-Type: application/json" \
  "$ELASTICSEARCH_URL/_security/user/kibana_system/_password" \
  -d "{\"password\":\"$KIBANA_PASSWORD\"}" | grep -q "^{}"; do 
  sleep 5
  echo "重试设置 kibana_system 密码..."
done

# 设置 logstash_system 密码
echo "设置 logstash_system 密码..."
until curl -s -X POST -u "$ELASTIC_USER:$ELASTIC_PASSWORD" -H "Content-Type: application/json" \
  "$ELASTICSEARCH_URL/_security/user/logstash_system/_password" \
  -d "{\"password\":\"$LOGSTASH_PASSWORD\"}" | grep -q "^{}"; do 
  sleep 5
  echo "重试设置 logstash_system 密码..."
done

# 创建 logstash writer 角色
echo "创建 logstash writer 角色..."
curl -s -X POST -u "$ELASTIC_USER:$ELASTIC_PASSWORD" -H "Content-Type: application/json" \
  "$ELASTICSEARCH_URL/_security/role/logstash_writer" -d '{
    "cluster": ["manage_index_templates", "monitor", "manage_ilm"],
    "indices": [
      {
        "names": ["windblog-logs-*", "logstash-*"],
        "privileges": ["write", "create", "create_index", "manage", "manage_ilm"]
      }
    ]
  }'

echo ""
echo "======================================"
echo "ELK 配置完成!"
echo "======================================"
echo ""
echo "kibana_system 用户密码已设置"
echo "logstash_system 用户密码已设置"
echo "logstash_writer 角色已创建"
echo ""