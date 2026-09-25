<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { exit(1); }
fwrite(STDOUT, bin2hex(random_bytes(32)) . PHP_EOL);
