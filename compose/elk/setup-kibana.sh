#!/bin/bash

# Kibana 自动配置脚本
# 用于自动创建索引模式和配置

set -e

KIBANA_URL="${KIBANA_URL:-http://kibana:5601}"
ELASTICSEARCH_URL="${ELASTICSEARCH_URL:-http://elasticsearch:9200}"
ELASTIC_USER="${ELASTIC_USER:-elastic}"
ELASTIC_PASSWORD="${ELASTIC_PASSWORD:-changeme}"

echo "等待 Kibana 启动..."
until curl -s -I "$KIBANA_URL/api/status" | grep -q "HTTP/1.1"; do
  sleep 5
  echo "Kibana 还未就绪,继续等待..."
done

echo "Kibana 已启动,等待其完全就绪..."
sleep 30

# 创建 ILM 策略 - 保留 30 天日志
echo "创建索引生命周期管理策略..."
curl -X PUT "$ELASTICSEARCH_URL/_ilm/policy/windblog-logs-policy" \
  -u "$ELASTIC_USER:$ELASTIC_PASSWORD" \
  -H "Content-Type: application/json" \
  -d '{
    "policy": {
      "phases": {
        "hot": {
          "min_age": "0ms",
          "actions": {
            "rollover": {
              "max_size": "50gb",
              "max_age": "1d"
            },
            "set_priority": {
              "priority": 100
            }
          }
        },
        "warm": {
          "min_age": "7d",
          "actions": {
            "set_priority": {
              "priority": 50
            }
          }
        },
        "delete": {
          "min_age": "30d",
          "actions": {
            "delete": {}
          }
        }
      }
    }
  }'

echo ""
echo "创建索引模板..."
curl -X PUT "$ELASTICSEARCH_URL/_index_template/windblog-logs" \
  -u "$ELASTIC_USER:$ELASTIC_PASSWORD" \
  -H "Content-Type: application/json" \
  -d '{
    "index_patterns": ["windblog-logs-*"],
    "template": {
      "settings": {
        "number_of_shards": 1,
        "number_of_replicas": 0,
        "index.lifecycle.name": "windblog-logs-policy",
        "index.lifecycle.rollover_alias": "windblog-logs"
      },
      "mappings": {
        "properties": {
          "@timestamp": {
            "type": "date"
          },
          "message": {
            "type": "text"
          },
          "level": {
            "type": "keyword"
          },
          "level_name": {
            "type": "keyword"
          },
          "channel": {
            "type": "keyword"
          },
          "application": {
            "type": "keyword"
          }
        }
      }
    },
    "priority": 200,
    "composed_of": []
  }'

echo ""
echo "创建初始索引..."
curl -X PUT "$ELASTICSEARCH_URL/windblog-logs-000001" \
  -u "$ELASTIC_USER:$ELASTIC_PASSWORD" \
  -H "Content-Type: application/json" \
  -d '{
    "aliases": {
      "windblog-logs": {
        "is_write_index": true
      }
    }
  }'

echo ""
echo "创建 Kibana 数据视图 (Index Pattern)..."
curl -X POST "$KIBANA_URL/api/data_views/data_view" \
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

echo ""
echo "设置默认数据视图..."
DATA_VIEW_ID=$(curl -s -X GET "$KIBANA_URL/api/data_views" \
  -u "$ELASTIC_USER:$ELASTIC_PASSWORD" \
  -H "kbn-xsrf: true" | grep -o '"id":"[^"]*windblog-logs[^"]*"' | head -1 | cut -d'"' -f4)

if [ ! -z "$DATA_VIEW_ID" ]; then
  curl -X POST "$KIBANA_URL/api/data_views/default" \
    -u "$ELASTIC_USER:$ELASTIC_PASSWORD" \
    -H "Content-Type: application/json" \
    -H "kbn-xsrf: true" \
    -d "{
      \"data_view_id\": \"$DATA_VIEW_ID\",
      \"force\": true
    }"
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
echo "索引模式已创建: windblog-logs-*"
echo "日志保留策略: 30 天"
echo ""
