<?php
declare(strict_types=1);
namespace WordPressR2Backup;

/** Non-transactional tables prevent a consistent online database capture. */
final class UnsupportedOnlineDatabaseEngine extends \RuntimeException {}
