<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(403); exit; }
// Run locally after tests, then deploy this manifest with the reviewed release.
$root=realpath(__DIR__.'/..');
$paths=['index.php','.htaccess','config/database.php','config/database_bootstrap.php','config/paths.php','config/email.php','config/upgrade.php','config/.htaccess','database/migrations/20261025_subscriptions.sql','database/migrations/20261025_registration_payments.sql'];
foreach (['auth','admin','user','includes','emails','emails/templates','bin'] as $dir) {
    foreach (glob($root.'/'.$dir.'/*.php') as $file) { $paths[]=str_replace('\\','/',substr($file,strlen($root)+1)); }
    if (is_file($root.'/'.$dir.'/.htaccess')) { $paths[]=$dir.'/.htaccess'; }
}
foreach (['assets/css/*.css','assets/js/*.js'] as $pattern) { foreach (glob($root.'/'.$pattern) as $file) { $paths[]=str_replace('\\','/',substr($file,strlen($root)+1)); } }
$paths=array_unique($paths); sort($paths); $files=[];
foreach ($paths as $path) { $files[$path]=hash('sha256',str_replace("\r\n","\n",file_get_contents($root.'/'.$path))); }
file_put_contents(__DIR__.'/upgrade-release.json',json_encode(['version'=>'20261025_subscriptions','hash_normalization'=>'lf','files'=>$files],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL);
echo 'Release manifest contains '.count($files)." runtime file hashes. No private configuration included.\n";
