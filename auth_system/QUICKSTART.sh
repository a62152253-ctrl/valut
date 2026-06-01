#!/bin/bash
# Quick Start - Dashboard with Real Database

echo "🚀 Vaultly Dashboard - Quick Start Guide"
echo "=========================================="
echo ""

# Step 1: Setup
echo "✓ Step 1: Initialize vault tables"
echo "  URL: http://localhost:5555/auth_system/setup-vault.php"
echo ""

# Step 2: Register (if needed)
echo "✓ Step 2: Register new account (skip if already registered)"
echo "  URL: http://localhost:5555/auth_system/register.php"
echo ""

# Step 3: Login
echo "✓ Step 3: Login to your account"
echo "  URL: http://localhost:5555/auth_system/login.php"
echo ""

# Step 4: View Dashboard
echo "✓ Step 4: View dashboard with real data"
echo "  URL: http://localhost:5555/auth_system/dashboard.php"
echo ""

# Step 5: Add passwords
echo "✓ Step 5: Add passwords using the modal"
echo "  - Click '+ Add password' button"
echo "  - Fill in the form fields"
echo "  - Click 'Save Password'"
echo "  - Data is encrypted and saved to database"
echo ""

# Keyboard shortcuts
echo "⌨️  Keyboard Shortcuts:"
echo "  - Ctrl+K : Focus search"
echo "  - Ctrl+N : Open add password modal"
echo ""

# Features
echo "🎯 Features Available:"
echo "  ✓ Real-time stats from database"
echo "  ✓ Add/save encrypted passwords"
echo "  ✓ View recent activity"
echo "  ✓ Security score calculation"
echo "  ✓ Quick access to favorites"
echo "  ✓ Organize into vaults/folders"
echo ""

# Database
echo "🗄️  Database:"
echo "  Host: localhost"
echo "  Database: auth_system"
echo "  Tables: users, vault_entries, vault_folders, vault_history"
echo ""

# Support
echo "📚 Documentation:"
echo "  - Dashboard Update: /auth_system/DASHBOARD_UPDATE.md"
echo "  - Setup Guide: /auth_system/SETUP.md"
echo ""

# File Structure
echo "📁 Key Files:"
echo "  - auth_system/dashboard.php         (Main dashboard - UPDATED)"
echo "  - auth_system/api/vault.php         (Encryption API)"
echo "  - auth_system/setup-vault.php       (Setup vault tables)"
echo "  - auth_system/js/*.js               (JavaScript modules)"
echo ""

echo "✨ Ready to start? Open: http://localhost:5555/auth_system/dashboard.php"
