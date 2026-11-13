<?php
declare(strict_types=1);

require_can('sales');

$id = (int) get('id');
$sale = sale_totals($id);
if (!$sale) {
    http_response_code(404);
    exit('Sale not found.');
}
$client = row('SELECT * FROM clients WHERE id = ?', [$sale['client_id']]);
$items = rows('SELECT * FROM sale_items WHERE sale_id = ? ORDER BY id', [$id]);
$payments = rows('SELECT * FROM payments WHERE sale_id = ? ORDER BY paid_at, id', [$id]);
$balance = max(0, round((float) $sale['total'] - (float) $sale['paid'], 2));
$status = pay_status($sale);
$studio = setting('studio_name', 'DotCliq Studio');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Receipt <?= e($sale['receipt_no']) ?></title>
<style>
  *{box-sizing:border-box}body{margin:0;background:#eee;font:14px/1.5 system-ui,-apple-system,'Segoe UI',Roboto,sans-serif;color:#1c1c1c}
  .sheet{max-width:720px;margin:18px auto;background:#fff;padding:34px 38px;border-radius:6px;box-shadow:0 4px 24px rgba(0,0,0,.12);position:relative}
  .hd{display:flex;justify-content:space-between;gap:20px;border-bottom:3px solid #a31f37;padding-bottom:16px;margin-bottom:18px}
  h1{margin:0;font-size:22px;letter-spacing:.03em}h1 span{color:#c58a00}.tag{color:#777;font-size:12px}
  .rn{text-align:right}.rn b{font-size:18px;color:#a31f37}
  table{width:100%;border-collapse:collapse;margin:14px 0}th{background:#f6eef0;text-align:left;padding:8px 10px;font-size:11px;text-transform:uppercase;letter-spacing:.05em}td{padding:8px 10px;border-bottom:1px solid #eee}.r{text-align:right}
  .tot{margin-left:auto;width:290px}.tot div{display:flex;justify-content:space-between;padding:4px 0}.tot .g{font-size:17px;font-weight:700;border-top:2px solid #1c1c1c;margin-top:4px;padding-top:8px}
  .stamp{position:absolute;right:46px;top:150px;border:3px solid;padding:4px 16px;border-radius:8px;font-weight:800;letter-spacing:.12em;text-transform:uppercase;transform:rotate(-10deg);opacity:.8}
  .paid{color:#0f9d72}.partial{color:#c58a00}.unpaid{color:#d12c4a}.cancelled{color:#777}
  .meta{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:6px}.meta small{display:block;color:#777;text-transform:uppercase;font-size:10.5px;letter-spacing:.06em}
  .sig{display:flex;justify-content:space-between;gap:30px;margin-top:46px}.sig div{flex:1;border-top:1px solid #999;padding-top:4px;font-size:12px;color:#777}
  .foot{margin-top:22px;text-align:center;color:#777;font-size:12px}
  .bar{max-width:720px;margin:14px auto 0;display:flex;gap:10px;justify-content:flex-end}.bar button,.bar a{background:#a31f37;color:#fff;border:0;border-radius:8px;padding:9px 16px;font:600 13px system-ui;cursor:pointer;text-decoration:none}.bar a{background:#555}
  @media print{body{background:#fff}.sheet{box-shadow:none;margin:0;max-width:none;padding:10mm}.bar{display:none}}
  @media (max-width:600px){.sheet{padding:20px 16px;margin:8px}.hd{flex-direction:column}.rn{text-align:left}.meta{grid-template-columns:1fr}.tot{width:100%}.stamp{top:auto;bottom:10px;right:10px}}
</style>
</head>
<body>
<div class="bar"><a href="<?= e(url('sale_view', ['id' => $id])) ?>">← Back</a><button onclick="window.print()">Print / Save PDF</button></div>
<div class="sheet">
  <?php if ($status !== 'unpaid'): ?><div class="stamp <?= e($status) ?>"><?= $status === 'partial' ? 'Part paid' : e($status) ?></div><?php endif; ?>
  <div class="hd">
    <div>
      <h1><?php $parts = preg_split('/\s+/', mb_strtoupper($studio), 2); echo e($parts[0]); echo isset($parts[1]) ? ' <span>' . e($parts[1]) . '</span>' : ''; ?></h1>
      <div class="tag"><?= e(setting('brand_tag')) ?></div>
      <div class="tag"><?= e(setting('address')) ?><?= setting('phone') ? ' · ' . e(setting('phone')) : '' ?><?= setting('email') ? ' · ' . e(setting('email')) : '' ?></div>
    </div>
    <div class="rn"><div class="tag">RECEIPT</div><b><?= e($sale['receipt_no']) ?></b><div class="tag"><?= e(fmt_date($sale['sale_date'], 'd F Y')) ?></div></div>
  </div>
  <div class="meta">
    <div><small>Billed to</small><b><?= e($client['name']) ?></b><br><?= e($client['phone']) ?><?= $client['email'] ? '<br>' . e($client['email']) : '' ?></div>
    <div class="r"><small>Payment status</small><b class="<?= e($status) ?>"><?= e(ucfirst($status === 'partial' ? 'Part paid' : $status)) ?></b></div>
  </div>
  <table>
    <thead><tr><th>Description</th><th class="r">Qty</th><th class="r">Price</th><th class="r">Amount</th></tr></thead>
    <tbody><?php foreach ($items as $it): ?><tr><td><?= e($it['description']) ?></td><td class="r"><?= (int) $it['qty'] ?></td><td class="r"><?= money($it['unit_price'], false) ?></td><td class="r"><?= money($it['line_total'], false) ?></td></tr><?php endforeach; ?></tbody>
  </table>
  <div class="tot">
    <div><span>Subtotal</span><span><?= money($sale['subtotal']) ?></span></div>
    <?php if ((float) $sale['discount'] > 0): ?><div><span>Discount</span><span>− <?= money($sale['discount']) ?></span></div><?php endif; ?>
    <div class="g"><span>Total</span><span><?= money($sale['total']) ?></span></div>
    <div><span>Paid</span><span><?= money($sale['paid']) ?></span></div>
    <div><b>Balance due</b><b><?= money($balance) ?></b></div>
  </div>
  <?php if ($payments): ?>
  <table>
    <thead><tr><th>Payment date</th><th>Method</th><th>Reference</th><th class="r">Amount</th></tr></thead>
    <tbody><?php foreach ($payments as $p): ?><tr><td><?= e(fmt_date(substr((string) $p['paid_at'], 0, 10))) ?></td><td><?= e(PAY_METHODS[$p['method']] ?? $p['method']) ?></td><td><?= e($p['reference'] ?: '—') ?></td><td class="r"><?= money($p['amount']) ?></td></tr><?php endforeach; ?></tbody>
  </table>
  <?php endif; ?>
  <div class="sig"><div>Client signature</div><div>Authorised by</div></div>
  <div class="foot"><?= e(setting('receipt_footer')) ?><br>Printed <?= date('d M Y, g:i A') ?> by <?= e(user()['name']) ?></div>
</div>
</body>
</html>
