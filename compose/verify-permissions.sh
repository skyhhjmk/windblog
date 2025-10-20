#!/bin/bash

# 权限验证脚本
# 用于验证所有数据目录的权限设置是否正确

echo "=================================="
echo "Windblog 权限验证脚本"
echo "=================================="
echo ""

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DATA_DIR="${SCRIPT_DIR}/data"

# 验证函数
verify_permissions() {
    local dir=$1
    local expected_uid=$2
    local expected_gid=$3
    local expected_perms=$4
    local name=$5
    
    if [ ! -d "$dir" ]; then
        echo "❌ $name: 目录不存在 ($dir)"
        return 1
    fi
    
    # 获取实际权限
    local actual_uid=$(stat -c '%u' "$dir" 2>/dev/null || stat -f '%u' "$dir" 2>/dev/null)
    local actual_gid=$(stat -c '%g' "$dir" 2>/dev/null || stat -f '%g' "$dir" 2>/dev/null)
    local actual_perms=$(stat -c '%a' "$dir" 2>/dev/null || stat -f '%A' "$dir" 2>/dev/null)
    
    local status="✅"
    local issues=""
    
    if [ "$actual_uid" != "$expected_uid" ]; then
        status="⚠️"
        issues="${issues}UID不匹配(期望:$expected_uid,实际:$actual_uid) "
    fi
    
    if [ "$actual_gid" != "$expected_gid" ]; then
        status="⚠️"
        issues="${issues}GID不匹配(期望:$expected_gid,实际:$actual_gid) "
    fi
    
    if [ "$actual_perms" != "$expected_perms" ]; then
        status="⚠️"
        issues="${issues}权限不匹配(期望:$expected_perms,实际:$actual_perms) "
    fi
    
    if [ "$status" = "✅" ]; then
        echo "$status $name: $actual_uid:$actual_gid $actual_perms"
    else
        echo "$status $name: $issues"
    fi
}

echo "验证目录权限..."
echo ""

# Windblog 应用目录
verify_permissions "${DATA_DIR}/windblog/runtime" "1000" "1000" "750" "Windblog Runtime"
verify_permissions "${DATA_DIR}/windblog/uploads" "1000" "1000" "750" "Windblog Uploads"

# 数据库和中间件
verify_permissions "${DATA_DIR}/postgres" "70" "70" "700" "PostgreSQL"
verify_permissions "${DATA_DIR}/redis" "999" "999" "750" "Redis"
verify_permissions "${DATA_DIR}/rabbitmq" "999" "999" "750" "RabbitMQ"

# ELK Stack
verify_permissions "${DATA_DIR}/elasticsearch" "1000" "1000" "750" "Elasticsearch"
verify_permissions "${DATA_DIR}/logstash" "1000" "1000" "750" "Logstash"
verify_permissions "${DATA_DIR}/kibana" "1000" "1000" "750" "Kibana"

echo ""
echo "=================================="
echo "验证完成"
echo "=================================="
echo ""
echo "说明："
echo "  ✅ - 权限配置正确"
echo "  ⚠️  - 权限配置需要调整"
echo ""
echo "如果有警告，请运行:"
echo "  sudo bash init-data-dirs.sh"
echo ""
