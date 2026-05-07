#!/bin/bash
# ================================================
# 数据库服务器一键配置脚本
# Ubuntu 系统 | 供交易所主服务器内网连接
# ================================================
set -e

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; NC='\033[0m'
log()  { echo -e "${GREEN}[✓] $1${NC}"; }
warn() { echo -e "${YELLOW}[!] $1${NC}"; }
err()  { echo -e "${RED}[✗] $1${NC}"; exit 1; }

echo -e "${GREEN}"
echo "================================================"
echo "  交易所数据库服务器 一键配置脚本"
echo "================================================"
echo -e "${NC}"

# ---------- 1. 安装 MySQL ----------
log "更新软件源..."
apt-get update -q

log "安装 MySQL Server..."
DEBIAN_FRONTEND=noninteractive apt-get install -y mysql-server

log "启动 MySQL..."
systemctl enable mysql
systemctl start mysql

# ---------- 2. 配置数据库 ----------
log "创建数据库和用户..."
DB_NAME="jiaoyi"
DB_USER="jiaoyi"
DB_PASS="Jiaoyi@2026#Hkd"
INTRANET_IP="10.4.0.7"   # 主服务器内网IP

mysql -u root <<SQL
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` DEFAULT CHARACTER SET utf8 COLLATE utf8_general_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'$INTRANET_IP' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'$INTRANET_IP';
CREATE USER IF NOT EXISTS '$DB_USER'@'%' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'%';
FLUSH PRIVILEGES;
SQL

# ---------- 3. 导入数据 ----------
log "下载并导入数据库..."
wget -q "https://raw.githubusercontent.com/cartierhellos-lab/jiaoyi/main/jiaoyi.sql" -O /tmp/jiaoyi.sql
sed -i 's/ROW_FORMAT=COMPACT/ROW_FORMAT=DYNAMIC/g' /tmp/jiaoyi.sql
mysql -u root $DB_NAME < /tmp/jiaoyi.sql
log "数据库导入完成，共 $(mysql -u root $DB_NAME -e 'SHOW TABLES;' 2>/dev/null | wc -l) 张表"

# ---------- 4. 开放内网访问 ----------
log "配置 MySQL 监听所有地址..."
sed -i 's/^bind-address.*/bind-address = 0.0.0.0/' /etc/mysql/mysql.conf.d/mysqld.cnf 2>/dev/null || \
sed -i 's/^bind-address.*/bind-address = 0.0.0.0/' /etc/mysql/my.cnf 2>/dev/null || \
echo -e "[mysqld]\nbind-address = 0.0.0.0" >> /etc/mysql/mysql.conf.d/mysqld.cnf

systemctl restart mysql
log "MySQL 已开放内网连接"

# ---------- 5. 验证 ----------
log "验证数据库..."
TABLE_COUNT=$(mysql -u root $DB_NAME -e 'SHOW TABLES;' 2>/dev/null | wc -l)

echo ""
echo -e "${GREEN}================================================"
echo "  🎉 数据库配置完成！"
echo "================================================${NC}"
echo ""
echo -e "  数据库地址：${GREEN}10.4.0.13:3306${NC}（内网）"
echo -e "  数据库名：  ${YELLOW}$DB_NAME${NC}"
echo -e "  用户名：    ${YELLOW}$DB_USER${NC}"
echo -e "  密码：      ${YELLOW}$DB_PASS${NC}"
echo -e "  数据表数量：${YELLOW}$TABLE_COUNT 张${NC}"
echo ""
warn "请在主服务器(10.4.0.7)上运行部署脚本连接此数据库"
echo ""
