#!/bin/bash
# VAULTLY SSL/TLS CERTIFICATE SETUP
# ═══════════════════════════════════════════════════════════════════════════════
# 
# Setup Let's Encrypt SSL certificates with auto-renewal
# Usage: sudo bash ssl-setup.sh vaultly.example.com

set -e

if [ $# -eq 0 ]; then
    echo "Usage: sudo bash ssl-setup.sh vaultly.example.com"
    exit 1
fi

DOMAIN="$1"

echo "=== Setting up SSL Certificate for $DOMAIN ==="

# ═══════════════════════════════════════════════════════════════════════════════
# INSTALL CERTBOT
# ═══════════════════════════════════════════════════════════════════════════════

if ! command -v certbot &> /dev/null; then
    echo "Installing Certbot..."
    apt-get update
    apt-get install -y certbot python3-certbot-apache
fi

echo "✓ Certbot installed"

# ═══════════════════════════════════════════════════════════════════════════════
# OBTAIN CERTIFICATE
# ═══════════════════════════════════════════════════════════════════════════════

echo "Obtaining SSL certificate for $DOMAIN..."

sudo certbot certonly \
    --apache \
    --agree-tos \
    --no-eff-email \
    --non-interactive \
    --email admin@$DOMAIN \
    --domain $DOMAIN \
    --domain www.$DOMAIN

echo "✓ Certificate obtained"

# ═══════════════════════════════════════════════════════════════════════════════
# SETUP AUTO-RENEWAL
# ═══════════════════════════════════════════════════════════════════════════════

echo "Setting up certificate auto-renewal..."

# Create renewal timer
sudo systemctl enable certbot.timer
sudo systemctl start certbot.timer

# Add renewal hook
sudo tee /etc/letsencrypt/renewal-hooks/post/apache.sh > /dev/null <<EOF
#!/bin/bash
systemctl reload apache2
EOF

sudo chmod +x /etc/letsencrypt/renewal-hooks/post/apache.sh

echo "✓ Auto-renewal configured"

# ═══════════════════════════════════════════════════════════════════════════════
# ENABLE HTTPS IN APACHE
# ═══════════════════════════════════════════════════════════════════════════════

echo "Enabling HTTPS in Apache..."

sudo a2enmod ssl
sudo a2enmod rewrite
sudo a2enmod headers

# Create/update VirtualHost for HTTPS
sudo tee /etc/apache2/sites-available/$DOMAIN-ssl.conf > /dev/null <<EOF
<VirtualHost *:443>
    ServerName $DOMAIN
    ServerAlias www.$DOMAIN
    ServerAdmin admin@$DOMAIN
    
    DocumentRoot /var/www/html
    
    # SSL Certificate
    SSLEngine on
    SSLCertificateFile /etc/letsencrypt/live/$DOMAIN/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/$DOMAIN/privkey.pem
    SSLCertificateChainFile /etc/letsencrypt/live/$DOMAIN/chain.pem
    
    # SSL Security
    SSLProtocol -all +TLSv1.2 +TLSv1.3
    SSLCipherSuite HIGH:!aNULL:!MD5
    SSLHonorCipherOrder on
    
    # Include Vaultly security config
    Include /var/www/html/.htaccess
    
    <Directory /var/www/html>
        Options -Indexes
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
EOF

sudo a2ensite $DOMAIN-ssl

echo "✓ Apache HTTPS configured"

# ═══════════════════════════════════════════════════════════════════════════════
# REDIRECT HTTP TO HTTPS
# ═══════════════════════════════════════════════════════════════════════════════

sudo tee /etc/apache2/sites-available/$DOMAIN.conf > /dev/null <<EOF
<VirtualHost *:80>
    ServerName $DOMAIN
    ServerAlias www.$DOMAIN
    
    RewriteEngine On
    RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
</VirtualHost>
EOF

sudo a2ensite $DOMAIN

echo "✓ HTTP to HTTPS redirect configured"

# ═══════════════════════════════════════════════════════════════════════════════
# VERIFY AND RESTART
# ═══════════════════════════════════════════════════════════════════════════════

echo "Testing Apache configuration..."
sudo apache2ctl configtest

echo "Restarting Apache..."
sudo systemctl restart apache2

echo ""
echo "=== SSL Setup Complete ==="
echo "✓ Certificate installed for: $DOMAIN"
echo "✓ Auto-renewal enabled"
echo "✓ HTTPS enforced"
echo ""
echo "Test your certificate:"
echo "  https://www.ssllabs.com/ssltest/analyze.html?d=$DOMAIN"
echo ""
echo "Certificate will auto-renew 30 days before expiry"
echo "Check renewal logs: sudo journalctl -u certbot.timer -f"
