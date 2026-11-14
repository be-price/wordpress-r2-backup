<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';
$input = json_decode(stream_get_contents(STDIN), true, 16, JSON_THROW_ON_ERROR);
$rebuilder = new WordPressR2Backup\SourceRebuilder($input['admin'], $input['reader'], new WordPressR2Backup\AgeProcess($input['age']));
$identity = fopen($input['identity'], 'rb');
try { $rebuilder->switch(1, $identity, $input['recipients'], $input['index'], $input['profile']); }
finally { fclose($identity); }
