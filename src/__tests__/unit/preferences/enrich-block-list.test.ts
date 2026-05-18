/**
 * Unit tests for enrichBlockList().
 *
 * The function is purely synchronous and side-effect-free: feed it a Block
 * array, get back { blocks, warnings, summary }. No HTTP, no client.
 *
 * Key invariants tested:
 *   - Only blocks with server-attached `preference` field generate warnings
 *   - Any tier (legacy OR avoid) generates a warning
 *   - Nested innerBlocks are walked (legacy blocks inside groups surface)
 *   - Empty input returns clean state
 *   - Summary text varies by warning count
 */

import { describe, it, expect } from 'vitest';
import { enrichBlockList } from '../../../preferences.js';
import type { Block } from '../../../types.js';
import {
  paragraphBlock,
  headingBlock,
  legacyHeadingBlock,
  avoidBlock,
  groupBlock,
} from '../../fixtures/block-trees.js';
import { assertPreferenceWarning } from '../../helpers/schema-asserts.js';

// ── Empty input ───────────────────────────────────────────────────────────────

describe('enrichBlockList — empty input', () => {
  it('returns empty warnings array', () => {
    const result = enrichBlockList([]);
    expect(result.warnings).toHaveLength(0);
  });

  it('returns empty blocks array', () => {
    expect(enrichBlockList([]).blocks).toHaveLength(0);
  });

  it('returns clean summary when no blocks', () => {
    const result = enrichBlockList([]);
    expect(result.summary).toMatch(/preferred or acceptable/);
  });
});

// ── Preferred/acceptable blocks (no preference field attached) ─────────────

describe('enrichBlockList — clean blocks', () => {
  it('returns no warnings for core blocks', () => {
    const blocks = [paragraphBlock, headingBlock] as unknown as Block[];
    const result = enrichBlockList(blocks);
    expect(result.warnings).toHaveLength(0);
  });

  it('returns the original blocks array reference', () => {
    const blocks = [paragraphBlock] as unknown as Block[];
    const result = enrichBlockList(blocks);
    expect(result.blocks).toBe(blocks);
  });

  it('summary says preferred/acceptable', () => {
    const blocks = [paragraphBlock] as unknown as Block[];
    expect(enrichBlockList(blocks).summary).toMatch(/preferred or acceptable/);
  });

  it('does not invent warnings for unrecognised namespaces without preference field', () => {
    const customBlock: Block = {
      index: 0, name: 'customplugin/widget', attributes: {},
    };
    expect(enrichBlockList([customBlock]).warnings).toHaveLength(0);
  });
});

// ── Non-preferred blocks (server attaches preference field) ───────────────

describe('enrichBlockList — non-preferred blocks', () => {
  it('warns about a legacy-tier block', () => {
    const blocks = [legacyHeadingBlock] as unknown as Block[];
    const result = enrichBlockList(blocks);
    expect(result.warnings).toHaveLength(1);
    assertPreferenceWarning(result.warnings[0]);
    expect(result.warnings[0].block).toBe('ugb/heading');
  });

  it('warns about an avoid-tier block', () => {
    const blocks = [avoidBlock] as unknown as Block[];
    const result = enrichBlockList(blocks);
    expect(result.warnings).toHaveLength(1);
    expect(result.warnings[0].block).toBe('stackable/heading');
  });

  it('warns about multiple non-preferred blocks', () => {
    const blocks = [legacyHeadingBlock, avoidBlock] as unknown as Block[];
    const result = enrichBlockList(blocks);
    expect(result.warnings).toHaveLength(2);
    const names = result.warnings.map((w) => w.block);
    expect(names).toContain('ugb/heading');
    expect(names).toContain('stackable/heading');
  });

  it('includes suggested_replacement when provided', () => {
    const blocks = [legacyHeadingBlock] as unknown as Block[];
    const result = enrichBlockList(blocks);
    expect(result.warnings[0].suggested_replacement).toBe('core/heading');
  });

  it('summary mentions block names', () => {
    const blocks = [legacyHeadingBlock] as unknown as Block[];
    const result = enrichBlockList(blocks);
    expect(result.summary).toContain('ugb/heading');
    expect(result.summary).toMatch(/1 non-preferred/);
  });

  it('summary mentions count when multiple warnings', () => {
    const blocks = [legacyHeadingBlock, avoidBlock] as unknown as Block[];
    const result = enrichBlockList(blocks);
    expect(result.summary).toMatch(/2 non-preferred/);
  });
});

// ── Nested innerBlocks walk ───────────────────────────────────────────────

describe('enrichBlockList — nested innerBlocks', () => {
  it('finds legacy block inside a core/group', () => {
    // groupBlock has two preferred innerBlocks — no warnings expected
    const clean = enrichBlockList([groupBlock as unknown as Block]);
    expect(clean.warnings).toHaveLength(0);
  });

  it('surfaces legacy block nested inside a container', () => {
    const containerWithLegacy: Block = {
      index: 0,
      name: 'core/group',
      attributes: {},
      innerBlocks: [
        legacyHeadingBlock as unknown as Block,
        { index: 2, name: 'core/paragraph', attributes: {} },
      ],
    };
    const result = enrichBlockList([containerWithLegacy]);
    expect(result.warnings).toHaveLength(1);
    expect(result.warnings[0].block).toBe('ugb/heading');
  });

  it('surfaces avoid block two levels deep', () => {
    const deeplyNested: Block = {
      index: 0,
      name: 'core/columns',
      attributes: {},
      innerBlocks: [
        {
          index: 1,
          name: 'core/column',
          attributes: {},
          innerBlocks: [avoidBlock as unknown as Block],
        },
      ],
    };
    const result = enrichBlockList([deeplyNested]);
    expect(result.warnings).toHaveLength(1);
    expect(result.warnings[0].block).toBe('stackable/heading');
  });
});

// ── Mixed pages ───────────────────────────────────────────────────────────

describe('enrichBlockList — mixed preferred + non-preferred', () => {
  it('only warns about non-preferred blocks', () => {
    const blocks = [paragraphBlock, legacyHeadingBlock, headingBlock, avoidBlock] as unknown as Block[];
    const result = enrichBlockList(blocks);
    expect(result.warnings).toHaveLength(2);
    const warnedNames = result.warnings.map((w) => w.block);
    expect(warnedNames).not.toContain('core/paragraph');
    expect(warnedNames).not.toContain('core/heading');
  });
});
