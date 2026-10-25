<?php
/**
 * Page chrome: top bar, folder-tab navigation, page bar, help drawer, footer.
 */
class View
{
    public static $section = '';
    public static $page = '';
    public static $helpKey = '';

    public static function nav()
    {
        return array(
            'overview' => array('label' => 'Overview', 'icon' => 'home', 'items' => array(
                array('dashboard', 'Dashboard', null),
                array('search', 'Search records', 'records.view'),
                array('notifications', 'Notifications', null),
                array('manual', "User's Manual", null),
            )),
            'records' => array('label' => 'Records', 'icon' => 'ledger', 'items' => array(
                array('sales', 'Sales', 'records.view'),
                array('purchases', 'Purchases', 'records.view'),
                array('importations', 'Importations', 'records.view'),
                array('withholding', 'Taxes we withheld', 'records.view'),
                array('withheld_on_us', 'Taxes withheld on us', 'records.view'),
                array('compensation', 'Compensation', 'records.view'),
                array('grid', 'Grid entry', 'records.edit'),
                array('directory', 'Customers, suppliers, payees', 'records.view'),
                array('employees', 'Employees', 'records.view'),
                array('periods', 'Period locks', 'records.view'),
            )),
            'imports' => array('label' => 'Imports', 'icon' => 'sheet', 'items' => array(
                array('imports', 'Import a spreadsheet', 'imports.run'),
                array('templates', 'Template library', 'records.view'),
            )),
            'filing' => array('label' => 'Filing', 'icon' => 'file', 'items' => array(
                array('generate', 'Generate a file', 'reports.generate'),
                array('files', 'File library', 'records.view'),
                array('dat_upload', 'Upload an existing DAT', 'dat.upload'),
                array('consolidate', 'SRS and annual builder', 'reports.generate'),
                array('reconcile', 'Reconciliation', 'records.view'),
                array('filings', 'Filing records', 'records.view'),
            )),
            'validation' => array('label' => 'Validation', 'icon' => 'check', 'items' => array(
                array('validation', 'Validation center', 'records.view'),
                array('approvals', 'Approvals', 'records.view'),
            )),
            'certificates' => array('label' => 'Certificates', 'icon' => 'stamp', 'items' => array(
                array('certificates', 'Certificate register', 'records.view'),
                array('cert_issue', 'Issue certificates', 'certificates.issue'),
                array('cert_quick', 'Quick 2307 entry', 'certificates.issue'),
            )),
            'admin' => array('label' => 'Admin', 'icon' => 'gear', 'items' => array(
                array('clients', 'Clients and branches', 'clients.view'),
                array('users', 'Members and roles', 'users.manage'),
                array('atc', 'Tax codes (ATC)', 'reference.view'),
                array('specs', 'File layouts', 'reference.view'),
                array('reference', 'RDO codes and deadlines', 'reference.view'),
                array('audit', 'Audit trail', 'audit.view'),
                array('backups', 'Backup and restore', 'backups.manage'),
                array('api_tokens', 'API tokens', 'api.manage'),
                array('system', 'Platform settings', 'system.manage'),
            )),
        );
    }

    /** Whether the signed-in user may open a page (used for manual links). */
    public static function canSee($page)
    {
        if (!Ctx::org() && !in_array($page, array('settings', 'orgs', 'org_new', 'manual', 'system', 'specs', 'spec_view', 'atc', 'reference'), true)) {
            return false;
        }
        if ($page === 'system') {
            return Ctx::isSuper();
        }
        foreach (self::nav() as $sec) {
            foreach ($sec['items'] as $it) {
                if ($it[0] === $page) {
                    return $it[2] === null || Ctx::can($it[2]);
                }
            }
        }
        return true;
    }

    public static function sectionOf($page)
    {
        foreach (self::nav() as $key => $sec) {
            foreach ($sec['items'] as $it) {
                if ($it[0] === $page) {
                    return $key;
                }
            }
        }
        $map = array('record_form' => 'records', 'cp_form' => 'records', 'employee_form' => 'records', 'comp_form' => 'records',
            'import_map' => 'imports', 'import_review' => 'imports', 'run_view' => 'filing', 'file_view' => 'filing', 'filing_new' => 'filing',
            'cert_view' => 'certificates', 'client_form' => 'admin', 'spec_view' => 'admin', 'user_form' => 'admin', 'settings' => 'overview');
        return isset($map[$page]) ? $map[$page] : 'overview';
    }

