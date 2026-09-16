# Shared Panel Features (`includes/features/`)

Reusable page logic for multiple panel roles. Each role keeps a **thin wrapper** in `{role}/{page}.php` that bootstraps auth and calls `feature_run()`.

## Quick start

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/panel/bootstrap.php';
panel_bootstrap('owner');
$DEBUG = panel_debug();
require_once __DIR__ . '/../includes/features/feature_run.php';

feature_run('enquiry_list', [
    'panel' => 'owner',
    'page_title' => 'Enquiry List',
]);
```

## Loader API

| Function | Purpose |
|----------|---------|
| `feature_run(string $name, array $config)` | Load `includes/features/{name}.php` with config |
| `feature_config()` | Full config array |
| `feature_panel()` | Current panel slug (`owner`, `accounts`, …) |
| `feature_cfg(string $key, mixed $default)` | Single config value |

Config is stored in `$GLOBALS['FEATURE_CONFIG']`.

## Feature modules

### Owner ↔ Reception (Phase 3.5)

| Module | Wrappers | Key config |
|--------|----------|------------|
| `parents_children` | owner, reception | `panel` |
| `notices_publish` | owner, reception | `panel` |
| `pending_alerts` | owner, reception | `panel` |
| `news_events` | owner, reception | `panel` |
| `enquiry_list` | owner, reception | `page_title`, `auth_roles`, `source_default_add`, `source_default_edit` |
| `pending_tasks` | owner, reception | `task_scope`, `show_assign_ui`, `enforce_ownership`, `page_title` |

### Owner ↔ Accounts finance (Phase 4)

| Module | Wrappers | Key config |
|--------|----------|------------|
| `daily_collection` | owner, accounts | `page_title` |
| `monthly_summary` | owner, accounts | `page_title` |
| `expenses` | owner (`expense.php`), accounts (`expenses.php`) | `page_title` |
| `pending_fees` | owner, accounts | `page_title`; accounts wrapper uses `skip_auth` + `require_accounts_or_reception_auth()` |

### User profiles (Phase 4)

| Module | Wrappers | Key config |
|--------|----------|------------|
| `staff_profile` | reception, accounts | `page_title` — staff meta/role fields |
| `member_profile` | teacher, parent | `show_teacher_fields`, `avatar_subdir`, `default_role_label` |

### Owner CMS / website content (Phase 4)

| Module | Wrapper | Key config |
|--------|---------|------------|
| `content_about` | owner | `page_title` |
| `content_facilities` | owner | `page_title` |
| `content_photos` | owner | `page_title` |
| `content_testimonialsfaq` | owner | `page_title` |
| `school_profile` | owner (`profile.php`) | `page_title` — school branding, hero, logo |

CMS modules use `includes/cms/helpers.php` for uploads and JSON fields.

## Adding a new shared feature

1. Create `includes/features/my_feature.php` (logic only — no `panel_bootstrap()`).
2. Read config at top: `$cfg = $GLOBALS['FEATURE_CONFIG'] ?? [];`
3. Use `require_once __DIR__ . '/../header.php'` and `footer.php` for panel layout.
4. Add thin wrappers per role.
5. Register menu links in `includes/panel/{role}_menu.php`.

## Accounts + reception auth pattern

```php
panel_bootstrap('accounts', ['skip_auth' => true]);
require_accounts_or_reception_auth();
$DEBUG = panel_debug();
feature_run('pending_fees', ['panel' => 'accounts']);
```

## Scaling checklist

- Prefer `safe_db_*`, `table_exists()`, `column_exists()` from bootstrap — do not redeclare helpers in features.
- Use `auth_user_id()`, `auth_login_url($panel)`, `panel_base_url($panel)` instead of hard-coded paths.
- Parameterize differences via `$cfg` flags, not copy-paste wrappers.
- Keep wrappers under ~25 lines; business logic lives in `includes/features/`.
