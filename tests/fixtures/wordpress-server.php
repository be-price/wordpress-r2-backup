<?php
declare(strict_types=1);
if (posix_setsid() === -1) { exit(1); }
pcntl_exec(PHP_BINARY, array_slice($argv, 1));
exit(1);
