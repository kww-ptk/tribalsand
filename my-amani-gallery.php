<?php
// Legacy per-property gallery → consolidated, DB-driven gallery.php.
// The full gallery is now editable in Admin → Venues → Gallery. 301 keeps old links + SEO alive.
header('Location: /gallery.php?venue=my-amani', true, 301);
exit;
