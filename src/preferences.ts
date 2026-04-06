/**
 * Client-side Preference Enrichment
 *
 * Functions that add natural-language annotations and AI-friendly context
 * to raw API responses. The WordPress plugin returns numeric scores and
 * tier labels; this module translates them into actionable guidance for
 * AI agents consuming MCP tool results.
 */

import type {
  BlockType,
  Pattern,
  Block,
  PreferenceWarning,
} from './types.js';

// ============================================
// Known replacement map (mirrors the WordPress plugin config)
// ============================================

/** Map of legacy block names to their preferred replacements. */
const REPLACEMENT_MAP: Record<string, string> = {
  'stackable/heading': 'core/heading',
  'stackable/text': 'core/paragraph',
  'stackable/button': 'core/button',
  'stackable/button-group': 'core/buttons',
  'stackable/columns': 'core/columns',
  'stackable/column': 'core/column',
  'stackable/image': 'core/image',
  'stackable/spacer': 'core/spacer',
  'stackable/divider': 'core/separator',
  'stackable/testimonial': 'filter/testimonial-wall',
  'stackable/accordion': 'filter/accordion',
  'stackable/icon': 'outermost/icon-block',
  'stackable/icon-label': 'outermost/icon-block',
  'stackable/card': 'core/group',
  'stackable/subtitle': 'core/paragraph',
  'ugb/columns': 'core/columns',
  'ugb/column': 'core/column',
  'ugb/button': 'core/button',
  'ugb/text': 'core/paragraph',
  'ugb/pricing-box': 'core/group',
};

/** Namespaces considered legacy and to be avoided. */
const LEGACY_NAMESPACES = new Set(['stackable', 'ugb', 'jetpack']);

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

/**
 * Check whether a block name belongs to a legacy namespace.
 *
 * @param blockName - Fully-qualified block name
 * @returns True if the block should be avoided
 */
function isLegacyBlock(blockName: string): boolean {
  return LEGACY_NAMESPACES.has(getNamespace(blockName));
}

// ============================================
// Enrichment Functions
// ============================================

/**
 * Annotate a list of page blocks with preference warnings.
 *
 * When blocks from legacy namespaces (stackable/, ugb/, jetpack/) are
 * present, this adds human-readable warning lines to help AI agents
 * understand which blocks need attention.
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

  for (const block of blocks) {
    if (isLegacyBlock(block.name)) {
      const replacement = REPLACEMENT_MAP[block.name];
      warnings.push({
        block: block.name,
        message: replacement
          ? `Block ${block.index}: ${block.name} (AVOID — use ${replacement} instead)`
          : `Block ${block.index}: ${block.name} (AVOID — ${getNamespace(block.name)}/ blocks are legacy on this site)`,
        suggested_replacement: replacement,
      });
    }
  }

  const summary = warnings.length > 0
    ? `Found ${warnings.length} legacy block(s) on this page that should not be used for new content:\n${warnings.map((w) => `  - ${w.message}`).join('\n')}`
    : 'All blocks on this page use preferred or standard namespaces.';

  return { blocks, warnings, summary };
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
  // Sort descending by score
  const sorted = [...patterns].sort(
    (a, b) => b.preference.score - a.preference.score
  );

  const recommended = sorted.filter(
    (p) => (p.preference.tier === 'recommended' || p.preference.score >= 50) &&
           p.preference.tier !== 'avoid' && p.preference.tier !== 'legacy'
  );
  const avoid = sorted.filter(
    (p) => p.preference.tier === 'avoid' || p.preference.tier === 'legacy' ||
           (p.preference.score < 0 && p.preference.tier !== 'recommended')
  );

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
    lines.push('AVOID patterns (contain legacy blocks):');
    for (const p of avoid) {
      const legacyInfo = p.legacy_blocks && p.legacy_blocks.length > 0
        ? `, contains ${p.legacy_blocks.slice(0, 3).join(', ')}`
        : '';
      lines.push(
        `  Avoid: "${p.name}" (score: ${p.preference.score}, LEGACY${legacyInfo})`
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
 * @param types - Block types from the API
 * @returns Grouped types with a guidance summary string
 */
export function enrichBlockTypes(types: BlockType[]): {
  block_types: BlockType[];
  guidance: string;
} {
  const preferred: BlockType[] = [];
  const standard: BlockType[] = [];
  const acceptable: BlockType[] = [];
  const avoid: BlockType[] = [];
  const legacy: BlockType[] = [];

  for (const t of types) {
    switch (t.preference.tier) {
      case 'preferred':
        preferred.push(t);
        break;
      case 'acceptable':
        // Split core/ into "standard" for clearer guidance
        if (getNamespace(t.name) === 'core') {
          standard.push(t);
        } else {
          acceptable.push(t);
        }
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
    const names = preferred.map((t) => getShortName(t.name)).join(', ');
    lines.push(`PREFERRED (filter/): ${names}`);
  }
  if (standard.length > 0) {
    const names = standard.map((t) => getShortName(t.name)).join(', ');
    lines.push(`STANDARD (core/): ${names}`);
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
        const replacement = t.preference.replacement || REPLACEMENT_MAP[t.name];
        const shortName = getShortName(t.name);
        return replacement ? `${shortName} -> use ${replacement}` : shortName;
      });
      lines.push(`AVOID (${ns}/): ${mappings.join(', ')}`);
    }
  }
  if (legacy.length > 0) {
    const grouped = groupByNamespace(legacy);
    for (const [ns, blocks] of Object.entries(grouped)) {
      const names = blocks.map((t) => getShortName(t.name)).join(', ');
      lines.push(`LEGACY — NEVER USE (${ns}/): ${names}`);
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
    return `WARNING: ${warning.block} is deprecated. Use ${warning.suggested_replacement} instead.`;
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
