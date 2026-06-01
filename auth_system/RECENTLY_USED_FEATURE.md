# Recently Used Items Feature - Implementation Summary

## Components Added

### 1. Database Changes (includes/db.php)
- Created new table `vault_recent` to track recently used items
- Stores: user_id, entry_uuid, entry_title, entry_type, accessed_at, access_count
- Indexed on (user_id, accessed_at) for fast queries

### 2. Helper Functions (includes/recent-items.php)
- **getServiceIcon($title)**: Automatically detects service/app from title and returns emoji icon
  - Supports: GitHub (🐙), Google (🔍), Notion (📋), Gmail (📧), Minecraft (⛏️), and 60+ more
  - Falls back to 📌 for unknown services
  
- **logRecentItem($user_id, $entry_uuid, $entry_title, $entry_type)**: Tracks item access
  - Creates new record on first access
  - Updates timestamp and increments counter on subsequent accesses
  
- **getRecentItems($user_id, $limit)**: Retrieves most recent items
  - Returns sorted by accessed_at DESC
  - Limited to recent 6 items by default

### 3. API Endpoint (api/recent.php)
- **GET**: Returns user's recent items with auto-detected icons
  - Response: `{ "items": [ {entry_uuid, entry_title, entry_type, accessed_at, icon}, ...] }`
  
- **POST**: Logs an item access event
  - Payload: `{ entry_uuid, entry_title, entry_type }`
  - Response: `{ ok: true/false }`

### 4. Frontend Integration (js/recent-items.js)
- `logRecentItemUsed(uuid, title, type)`: Send access event to API
- `loadRecentItems()`: Fetch recently used items from API
- `renderRecentItems(items)`: Generate HTML with service icons
- Auto-initializes on page load

### 5. Dashboard Updates (dashboard.php)
- Includes recent-items.php functions
- Calls getRecentItems() to fetch PHP-rendered section (optional)
- Placeholder: `<div id="recentItemsContainer"></div>` for JS injection

## Usage

### In Dashboard HTML
Add this placeholder in the right column (after Quick Access, before Your Vaults):
```html
<div id="recentItemsContainer"></div>
```

Then include the script:
```html
<script src="js/recent-items.js"></script>
```

### Logging Item Access
When user opens/views an item in vault.php or other views:
```javascript
await logRecentItemUsed('uuid-value', 'GitHub', 'login');
```

### Server-side Rendering (Optional)
In any PHP view:
```php
<?php
include 'includes/recent-items.php';
$items = getRecentItems($user_id, 3);
foreach ($items as $item) {
    echo $item['entry_title'] . ' ' . getServiceIcon($item['entry_title']);
}
?>
```

## Service Icon Detection

### Supported Services (60+)
- **Cloud**: Google Drive, OneDrive, iCloud, AWS, GCP, Azure, Vercel, Netlify
- **Social**: Facebook, Twitter, Instagram, LinkedIn, Discord, Slack, Telegram, Reddit, Mastodon, Bluesky
- **Entertainment**: Netflix, Spotify, Steam, Twitch, YouTube, Discord
- **Productivity**: Asana, Monday, Trello, Jira, Confluence
- **Design**: Figma, Sketch, Adobe, Photoshop, Canva
- **Development**: GitHub, GitLab, Docker, Heroku, Netlify
- **Finance**: PayPal, Stripe, Bitcoin, Banks, Cards (Visa, Mastercard, Amex)
- **Gaming**: Minecraft, Epic Games, Ubisoft
- **Email**: Gmail, Outlook
- **And more...** (auto-updates via getServiceIcon() function)

## Database Schema

```sql
CREATE TABLE vault_recent (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    entry_uuid VARCHAR(36) NOT NULL,
    entry_title VARCHAR(255),
    entry_type ENUM('login','note','card','identity'),
    accessed_at TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    access_count INT DEFAULT 1,
    KEY (user_id, accessed_at),
    KEY (entry_uuid),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)
```

## Files Modified/Created

- **Modified**: includes/db.php (added table creation)
- **Created**: includes/recent-items.php (helper functions)
- **Created**: includes/recent-items-html.php (HTML template)
- **Created**: api/recent.php (API endpoint)
- **Created**: js/recent-items.js (frontend tracking)
- **To Update**: dashboard.php (add JS script tag + container div)

## Display Format

Shows 3 most recently used items in dashboard with:
- Service icon (auto-detected emoji)
- Item title
- Item type (Login, Note, Card, Identity)
- Last accessed date
- Gradient background color per item
