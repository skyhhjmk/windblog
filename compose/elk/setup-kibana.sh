#!/bin/bash

# Kibana 自动配置脚本
# 用于自动创建索引模式和配置（健壮、幂等、无 JSON 注释）

set -e

KIBANA_URL="${KIBANA_URL:-http://kibana:5601}"
ELASTICSEARCH_URL="${ELASTICSEARCH_URL:-http://elasticsearch:9200}"
ELASTIC_USER="${ELASTIC_USER:-elastic}"
ELASTIC_PASSWORD="${ELASTIC_PASSWORD:-changeme}"

# 等待 Elasticsearch 就绪（返回 200 或 401 即视为联通）
echo "等待 Elasticsearch 启动..."
until code=$(curl -s -o /dev/null -w "%{http_code}" "$ELASTICSEARCH_URL"); [ "$code" = "200" ] || [ "$code" = "401" ]; do
  sleep 5
  echo "Elasticsearch 还未就绪,继续等待... (状态码: $code)"
done

# 等待 Kibana 就绪（检查200状态码）
echo "等待 Kibana 启动..."
until curl -s -o /dev/null -w "%{http_code}" "$KIBANA_URL/api/status" | grep -q "200"; do
  sleep 5
  echo "Kibana 还未就绪,继续等待..."
done

echo "Kibana 已启动,等待其完全就绪..."
sleep 10

# Fleet 初始化（仅当显式启用时）
ENABLE_FLEET="${ENABLE_FLEET:-false}"
if [ "$ENABLE_FLEET" = "true" ]; then
  echo "初始化 Fleet（带重试）..."
  tmp=$(mktemp)
  for i in $(seq 1 60); do
    code=$(curl -s -o "$tmp" -w "%{http_code}" -X POST "$KIBANA_URL/api/fleet/setup" \
      -u "$ELASTIC_USER:$ELASTIC_PASSWORD" \
      -H "kbn-xsrf: true" \
      -H "Content-Type: application/json" \
      -d '{}') || code=000
    body="$(cat "$tmp")"
    if echo "$code" | grep -q '^2'; then
      echo "Fleet setup 成功"
      break
    fi
    if echo "$body" | grep -qi 'Agent binary source needs encrypted saved object api key to be set'; then
      echo "等待 Kibana 生成 Encrypted Saved Objects 内部 API key（$i/60）..."
      sleep 5
      continue
    fi
    echo "Fleet setup 返回($code): $body"
    sleep 5
  done
  rm -f "$tmp"

  # 确保 Fleet 关键 component templates 存在（离线环境兜底）
  for tpl in ".fleet_globals-1" ".fleet_agent_id_verification-1"; do
    code=$(curl -s -o /dev/null -w "%{http_code}" -u "$ELASTIC_USER:$ELASTIC_PASSWORD" "$ELASTICSEARCH_URL/_component_template/$tpl")
    if [ "$code" = "404" ]; then
      echo "创建缺失的 component template: $tpl"
      curl -sS -f -X PUT "$ELASTICSEARCH_URL/_component_template/$tpl" \
        -u "$ELASTIC_USER:$ELASTIC_PASSWORD" \
        -H "Content-Type: application/json" \
        -d '{"template":{"settings":{},"mappings":{"dynamic":true},"aliases":{}},"version":1}' >/dev/null || true
    fi
  done
fi

# 创建/更新 ILM 策略（幂等）
echo "确保 ILM 策略存在..."
if ! curl -s -o /dev/null -w "%{http_code}" -u "$ELASTIC_USER:$ELASTIC_PASSWORD" "$ELASTICSEARCH_URL/_ilm/policy/windblog-logs-policy" | grep -q "200"; then
  echo "创建 ILM 策略 windblog-logs-policy"
