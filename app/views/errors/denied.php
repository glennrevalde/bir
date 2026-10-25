<div class="<?= Auth::check() ? '' : 'auth' ?>">
<div class="sheet"><div class="empty">
  <h2>Access denied</h2>
  <p><?= e($message) ?></p>
  <a class="btn" href="<?= e(url('dashboard')) ?>">Back to dashboard</a>
</div></div>
</div>
