<?php
declare(strict_types=1);

require_can('settings');

if (is_post()) {
    $errors = [];
    $studio = post('studio_name');
    $currency = post('currency');
    $target = post('monthly_target');
    $prefix = post('receipt_prefix');
    if ($studio === '') $errors[] = 'Studio name is required.';
    if ($currency === '' || mb_strlen($currency) > 8) $errors[] = 'Currency label must be 1–8 characters (e.g. KSh).';
    if ($target === '' || num($target) < 0) $errors[] = 'Monthly target must be a number.';
    if (!preg_match('/^[A-Za-z0-9-]{0,10}$/', $prefix)) $errors[] = 'Receipt prefix can only contain letters, numbers and dashes (max 10).';
    if (post('email') !== '' && !filter_var(post('email'), FILTER_VALIDATE_EMAIL)) $errors[] = 'Studio email is not valid.';

    if ($errors) {
        foreach ($errors as $er) flash($er, 'err');
    } else {
        $map = [
            'studio_name' => mb_substr($studio, 0, 60), 'brand_tag' => mb_substr(post('brand_tag'), 0, 100), 'currency' => $currency,
            'monthly_target' => (string) num($target), 'receipt_prefix' => $prefix, 'phone' => mb_substr(post('phone'), 0, 40),
            'email' => mb_substr(post('email'), 0, 120), 'address' => mb_substr(post('address'), 0, 200), 'receipt_footer' => mb_substr(post('receipt_footer'), 0, 250),
        ];
        foreach ($map as $k => $v) set_setting($k, $v);
        log_activity('settings', 'Settings updated');
        flash('Settings saved.');
    }
    redirect('settings');
}

$s = fn(string $k, string $d = '') => setting($k, $d);

page_start('Settings', 'settings');
page_head('Settings', 'Studio details, targets and receipt wording');
?>
<form method="post" class="grid g2">
  <?= csrf_field() ?>
  <div class="card stack">
    <div class="card-h"><h2><?= icon('camera') ?> Studio</h2></div>
    <label class="fld"><span>Studio name *</span><input name="studio_name" value="<?= e($s('studio_name', 'DotCliq Studio')) ?>" required maxlength="60"></label>
    <label class="fld"><span>Tagline</span><input name="brand_tag" value="<?= e($s('brand_tag')) ?>" maxlength="100"></label>
    <div class="grid2">
      <label class="fld"><span>Phone</span><input name="phone" value="<?= e($s('phone')) ?>" maxlength="40"></label>
      <label class="fld"><span>Email</span><input type="email" name="email" value="<?= e($s('email')) ?>" maxlength="120"></label>
    </div>
    <label class="fld"><span>Address</span><input name="address" value="<?= e($s('address')) ?>" maxlength="200"></label>
  </div>
  <div class="card stack">
    <div class="card-h"><h2><?= icon('target') ?> Targets & receipts</h2></div>
    <div class="grid2">
      <label class="fld"><span>Currency label *</span><input name="currency" value="<?= e($s('currency', 'KSh')) ?>" required maxlength="8"></label>
      <label class="fld"><span>Monthly sales target *</span><input name="monthly_target" inputmode="decimal" value="<?= e($s('monthly_target', '150000')) ?>" required></label>
      <label class="fld"><span>Receipt prefix</span><input name="receipt_prefix" value="<?= e($s('receipt_prefix', 'DCS-')) ?>" maxlength="10"></label>
    </div>
    <label class="fld"><span>Receipt footer message</span><textarea name="receipt_footer" maxlength="250"><?= e($s('receipt_footer')) ?></textarea></label>
    <p class="muted small">Receipt numbers look like <b><?= e(make_receipt_no(1)) ?></b>. Changing the prefix only affects new sales.</p>
    <div class="form-actions"><button class="btn gold" type="submit">Save settings</button></div>
  </div>
</form>
<?php page_end();