    public static function icon($name, $cls = 'ico')
    {
        $p = array(
            'home' => '<path d="M3 11.5 12 4l9 7.5"/><path d="M5.5 10v9.5h13V10"/><path d="M10 19.5v-5h4v5"/>',
            'ledger' => '<rect x="4" y="3.5" width="16" height="17" rx="2"/><path d="M8 3.5v17M11 8h6M11 12h6M11 16h4"/>',
            'sheet' => '<rect x="3.5" y="4" width="17" height="16" rx="2"/><path d="M3.5 9h17M3.5 14h17M9.5 4v16"/>',
            'file' => '<path d="M6 3.5h8l4 4v13H6z"/><path d="M14 3.5v4h4M9 12h6M9 16h6"/>',
            'check' => '<path d="M4 12.5 9 17.5 20 6.5"/>',
            'stamp' => '<path d="M9 4h6l-1 7h-4z"/><path d="M5 14h14v3H5zM6 20h12"/>',
            'gear' => '<circle cx="12" cy="12" r="3"/><path d="M12 2.5v3M12 18.5v3M4.2 6.2l2.1 2.1M17.7 15.7l2.1 2.1M2.5 12h3M18.5 12h3M4.2 17.8l2.1-2.1M17.7 8.3l2.1-2.1"/>',
            'search' => '<circle cx="11" cy="11" r="6.5"/><path d="m20 20-4.2-4.2"/>',
            'sun' => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2.5M12 19.5V22M2 12h2.5M19.5 12H22M4.9 4.9l1.8 1.8M17.3 17.3l1.8 1.8M4.9 19.1l1.8-1.8M17.3 6.7l1.8-1.8"/>',
            'moon' => '<path d="M20 14.5A8 8 0 1 1 9.5 4a6.5 6.5 0 0 0 10.5 10.5z"/>',
            'bell' => '<path d="M6 16.5V11a6 6 0 0 1 12 0v5.5l1.5 2h-15z"/><path d="M10 20.5a2 2 0 0 0 4 0"/>',
            'user' => '<circle cx="12" cy="8.5" r="4"/><path d="M4 20.5a8 8 0 0 1 16 0"/>',
            'plus' => '<path d="M12 5v14M5 12h14"/>',
            'download' => '<path d="M12 4v11M7 10.5l5 5 5-5M5 20h14"/>',
            'upload' => '<path d="M12 20V9M7 13.5l5-5 5 5M5 4h14"/>',
            'help' => '<circle cx="12" cy="12" r="9"/><path d="M9.5 9.5a2.5 2.5 0 1 1 3.5 2.3c-.7.3-1 .9-1 1.7v.5M12 17.2v.1"/>',
            'close' => '<path d="M6 6l12 12M18 6 6 18"/>',
            'menu' => '<path d="M4 7h16M4 12h16M4 17h16"/>',
            'lock' => '<rect x="5" y="10.5" width="14" height="10" rx="2"/><path d="M8 10.5V8a4 4 0 0 1 8 0v2.5"/>',
            'chev' => '<path d="m6 9 6 6 6-6"/>',
            'warn' => '<path d="M12 3.5 2.5 20h19z"/><path d="M12 10v4.5M12 17.2v.1"/>',
            'x' => '<path d="M6 6l12 12M18 6 6 18"/>',
            'swap' => '<path d="M7 7h11l-3-3M17 17H6l3 3"/>',
            'logout' => '<path d="M14 4h5v16h-5M10 8l-4 4 4 4M6 12h10"/>',
        );
        $body = isset($p[$name]) ? $p[$name] : $p['file'];
        return '<svg class="' . $cls . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $body . '</svg>';
    }

    public static function header($title, $opts = array())
    {
        self::$page = isset($opts['page']) ? $opts['page'] : (isset($_GET['p']) ? $_GET['p'] : 'dashboard');
        self::$section = self::sectionOf(self::$page);
        self::$helpKey = isset($opts['help']) ? $opts['help'] : self::$page;
        $bare = !empty($opts['bare']);
        $u = Auth::user();
        ?><!doctype html>
<html lang="en" data-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<title><?= e($title) ?> | G-SMaRT BIR</title>
<script>(function(){try{var t=localStorage.getItem('gsb-theme')||'system';var d=t==='dark'||(t==='system'&&window.matchMedia('(prefers-color-scheme: dark)').matches);document.documentElement.setAttribute('data-theme',d?'dark':'light');document.documentElement.setAttribute('data-theme-pref',t);}catch(e){}})();</script>
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
<link rel="icon" href="<?= e(asset('img/mark.svg')) ?>" type="image/svg+xml">
</head>
<body class="<?= $bare ? 'is-bare' : 'is-app' ?>">
<a class="skip" href="#main">Skip to content</a>
<?php if (!$bare && $u) { self::topbar($u); } ?>
<main id="main" class="<?= $bare ? 'bare-main' : 'sheet-wrap' ?>">
<?php
        foreach (flashes() as $f) {
            echo '<div class="notice notice-' . e($f['type']) . '" role="status">' . e($f['msg']) . '<button type="button" class="notice-x" data-dismiss aria-label="Dismiss">' . self::icon('x') . '</button></div>';
        }
    }

