<div class="<?= Auth::check() ? '' : 'auth' ?>">
<div class="sheet"><div class="empty">
  <h2>Page not found</h2>
  <p>The address may be mistyped, or the page was moved.</p>
  <a class="btn" href="<?= e(url('dashboard')) ?>">Back to dashboard</a>
</div></div>
</div>
