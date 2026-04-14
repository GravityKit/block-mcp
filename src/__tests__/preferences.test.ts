import { describe, it, expect } from 'vitest';
import { enrichBlockList, enrichPatternList, formatPreferenceWarning } from '../preferences.js';
import type { Block, Pattern, PreferenceWarning } from '../types.js';

describe('enrichBlockList', () => {
  it('flags legacy blocks with warnings', () => {
    const blocks = [
      { index: 0, name: 'core/paragraph', attributes: {} },
      { index: 1, name: 'stackable/heading', attributes: {} },
      { index: 2, name: 'ugb/button', attributes: {} },
    ] as Block[];
    const result = enrichBlockList(blocks);
    expect(result.warnings).toHaveLength(2);
    expect(result.warnings[0].block).toBe('stackable/heading');
    expect(result.warnings[0].suggested_replacement).toBe('core/heading');
    expect(result.warnings[1].block).toBe('ugb/button');
    expect(result.warnings[1].suggested_replacement).toBe('core/button');
  });

  it('returns no warnings for preferred blocks', () => {
    const blocks = [
      { index: 0, name: 'core/paragraph', attributes: {} },
      { index: 1, name: 'filter/accordion', attributes: {} },
    ] as Block[];
    const result = enrichBlockList(blocks);
    expect(result.warnings).toHaveLength(0);
  });

  it('includes summary in output', () => {
    const blocks = [
      { index: 0, name: 'stackable/text', attributes: {} },
    ] as Block[];
    const result = enrichBlockList(blocks);
    expect(result.summary).toContain('1 legacy block');
    expect(result.summary).toContain('stackable/text');
  });

  it('returns clean summary when no legacy blocks', () => {
    const blocks = [
      { index: 0, name: 'core/heading', attributes: {} },
    ] as Block[];
    const result = enrichBlockList(blocks);
    expect(result.summary).toContain('preferred or standard');
  });

  it('flags jetpack as legacy', () => {
    const blocks = [
      { index: 0, name: 'jetpack/map', attributes: {} },
    ] as Block[];
    const result = enrichBlockList(blocks);
    expect(result.warnings).toHaveLength(1);
    expect(result.warnings[0].block).toBe('jetpack/map');
  });

  it('handles empty block list', () => {
    const result = enrichBlockList([]);
    expect(result.warnings).toHaveLength(0);
    expect(result.blocks).toHaveLength(0);
  });
});

describe('enrichPatternList', () => {
  it('sorts by score descending', () => {
    const patterns: Pattern[] = [
      {
        id: 1, name: 'Low', type: 'synced' as const,
        created: '2024-01-01', modified: '2024-01-01',
        reference_count: 0,
        preference: { score: 10, tier: 'avoid' as const, reasons: [] },
        contains_blocks: [], has_legacy_blocks: true,
      },
      {
        id: 2, name: 'High', type: 'synced' as const,
        created: '2026-01-01', modified: '2026-01-01',
        reference_count: 10,
        preference: { score: 95, tier: 'recommended' as const, reasons: [] },
        contains_blocks: [], has_legacy_blocks: false,
      },
    ] as Pattern[];
    const result = enrichPatternList(patterns);
    expect(result.patterns[0].name).toBe('High');
    expect(result.patterns[1].name).toBe('Low');
  });

  it('returns summary text', () => {
    const patterns: Pattern[] = [
      {
        id: 1, name: 'Good Pattern', type: 'synced' as const,
        created: '2026-01-01', modified: '2026-01-01',
        reference_count: 5,
        preference: { score: 80, tier: 'recommended' as const, reasons: [] },
        contains_blocks: ['core/paragraph'], has_legacy_blocks: false,
      },
    ] as Pattern[];
    const result = enrichPatternList(patterns);
    expect(result.summary).toContain('RECOMMENDED');
    expect(result.summary).toContain('Good Pattern');
  });

  it('handles empty list', () => {
    const result = enrichPatternList([]);
    expect(result.patterns).toHaveLength(0);
    expect(result.summary).toContain('No patterns found');
  });
});

describe('formatPreferenceWarning', () => {
  it('formats warning with replacement', () => {
    const warning: PreferenceWarning = {
      block: 'stackable/heading',
      message: 'Block 5: stackable/heading (AVOID)',
      suggested_replacement: 'core/heading',
    };
    const result = formatPreferenceWarning(warning);
    expect(result).toContain('WARNING');
    expect(result).toContain('stackable/heading');
    expect(result).toContain('core/heading');
  });

  it('formats warning without replacement', () => {
    const warning: PreferenceWarning = {
      block: 'jetpack/map',
      message: 'Block 3: jetpack/map (AVOID)',
    };
    const result = formatPreferenceWarning(warning);
    expect(result).toContain('WARNING');
    expect(result).toContain('jetpack/map');
  });
});
