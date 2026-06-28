# KhvichaDev - Waitlist & Notify

**KhvichaDev - Waitlist & Notify** is a modular and secure **WordPress waitlist & lead generation plugin** designed to capture, manage, and notify subscribers for product, service, event, or application launches.

The codebase is built following modern object-oriented programming (OOP) principles, structured directory layouts, and secure WordPress coding standards.

---

## 📂 Project Directory Structure

Unlike typical monolithic WordPress plugins, this project follows a structured feature-first directory layout:

```text
khvichadev-waitlist-notify/ (directory renamed to match slug)
├── core/
│   └── kd-database.php                 # Database schema manager & CRUD operations wrapper
├── features/
│   ├── dashboard/
│   │   ├── controller/
│   │   │   └── kd-admin-handler.php    # AJAX endpoints, Twilio API, custom HTTP gate routes
│   │   └── ui/
│   │       └── kd-admin-page.php       # Admin dashboard UI panel rendering
│   └── widget/
│       ├── controller/
│       │   └── kd-signup-handler.php   # AJAX validation & phone E.164 normalization logic
│       └── ui/
│           ├── kd-signup-form.php      # Front-end signup form rendering
│           ├── kd-signup-form.css      # Responsive styles & layout configurations
│           └── kd-signup-form.js       # Dynamic AJAX submission & cache-busting loader
├── khvichadev-waitlist-notify.php              # Main plugin bootstrap file
├── readme.txt                          # WordPress.org catalog description
├── uninstall.php                       # Database schema cleaner on plugin uninstallation
└── LICENSE                             # GPLv2 license definition
```

---

## 🏗️ Technical Architecture & Design Patterns

* **Feature-First Architecture**: Code is split into self-contained business modules (`widget` for the frontend registration, `dashboard` for the admin settings).
* **Presentation and Logic Separation**: Frontend layout files (`ui/`) are separated from backend AJAX handlers and configuration processors (`controller/`).
* **Database Wrapper & Schema Handler**: Encompasses a dedicated database controller class (`kdwn_Database`) that handles setup, migrations, settings caching using the WordPress Options API, and prepared SQL queries.
* **WordPress Guidelines (WPCS)**: Verified locally against the official WordPress `Plugin Check` tool to ensure compliance with security, naming conventions, and best practices.

---

## ⚙️ Advanced Technical Specifications

### 1. Database Schema & Dynamic Migrations
Database table creations and schema upgrades are managed dynamically inside `kdwn_Database::kdwn_create_table()` on plugin activation:
* **Dynamic Columns Verification**: Instead of dropping tables, it checks for existing table columns (`SHOW COLUMNS LIKE ...`) and appends missing fields dynamically to ensure **zero data-loss** on updates.
* **Composite Indexes**: The handler creates composite indexes (`service_email`, `service_phone`, `service_whatsapp`, `service_status`) to ensure fast query executions even with hundreds of thousands of subscribers:
  ```sql
  ALTER TABLE wp_kdwn_subscribers ADD INDEX service_email (email, service_id);
  ```

### 2. Resilient State Machine & Interruption Recovery
The database schema uses a strict row-level state machine representing subscriber states (`subscribed`, `notified`, `failed`). This design ensures transaction resilience during campaigns:
* **Auto-Resume**: If a network error, browser crash, or server timeout occurs during batch broadcasts, the campaign state is preserved. Pressing "Send" again safely resumes the queue, as the query pulls only remaining records (`WHERE status = 'subscribed'`).
* **Granular Failure Audits**: Failsafe hooks catch gateway connection exceptions, mark individual records as `failed`, and record error logs. This avoids halting the entire queue for isolated target issues.

### 3. Scalable Batch Processing & Memory Safeguards
* **Client-Side Sequenced Batching**: Instead of relying on a heavy server-side cron runner (which often fails on budget shared hosting environments where `wp-cron.php` is disabled or throttled), the plugin utilizes an AJAX-driven batch process.
* **Rate-Limit Friendly**: The administrator's browser orchestrates the broadcast by triggering requests sequentially in small, safe batch sizes (default 15/chunk). The next batch is fired only after the server responds, preventing execution timeouts and API rate-limiting issues.
* **Memory Exhaust Protection**: Processing in discrete chunks keeps memory usage constant and small, preventing PHP `memory_limit` exhaustion or `max_execution_time` timeouts.

