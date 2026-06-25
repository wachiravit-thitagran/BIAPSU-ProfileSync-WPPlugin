<?php
$shortcode = "[biapsu_profilesync]";
$output = do_shortcode($shortcode);
if ($output === $shortcode) {
    echo "Failed to render $shortcode\n";
    exit(1);
}
echo "Shortcode rendering tests passed!\n";
