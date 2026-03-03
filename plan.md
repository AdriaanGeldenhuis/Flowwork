# Plan: Review & Fix Projects Module + Migrations

## Issues Found

After thorough review of all `/projects/` API files against the SQL schema in `/Migrations/flowwwqmnt_db1.sql`, here are the issues categorized by severity:

---

### CRITICAL: Data Integrity / Cascading Deletes

**1. `board.delete.php` - Missing child record cleanup**
- Missing: `board_subitems`, `board_guests`, `board_guest_activity`, `board_audit_log`, `board_column_settings`
- FK cascades handle some (board_guests → CASCADE, board_subitems → CASCADE on parent_item), but `board_column_settings` has NO FK and will be orphaned
- **Fix**: Add DELETE statements for `board_column_settings` and `board_audit_log` before deleting the board. The FK cascades handle `board_guests`/`board_guest_activity`/`board_subitems`.

**2. `project.delete.php` - Same missing cleanup as above**
- Same tables not cleaned up during full project deletion
- **Fix**: Add DELETE for `board_column_settings` and `board_audit_log` alongside existing cleanup

---

### HIGH: Security

**3. `_respond.php` - Doesn't clean output buffer before JSON response**
- `_bootstrap.php` calls `ob_start()`, but `respond_ok()`/`respond_error()` don't flush the buffer
- Any PHP warning/notice will contaminate JSON, potentially leaking file paths or internal info
- Compare with `_response.php` which does `while (ob_get_level()) ob_end_clean();`
- **Fix**: Add buffer cleaning to both `respond_ok()` and `respond_error()`

**4. `_guard.php` - `require_board_role()` missing `company_id` check**
- Board membership query (line 78-84) only checks `board_id` + `user_id`, not `company_id`
- The project_boards fallback (line 97-103) also doesn't verify `company_id`
- Could theoretically allow cross-company access if user IDs collide
- **Fix**: Add `company_id` filter to the `project_boards` lookup query

**5. `board.load.php` - Board meta query missing `company_id` filter**
- Line 17-22: Only filters by `board_id`, not `company_id`
- The role check guards access, but defense-in-depth says add `company_id`
- **Fix**: Add `AND pb.company_id = ?` to the board meta query

**6. `project.view.php` & `project.delete.php` - Expose internal error messages**
- `respond_error('Failed to load project: ' . $e->getMessage(), 500)` leaks stack details
- **Fix**: Log the error, return generic message to user

**7. `project.list.php` - LIKE wildcard injection**
- Line 27-28: `"%$search%"` doesn't escape `%` and `_` LIKE meta-characters
- User can craft search to bypass filtering
- **Fix**: Escape LIKE wildcards: `str_replace(['%', '_'], ['\\%', '\\_'], $search)`

---

### MEDIUM: Logic Bugs

**8. `project.create.php` - Budget cast as `(int)` loses decimal precision**
- Line 9: `$budget = (int)($_POST['budget'] ?? 0);`
- DB column is `decimal(15,2)` — casting to int loses cents
- **Fix**: Use `floatval()` or keep as string and let PDO handle it

**9. `project.create.php` - Template `board_type` ignored**
- Line 57: `$bType = 'general';` hardcoded
- Template JSON has `"type": "schedule"`, `"type": "costing"`, `"type": "procurement"`
- These are all valid enum values in `project_boards.board_type`
- **Fix**: Read `$boardDef['type']`, validate against allowed enum values, fall back to 'general'

---

### LOW: Consistency / Cleanup

**10. Dual response helpers**
- `_respond.php` has `respond_ok()` / `respond_error()`
- `_response.php` has `api_success()` / `api_error()`
- All API files use `_respond.php` — the `_response.php` file is unused dead code
- **Fix**: No code change needed now, but noting for awareness

---

## Implementation Plan

### Step 1: Fix `_respond.php` - Add output buffer cleaning
- File: `/projects/api/_respond.php`
- Add `while (ob_get_level()) ob_end_clean();` before json_encode in both functions

### Step 2: Fix `project.list.php` - Escape LIKE wildcards
- File: `/projects/api/project.list.php`
- Add LIKE escape before building params

### Step 3: Fix `project.create.php` - Budget precision + template board_type
- File: `/projects/api/project.create.php`
- Change `(int)` to `floatval()` for budget
- Use template's `type` field with validation against allowed enum values

### Step 4: Fix `_guard.php` - Add company_id safety
- File: `/projects/api/_guard.php`
- Add `company_id` check to `require_board_role()` project_boards fallback

### Step 5: Fix `board.load.php` - Add company_id filter
- File: `/projects/api/board.load.php`
- Add `company_id` to board meta query

### Step 6: Fix `board.delete.php` - Complete cascading cleanup
- File: `/projects/api/board.delete.php`
- Add cleanup for `board_column_settings` and `board_audit_log`

### Step 7: Fix `project.delete.php` - Complete cascading cleanup
- File: `/projects/api/project.delete.php`
- Add cleanup for `board_column_settings` and `board_audit_log`

### Step 8: Fix error message exposure
- Files: `project.view.php`, `project.delete.php`
- Replace `$e->getMessage()` with generic error messages

### Step 9: Commit & push
