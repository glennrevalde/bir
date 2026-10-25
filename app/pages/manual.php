<?php
/**
 * User's Manual: table of contents, search, one section at a time, and a printable full edition.
 */
$q = trim((string)input('q', ''));
$chapters = Help::chapters();
$key = Help::resolve((string)input('s', 'intro')) ?: 'intro';

// ------------------------------------------------------------------ full edition for printing or saving as PDF
if (input('print') === '1') {
    View::header("User's Manual", array('help' => ''));
    ?>
<div class="manual-print">
  <div class="mp-cover">
    <img src="<?= e(asset('img/mark.svg')) ?>" alt="" width="64" height="64">
    <h1>G-SMaRT BIR Integration System</h1>
    <p class="mp-sub">User's Manual</p>
    <p class="small muted">Version <?= e(GSB_VERSION) ?>. Printed <?= e(date('F j, Y')) ?>.</p>
    <p class="no-print"><button type="button" class="btn btn-primary" onclick="window.print()"><?= View::icon('download') ?>Print or save as PDF</button>
      <a class="btn btn-quiet" href="<?= e(url('manual')) ?>">Back to the manual</a></p>
  </div>
  <nav class="mp-toc"><h2>Contents</h2>
    <?php foreach ($chapters as $c) { ?><div class="mp-toc-ch"><strong><?= $c['num'] ?>. <?= e($c['title']) ?></strong>
      <ol><?php foreach ($c['sections'] as $k => $s) { ?><li><a href="#m-<?= e($k) ?>"><span><?= e($s['num']) ?></span><?= e($s['title']) ?></a></li><?php } ?></ol></div><?php } ?>
  </nav>
  <?php foreach ($chapters as $c) { ?>
    <section class="mp-chapter"><h2 class="mp-ch-title"><span><?= $c['num'] ?></span><?= e($c['title']) ?></h2>
    <?php foreach ($c['sections'] as $k => $s) { ?>
      <article class="mp-section prose" id="m-<?= e($k) ?>"><h3 class="mp-sec-title"><span><?= e($s['num']) ?></span><?= e($s['title']) ?></h3>
        <?= Help::render($s['html']) ?></article>
    <?php } ?></section>
  <?php } ?>
</div>
<?php
    View::footer();
    return;
}

$section = Help::article($key);
list($prev, $next) = Help::neighbours($key);
$results = $q !== '' ? Help::search($q) : null;
$canOpen = $section['page'] && $section['page'] !== 'manual' && View::canSee($section['page']);

View::header("User's Manual", array('help' => ''));
View::pageBar("User's Manual", 'How to use G-SMaRT BIR, chapter by chapter',
    '<a class="btn" href="' . e(url('manual', array('print' => 1))) . '" target="_blank" rel="noopener">' . View::icon('download') . 'Print or save as PDF</a>', false);
?>
<div class="manual">
  <aside class="manual-side sheet">
    <form class="manual-search" method="get" role="search">
      <input type="hidden" name="p" value="manual"><input type="hidden" name="s" value="<?= e($key) ?>">
      <label for="mq" class="sr-only">Search the manual</label>
      <span class="manual-search-icon"><?= View::icon('search') ?></span>
      <input id="mq" name="q" type="search" value="<?= e($q) ?>" placeholder="Search the manual" autocomplete="off">
    </form>
    <details class="manual-toc" <?= $results === null ? 'open' : '' ?> data-toc>
      <summary><span>Contents</span><span class="small muted"><?= e($section['num']) ?> <?= e($section['title']) ?></span></summary>
      <nav aria-label="Manual contents">
        <?php foreach ($chapters as $c) { $here = isset($c['sections'][$key]); ?>
          <div class="toc-ch<?= $here ? ' is-here' : '' ?>">
            <p class="toc-ch-title"><span class="toc-no"><?= $c['num'] ?></span><?= e($c['title']) ?></p>
            <ol><?php foreach ($c['sections'] as $k => $s) { ?>
              <li><a href="<?= e(url('manual', array('s' => $k))) ?>"<?= $k === $key ? ' aria-current="page"' : '' ?>><span class="toc-no"><?= e($s['num']) ?></span><?= e($s['title']) ?></a></li>
            <?php } ?></ol>
          </div>
        <?php } ?>
      </nav>
    </details>
  </aside>

  <div class="manual-main">
    <?php if ($results !== null) { ?>
      <div class="sheet manual-results">
        <div class="sheet-head"><div><h2><?= plural(count($results), 'section') ?> for &ldquo;<?= e($q) ?>&rdquo;</h2>
          <p><a href="<?= e(url('manual', array('s' => $key))) ?>">Clear the search</a></p></div></div>
        <?php if (!$results) { ?>
          <?= View::empty_state('Nothing found', 'Try a shorter word, a form number such as 2307, or a page name such as Generate.') ?>
        <?php } else { ?><ol class="result-list">
          <?php foreach ($results as $r) { $s = $r['section']; ?>
            <li><a href="<?= e(url('manual', array('s' => $s['key'], 'q' => $q))) ?>"><span class="toc-no"><?= e($s['num']) ?></span><strong><?= e($s['title']) ?></strong></a>
              <span class="small muted"><?= e($s['chapter_title']) ?></span>
              <p><?= Help::highlight($r['snippet'], $q) ?></p></li>
          <?php } ?></ol><?php } ?>
      </div>
    <?php } ?>

    <article class="sheet manual-article" id="article">
      <header class="manual-head">
        <p class="manual-crumb">Chapter <?= (int)$section['chapter'] ?>, <?= e($section['chapter_title']) ?></p>
        <h2><span class="manual-num"><?= e($section['num']) ?></span><?= e($section['title']) ?></h2>
        <p class="manual-summary"><?= e($section['summary']) ?></p>
        <?php if ($canOpen) { ?><a class="btn btn-sm" href="<?= e(url($section['page'])) ?>">Go to this page</a><?php } ?>
      </header>
      <div class="manual-body prose"><?= $section['html'] ?></div>
      <nav class="manual-pager" aria-label="Previous and next section">
        <?php if ($prev) { ?><a class="pager-prev" href="<?= e(url('manual', array('s' => $prev['key']))) ?>"><span class="small muted">Previous</span><strong><?= e($prev['num']) ?> <?= e($prev['title']) ?></strong></a><?php } else { ?><span></span><?php } ?>
        <?php if ($next) { ?><a class="pager-next" href="<?= e(url('manual', array('s' => $next['key']))) ?>"><span class="small muted">Next</span><strong><?= e($next['num']) ?> <?= e($next['title']) ?></strong></a><?php } ?>
      </nav>
    </article>
  </div>
</div>
<?php View::footer();
