#!/bin/bash

FOUNDATION_NAMESPACE="GravityKit\BlockMCP"
VENDOR_FOLDERS_TO_KEEP=("composer")

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

# Keep only the essential dependencies/folders in the vendor directory
if [[ -d "vendor" && "${COMPOSER_DEV_MODE}" -eq 0 ]]; then
  find ./vendor -mindepth 1 -maxdepth 1 -type d $(printf -- "-not -name %s " "${VENDOR_FOLDERS_TO_KEEP[@]}") -exec rm -rf '{}' \;
fi
