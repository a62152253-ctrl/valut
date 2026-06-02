#!/bin/bash
# VAULTLY FIREWALL RULES (UFW)
# ═══════════════════════════════════════════════════════════════════════════════
# 
# Install firewall rules for Vaultly server
# Usage: sudo bash ufw-rules.sh

set -e

echo "=== Installing UFW Firewall Rules for Vaultly ==="

# Enable UFW
sudo ufw --force enable

# Default policies
sudo ufw default deny incoming
sudo ufw default allow outgoing
sudo ufw default deny routed

echo "✓ Default policies set"

# ═══════════════════════════════════════════════════════════════════════════════
# ALLOW ESSENTIAL SERVICES
# ═══════════════════════════════════════════════════════════════════════════════

# SSH (with rate limiting)
sudo ufw limit 22/tcp comment "SSH with rate limit"

# HTTP/HTTPS
sudo ufw allow 80/tcp comment "HTTP"
sudo ufw allow 443/tcp comment "HTTPS"

echo "✓ Essential services allowed"

# ═══════════════════════════════════════════════════════════════════════════════
# BLOCK DANGEROUS PORTS
# ═══════════════════════════════════════════════════════════════════════════════

# Block common attack ports
sudo ufw deny 21/tcp comment "FTP"
sudo ufw deny 23/tcp comment "Telnet"
sudo ufw deny 3306/tcp comment "MySQL (local only)"
sudo ufw deny 5432/tcp comment "PostgreSQL (local only)"
sudo ufw deny 6379/tcp comment "Redis (local only)"

echo "✓ Dangerous ports blocked"

# ═══════════════════════════════════════════════════════════════════════════════
# ALLOW INTERNAL SERVICES (for Docker/localhost)
# ═══════════════════════════════════════════════════════════════════════════════

# MySQL (localhost only)
sudo ufw allow from 127.0.0.1 to 127.0.0.1 port 3306 comment "MySQL localhost"
sudo ufw allow from 172.21.0.0/16 to any port 3306 comment "MySQL Docker network"

echo "✓ Internal services configured"

# ═══════════════════════════════════════════════════════════════════════════════
# DENY ALL OTHER PORTS
# ═══════════════════════════════════════════════════════════════════════════════

# Block all other incoming traffic (already set as default, but explicit is good)
# sudo ufw deny in on eth0 from any to any port 1:65535

echo "✓ All other ports blocked"

# ═══════════════════════════════════════════════════════════════════════════════
# DISPLAY RULES
# ═══════════════════════════════════════════════════════════════════════════════

echo ""
echo "=== UFW Firewall Rules ==="
sudo ufw status verbose

echo ""
echo "✓ Firewall configured successfully"
echo ""
echo "Important: Only SSH (22), HTTP (80), and HTTPS (443) are open to the internet"
echo "All other ports are blocked by default"
