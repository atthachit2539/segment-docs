# Click Redirect Handler

This script redirects real users to marketing links, logs each click, and proxies bots to a Webflow page. It is compatible with shared hosting environments such as Hostinger's hPanel (no VPS required).

## Deployment on Hostinger

1. **Upload** `scripts/click_redirect.php` to your `public_html` directory or a subfolder.
2. **Create a `.env` file** in the project root (copy from `.env.example`) and fill in database, Telegram, and optional redirect settings (`WEBFLOW_URL`, `REDIRECT_LINKS`, `UTM_*`). You can alternatively set these values via **hPanel → Advanced → Environment Variables**.
3. **Create a MySQL database** and a `click_logs` table:
   ```sql
   CREATE TABLE click_logs (
     id INT AUTO_INCREMENT PRIMARY KEY,
     created_at DATETIME NOT NULL,
     ip VARCHAR(45),
     country CHAR(2),
     user_agent TEXT,
     tracking_id VARCHAR(32),
     redirect_url TEXT,
     utm_source VARCHAR(255),
     utm_medium VARCHAR(255),
     utm_campaign VARCHAR(255)
   );
   ```
4. Ensure PHP 7.3+ is selected in hPanel and that the script can write to `scripts/logs/` (created automatically).
5. Access the script via `https://yourdomain.com/click_redirect.php`.

The handler generates UTM parameters, stores click data in the database, sends optional Telegram alerts, and sets a secure tracking cookie.
