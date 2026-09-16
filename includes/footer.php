<?php
/**
 * includes/footer.php — closes panel or legacy layout.
 */
declare(strict_types=1);

require_once __DIR__ . '/panel/helpers.php';

$script = $_SERVER['SCRIPT_NAME'] ?? '';
$panelRole = panel_detect_role($script, $panel_role ?? null);
$is_panel = $panelRole !== null;

$base = defined('BASE_URL') ? rtrim(constant('BASE_URL'), '/') : '';
$siteName = defined('APP_NAME') ? constant('APP_NAME') : 'Preschool App';

if ($is_panel): ?>
    </main>
  </div>
</div>
<?php if (function_exists('render_panel_quick_fab')) {
    render_panel_quick_fab($panelRole);
} ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
  var toggle = document.getElementById('panelToggle');
  var collapseBtn = document.getElementById('panelCollapse');
  var sidebar = document.getElementById('panelSidebar');
  var main = document.querySelector('.panel-main');
  var overlay = document.getElementById('panelOverlay');
  var STORAGE_KEY = 'panelSidebarCollapsed';

  function closeSidebar() {
    if (sidebar) sidebar.classList.remove('open');
    if (overlay) overlay.classList.remove('show');
  }
  function setCollapsed(collapsed) {
    if (!sidebar || !main) return;
    sidebar.classList.toggle('collapsed', collapsed);
    main.classList.toggle('sidebar-collapsed', collapsed);
    if (collapseBtn) {
      var icon = collapseBtn.querySelector('i');
      if (icon) icon.className = collapsed ? 'bi bi-layout-sidebar' : 'bi bi-layout-sidebar-inset';
      collapseBtn.title = collapsed ? 'Expand sidebar' : 'Collapse sidebar';
    }
  }
  if (toggle && sidebar) {
    toggle.addEventListener('click', function () {
      sidebar.classList.toggle('open');
      if (overlay) overlay.classList.toggle('show');
    });
  }
  if (collapseBtn && sidebar && main) {
    if (window.innerWidth >= 992 && localStorage.getItem(STORAGE_KEY) === '1') {
      setCollapsed(true);
    }
    collapseBtn.addEventListener('click', function () {
      var next = !sidebar.classList.contains('collapsed');
      setCollapsed(next);
      try { localStorage.setItem(STORAGE_KEY, next ? '1' : '0'); } catch (e) {}
    });
  }
  if (overlay) overlay.addEventListener('click', closeSidebar);
  document.querySelectorAll('.panel-nav-link').forEach(function (link) {
    link.addEventListener('click', function () {
      if (window.innerWidth < 992) closeSidebar();
    });
  });
})();

(function () {
  var fab = document.getElementById('panelQuickFabToggle');
  var menu = document.getElementById('panelQuickFabMenu');
  if (!fab || !menu) return;
  fab.addEventListener('click', function (e) {
    e.stopPropagation();
    var open = menu.hidden;
    menu.hidden = !open;
    fab.setAttribute('aria-expanded', open ? 'true' : 'false');
    fab.classList.toggle('open', open);
  });
  document.addEventListener('click', function () {
    menu.hidden = true;
    fab.setAttribute('aria-expanded', 'false');
    fab.classList.remove('open');
  });
})();

