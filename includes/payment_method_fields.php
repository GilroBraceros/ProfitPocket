<?php
// Shared by Add Sale and Record Payment; $method_values and $methods are scoped.
$selectedCategory=$method_values['payment_category']??'';
if ($selectedCategory==='') {
    $selectedCategory='cash';
    foreach ($methods as $m) { if ((string)$m['payment_method_id']===($method_values['payment_method_id']??'')) { $selectedCategory=payment_category($m['method_code']); } }
}
$selectedWallet=$method_values['wallet_method_id']??($method_values['payment_method_id']??'');
$walletOther=false;
foreach ($methods as $m) { if ((string)$m['payment_method_id']===$selectedWallet && $m['method_code']==='wallet_other') { $walletOther=true; } }
$showProvider=$selectedCategory==='bank' || ($selectedCategory==='wallet' && $walletOther);
?>
<div class="payment-method-fields row g-3" data-payment-method-fields>
    <input type="hidden" id="payment_method_id" name="payment_method_id" value="<?= e($method_values['payment_method_id']??'') ?>">
    <div class="col-sm-6"><label class="form-label" for="payment_category">Payment method</label><select class="form-select" id="payment_category" name="payment_category" required>
        <?php foreach (['cash'=>'Cash','wallet'=>'Digital Wallet','bank'=>'Bank Transfer'] as $key=>$label): $ids=array_values(array_filter($methods,fn($m)=>payment_category($m['method_code'])===$key)); ?>
        <option value="<?= e($key) ?>" data-method-id="<?= e($ids[0]['payment_method_id']??'') ?>" <?= $selectedCategory===$key?'selected':'' ?> <?= !$ids?'disabled':'' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
    </select></div>
    <div class="col-sm-6" data-wallet-field <?= $selectedCategory!=='wallet'?'hidden':'' ?>><label class="form-label" for="wallet_method_id">Wallet provider</label><select class="form-select" id="wallet_method_id" name="wallet_method_id" <?= $selectedCategory==='wallet'?'required':'disabled' ?>>
        <option value="">Choose a wallet</option><?php foreach ($methods as $m): if (payment_category($m['method_code'])!=='wallet') { continue; } ?>
        <option value="<?= e($m['payment_method_id']) ?>" data-code="<?= e($m['method_code']) ?>" <?= $selectedWallet===(string)$m['payment_method_id']?'selected':'' ?>><?= e($m['method_code']==='wallet_other'?'Other':$m['method_name']) ?></option><?php endforeach; ?>
    </select></div>
    <div class="col-sm-6" data-provider-field <?= !$showProvider?'hidden':'' ?>><label class="form-label" for="provider_name" data-provider-label><?= $selectedCategory==='bank'?'Bank name':'Other wallet name' ?></label><input class="form-control" id="provider_name" name="provider_name" maxlength="80" value="<?= e($method_values['provider_name']??'') ?>" <?= $showProvider?'required':'disabled' ?> autocomplete="off"></div>
</div>
