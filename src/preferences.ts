/**
 * Client-side Preference Enrichment
 *
 * Functions that add natural-language annotations and AI-friendly context
 * to raw API responses. The WordPress plugin (gk-block-api) is the single
 * source of truth for which namespaces are legacy, avoid, etc. — driven by
 * admin-editable preferences (`wp_options.gk_block_api_preferences`) and
 * extensible via the WordPress filter system.
 *
 * This module deliberately holds NO hardcoded namespace lists or replacement
 * maps. It reads `block.preference.tier` and `block.preference.suggested_replacement`
 * fields the server attaches to non-preferred blocks. Sites that want
 * different policies just edit their Preferences config — no code change.
 */

import type {
  BlockType,
  Pattern,
  Block,
  PreferenceWarning,
} from './types.js';

// ============================================
// Helpers
// ============================================

/**
 * Extract the namespace from a fully-qualified block name.
 *
 * @param blockName - e.g. "filter/testimonial-wall"
 * @returns Namespace string, e.g. "filter"
 */
function getNamespace(blockName: string): string {
  return blockName.split('/')[0] ?? blockName;
}

/**
 * Extract the short name (after the slash) from a fully-qualified block name.
 *
 * @param blockName - e.g. "filter/testimonial-wall"
 * @returns Short name string, e.g. "testimonial-wall", or the full name if no slash.
 */
function getShortName(blockName: string): string {
  const parts = blockName.split('/');
  return parts[1] ?? parts[0];
}

// ============================================
// Enrichment Functions
// ============================================

/**
 * Annotate a list of page blocks with preference warnings.
 *
 * Reads the `block.preference.tier` field the server attaches to non-preferred
 * blocks. No client-side namespace list — whatever the server flags as legacy
 * gets a warning here.
 *
 * @param blocks - Parsed blocks from a page
 * @returns Object with blocks, warnings array, and a summary string
 */
export function enrichBlockList(blocks: Block[]): {
  blocks: Block[];
  warnings: PreferenceWarning[];
  summary: string;
} {
  const warnings: PreferenceWarning[] = [];

  // Walk the full tree so legacy/avoid blocks nested inside core/group,
  // core/columns, etc. surface in the warning summary too. The previous
  // single-level loop missed them, letting deprecated namespaces hide
  // a level deep.
  walkBlocksForPreferences(blocks, warnings);

  const summary = warnings.length > 0
    ? `Found ${warnings.length} non-preferred block(s) on this page:\n${warnings.map((w) => `  - ${w.message}`).join('\n')}`
    : 'All blocks on this page use preferred or acceptable namespaces.';

  return { blocks, warnings, summary };
}

function walkBlocksForPreferences(blocks: Block[], warnings: PreferenceWarning[]): void {
  for (const block of blocks) {
    const tier = block.preference?.tier;
    if (tier === 'legacy' || tier === 'avoid') {
      const replacement = block.preference?.suggested_replacement;
      const verb = tier === 'legacy' ? 'LEGACY — do not use' : 'AVOID';
      warnings.push({
        block: block.name,
        message: replacement
          ? `Block ${block.index}: ${block.name} (${verb} — use ${replacement} instead)`
          : `Block ${block.index}: ${block.name} (${verb})`,
        suggested_replacement: replacement,
      });
    }
    if (block.innerBlocks?.length) {
      walkBlocksForPreferences(block.innerBlocks, warnings);
    }
  }
}

/**
 * Sort patterns by preference score and add a recommendation summary.
 *
 * Returns patterns ordered best-first with a natural-language summary
 * grouping them into recommended, acceptable, and avoid tiers.
 *
 * @param patterns - Patterns from the API
 * @returns Sorted patterns with a summary string
 */
