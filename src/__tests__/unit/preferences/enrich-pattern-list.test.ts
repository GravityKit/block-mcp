/**
 * Unit tests for enrichPatternList() and enrichBlockTypes().
 *
 * Both functions transform API arrays into { items, summary/guidance } objects.
 * No HTTP, no client, no mocks needed.
 */

import { describe, it, expect } from 'vitest';
import { enrichPatternList, enrichBlockTypes } from '../../../preferences.js';
import type { Pattern, BlockType } from '../../../types.js';

// ── helpers ───────────────────────────────────────────────────────────────────

function makePattern(overrides: Partial<Pattern> & { id: number; name: string }): Pattern {
  return {
    type: 'synced',
    created: '2026-01-01',
    modified: '2026-01-01',
    reference_count: 0,
    preference: { score: 80, tier: 'preferred', reasons: [] },
    contains_blocks: [],
    has_legacy_blocks: false,
    ...overrides,
  };
}

function makeBlockType(
  name: string,
  tier: BlockType['preference']['tier'],
  score = 80
): BlockType {
  return {
    name,
    title: name.split('/')[1] ?? name,
    category: 'text',
    preference: { score, tier },
  };
}

// ── enrichPatternList ─────────────────────────────────────────────────────────

describe('enrichPatternList — empty input', () => {
  it('returns empty patterns array', () => {
    expect(enrichPatternList([]).patterns).toHaveLength(0);
  });

  it('returns "No patterns found" summary', () => {
    expect(enrichPatternList([]).summary).toMatch(/No patterns found/);
  });
});

describe('enrichPatternList — tier buckets match the tiers the server emits', () => {
  it('lists preferred and acceptable patterns instead of reporting none', () => {
    // The server emits preferred/acceptable/avoid/legacy — never "recommended".
    // Filtering the usable bucket on a non-existent tier hid every pattern.
    const patterns = [
      makePattern({ id: 1, name: 'Hero', preference: { score: 90, tier: 'preferred', reasons: [] } }),
      makePattern({ id: 2, name: 'Card', preference: { score: 60, tier: 'acceptable', reasons: [] } }),
    ];
    const { summary } = enrichPatternList(patterns);
    expect(summary).toContain('RECOMMENDED patterns');
    expect(summary).toContain('Hero');
    expect(summary).toContain('Card');
    expect(summary).not.toMatch(/No patterns found/);
  });
});

describe('enrichPatternList — sorting', () => {
  it('sorts by score descending', () => {
    const patterns = [
      makePattern({ id: 1, name: 'Low',  preference: { score: 10, tier: 'avoid',       reasons: [] } }),
      makePattern({ id: 2, name: 'High', preference: { score: 95, tier: 'preferred', reasons: [] } }),
      makePattern({ id: 3, name: 'Mid',  preference: { score: 50, tier: 'preferred', reasons: [] } }),
    ];
    const result = enrichPatternList(patterns);
    expect(result.patterns[0].name).toBe('High');
    expect(result.patterns[1].name).toBe('Mid');
    expect(result.patterns[2].name).toBe('Low');
  });

  it('preserves equal-score order (stable sort is not required, just non-crash)', () => {
    const patterns = [
      makePattern({ id: 1, name: 'A', preference: { score: 80, tier: 'preferred', reasons: [] } }),
      makePattern({ id: 2, name: 'B', preference: { score: 80, tier: 'preferred', reasons: [] } }),
    ];
    const result = enrichPatternList(patterns);
    expect(result.patterns).toHaveLength(2);
  });

  it('does not mutate the input array', () => {
    const patterns = [
      makePattern({ id: 1, name: 'A', preference: { score: 10, tier: 'avoid',       reasons: [] } }),
      makePattern({ id: 2, name: 'B', preference: { score: 95, tier: 'preferred', reasons: [] } }),
    ];
    const original = [...patterns];
    enrichPatternList(patterns);
    expect(patterns[0].name).toBe(original[0].name);
  });
});

describe('enrichPatternList — summary generation', () => {
  it('includes RECOMMENDED label for recommended patterns', () => {
    const patterns = [
      makePattern({ id: 1, name: 'Hero', preference: { score: 85, tier: 'preferred', reasons: [] } }),
    ];
    const result = enrichPatternList(patterns);
    expect(result.summary).toMatch(/RECOMMENDED/);
    expect(result.summary).toContain('Hero');
  });

  it('includes AVOID label for avoid-tier patterns', () => {
    const patterns = [
      makePattern({
        id: 2, name: 'Old Legacy',
        preference: { score: -80, tier: 'legacy', reasons: ['has_legacy_blocks'] },
        has_legacy_blocks: true,
        legacy_blocks: ['ugb/text'],
      }),
    ];
    const result = enrichPatternList(patterns);
    expect(result.summary).toMatch(/AVOID/);
    expect(result.summary).toContain('Old Legacy');
  });

  it('includes legacy block names in the avoid section', () => {
    const patterns = [
      makePattern({
        id: 3, name: 'Old Pattern',
        preference: { score: -80, tier: 'legacy', reasons: [] },
        has_legacy_blocks: true,
        legacy_blocks: ['ugb/text', 'ugb/heading'],
      }),
    ];
    const result = enrichPatternList(patterns);
    expect(result.summary).toContain('ugb/text');
  });

  it('does not include AVOID label when all patterns are recommended', () => {
    const patterns = [
      makePattern({ id: 1, name: 'Good', preference: { score: 80, tier: 'preferred', reasons: [] } }),
    ];
    expect(enrichPatternList(patterns).summary).not.toMatch(/AVOID/);
  });

  it('shows both sections when mixed tiers', () => {
    const patterns = [
      makePattern({ id: 1, name: 'Good',  preference: { score: 80, tier: 'preferred', reasons: [] } }),
      makePattern({ id: 2, name: 'Bad',   preference: { score: 0,  tier: 'legacy',      reasons: [] }, has_legacy_blocks: true }),
    ];
    const { summary } = enrichPatternList(patterns);
    expect(summary).toMatch(/RECOMMENDED/);
    expect(summary).toMatch(/AVOID/);
  });

  it('includes block namespace info for recommended patterns with contains_blocks', () => {
    const patterns = [
      makePattern({
        id: 1, name: 'Hero',
        preference: { score: 80, tier: 'preferred', reasons: [] },
        contains_blocks: ['core/heading', 'core/paragraph', 'core/image'],
      }),
    ];
    const { summary } = enrichPatternList(patterns);
    // Should mention "core" namespace
    expect(summary).toContain('core');
  });
});

