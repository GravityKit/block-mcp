#!/bin/bash

FOUNDATION_NAMESPACE="GravityKit\BlockMCP"
# woocommerce = Action Scheduler: a non-prefixed runtime dependency Foundation
# requires by its real path (vendor/woocommerce/action-scheduler/action-scheduler.php),
# so it must survive the prune and ship.
VENDOR_FOLDERS_TO_KEEP=("composer" "woocommerce")

# Namespace Laravel's helper functions in Foundation as they are otherwise globally declared and can cause conflicts
if [ -f "vendor_prefixed/illuminate/support/helpers.php" ]; then
  insertion="${FOUNDATION_NAMESPACE}\Foundation\ThirdParty\Illuminate\Support"
  insertion="namespace ${insertion//\\/\\\\\\};" # Escape backslashes for sed

  if [[ "$(uname)" = "Darwin" ]]; then
    in_place_edit=(-i '')
    sed "${in_place_edit[@]}" -e "1a\\
${insertion}" vendor_prefixed/illuminate/support/helpers.php
  else
    in_place_edit=(-i)
    sed "${in_place_edit[@]}" -e "1a ${insertion}" vendor_prefixed/illuminate/support/helpers.php
  fi
fi

# Strauss occasionally leaves a global `\GravityKit\Foundation\…` reference
# un-prefixed (a php-parser quirk around the adjacent `(bool)` cast in
# preflight_check.php), which fatals as "class GravityKit\Foundation\Helpers\Core
# not found" on activation. Re-prefix any survivors to the plugin namespace.
# Idempotent: the search term cannot match an already-prefixed reference.
if [ -d "vendor_prefixed/gravitykit/foundation" ]; then
  GK_NS="$FOUNDATION_NAMESPACE" php -r '
    $ns  = getenv("GK_NS");
    $dir = "vendor_prefixed/gravitykit/foundation";
    $it  = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
      if (strtolower($f->getExtension()) !== "php") { continue; }
      $c = file_get_contents($f);
      $n = str_replace("\\GravityKit\\Foundation\\", "\\" . $ns . "\\Foundation\\", $c);
      if ($n !== $c) { file_put_contents($f, $n); }
    }
  '
fi

# Keep only the essential dependencies/folders in the vendor directory
if [[ -d "vendor" && "${COMPOSER_DEV_MODE}" -eq 0 ]]; then
  find ./vendor -mindepth 1 -maxdepth 1 -type d $(printf -- "-not -name %s " "${VENDOR_FOLDERS_TO_KEEP[@]}") -exec rm -rf '{}' \;
fi