(function () {
  var cfg = window.PANEL_SEARCH;
  var input = document.getElementById('panelGlobalSearchInput');
  var dropdown = document.getElementById('panelGlobalSearchDropdown');
  if (!cfg || !input || !dropdown) return;

  var timer = null;
  var activeIdx = -1;
  var lastResults = [];

  function closeDropdown() {
    dropdown.hidden = true;
    input.setAttribute('aria-expanded', 'false');
    activeIdx = -1;
  }

  function openDropdown() {
    dropdown.hidden = false;
    input.setAttribute('aria-expanded', 'true');
  }

  function renderResults(data) {
    lastResults = data.results || [];
    if (!lastResults.length) {
      dropdown.innerHTML = '<div class="panel-search-empty">No results. Try phone or form number.</div>';
      openDropdown();
      return;
    }
    var html = lastResults.map(function (r, i) {
      return '<a class="panel-search-item" data-idx="' + i + '" href="' + r.url + '">' +
        '<span class="icon"><i class="bi ' + r.icon + '"></i></span>' +
        '<span><div class="title">' + escapeHtml(r.title) + '</div>' +
        '<div class="sub">' + escapeHtml(r.subtitle) + '</div></span></a>';
    }).join('');
    html += '<div class="panel-search-footer">Press <kbd>Enter</kbd> for all results · <a href="' + cfg.page + '?q=' + encodeURIComponent(data.q || input.value) + '">View all</a></div>';
    dropdown.innerHTML = html;
    openDropdown();
    dropdown.querySelectorAll('.panel-search-item').forEach(function (el) {
      el.addEventListener('mouseenter', function () {
        activeIdx = parseInt(el.getAttribute('data-idx'), 10);
        highlightActive();
      });
    });
  }

  function escapeHtml(s) {
    var d = document.createElement('div');
    d.textContent = s || '';
    return d.innerHTML;
  }

  function highlightActive() {
    dropdown.querySelectorAll('.panel-search-item').forEach(function (el, i) {
      el.classList.toggle('active', i === activeIdx);
    });
  }

  function fetchResults(q) {
    if (q.length < 2) { closeDropdown(); return; }
    dropdown.innerHTML = '<div class="panel-search-loading"><span class="spinner-border spinner-border-sm"></span> Searching…</div>';
    openDropdown();
    fetch(cfg.api + '?q=' + encodeURIComponent(q) + '&limit=8', { credentials: 'same-origin' })
      .then(function (r) {
        return r.json().then(function (data) {
          if (!r.ok || !data.ok) {
            throw new Error((data && data.error) ? data.error : 'Search failed');
          }
          return data;
        });
      })
      .then(function (data) { renderResults(data); })
      .catch(function (err) {
        dropdown.innerHTML = '<div class="panel-search-empty">' + escapeHtml(err && err.message ? err.message : 'Search failed.') + '</div>';
        openDropdown();
      });
  }

  input.addEventListener('input', function () {
    clearTimeout(timer);
    var q = input.value.trim();
    timer = setTimeout(function () { fetchResults(q); }, 280);
  });

  input.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') { closeDropdown(); return; }
    if (e.key === 'ArrowDown') {
      e.preventDefault();
      activeIdx = Math.min(activeIdx + 1, lastResults.length - 1);
      highlightActive();
      return;
    }
    if (e.key === 'ArrowUp') {
      e.preventDefault();
      activeIdx = Math.max(activeIdx - 1, 0);
      highlightActive();
      return;
    }
    if (e.key === 'Enter') {
      if (activeIdx >= 0 && lastResults[activeIdx]) {
        window.location.href = lastResults[activeIdx].url;
      } else {
        window.location.href = cfg.page + '?q=' + encodeURIComponent(input.value.trim());
      }
    }
  });

  document.addEventListener('click', function (e) {
    if (!document.getElementById('panelGlobalSearchWrap')?.contains(e.target)) closeDropdown();
  });

  document.addEventListener('keydown', function (e) {
    if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
      e.preventDefault();
      input.focus();
      input.select();
    }
  });
})();
</script>
</body>
</html>
<?php else: ?>
  </div>
  </main>
  <footer class="site-footer mt-5 py-4">
    <div class="container text-center text-white-50 small">
      &copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($siteName); ?>
    </div>
  </footer>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <?php if ($base !== ''): ?>
    <script src="<?php echo htmlspecialchars($base); ?>/assets/js/main.js"></script>
  <?php endif; ?>
</body>
</html>
<?php endif; ?>