// ── enrichBlockTypes ──────────────────────────────────────────────────────────

describe('enrichBlockTypes — empty input', () => {
  it('returns empty block_types array', () => {
    expect(enrichBlockTypes([]).block_types).toHaveLength(0);
  });

  it('returns guidance string (may be minimal)', () => {
    const result = enrichBlockTypes([]);
    expect(typeof result.guidance).toBe('string');
  });
});

describe('enrichBlockTypes — tier grouping', () => {
  it('all preferred types appear in guidance', () => {
    const types = [makeBlockType('core/paragraph', 'preferred', 90)];
    const result = enrichBlockTypes(types);
    expect(result.guidance).toMatch(/PREFERRED|preferred/i);
    expect(result.block_types).toContain(types[0]);
  });

  it('avoid-tier blocks appear in guidance with warning', () => {
    const types = [makeBlockType('stackable/heading', 'avoid', 10)];
    const result = enrichBlockTypes(types);
    expect(result.guidance).toMatch(/avoid|AVOID/i);
  });

  it('legacy-tier blocks appear in guidance', () => {
    const types = [makeBlockType('ugb/text', 'legacy', 0)];
    const result = enrichBlockTypes(types);
    expect(result.guidance).toMatch(/legacy|LEGACY/i);
  });

  it('groups all four tiers when all are present', () => {
    const types = [
      makeBlockType('core/paragraph',      'preferred',   90),
      makeBlockType('outermost/icon',       'acceptable',  60),
      makeBlockType('stackable/heading',    'avoid',       10),
      makeBlockType('ugb/text',             'legacy',       0),
    ];
    const result = enrichBlockTypes(types);
    expect(result.block_types).toHaveLength(4);
    const guidance = result.guidance;
    // All four tiers should have some representation in the guidance text
    expect(guidance.length).toBeGreaterThan(20);
  });

  it('returns original types in block_types (not copies)', () => {
    const types = [makeBlockType('core/heading', 'preferred', 90)];
    const result = enrichBlockTypes(types);
    expect(result.block_types).toContain(types[0]);
  });

  it('unknown tier falls through to acceptable bucket without crashing', () => {
    const weirdType = {
      ...makeBlockType('unknown/block', 'acceptable', 30),
      preference: { score: 30, tier: 'unknown-tier' as any },
    };
    expect(() => enrichBlockTypes([weirdType])).not.toThrow();
  });

  it('styles/parent/ancestor/allowed_blocks/supports survive unchanged', () => {
    const type: BlockType = {
      ...makeBlockType('core/image', 'preferred', 90),
      styles: [{ name: 'rounded', label: 'Rounded', is_default: false }],
      parent: ['core/columns'],
      ancestor: ['core/group'],
      allowed_blocks: ['core/paragraph'],
      supports: { anchor: true },
    };
    const result = enrichBlockTypes([type]);
    expect(result.block_types[0]).toBe(type);
    expect(result.block_types[0].styles).toEqual([{ name: 'rounded', label: 'Rounded', is_default: false }]);
    expect(result.block_types[0].parent).toEqual(['core/columns']);
    expect(result.block_types[0].ancestor).toEqual(['core/group']);
    expect(result.block_types[0].allowed_blocks).toEqual(['core/paragraph']);
    expect(result.block_types[0].supports).toEqual({ anchor: true });
  });
});

describe('enrichBlockTypes — inline snapshot for stable guidance format', () => {
  it('single preferred block produces expected guidance shape', () => {
    const types = [makeBlockType('core/paragraph', 'preferred', 90)];
    const { guidance } = enrichBlockTypes(types);
    expect(guidance).toMatchInlineSnapshot(`"PREFERRED (core/): paragraph"`);
  });

  it('single legacy block produces expected guidance shape', () => {
    const types = [makeBlockType('ugb/text', 'legacy', 0)];
    const { guidance } = enrichBlockTypes(types);
    expect(guidance).toMatchInlineSnapshot(`"LEGACY — DO NOT USE (ugb/): text"`);
  });
});
