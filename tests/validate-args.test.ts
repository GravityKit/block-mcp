import { describe, it, expect } from 'vitest';
import { validateToolArgs } from '../src/validate-args.js';
import { WRITE_TOOLS } from '../src/tools/write.js';

/**
 * Regression for the silent-misplace footgun.
 *
 * insert_blocks anchors position with `before_top_level`/`after_top_level`/
 * `before_ref`/`after_ref`. Calling it with `after: "start"` or `before: 0`
 * (plausible but WRONG key names) used to be SILENTLY ignored — the server read
 * none of them and fell through to append, putting blocks at the bottom of the
 * post with no error. This guards the dispatch-layer validation that now rejects
 * unknown keys loudly and names the valid ones.
 *
 * Teeth: without validateToolArgs wired in, none of these throw.
 */
const insertTool = WRITE_TOOLS.find((t) => t.name === 'insert_blocks');
const schema = insertTool?.inputSchema;

describe('validateToolArgs rejects unknown / misnamed tool parameters', () => {
  it('rejects a misnamed position key and names the valid anchors', () => {
    let msg = '';
    try {
      validateToolArgs('insert_blocks', schema, {
        post_id: 1,
        after: 'start',
        blocks: [{ name: 'core/paragraph', innerHTML: '<p>x</p>' }],
      });
    } catch (e) {
      msg = (e as Error).message;
    }
    expect(msg).toMatch(/Unknown parameter/i);
    expect(msg).toContain('after'); // the offending key is named
    expect(msg).toContain('after_top_level'); // the valid params are listed
  });

  it('rejects `before` and `position` too', () => {
    expect(() =>
      validateToolArgs('insert_blocks', schema, { post_id: 1, before: 0, blocks: [] }),
    ).toThrowError(/before/);
    expect(() =>
      validateToolArgs('insert_blocks', schema, { post_id: 1, position: 'top', blocks: [] }),
    ).toThrowError(/Unknown parameter/i);
  });

  it('accepts the correct anchor params', () => {
    expect(() =>
      validateToolArgs('insert_blocks', schema, {
        post_id: 1,
        before_top_level: 0,
        blocks: [{ name: 'core/paragraph', innerHTML: '<p>x</p>' }],
      }),
    ).not.toThrow();
  });

  it('flags a missing required param', () => {
    expect(() =>
      validateToolArgs('insert_blocks', schema, { post_id: 1 }),
    ).toThrowError(/Missing required.*blocks/i);
  });

  it('no-ops when the schema is absent (never blocks a tool lacking a schema)', () => {
    expect(() => validateToolArgs('whatever', undefined, { anything: true })).not.toThrow();
  });
});
