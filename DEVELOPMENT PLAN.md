# **WordPress Nitter Scraper GPU - Feature Development Plan**

## **Overview**

Comprehensive enhancement plan for the wp-nitter-scraper-gpu plugin to improve feed management, upload reliability, video processing efficiency, and admin tools.

**Repository:** [https://github.com/catsontv/wp-nitter-scraper-gpu](https://github.com/catsontv/wp-nitter-scraper-gpu)
**Base Branch:** `parallel-video-processing`
**Target Environment:** Windows 10, XAMPP, NVIDIA GTX 1070

***

## **Phase 1: Feed Correctness \& Publishing Logic**

### **Priority:** CRITICAL

### **Estimated Time:** 3-4 days

### **Feature 1.1: Feed Publishing by Processing Status**

**Objective:** Videos appear in feed only AFTER GIF processing completes; images appear immediately.

**Database Changes:**

- Add to `tweets` table:
    - `feed_status` ENUM('published', 'pending') DEFAULT 'published'
    - `date_published` DATETIME NULL
    - Ensure `date_scraped` exists (populate from existing data if missing)

**Write-Path Logic:**

- Image tweets (in API handler receiving scraper payload):
    - After WP media upload succeeds → Set `feed_status = 'published'`, `date_published = NOW()`
- Video/GIF tweets:
    - Initial scrape → Set `feed_status = 'pending'`, `date_published = NULL`
    - After successful GIF upload (in `class-video-handler.php`) → Update tweet: `feed_status = 'published'`, `date_published = NOW()`
    - On permanent failure/skip → Delete tweet + video queue row entirely

**Read-Path (Feed Display):**

- Main feed query: `WHERE feed_status = 'published' ORDER BY date_published DESC`
- Use fallback: `ORDER BY COALESCE(date_published, date_scraped) DESC` during migration

**Files to Modify:**

- `includes/class-api.php` (tweet insert logic)
- `includes/class-video-handler.php` (success/failure handlers)
- `admin/tweets-page.php` (feed query)
- `includes/class-database.php` (add schema migration)

***

### **Feature 1.2: Feed Reconciliation Auto-Healing**

**Objective:** Detect tweets that exist but never appeared in feed; auto-promote them to top.

**Database Changes:**

- Add to `tweets` table:
    - `in_feed` TINYINT(1) DEFAULT 0
    - `last_feed_check` DATETIME NULL

**Feed Marking Logic:**

- In main feed renderer (when no account filter applied):
    - Collect IDs of rendered tweets
    - Bulk update: `UPDATE tweets SET in_feed = 1, last_feed_check = NOW() WHERE id IN (...)`

**Reconciliation Cron:**

- New cron hook: `nitter_reconcile_feed` (runs every 15 minutes)
- Query: `WHERE feed_status = 'published' AND date_scraped >= NOW() - INTERVAL 12 HOUR AND in_feed = 0`
- For each orphan: Update `date_published = NOW()`
- Log: "Reconciled X orphaned tweets"

**Files to Modify:**

- `admin/tweets-page.php` (add feed marking after render)
- `includes/class-cron-handler.php` (add reconciliation cron)
- `includes/class-database.php` (schema migration)

***

### **Feature 1.3: Change Feed Ordering to date_scraped**

**Objective:** Show tweets in order they were added to system, not original Twitter post time.

**Implementation:**

- Initially: `ORDER BY date_scraped DESC`
- Later enhanced by Feature 1.1 to: `ORDER BY date_published DESC`

**Files to Modify:**

- `admin/tweets-page.php` (change ORDER BY clause)

***

## **Phase 2: Video Processing Intelligence**

### **Priority:** HIGH

### **Estimated Time:** 4-5 days

### **Feature 2.1: Orientation-Aware Smart Quality Levels**

**Objective:** Apply 3-pass quality reduction strategy with orientation detection and early abort.

**Video Orientation Detection:**

- Add method: `get_video_orientation()` using ffprobe
- Returns: 'landscape', 'portrait', 'square', 'unknown'

**Quality Level Definitions:**

- Landscape: Constrain width (720px → 640px → 540px)
- Portrait: Constrain height (720px → 640px → 540px)
- Square: Constrain both dimensions equally

**3-Pass Strategy:**

- Pass 1: 15fps, dimension=720
- Pass 2: 12fps, dimension=640
- Pass 3: 10fps, dimension=540

**Early Abort Logic:**

- New setting: "First Pass Abort Threshold" (default: 70 MB, configurable 30-200 MB)
- After Pass 1:
    - If size > threshold → Calculate estimated final size (size × 0.40)
    - If estimated > 20MB → Abort, mark as `skipped_too_complex`
    - Log: "Aborting: First pass XMB, estimated minimum ~YMB > 20MB"

**FFmpeg Scale Filter Updates:**

- Landscape: `scale=WIDTH:-1:flags=lanczos`
- Portrait: `scale=-1:HEIGHT:flags=lanczos`
- Square: `scale=WIDTH:HEIGHT:flags=lanczos`

**Files to Modify:**

- `includes/class-video-handler.php` (all conversion logic)
- `admin/video-settings-page.php` (add abort threshold setting)

***

### **Feature 2.2: Upload Retry Mechanism**

**Objective:** Retry failed uploads with exponential backoff instead of giving up immediately.

**Database Changes:**

- Add to media/images table:
    - `upload_attempts` INT DEFAULT 0
    - `last_upload_attempt` DATETIME NULL
    - `upload_status` ENUM('pending','success','failed','retrying','failed_permanent') DEFAULT 'pending'

**Immediate Retry (in upload function):**

- Wrap upload call in loop (max 3 attempts)
- Exponential backoff: sleep(1), sleep(3), sleep(9)
- Track: `upload_attempts++`, `last_upload_attempt = NOW()`, `upload_status = 'retrying'`
- On success: `upload_status = 'success'`

**Daily Cron Reprocessor:**

- New cron: `nitter_retry_failed_uploads` (once daily)
- Query: `WHERE upload_status IN ('failed','retrying') AND upload_attempts < 5`
- Retry upload (1 attempt per cron)
- After 5 total attempts: Set `upload_status = 'failed_permanent'`

**Integration:**

- Only set tweet `feed_status = 'published'` when `upload_status = 'success'`
- If `failed_permanent` → Delete tweet + queue row

**Files to Modify:**

- `includes/class-imgbb-client.php` (add retry loop)
- `includes/class-cron-handler.php` (add daily retry cron)
- `includes/class-database.php` (schema migration)

***

## **Phase 3: Quality of Life Improvements**

### **Priority:** MEDIUM

### **Estimated Time:** 2-3 days

### **Feature 3.1: Skip Text-Only Tweets**

**Objective:** Don't scrape/store tweets with no media.

**Settings:**

- Add checkbox: "Skip text-only tweets" (ON/OFF)
- Store as: `nitter_skip_text_only`

**API Layer:**

- When calling Node.js scraper, include in payload: `"skip_text_only": true/false`

**Node.js Scraper:**

- Before sending to WordPress:
    - `if (images.length === 0 && media_type !== 'video' && skip_text_only) continue;`

**Files to Modify:**

- `admin/settings-page.php` (add setting)
- `includes/class-api.php` (pass setting to scraper)
- `scraper-service.js` (add filter logic)

***

### **Feature 3.2: Randomize GIF Names**

**Objective:** Replace sequential names with creative random names.

**Format:** `{adjective}-{noun}-{8-char-hash}.gif`

**Implementation:**

- Create word lists: adjectives (50+), nouns (50+)
- Random selection: `$adjectives[array_rand(...)]`
- Hash: `substr(sha1($entry_id . microtime(true)), 0, 8)`
- Example: `cosmic-nebula-a7f3d9k2.gif`

**Files to Modify:**

- `includes/class-upload-manager.php` (new helper method)
- `includes/class-video-handler.php` (use helper before upload)

***

### **Feature 3.3: Enhanced Logging with Process-Type Filters**

**Objective:** Filter logs by image scraping vs. video processing.

**Database Changes:**

- Add to `logs` table:
    - `log_type` ENUM('scrape_image','scrape_video','conversion','upload','cron','other') DEFAULT 'other'

**Logging Updates:**

- When logging, specify type:
    - Image scraping: `log_type = 'scrape_image'`
    - Video processing: `log_type = 'scrape_video'` or `'conversion'`
    - Upload: `log_type = 'upload'`

**Admin UI:**

- Add dropdown filter: "All / Image Scraping / Video Processing / Upload / Cron"
- Filter query by `log_type`

**Files to Modify:**

- `includes/class-database.php` (update add_log method signature, schema)
- All files that call `add_log()` (specify type)
- `admin/logs-page.php` (add filter dropdown)

***

## **Phase 4: Scaling \& Multi-Provider Support**

### **Priority:** MEDIUM

### **Estimated Time:** 5-6 days

### **Feature 4.1: Multi-Provider Image Hosting with Fallback**

**Objective:** Use multiple hosting APIs (ImgBB, PostImages, Freeimage.host) with priority and randomization.

**Database Changes:**

- Add to media table: `hosting_provider` VARCHAR(50) DEFAULT 'imgbb'

**Architecture:**

- New file: `includes/class-upload-manager.php`
    - Manages providers, priorities, randomization
    - Tracks success rates per provider
- New provider clients:
    - `includes/class-postimages-client.php`
    - `includes/class-freeimage-client.php`
- Common interface/methods:
    - `upload($file_path, $name)` → returns `['success' => bool, 'url' => '...', 'error' => '...']`

**Settings Page:**

- For each provider (ImgBB, PostImages, Freeimage):
    - API Key 1 (text input)
    - API Key 2 (text input)
    - Priority Level (1, 2, 3)
    - Enabled (checkbox)

**Upload Flow:**

- Video handler calls UploadManager instead of ImgBB client
- UploadManager:
    - Get all priority 1 providers → shuffle
    - Try each (cycling through keys if multiple)
    - On success: Save `hosting_provider` in DB, return URL
    - On all priority 1 fail → try priority 2, then 3
    - On total failure → return error (retry mechanism from Feature 2.2 kicks in)

**Files to Create:**

- `includes/class-upload-manager.php`
- `includes/class-postimages-client.php`
- `includes/class-freeimage-client.php`

**Files to Modify:**

- `includes/class-video-handler.php` (use UploadManager)
- `admin/video-settings-page.php` (add multi-provider settings)

***

## **Phase 5: Admin Tools \& Management**

### **Priority:** MEDIUM

### **Estimated Time:** 3-4 days

### **Feature 5.1: Bulk Account Import/Export**

**Objective:** Upload/download account lists via TXT file.

**File Format (TXT):**

```
username1
username2,60
https://twitter.com/username3,90
@username4
```

**Import Features:**

- Drag-and-drop file upload
- Parse: username, retention days (default if not specified)
- Normalize: Strip whitespace, handle URLs, remove @
- Validate: Check format
- Deduplicate: Skip existing accounts, report count
- Preview before confirm
- Show: "X imported, Y duplicates skipped, Z invalid"

**Export Features:**

- Button: "Export Accounts"
- Format: `username,retention_days`
- Filename: `nitter-accounts-YYYY-MM-DD-HHMMSS.txt`
- Include: All accounts or filtered by status

**AJAX Handlers:**

- `nitter_import_accounts`
- `nitter_export_accounts`

**Files to Modify:**

- `admin/accounts-page.php` (add import/export UI)
- `ajax/accounts-ajax.php` (add handlers)

***

### **Feature 5.2: Export Logs to File**

**Objective:** Download logs as TXT file for analysis/archiving.

**Implementation:**

- Button: "Export Logs" on logs page
- Modal with filters:
    - Date range (start/end)
    - Log type (from Phase 3.3)
    - Account filter
- Format options: CSV or plain text
- Filename: `nitter-logs-YYYY-MM-DD-HHMMSS.txt`

**AJAX Handler:**

- `nitter_export_logs`
- Build query with filters
- Stream as download

**Files to Modify:**

- `admin/logs-page.php` (add export button + modal)
- `ajax/logs-ajax.php` (add handler)

***

### **Feature 5.3: Configurable Log Retention**

**Objective:** Control how long logs are stored.

**Settings:**

- Add dropdown: "Log retention days"
    - Options: 1, 7, 30, 0 (never delete)
    - Default: 7
- Store as: `nitter_log_retention_days`

**Daily Cron:**

- New cron: `nitter_cleanup_logs` (once daily)
- If retention > 0:
    - `DELETE FROM logs WHERE created_at < NOW() - INTERVAL X DAY`
- If 0: Do nothing (manual cleanup only)

**Files to Modify:**

- `admin/settings-page.php` (add retention setting)
- `includes/class-cron-handler.php` (add cleanup cron)

***

### **Feature 5.4: "Read More" for Long Tweets**

**Objective:** Collapse tweets over 400 characters with expand/collapse.

**Server-Side (HTML):**

- In tweet text rendering:
    - If `mb_strlen($text) <= 400`: Display full text
    - Else:
        - Find last space before 400: `mb_strrpos(mb_substr($text, 0, 400), ' ')`
        - Output structure:

```html
<span class="tweet-text-short" data-tweet-id="X">TRUNCATED...</span>
<span class="tweet-text-full" data-tweet-id="X" style="display:none;">FULL TEXT</span>
<a href="#" class="tweet-read-more" data-tweet-id="X">Read more</a>
```


**JavaScript:**

- On click `.tweet-read-more`:
    - Toggle visibility of `.tweet-text-short` / `.tweet-text-full`
    - Change link text: "Read more" ↔ "Show less"
    - Prevent default

**CSS:**

- Style `.tweet-read-more` as link (blue, pointer cursor)
- Optional smooth transition

**Files to Modify:**

- `admin/tweets-page.php` (truncation logic)
- `ajax/tweets-ajax.php` (if tweets load via AJAX)
- `assets/admin.js` (add toggle handler)
- `assets/admin.css` (styling)

***

## **Phase 6: Cron \& System Integration**

### **Priority:** MEDIUM

### **Estimated Time:** 2 days

### **Feature 6.1: System Cron for Account Scraping**

**Objective:** Move account scraping from WP-Cron to Windows Task Scheduler for reliability.

**Implementation:**

- Create entry point: `cron-scrape-accounts.php`
    - Bootstrap WordPress (`wp-load.php`)
    - Call existing scrape method manually
- Windows Task Scheduler setup:
    - Command: `C:\xampp\php\php.exe C:\xampp\htdocs\wp-content\plugins\nitter-scraper\cron-scrape-accounts.php`
    - Schedule: Every 60 minutes
- Keep WP-Cron hook as fallback (optional disable via setting)

**Files to Create:**

- `cron-scrape-accounts.php`

**Files to Modify:**

- `includes/class-cron-handler.php` (optional: add setting to disable WP-Cron version)

***

## **Configuration Summary for Video Settings Page**

**New Settings to Add:**

- **First Pass Abort Threshold (MB):** Number input, default 70, range 30-200
- **Multi-Provider Upload Settings:**
    - ImgBB: API Key 1, API Key 2, Priority (1-3), Enabled
    - PostImages: API Key 1, API Key 2, Priority (1-3), Enabled
    - Freeimage.host: API Key 1, API Key 2, Priority (1-3), Enabled

**Existing Settings:**

- Max GIF Size (20 MB - keep as is)
- Max Video Duration (90 seconds - keep as is)
- Enable Video Processing (checkbox - keep as is)

***

## **Database Schema Migrations**

**Phase 1 Migrations:**

```sql
-- tweets table
ALTER TABLE {prefix}nitter_tweets 
ADD COLUMN feed_status ENUM('published', 'pending') DEFAULT 'published',
ADD COLUMN date_published DATETIME NULL,
ADD COLUMN in_feed TINYINT(1) DEFAULT 0,
ADD COLUMN last_feed_check DATETIME NULL;

-- Populate date_scraped if missing
ALTER TABLE {prefix}nitter_tweets 
ADD COLUMN date_scraped DATETIME DEFAULT CURRENT_TIMESTAMP;
```

**Phase 2 Migrations:**

```sql
-- images/media table
ALTER TABLE {prefix}nitter_images 
ADD COLUMN upload_attempts INT DEFAULT 0,
ADD COLUMN last_upload_attempt DATETIME NULL,
ADD COLUMN upload_status ENUM('pending','success','failed','retrying','failed_permanent') DEFAULT 'pending';
```

**Phase 3 Migrations:**

```sql
-- logs table
ALTER TABLE {prefix}nitter_logs 
ADD COLUMN log_type ENUM('scrape_image','scrape_video','conversion','upload','cron','other') DEFAULT 'other';
```

**Phase 4 Migrations:**

```sql
-- images/media table
ALTER TABLE {prefix}nitter_images 
ADD COLUMN hosting_provider VARCHAR(50) DEFAULT 'imgbb';
```


***

## **Testing Checklist**

### **Phase 1 Testing:**

- [ ] Image tweets appear immediately in feed
- [ ] Video tweets don't appear until GIF ready
- [ ] Failed videos are deleted, never appear
- [ ] Feed ordered by `date_published` DESC
- [ ] Reconciliation cron detects and promotes orphans
- [ ] Orphaned tweets appear at top after reconciliation


### **Phase 2 Testing:**

- [ ] Landscape videos use width constraint
- [ ] Portrait videos use height constraint
- [ ] First pass >70MB triggers early abort
- [ ] Early abort threshold setting works
- [ ] Failed uploads retry 3 times with backoff
- [ ] Daily cron retries failed uploads
- [ ] After 5 failures, marked `failed_permanent`


### **Phase 3 Testing:**

- [ ] Text-only tweets skipped when setting enabled
- [ ] GIF names are randomized (adjective-noun-hash)
- [ ] Logs filterable by type (image/video/upload/cron)
- [ ] All log calls include correct type


### **Phase 4 Testing:**

- [ ] Multiple provider APIs configured
- [ ] Priority/randomization works correctly
- [ ] Fallback to next priority on failure
- [ ] `hosting_provider` saved correctly in DB
- [ ] Each provider tested individually


### **Phase 5 Testing:**

- [ ] Import TXT file with various formats
- [ ] Duplicates detected and skipped
- [ ] Export includes all accounts with metadata
- [ ] Log export filters work (date/type/account)
- [ ] Tweets >400 chars show "Read more"
- [ ] Expand/collapse works smoothly
- [ ] Log retention cron deletes old logs


### **Phase 6 Testing:**

- [ ] Windows Task Scheduler runs cron successfully
- [ ] Account scraping works via system cron
- [ ] WP-Cron can be disabled if desired

***

## **Performance Considerations**

### **GPU Usage (GTX 1070):**

- 5 parallel video processes with `-hwaccel cuda`
- Monitor VRAM usage (each decode process consumes memory)
- Expected capacity: 5 videos simultaneously without issue


### **Database Queries:**

- Add indexes on new columns:
    - `feed_status`, `date_published` for feed queries
    - `upload_status`, `upload_attempts` for retry queries
    - `log_type` for log filtering


### **Disk Space:**

- Temp folder cleanup critical with parallel processing
- Auto-delete after upload (already implemented)
- Monitor temp folder size

***

## **Documentation Updates Needed**

- [ ] README: Update with new features
- [ ] Installation: Document Windows Task Scheduler setup
- [ ] Settings guide: Explain all new settings
- [ ] API documentation: Multi-provider setup instructions
- [ ] Troubleshooting: Common issues with GPU, upload providers

***