<?php

declare(strict_types=1);

// Old address. 307 keeps the method, so a POST still logs out at /logout/
// and a GET lands on its confirmation.
header("Location: /logout/", true, 307);
exit;