    private static function topbar($u)
    {
        $client = Ctx::client();
        $clients = Ctx::clients();
        $unread = (int)DB::val('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL', array($u['id']));
        $pendingAppr = Ctx::orgId() && Ctx::can('approvals.decide') ? (int)DB::val('SELECT COUNT(*) FROM approval_requests WHERE organization_id = ? AND decision = ? AND requested_by <> ?', array(Ctx::orgId(), 'pending', $u['id'])) : 0;
        ?>
<header class="topbar">
  <div class="topbar-row">
    <a class="brand" href="<?= e(url('dashboard')) ?>" aria-label="G-SMaRT BIR home">
      <img src="<?= e(asset('img/mark.svg')) ?>" alt="" width="30" height="30">
      <span class="brand-text"><strong>G-SMaRT</strong> BIR Integration</span>
    </a>
    <form class="client-switch" method="post" action="<?= e(url('switch_client')) ?>">
      <?= csrf_field() ?>
      <label class="sr" for="client-select">Active client</label>
      <?php if ($clients) { ?>
        <select id="client-select" name="client_id" data-autosubmit>
          <?php foreach ($clients as $c) { ?>
            <option value="<?= (int)$c['id'] ?>" <?= $client && (int)$client['id'] === (int)$c['id'] ? 'selected' : '' ?>><?= e(Ctx::clientName($c)) ?></option>
          <?php } ?>
        </select>
        <?php if ($client) { echo Tin::boxes($client['tin'], (($ho = Ctx::headOffice()) ? $ho['branch_code'] : null)); } ?>
      <?php } else { ?>
        <?php if (Ctx::org()) { ?><a class="link" href="<?= e(url('client_form')) ?>">Add your first client</a><?php } else { ?><a class="link" href="<?= e(url('orgs')) ?>">Choose an organization</a><?php } ?>
      <?php } ?>
    </form>
    <div class="topbar-tools">
      <form class="quick-search" action="<?= e(base_url() . '/index.php') ?>" method="get" role="search">
        <input type="hidden" name="p" value="search">
        <label class="sr" for="qs">Search by TIN, name or file</label>
        <?= self::icon('search') ?><input id="qs" name="q" type="search" placeholder="TIN, name or file" value="<?= e(isset($_GET['q']) && self::$page === 'search' ? $_GET['q'] : '') ?>">
      </form>
      <button type="button" class="tool" data-theme-toggle aria-label="Switch light or dark mode" title="Light or dark mode"><?= self::icon('sun', 'ico ico-sun') ?><?= self::icon('moon', 'ico ico-moon') ?></button>
      <a class="tool" href="<?= e(url('notifications')) ?>" aria-label="Notifications" title="Notifications"><?= self::icon('bell') ?><?php if ($unread + $pendingAppr) { echo '<span class="count">' . ($unread + $pendingAppr) . '</span>'; } ?></a>
      <details class="user-menu">
        <summary class="tool user-tool" aria-label="Account menu"><?= self::icon('user') ?><span class="user-name"><?= e($u['full_name']) ?></span></summary>
        <div class="menu-panel">
          <p class="menu-who"><strong><?= e($u['full_name']) ?></strong><span><?= e(Ctx::roleName()) ?><?= Ctx::org() ? ', ' . e(Ctx::org()['name']) : '' ?></span></p>
          <a href="<?= e(url('settings')) ?>">Account and security</a>
          <?php $ms = Ctx::memberships(); if (count($ms) > 1 || Ctx::isSuper()) { ?><a href="<?= e(url('orgs')) ?>">Switch organization</a><?php } ?>
          <a href="<?= e(url('manual')) ?>">User's Manual</a>
          <form method="post" action="<?= e(url('logout')) ?>"><?= csrf_field() ?><button type="submit" class="menu-btn">Sign out</button></form>
        </div>
      </details>
    </div>
  </div>
  <nav class="tabs" aria-label="Sections">
    <?php foreach (self::nav() as $key => $sec) {
        $items = array_filter($sec['items'], function ($it) { return $it[2] === null || Ctx::can($it[2]); });
        if (!$items) { continue; }
        $active = self::$section === $key; ?>
      <div class="tab <?= $active ? 'is-active' : '' ?>">
        <button type="button" class="tab-btn" aria-expanded="false" aria-haspopup="true"><?= self::icon($sec['icon']) ?><span><?= e($sec['label']) ?></span><?= $key === 'validation' && $pendingAppr ? '<span class="count">' . $pendingAppr . '</span>' : '' ?></button>
        <div class="tab-menu">
          <?php foreach ($items as $it) { ?>
            <a href="<?= e(url($it[0])) ?>" class="<?= self::$page === $it[0] ? 'is-current' : '' ?>"><?= e($it[1]) ?></a>
          <?php } ?>
        </div>
      </div>
    <?php } ?>
  </nav>
</header>
<nav class="dock" aria-label="Quick sections">
  <a href="<?= e(url('dashboard')) ?>" class="<?= self::$section === 'overview' ? 'is-active' : '' ?>"><?= self::icon('home') ?><span>Home</span></a>
  <a href="<?= e(url('withholding')) ?>" class="<?= self::$section === 'records' ? 'is-active' : '' ?>"><?= self::icon('ledger') ?><span>Records</span></a>
  <a href="<?= e(url('generate')) ?>" class="<?= self::$section === 'filing' ? 'is-active' : '' ?>"><?= self::icon('file') ?><span>Filing</span></a>
  <a href="<?= e(url('validation')) ?>" class="<?= self::$section === 'validation' ? 'is-active' : '' ?>"><?= self::icon('check') ?><span>Validate</span></a>
  <button type="button" data-open-sheet="more-sheet"><?= self::icon('menu') ?><span>More</span></button>
</nav>
<div class="sheet-overlay" id="more-sheet" hidden>
  <div class="bottom-sheet" role="dialog" aria-label="All sections">
    <div class="sheet-head"><strong>All sections</strong><button type="button" class="tool" data-close-sheet aria-label="Close"><?= self::icon('close') ?></button></div>
    <?php foreach (self::nav() as $key => $sec) {
        $items = array_filter($sec['items'], function ($it) { return $it[2] === null || Ctx::can($it[2]); });
        if (!$items) { continue; } ?>
      <div class="sheet-group"><h3><?= e($sec['label']) ?></h3>
        <?php foreach ($items as $it) { ?><a href="<?= e(url($it[0])) ?>"><?= e($it[1]) ?></a><?php } ?>
      </div>
    <?php } ?>
  </div>
</div>
<?php
    }

