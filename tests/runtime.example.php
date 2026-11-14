<?php
// Copy to ignored tests/runtime.php only on your own machine, or use the environment variables below.
// Use official, checksum-verified age and age-keygen executables. Never put site credentials here.
return [
    'age' => getenv('WRB_AGE_BIN') ?: '',
    'keygen' => getenv('WRB_AGE_KEYGEN_BIN') ?: '',
];
