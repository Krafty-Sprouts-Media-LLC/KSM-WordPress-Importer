<?php
/**
 * Temporary script to find where seems_utf8() is being called
 * Run this via WP-CLI: wp eval-file find-deprecated-usage.php
 */

// Search for seems_utf8 usage in active theme
$theme_dir = get_template_directory();
$theme_files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($theme_dir),
    RecursiveIteratorIterator::SELF_FIRST
);

echo "=== Checking Active Theme: " . get_template() . " ===\n";
foreach ($theme_files as $file) {
    if ($file->isFile() && preg_match('/\.php$/', $file->getFilename())) {
        $content = file_get_contents($file->getPathname());
        if (strpos($content, 'seems_utf8') !== false) {
            echo "Found in: " . str_replace($theme_dir, '', $file->getPathname()) . "\n";
        }
    }
}

// Check child theme if exists
if (get_stylesheet() !== get_template()) {
    $child_dir = get_stylesheet_directory();
    echo "\n=== Checking Child Theme: " . get_stylesheet() . " ===\n";
    if (is_dir($child_dir)) {
        $child_files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($child_dir),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($child_files as $file) {
            if ($file->isFile() && preg_match('/\.php$/', $file->getFilename())) {
                $content = file_get_contents($file->getPathname());
                if (strpos($content, 'seems_utf8') !== false) {
                    echo "Found in: " . str_replace($child_dir, '', $file->getPathname()) . "\n";
                }
            }
        }
    }
}

// Check mu-plugins
$mu_plugins_dir = WPMU_PLUGIN_DIR;
if (is_dir($mu_plugins_dir)) {
    echo "\n=== Checking Must-Use Plugins ===\n";
    $mu_files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($mu_plugins_dir),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($mu_files as $file) {
        if ($file->isFile() && preg_match('/\.php$/', $file->getFilename())) {
            $content = file_get_contents($file->getPathname());
            if (strpos($content, 'seems_utf8') !== false) {
                echo "Found in: " . str_replace($mu_plugins_dir, '', $file->getPathname()) . "\n";
            }
        }
    }
}

echo "\n=== Done ===\n";

