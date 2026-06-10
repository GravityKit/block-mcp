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

# Foundation's post-install script rewrites implicitly nullable parameter
# signatures (`Type $x = null` → `?Type $x = null`) in vendor/ and
# vendor_prefixed/ — PHP 8.4 deprecates the implicit form at compile time,
# and the bundled illuminate/support helpers.php is files-autoloaded into
# every process, so the deprecation otherwise lands on stderr of every
# PHP process (including PHPUnit separate-process children, which treat
# any stderr as a test error). Its helpers.php-namespacing step is a no-op
# here: the sed above has already inserted the namespace. Must run before
# the prune below, which removes vendor/gravitykit from no-dev builds.
if [ -f "vendor/gravitykit/foundation/scripts/post-install.php" ]; then
  php vendor/gravitykit/foundation/scripts/post-install.php
fi

# Keep only the essential dependencies/folders in the vendor directory
if [[ -d "vendor" && "${COMPOSER_DEV_MODE}" -eq 0 ]]; then
  find ./vendor -mindepth 1 -maxdepth 1 -type d $(printf -- "-not -name %s " "${VENDOR_FOLDERS_TO_KEEP[@]}") -exec rm -rf '{}' \;
fi