export function enrichPatternList(patterns: Pattern[]): {
  patterns: Pattern[];
  summary: string;
} {
  // Sort descending by score for stable display ordering.
  const sorted = [...patterns].sort(
    (a, b) => b.preference.score - a.preference.score
  );

  // Classify by tier only — the server is the source of truth and already
  // applied policy. Mixing in score-based fallbacks (the old logic) caused
  // mis-bucketing: an `avoid`-tier pattern with score 5 leaked into the
  // recommended bucket; a `recommended`-tier pattern with negative score
  // was double-counted. Trust the tier.
  const recommended = sorted.filter((p) => p.preference.tier === 'recommended');
  const avoid       = sorted.filter((p) => p.preference.tier === 'avoid' || p.preference.tier === 'legacy');

  const lines: string[] = [];

  if (recommended.length > 0) {
    lines.push('RECOMMENDED patterns:');
    for (const p of recommended) {
      const blockInfo = p.contains_blocks.length > 0
        ? `, uses ${p.contains_blocks.slice(0, 3).map(getNamespace).filter((v, i, a) => a.indexOf(v) === i).join('/')} blocks`
        : '';
      lines.push(
        `  Recommended: "${p.name}" (score: ${p.preference.score}, ${p.type}${blockInfo})`
      );
    }
  }

  if (avoid.length > 0) {
    lines.push('AVOID patterns (contain non-preferred blocks):');
    for (const p of avoid) {
      const legacyInfo = p.legacy_blocks && p.legacy_blocks.length > 0
        ? `, contains ${p.legacy_blocks.slice(0, 3).join(', ')}`
        : '';
      lines.push(
        `  Avoid: "${p.name}" (score: ${p.preference.score}${legacyInfo})`
      );
    }
  }

  const summary = lines.length > 0
    ? lines.join('\n')
    : 'No patterns found matching the criteria.';

  return { patterns: sorted, summary };
}

/**
 * Group block types by preference tier with natural-language guidance.
 *
 * Tier classification comes from the server (which reads from the
 * Preferences config). No hardcoded namespace tables here.
 *
 * @param types - Block types from the API
 * @returns Grouped types with a guidance summary string
 */
export function enrichBlockTypes(types: BlockType[]): {
  block_types: BlockType[];
  guidance: string;
} {
  const preferred: BlockType[] = [];
  const acceptable: BlockType[] = [];
  const avoid: BlockType[] = [];
  const legacy: BlockType[] = [];

  for (const t of types) {
    switch (t.preference.tier) {
      case 'preferred':
        preferred.push(t);
        break;
      case 'acceptable':
        acceptable.push(t);
        break;
      case 'avoid':
        avoid.push(t);
        break;
      case 'legacy':
        legacy.push(t);
        break;
      default:
        acceptable.push(t);
    }
  }

  const lines: string[] = [];

  if (preferred.length > 0) {
    const grouped = groupByNamespace(preferred);
    for (const [ns, blocks] of Object.entries(grouped)) {
      const names = blocks.map((t) => getShortName(t.name)).join(', ');
      lines.push(`PREFERRED (${ns}/): ${names}`);
    }
  }
  if (acceptable.length > 0) {
    const grouped = groupByNamespace(acceptable);
    for (const [ns, blocks] of Object.entries(grouped)) {
      const names = blocks.map((t) => getShortName(t.name)).join(', ');
      lines.push(`ACCEPTABLE (${ns}/): ${names}`);
    }
  }
  if (avoid.length > 0) {
    const grouped = groupByNamespace(avoid);
    for (const [ns, blocks] of Object.entries(grouped)) {
      const mappings = blocks.map((t) => {
        const replacement = t.preference.replacement;
        const shortName = getShortName(t.name);
        return replacement ? `${shortName} -> use ${replacement}` : shortName;
      });
      lines.push(`AVOID (${ns}/): ${mappings.join(', ')}`);
    }
  }
  if (legacy.length > 0) {
    const grouped = groupByNamespace(legacy);
    for (const [ns, blocks] of Object.entries(grouped)) {
      const mappings = blocks.map((t) => {
        const replacement = t.preference.replacement;
        const shortName = getShortName(t.name);
        return replacement ? `${shortName} -> use ${replacement}` : shortName;
      });
      lines.push(`LEGACY — DO NOT USE (${ns}/): ${mappings.join(', ')}`);
    }
  }

  const guidance = lines.join('\n');

  return { block_types: types, guidance };
}

/**
 * Format a preference warning into a single human-readable line.
 *
 * @param warning - The preference warning to format
 * @returns Formatted warning string
 */
export function formatPreferenceWarning(warning: PreferenceWarning): string {
  if (warning.suggested_replacement) {
    return `WARNING: ${warning.block} is non-preferred. Use ${warning.suggested_replacement} instead.`;
  }
  return `WARNING: ${warning.message}`;
}

// ============================================
// Helpers
// ============================================

/**
 * Group block types by their namespace.
 *
 * @param types - Array of block types
 * @returns Object keyed by namespace
 */
function groupByNamespace(types: BlockType[]): Record<string, BlockType[]> {
  const groups: Record<string, BlockType[]> = {};
  for (const t of types) {
    const ns = getNamespace(t.name);
    if (!groups[ns]) groups[ns] = [];
    groups[ns].push(t);
  }
  return groups;
}
