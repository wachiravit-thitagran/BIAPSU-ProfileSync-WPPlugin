<?php
$option_key = \BIAPSU\ProfileSync\Settings::OPTION;
$test_data = array("api_url" => "https://example.com/api");
update_option($option_key, $test_data);
$saved = get_option($option_key);
if (empty($saved["api_url"]) || $saved["api_url"] !== "https://example.com/api") {
    echo "Failed to save settings.\n";
    exit(1);
}
echo "Settings API test passed!\n";
