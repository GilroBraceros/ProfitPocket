<?php
// Real HTTP mutations use only the runner's disposable nonfinancial catalog.
if (PHP_SAPI!=='cli') { http_response_code(403); exit; }
function product_http_fields(array $reply): array {
    $fields=[]; foreach (['csrf_token','request_key','request_signature','product_id'] as $name) { $fields[$name]=named_field($reply,$name); } return $fields;
}
function product_http_json(array $reply,int $status=200): array {
    expect_http($reply['status']===$status,'Unexpected product status: '.$reply['status']); return json_decode($reply['body'],true,512,JSON_THROW_ON_ERROR);
}
function product_http_option(array $reply,int $id): ?array {
    expect_http($reply['status']===200,'Sales form unavailable');
    if (!preg_match('/<option value="'.$id.'" data-price="([^"]+)" data-cost="([^"]+)"/',$reply['body'],$matches)) { return null; } return [$matches[1],$matches[2]];
}
expect_http(request_http('user/products.php')['status']===200,'Products unavailable');
expect_http(request_http('user/products.php?state=bad')['status']===400,'Invalid product filters accepted');
$productForm=request_http('user/product_action.php?action=create&modal=1');
$productPayload=product_http_fields($productForm)+['product_name'=>'<HTTP product>','sku'=>'HTTP-TEST','description'=>'<script>escaped product description</script>','default_unit_cost'=>'80.01','default_selling_price'=>'100.10'];
foreach ([['product_name'=>''],['default_unit_cost'=>'-1'],['default_selling_price'=>'1.001']] as $change) {
    $response=product_http_json(request_http('user/product_action.php?action=create&modal=1',array_replace($productPayload,$change)),400);
    expect_http(isset($response['fields'][array_key_first($change)]),'Invalid product field accepted');
}
product_http_json(request_http('user/product_action.php?action=create&modal=1',array_replace($productPayload,['csrf_token'=>'forged'])),403);
product_http_json(request_http('user/product_action.php?action=create&modal=1',array_replace($productPayload,['request_signature'=>'forged'])),403);
$savedProduct=product_http_json(request_http('user/product_action.php?action=create&modal=1',$productPayload)); $managedProduct=$savedProduct['id'];
expect_http(product_http_json(request_http('user/product_action.php?action=create&modal=1',$productPayload))['id']===$managedProduct,'Product create replay duplicated');
product_http_json(request_http('user/product_action.php?action=create&modal=1',array_replace($productPayload,['product_name'=>'Changed replay'])),400);
expect_http(product_http_option(request_http('user/sale_form.php?modal=1'),$managedProduct)===['100.10','80.01'],'New product defaults absent from Sales');
$details=request_http('user/product_action.php?action=details&id='.$managedProduct.'&modal=1');
expect_http($details['status']===200 && str_contains($details['body'],'&lt;HTTP product&gt;') && str_contains($details['body'],'&lt;script&gt;escaped') && !str_contains($details['body'],'<script>escaped'),'Product details not escaped');
expect_http(request_http('user/product_action.php?action=details&modal=1',array_replace($productPayload,['product_id'=>(string)$managedProduct]))['status']===405,'Product details allowed mutation');
$editForm=request_http('user/product_action.php?action=edit&id='.$managedProduct.'&modal=1');
$editProduct=product_http_fields($editForm)+['revision'=>named_field($editForm,'revision'),'product_name'=>'HTTP product edited','sku'=>'HTTP-UPDATED','description'=>'Updated product','default_unit_cost'=>'80.01','default_selling_price'=>'75.00'];
$staleProductForm=request_http('user/product_action.php?action=edit&id='.$managedProduct.'&modal=1');
$staleProductPayload=array_replace($editProduct,product_http_fields($staleProductForm),['revision'=>named_field($staleProductForm,'revision')]);
$edited=product_http_json(request_http('user/product_action.php?action=edit&modal=1',$editProduct)); expect_http(str_contains($edited['message'],'below cost'),'Below-cost warning absent');
expect_http(product_http_option(request_http('user/sale_form.php?modal=1'),$managedProduct)===['75.00','80.01'],'Edited Sales defaults stale');
product_http_json(request_http('user/product_action.php?action=edit&modal=1',$editProduct));
product_http_json(request_http('user/product_action.php?action=edit&modal=1',$staleProductPayload),400);
$staleEdit=$editProduct; $staleEdit['request_key']=bin2hex(random_bytes(32)); // A forged signature must not bypass the endpoint.
product_http_json(request_http('user/product_action.php?action=edit&modal=1',$staleEdit),403);
$duplicateForm=request_http('user/product_action.php?action=create&modal=1');
$duplicate=product_http_fields($duplicateForm)+array_intersect_key($editProduct,array_flip(['product_name','sku','description','default_unit_cost','default_selling_price']));
expect_http(isset(product_http_json(request_http('user/product_action.php?action=create&modal=1',$duplicate),400)['fields']['sku']),'Duplicate SKU accepted');
$archiveForm=request_http('user/product_action.php?action=archive&id='.$managedProduct.'&modal=1');
$archiveProduct=product_http_fields($archiveForm)+['revision'=>named_field($archiveForm,'revision'),'confirm_product_action'=>'1'];
product_http_json(request_http('user/product_action.php?action=archive&modal=1',array_replace($archiveProduct,['confirm_product_action'=>''])),400);
product_http_json(request_http('user/product_action.php?action=archive&modal=1',$archiveProduct)); product_http_json(request_http('user/product_action.php?action=archive&modal=1',$archiveProduct));
expect_http(product_http_option(request_http('user/sale_form.php?modal=1'),$managedProduct)===null,'Archived product selectable in new sale');
expect_http(str_contains(request_http('user/products.php?state=archived&q=HTTP&sort=price_high')['body'],'HTTP product edited'),'Archived filter/search failed');
$restoreForm=request_http('user/product_action.php?action=restore&id='.$managedProduct.'&modal=1');
$restoreProduct=product_http_fields($restoreForm)+['revision'=>named_field($restoreForm,'revision'),'confirm_product_action'=>'1'];
product_http_json(request_http('user/product_action.php?action=restore&modal=1',$restoreProduct)); product_http_json(request_http('user/product_action.php?action=restore&modal=1',$restoreProduct));
expect_http(product_http_option(request_http('user/sale_form.php?modal=1'),$managedProduct)===['75.00','80.01'],'Restored product not selectable');
$foreignProduct=(int)$connection->query('SELECT product_id FROM products WHERE business_id<>'.(int)$owner['business_id'].' LIMIT 1')->fetchColumn();
foreach (['details','edit','archive','restore'] as $action) { expect_http(request_http('user/product_action.php?action='.$action.'&id='.$foreignProduct.'&modal=1')['status']===404,'Foreign product form exposed'); }
expect_http(str_contains(request_http('user/products.php?q=absentproduct')['body'],'No products match these filters'),'Empty search state missing');
$checks[]='PASS: real HTTP product create/edit/archive/restore/replay, field/CSRF/signature/SKU validation, escaped details, active Sales defaults, search/status/empty states and business isolation';