    /**
     * Compact page bar: title, one context line, actions on the right, help button.
     */
    public static function pageBar($title, $context = '', $actions = '', $showHelp = true)
    {
        echo '<div class="pagebar"><div class="pagebar-text"><h1>' . e($title) . '</h1>';
        if ($context !== '') {
            echo '<p class="pagebar-context">' . $context . '</p>';
        }
        echo '</div><div class="pagebar-actions">' . $actions;
        if ($showHelp && Help::has(self::$helpKey)) {
            echo '<button type="button" class="btn btn-quiet" data-help="' . e(self::$helpKey) . '">' . self::icon('help') . '<span>Help</span></button>';
        }
        echo '</div></div>';
    }

    public static function clientContext($extra = '')
    {
        $c = Ctx::client();
        if (!$c) {
            return e($extra);
        }
        $s = e(Ctx::clientName($c));
        if ($extra !== '') {
            $s .= ', ' . $extra;
        }
        return $s;
    }

    public static function footer()
    {
        $help = Help::has(self::$helpKey) ? Help::article(self::$helpKey) : null;
        ?>
</main>
<?php if ($help) { ?>
<aside class="drawer" id="help-drawer" hidden aria-label="Help">
  <div class="drawer-panel">
    <div class="drawer-head"><strong><?= e($help['title']) ?></strong><button type="button" class="tool" data-close-help aria-label="Close help"><?= self::icon('close') ?></button></div>
    <div class="drawer-body prose"><p class="drawer-summary"><?= e($help['summary']) ?></p><?= $help['html'] ?><p class="drawer-more"><a class="btn btn-sm" href="<?= e(url('manual', array('s' => $help['key']))) ?>">Open section <?= e($help['num']) ?> in the User's Manual</a></p></div>
  </div>
</aside>
<?php } ?>
<div class="zoom" id="img-zoom" hidden><figure><img alt=""><figcaption></figcaption></figure><button type="button" class="tool" data-zoom-close aria-label="Close image"><?= self::icon('close') ?></button></div>
<footer class="foot"><span>G-SMaRT BIR Integration System <?= e(GSB_VERSION) ?></span><span>Internal checks never replace the official BIR validator.</span></footer>
<script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
<?php
    }

