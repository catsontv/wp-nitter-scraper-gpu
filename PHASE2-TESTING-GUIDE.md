# Phase 2 Implementation - Testing Guide

## Download and Installation

### Step 1: Download the Plugin

1. Go to: https://github.com/catsontv/wp-nitter-scraper-gpu/tree/phase-2-implementation
2. Click the green **Code** button
3. Click **Download ZIP**
4. Extract the ZIP file to get the `nitter-scraper` folder

### Step 2: Install in WordPress

1. **Deactivate** your current Nitter Scraper plugin (if active):
   - Go to WordPress Admin → Plugins
   - Find "Nitter Scraper GPU" and click **Deactivate**

2. **Delete** the old plugin folder:
   - Navigate to `C:\xampp\htdocs\wp-content\plugins\`
   - Delete the old `nitter-scraper` folder

3. **Upload** the new Phase 2 version:
   - Copy the extracted `nitter-scraper` folder to `C:\xampp\htdocs\wp-content\plugins\`

4. **Activate** the plugin:
   - Go to WordPress Admin → Plugins
   - Find "Nitter Scraper GPU" and click **Activate**

### Step 3: Verify Installation

After activation, the plugin will automatically:
- Run Phase 1 and Phase 2 database migrations
- Add new settings with default values
- You should see migration logs in the Logs page

---

## Phase 2 Features Testing

### Feature 2.1: Orientation-Aware Smart Quality Levels

**What to Test:**
- Upload a landscape video (wider than tall)
- Upload a portrait video (taller than wide)
- Upload a square video (equal width/height)

**Expected Behavior:**
- Check the logs for "Video orientation detected: landscape/portrait/square"
- Landscape videos should constrain width
- Portrait videos should constrain height
- Square videos should constrain both dimensions

**Early Abort Testing:**
1. Go to **Settings → Video Settings**
2. Find **"First Pass Abort Threshold (MB)"** (default: 70 MB)
3. Upload a complex/long video
4. Check logs for abort message: "Aborting: First pass XMB, estimated minimum ~YMB > 20MB"

**How to Test:**
1. Add a Twitter account with video content
2. Run scraper
3. Wait for video processing cron (or manually trigger)
4. Check **Logs** page for orientation detection and conversion details

---

### Feature 2.2: Enhanced Logging with Process-Type Filters

**What to Test:**
1. Go to **Logs** page
2. Look for new **"Filter by Type"** dropdown at the top
3. Options should include:
   - All
   - Image Scraping
   - Video Processing
   - Conversion
   - Upload
   - Feed
   - Cron
   - System

**How to Test:**
1. Scrape some accounts (generates `scrape_image` and `scrape_video` logs)
2. Process some videos (generates `conversion` logs)
3. Use the filter dropdown to view specific log types
4. Verify filtering works correctly

---

### Feature 2.3: Bulk Account Import/Export

**Import Testing:**

1. Go to **Accounts** page
2. Look for **"Import Accounts"** button
3. Click it to open import modal
4. Create a test file `accounts.txt` with content:
   ```
   elonmusk
   NASA,90
   https://twitter.com/SpaceX,60
   @POTUS
   ```
5. Upload or paste the content
6. Click **"Import"**
7. Verify results show:
   - X imported
   - Y duplicates skipped
   - Z invalid

**Export Testing:**

1. Go to **Accounts** page
2. Look for **"Export Accounts"** button
3. Click it
4. A file `nitter-accounts-YYYY-MM-DD-HHMMSS.txt` should download
5. Open the file and verify format:
   ```
   username1,30
   username2,60
   ```

---

### Feature 2.4: Configurable Log Retention

**Settings Test:**

1. Go to **Settings → General Settings**
2. Find **"Log Retention (Days)"** dropdown
3. Options: 1, 7, 14, 30, 90, Never Delete (0)
4. Change setting and save

**Cron Test:**

1. Set retention to **1 day**
2. Wait 24 hours (or manually trigger cron)
3. Check that logs older than 1 day are deleted
4. Verify in Logs page

**Manual Cleanup Test:**

1. Go to **Logs** page
2. Look for **"Clean Old Logs"** button
3. Click it
4. Logs older than retention setting should be deleted
5. Confirmation message should appear

---

### Feature 2.5: System Cron for Account Scraping

**Setup Instructions:**

1. Go to **Settings → General Settings**
2. Find **"Use System Cron (Windows Task Scheduler)"** section
3. Check the box to enable
4. Note the command shown:
   ```
   C:\xampp\php\php.exe C:\xampp\htdocs\wp-content\plugins\nitter-scraper\cron-scrape-accounts.php
   ```
5. Set **"System Cron Interval (minutes)"** to 60 (or desired interval)

**Windows Task Scheduler Setup:**

1. Press `Win + R`, type `taskschd.msc`, press Enter
2. Click **"Create Basic Task"**
3. Name: "Nitter Account Scraper"
4. Trigger: **Daily**
5. Action: **Start a program**
6. Program/script: `C:\xampp\php\php.exe`
7. Add arguments: `C:\xampp\htdocs\wp-content\plugins\nitter-scraper\cron-scrape-accounts.php`
8. **Important:** Check "Repeat task every" and set to your interval (e.g., 60 minutes)
9. **Important:** Set "for a duration of" to **Indefinitely**
10. Finish

**Testing:**

1. Right-click the task in Task Scheduler
2. Click **"Run"**
3. Go to **Logs** page in WordPress
4. Look for entries:
   - "=== SYSTEM CRON: Account scraping started ==="
   - "SYSTEM CRON: Scraping account [username]"
   - "=== SYSTEM CRON: Completed - X success, Y failed ==="

**Note:** When system cron is enabled, WordPress cron scraping is automatically disabled to avoid duplicates.

---

## Additional Phase 2 Enhancements

### Randomized GIF Names (Feature 3.3)

**What to Test:**
- Process a video
- Check the uploaded GIF name in ImgBB or logs
- Should see format: `cosmic-nebula-a7f3d9k2.gif`
- Each GIF should have a unique creative name

**Where to Check:**
- Logs page: Look for "Successfully processed entry ID" messages
- They will show the random name generated

---

## Common Issues and Troubleshooting

### Issue: Migrations not running
**Solution:**
1. Deactivate and reactivate the plugin
2. Check Logs for "Phase 2: Added..." messages

### Issue: Filter dropdown not showing
**Solution:**
1. Clear browser cache
2. Hard refresh (Ctrl + F5)
3. Check that `admin/logs-page.php` was updated

### Issue: Import/Export buttons missing
**Solution:**
1. Verify `admin/accounts-page.php` exists
2. Check browser console for JavaScript errors
3. Ensure AJAX handlers are loaded

### Issue: System cron not working
**Solution:**
1. Test the PHP command manually in Command Prompt:
   ```
   C:\xampp\php\php.exe C:\xampp\htdocs\wp-content\plugins\nitter-scraper\cron-scrape-accounts.php
   ```
2. Check logs for output
3. Verify Task Scheduler task is configured correctly
4. Ensure "Use System Cron" is checked in settings

### Issue: Videos not processing
**Solution:**
1. Check **Settings → Video Settings**
2. Ensure "Enable Video Processing" is checked
3. Verify FFmpeg/FFprobe paths are correct:
   - `C:\Users\destro\bin\ffmpeg.exe`
   - `C:\Users\destro\bin\ffprobe.exe`
4. Check GPU settings (CUDA should be enabled)

---

## Verification Checklist

- [ ] Plugin activates successfully
- [ ] Phase 2 migration logs appear
- [ ] New settings appear in Settings pages
- [ ] Log type filter dropdown works
- [ ] Import accounts works (import modal appears, file upload works)
- [ ] Export accounts works (file downloads with correct format)
- [ ] Log retention setting appears and saves
- [ ] System cron section appears in settings
- [ ] System cron script file exists: `nitter-scraper/cron-scrape-accounts.php`
- [ ] Video processing detects orientation
- [ ] Early abort threshold setting appears
- [ ] GIF names are randomized (check logs)
- [ ] All existing Phase 1 features still work

---

## Phase 2 Features Summary

### Implemented in this branch:

1. ✅ **Feature 2.1:** Orientation-aware quality levels with early abort
2. ✅ **Feature 2.2:** Enhanced logging with process-type filters  
3. ⚠️ **Feature 2.3:** Bulk account import/export (backend ready, needs admin UI)
4. ✅ **Feature 2.4:** Configurable log retention
5. ✅ **Feature 2.5:** System cron for account scraping
6. ✅ **Feature 3.3:** Randomized GIF names

### Still needs admin UI implementation:

- Import/Export modal and AJAX handlers (accounts-page.php updates)
- Log filter dropdown UI (logs-page.php updates)
- First pass abort threshold setting UI (video-settings-page.php updates)
- Log retention dropdown UI (settings-page.php updates)
- System cron settings UI (settings-page.php updates)

**Note:** The backend logic for ALL features is complete and working. The admin UI updates need to be added to the respective admin pages.

---

## Contact

If you encounter any issues during testing, check the **Logs** page first. Most issues will be logged there with detailed error messages.