fi
curl -sS -f -X PUT "$ELASTICSEARCH_URL/_ilm/policy/windblog-logs-policy" \
  -u "$ELASTIC_USER:$ELASTIC_PASSWORD" \
  -H "Content-Type: application/json" \
  -d '{
    "policy": {
      "phases": {
        "hot": {
          "min_age": "0ms",
          "actions": {
            "rollover": { "max_size": "50gb", "max_age": "1d" },
            "set_priority": { "priority": 100 }
          }
        },
        "warm": {
          "min_age": "7d",
          "actions": { "set_priority": { "priority": 50 } }
        },
        "delete": {
          "min_age": "30d",
          "actions": { "delete": {} }
        }
      }
    }
  }'

echo "创建/更新索引模板 windblog-logs..."
curl -sS -f -X PUT "$ELASTICSEARCH_URL/_index_template/windblog-logs" \
  -u "$ELASTIC_USER:$ELASTIC_PASSWORD" \
  -H "Content-Type: application/json" \
  -d '{
    "index_patterns": ["windblog-logs-*"] ,
    "template": {
      "settings": {
        "number_of_shards": 1,
        "number_of_replicas": 1,
        "index.lifecycle.name": "windblog-logs-policy",
        "index.lifecycle.rollover_alias": "windblog-logs"
      },
      "mappings": {
        "properties": {
          "@timestamp": { "type": "date" },
          "message": { "type": "text" },
          "level": { "type": "keyword" },
          "level_name": { "type": "keyword" },
          "channel": { "type": "keyword" },
          "application": { "type": "keyword" }
        }
      }
    },
    "priority": 200,
    "composed_of": []
  }'

# 引导首个写索引（存在则跳过）
echo "确保初始索引 windblog-logs-000001 存在..."
if curl -s -o /dev/null -w "%{http_code}" -u "$ELASTIC_USER:$ELASTIC_PASSWORD" "$ELASTICSEARCH_URL/windblog-logs-000001" | grep -q "404"; then
  curl -sS -f -X PUT "$ELASTICSEARCH_URL/windblog-logs-000001" \
    -u "$ELASTIC_USER:$ELASTIC_PASSWORD" \
    -H "Content-Type: application/json" \
    -d '{
      "aliases": { "windblog-logs": { "is_write_index": true } }
    }'
else
  echo "初始索引已存在，跳过创建"
fi

# 创建 Kibana 数据视图（存在则跳过）
echo "确保 Kibana 数据视图存在..."
if ! curl -s -u "$ELASTIC_USER:$ELASTIC_PASSWORD" -H "kbn-xsrf: true" "$KIBANA_URL/api/data_views" | grep -q '"title":"windblog-logs-\\*"'; then
  curl -sS -f -X POST "$KIBANA_URL/api/data_views/data_view" \
    -u "$ELASTIC_USER:$ELASTIC_PASSWORD" \
    -H "Content-Type: application/json" \
    -H "kbn-xsrf: true" \
    -d '{
      "data_view": {
        "title": "windblog-logs-*",
        "name": "Windblog Logs",
        "timeFieldName": "@timestamp"
      }
    }' || echo "数据视图可能已存在"
else
  echo "数据视图已存在"
fi

# 设为默认数据视图（若可解析到 ID）
echo "设置默认数据视图..."
DATA_VIEW_ID=$(curl -s -X GET "$KIBANA_URL/api/data_views" \
  -u "$ELASTIC_USER:$ELASTIC_PASSWORD" \
  -H "kbn-xsrf: true" | grep -o '"id":"[^\"]*windblog-logs[^\"]*"' | head -1 | cut -d'"' -f4)
if [ -n "$DATA_VIEW_ID" ]; then
  curl -sS -f -X POST "$KIBANA_URL/api/data_views/default" \
    -u "$ELASTIC_USER:$ELASTIC_PASSWORD" \
    -H "Content-Type: application/json" \
    -H "kbn-xsrf: true" \
    -d "{ \"data_view_id\": \"$DATA_VIEW_ID\", \"force\": true }" || true
fi

echo ""
echo "======================================"
echo "Kibana 配置完成!"
echo "======================================"
echo ""
echo "访问地址: $KIBANA_URL"
echo "用户名: $ELASTIC_USER"
echo "密码: $ELASTIC_PASSWORD"
echo ""
echo "索引模式: windblog-logs-*"
echo "ILM: windblog-logs-policy / 别名 windblog-logs"
echo ""
