<?php
if (PHP_SAPI !== 'cli') exit('CLI only');
$pw=$argv[1]??''; if($pw==='') exit("Usage: php make-password-hash.php 'password'\n");
echo password_hash($pw,PASSWORD_DEFAULT),PHP_EOL;
