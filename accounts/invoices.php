<?php
/**
 * Invoices was a duplicate list of fee receipts. Send users to Daily Collection.
 */
declare(strict_types=1);

header('Location: daily_collection.php', true, 301);
exit;
