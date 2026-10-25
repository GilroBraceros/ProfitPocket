<?php
declare(strict_types=1);

function profitpocket_base_path(array $server,?string $override=null): string
{
    if ($override!==null) { $base=rtrim($override,'/'); }
    else {
        $script=(string)($server['SCRIPT_NAME']??'');
        if (preg_match('~\A(.*)/(?:auth|admin|user)/[^/]+\.php\z~',$script,$match)) { $base=$match[1]; }
        elseif (preg_match('~\A(.*)/index\.php\z~',$script,$match)) { $base=$match[1]; }
        else { $base='/profitpocket'; } // CLI compatibility, not a hosted URL guess.
    }
    if (!preg_match('~\A(?:/[A-Za-z0-9_-]+)*\z~',$base)) { throw new RuntimeException('Invalid application base path.'); }
    return $base;
}