    /** Render a small view file from app/views inside the full chrome. */
    public static function render($view, $data = array(), $title = '')
    {
        self::header($title, array('bare' => !Auth::check()));
        extract($data);
        include APP_PATH . '/views/' . $view . '.php';
        self::footer();
    }

    // ------------------------------------------------------------ form helpers
    public static function select($name, $options, $selected, $attrs = '', $placeholder = null)
    {
        $h = '<select name="' . e($name) . '" ' . $attrs . '>';
        if ($placeholder !== null) {
            $h .= '<option value="">' . e($placeholder) . '</option>';
        }
        foreach ($options as $v => $label) {
            $h .= '<option value="' . e($v) . '"' . ((string)$v === (string)$selected ? ' selected' : '') . '>' . e($label) . '</option>';
        }
        return $h . '</select>';
    }

    public static function status($status)
    {
        $map = array(
            'bir_validated' => array('ok', 'Officially validated'), 'approved' => array('ok', 'Approved'), 'submitted' => array('ok', 'Submitted'),
            'structure_checked' => array('info', 'Structure checked'), 'pending_bir' => array('wait', 'Pending BIR validation'),
            'prepared' => array('info', 'Prepared'), 'bir_failed' => array('bad', 'Failed BIR validation'), 'structure_failed' => array('bad', 'Structure check failed'),
            'superseded' => array('muted', 'Superseded'), 'draft' => array('muted', 'Draft'), 'pending' => array('wait', 'Pending'),
            'rejected' => array('bad', 'Rejected'), 'posted' => array('ok', 'Posted'), 'void' => array('muted', 'Void'),
            'issued' => array('ok', 'Issued'), 'reissued' => array('muted', 'Reissued'), 'committed' => array('ok', 'Committed'),
            'staged' => array('info', 'Staged'), 'uploaded' => array('info', 'Uploaded'), 'mapped' => array('info', 'Mapped'),
            'rolled_back' => array('muted', 'Rolled back'), 'failed' => array('bad', 'Failed'), 'pending_verification' => array('wait', 'Pending verification'),
            'retired' => array('muted', 'Retired'), 'pending_review' => array('wait', 'Pending review'), 'active' => array('ok', 'Active'),
            'disabled' => array('muted', 'Disabled'), 'pass' => array('ok', 'Passed'), 'fail' => array('bad', 'Failed'), 'warn' => array('wait', 'Passed with warnings'),
            'submission_ready' => array('ok', 'Submission-ready'), 'unvalidated_draft' => array('wait', 'Unvalidated draft'), 'locked' => array('info', 'Locked'),
        );
        $m = isset($map[$status]) ? $map[$status] : array('muted', ucfirst(str_replace('_', ' ', (string)$status)));
        return '<span class="st st-' . $m[0] . '">' . e($m[1]) . '</span>';
    }

    public static function pager($total, $page, $per, $params)
    {
        $pages = max(1, (int)ceil($total / $per));
        if ($pages <= 1) {
            return '<p class="pager-info">' . plural($total, 'record') . '</p>';
        }
        $p = isset($_GET['p']) ? $_GET['p'] : 'dashboard';
        $h = '<nav class="pager" aria-label="Pages"><span class="pager-info">' . plural($total, 'record') . ', page ' . $page . ' of ' . $pages . '</span>';
        if ($page > 1) {
            $h .= '<a class="btn btn-quiet" href="' . e(url($p, array_merge($params, array('page' => $page - 1)))) . '">Previous</a>';
        }
        if ($page < $pages) {
            $h .= '<a class="btn btn-quiet" href="' . e(url($p, array_merge($params, array('page' => $page + 1)))) . '">Next</a>';
        }
        return $h . '</nav>';
    }

    public static function empty_state($title, $body, $actionHtml = '')
    {
        return '<div class="empty"><h2>' . e($title) . '</h2><p>' . e($body) . '</p>' . $actionHtml . '</div>';
    }
}