### 4. Security & Credentials Storage
* **WordPress Options API**: Gateway credentials (Twilio SID, Token, Custom Gateways) are stored in the WordPress `options` database table, inheriting core database access safeguards.
* **Admin Privilege Gating**: All administrative AJAX endpoints verify authorization using `current_user_can('manage_options')` combined with strict WordPress nonces:
  ```php
  if (!current_user_can('manage_options') || !wp_verify_nonce($nonce, 'kdwn_admin_nonce')) {
      wp_send_json_error(array('message' => 'Unauthorized access.'));
  }
  ```

### 5. Logging & Observability
* **Native Debugging Hooks**: To prevent database bloating and ensure compliance with WordPress standards, the plugin avoids writing custom logging tables. Instead, it hooks into the native WordPress error tracking (`WP_DEBUG_LOG`) via standard `error_log()` outputs for failed Twilio or custom HTTP gateway API requests, keeping error tracking centralized and standard.

### 6. Uninstall Lifecycle & Clean Slate Compliance
* **Data Safeguard Option**: The plugin prevents accidental database loss by implementing a dedicated `uninstall.php` script that respects a user-configured toggle.
* **Cleanup Strategy**: If `kdwn_delete_data_on_uninstall` is enabled, the script drops the custom table and deletes all option settings matching `kdwn_%` to prevent WordPress database bloating:
  ```php
  $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}kdwn_subscribers" );
  $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'kdwn_%'" );
  ```

---

## 🔒 Front-End Security & GDPR Safeguards

* **Cache-Busting Nonces**: Includes an AJAX endpoint to dynamically fetch fresh cryptographic nonces (`wp_create_nonce`) on page load. This keeps the signup form functional even when served from aggressively cached pages (Cloudflare, Varnish, Litespeed).
* **Anti-Spam Honeypot**: Integrates a silent honeypot field to trap automated spam bots without degrading user experience with intrusive CAPTCHAs.
* **Strict Input Sanitization & Output Escaping**: All database insertions are sanitized via `sanitize_text_field()` and `sanitize_email()`. All UI rendering uses escaping tags (`esc_html()`, `esc_attr()`, `esc_url()`).
* **E.164 Phone Normalization**: Automatically normalizes user telephone inputs into valid international formats:
  ```php
  private function kdwn_normalize_phone($phone, $default_code = '+995') {
      $phone = preg_replace('/[^\d+]/', '', $phone);
      if (empty($phone)) return '';
      if (strpos($phone, '+') !== 0) {
          $phone = ltrim($phone, '0');
          if (strpos($default_code, '+') !== 0) {
              $default_code = '+' . $default_code;
          }
          $phone = $default_code . $phone;
      }
      if (!preg_match('/^\+[1-9]\d{6,14}$/', $phone)) {
          return false;
      }
      return $phone;
  }
  ```
* **GDPR Compliance & Local Assets**: Fully self-contained. Zero external CDN calls or Google Fonts fetches to ensure full compliance with privacy laws. Includes customizable opt-in notification consent.

---

## 🚀 Broadcast Channels & Settings

* **Standard Email (SMTP/wp_mail)**: Processes batch notifications with customizable mail headers.
* **Twilio Gateway (SMS / WhatsApp)**: Built-in support for official Twilio API endpoints.
* **Custom HTTP Gateways**: Allows administrator to define a custom GET endpoint using `{phone}` and `{message}` placeholders (e.g. `https://api.mygateway.com/send?to={phone}&msg={message}`). The plugin encodes parameters and routes them dynamically.
* **Zero-Cost Manual Sending**: Direct protocol integration (`sms:`, Web WhatsApp, Desktop WhatsApp) to send notifications directly from the browser or device client.

---

## 💡 External Service Disclosures
This plugin integrates with Twilio APIs to broadcast launch SMS and WhatsApp notifications. 
* Twilio Terms of Service: https://www.twilio.com/legal/tos
* Twilio Privacy Policy: https://www.twilio.com/legal/privacy

---

## 荒 Developer Shortcode Guide

### 1. Waitlist Signup Form
```text
[kdwn_signup service="My Launch Product"]
```
* **Parameters:**
  * `service` *(string)*: Unique identifier for the waitlist service. Defaults to `"Default Service"`.

### 2. Subscriber Count Badge
```text
[kdwn_subscriber_count service="My Launch Product" format="badge"]
```
* **Parameters:**
  * `service` *(string)*: Target waitlist identifier.
  * `format` *(string)*: Use `"badge"` for styled container or `"raw"` to return the bare integer.

---

## 📄 License

This software is released under the GPLv2 or later. See `LICENSE` for details.
