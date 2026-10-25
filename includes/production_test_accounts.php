<?php
declare(strict_types=1);
// Temporary provisioning identities. Remove after production provisioning.
// Passwords are handed to the operator separately; only password_hash() results
// are deployed. These reserved test addresses are login IDs, not real inboxes.
if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) { http_response_code(403); exit; }
return [
    ['email'=>'pp-admin1-3692b0e9@example.invalid', 'name'=>'ProfitPocket Test Administrator 1', 'password_hash'=>'$2y$10$.VU.2rleKpylwLW9vBL3tuHdqUFbxHSJTJJcYSELVB6IiqzWeohg.'],
    ['email'=>'pp-admin2-3692b0e9@example.invalid', 'name'=>'ProfitPocket Test Administrator 2', 'password_hash'=>'$2y$10$JgpZC3CtBsJnxK.DFm4Gwu4YSPah3/TO3UIOUysowC2EVhW33UlBS'],
];
