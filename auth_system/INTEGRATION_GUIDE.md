<!-- Recently Used Items Section - Insert this in dashboard.php -->
<!-- Place AFTER Quick Access card, BEFORE Recent Activity card in the d-left section -->

<div id="recentItemsContainer"></div>

<!-- ─── Script Includes ───
Add these script tags at the end of dashboard.php, after existing script imports:
-->

<script src="js/recent-items.js"></script>

<!-- ─── To Track Item Access ───
In vault.php or any page that displays vault items, add this call when user opens/views an item:
-->

<script>
// When user accesses/views a vault item:
logRecentItemUsed(entryUuid, entryTitle, entryType);

// Example:
// logRecentItemUsed('123e4567-e89b-12d3-a456-426614174000', 'GitHub Account', 'login');
</script>

<!-- ─── Service Icons Supported ───

Google 🔍
GitHub 🐙
Notion 📋
Gmail 📧
Facebook 📱
Twitter 𝕏
Instagram 📷
LinkedIn 💼
Dropbox 📁
Google Drive ☁️
OneDrive ☁️
iCloud ☁️
Amazon 📦
Netflix 🎬
Spotify 🎵
Steam 🎮
Discord 💬
Slack 💼
Zoom 📹
Microsoft 💻
Apple 🍎
PayPal 💳
Stripe 💳
Bank 🏦
Bitcoin ₿
Crypto 🪙
Minecraft ⛏️
Epic Games 🎮
Ubisoft 🎮
Twitch 📺
YouTube ▶️
Reddit 👾
Mastodon 🐘
Bluesky 🦋
X.com 𝕏
Telegram ✈️
WhatsApp 💬
Signal 🔐
AWS ☁️
GCP ☁️
Azure ☁️
Vercel ▲
Netlify ⚡
Heroku 📦
Docker 🐳
GitHub Pages 📄
GitLab 🦊
Bitbucket 🪣
Jira ⚙️
Confluence 📚
Asana ✓
Monday 📅
Trello 📊
Figma 🎨
Sketch 🎨
Adobe 🎨
Photoshop 🖼️
Canva 🎨
G Suite 📊
Office365 📊
Outlook 📧
WordPress 📝
Shopify 🛒
Wix 🌐
Squarespace 🌐
Webflow 🌐
Passport 📋
Visa 💳
Mastercard 💳
Amex 💳

To add more icons, edit the $services array in includes/recent-items.php
-->
