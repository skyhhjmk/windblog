#!/bin/sh

# ELK 自动配置脚本（更健壮）
# - 等待 ES 就绪（200/401 即视为可用）
# - 设置系统账户密码并验证
# - 创建 ILM 策略、索引模板与引导写索引（供 Logstash 使用）

ELASTICSEARCH_URL="${ELASTICSEARCH_URL:-http://elasticsearch:9200}"
ELASTIC_USER="${ELASTIC_USER:-elastic}"
ELASTIC_PASSWORD="${ELASTIC_PASSWORD:-changeme}"
KIBANA_PASSWORD="${KIBANA_PASSWORD:-changeme}"
LOGSTASH_PASSWORD="${LOGSTASH_PASSWORD:-changeme}"

set -e

http() {
  method="$1"; path="$2"; data="$3"
  if [ -n "$data" ]; then
    curl -sS -f -u "$ELASTIC_USER:$ELASTIC_PASSWORD" -H "Content-Type: application/json" -X "$method" "$ELASTICSEARCH_URL$path" -d "$data"
  else
    curl -sS -f -u "$ELASTIC_USER:$ELASTIC_PASSWORD" -X "$method" "$ELASTICSEARCH_URL$path"
  fi
}

echo "等待 Elasticsearch 启动..."
retries=60
while :; do
  code=$(curl -s -o /dev/null -w "%{http_code}" "$ELASTICSEARCH_URL") || code=000
  if [ "$code" = "200" ] || [ "$code" = "401" ]; then
    break
  fi
  retries=$((retries-1))
  if [ $retries -le 0 ]; then
    echo "Elasticsearch 启动超时 (最后状态码: $code)"
    exit 1
  fi
  echo "Elasticsearch 还未就绪,继续等待... (剩余重试次数: $retries, 状态码: $code)"
  sleep 5
done

# 等待集群达到 yellow
echo "等待集群健康 (yellow)..."
http GET "/_cluster/health?wait_for_status=yellow&timeout=60s" >/dev/null

echo "设置 kibana_system 密码..."
http POST "/_security/user/kibana_system/_password" "{\"password\":\"$KIBANA_PASSWORD\"}" >/dev/null

echo "设置 logstash_system 密码..."
http POST "/_security/user/logstash_system/_password" "{\"password\":\"$LOGSTASH_PASSWORD\"}" >/dev/null

echo "验证 kibana_system 登录..."
curl -sS -f -u "kibana_system:$KIBANA_PASSWORD" "$ELASTICSEARCH_URL/_security/_authenticate" >/dev/null

echo "验证 logstash_system 登录..."
curl -sS -f -u "logstash_system:$LOGSTASH_PASSWORD" "$ELASTICSEARCH_URL/_security/_authenticate" >/dev/null

echo "创建/更新 logstash_writer 角色..."
http POST "/_security/role/logstash_writer" '{
  "cluster": ["manage_index_templates", "monitor", "manage_ilm"],
  "indices": [
    {"names": ["windblog-logs-*", "logstash-*"], "privileges": ["write", "create", "create_index", "manage", "manage_ilm"]}
  ]
}' >/dev/null

# ILM 策略
echo "创建/更新 ILM 策略 windblog-logs-policy..."
http PUT "/_ilm/policy/windblog-logs-policy" '{
  "policy": {
    "phases": {
      "hot":    { "actions": { "rollover": { "max_size": "50gb", "max_age": "1d" }, "set_priority": { "priority": 100 } } },
      "warm":   { "min_age": "7d",  "actions": { "set_priority": { "priority": 50 } } },
      "delete": { "min_age": "30d", "actions": { "delete": {} } }
    }
  }
}' >/dev/null

# 索引模板
echo "创建/更新索引模板 windblog-logs..."
http PUT "/_index_template/windblog-logs" '{
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
}' >/dev/null

# 引导写索引
echo "确保初始写索引 windblog-logs-000001 存在..."
code=$(curl -s -o /dev/null -w "%{http_code}" -u "$ELASTIC_USER:$ELASTIC_PASSWORD" "$ELASTICSEARCH_URL/windblog-logs-000001")
if [ "$code" = "404" ]; then
  http PUT "/windblog-logs-000001" '{
    "aliases": { "windblog-logs": { "is_write_index": true } }
  }' >/dev/null
fi

echo ""
echo "======================================"
echo "ELK 配置完成!"
echo "======================================"
echo ""
echo "已设置 kibana_system 与 logstash_system 密码，并创建 ILM/模板/初始索引"
echo "".
