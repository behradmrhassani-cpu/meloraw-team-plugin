<?php
/**
 * Writes docs/import-template/*.json from MLT_Schema (the only source of the templates):
 *   wp eval-file tests/gen-templates.php
 * tests/ui-check.sh (section import) fails when the committed files differ from what the plugin serves.
 */
if (!defined('ABSPATH')) {
    exit(1);
}
$dir = dirname(__DIR__) . '/docs/import-template/';
foreach (MLT_Schema::TYPES as $type) {
    file_put_contents($dir . MLT_Schema::template_filename($type, 'sample'), MLT_Schema::template_json($type, 'sample'));
    echo "wrote " . MLT_Schema::template_filename($type, 'sample') . "\n";
}
