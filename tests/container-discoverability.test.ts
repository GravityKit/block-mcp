import { describe, it, expect } from 'vitest';
import { AGENT_GUIDE_CONTENT } from '../src/agent-guide.js';
import { WRITE_TOOLS } from '../src/tools/write.js';

/**
 * Container-authoring must be discoverable from the tool surface alone.
 *
 * Building a nested container (a group/callout wrapping children) is fully
 * supported via `innerBlocks` in every block def — but nothing said so where
 * an agent looks: the insert-shaped tools' `blocks` descriptions never
 * mentioned nesting, and the agent guide had no container section. An agent
 * had to read this repo's source to learn the capability. These assertions
 * pin the discoverability contract so it can't silently regress.
 */
describe('container authoring is discoverable without reading source', () => {
  const blocksDescriptionOf = (toolName: string): string => {
    const tool = WRITE_TOOLS.find((t) => t.name === toolName);
    const props = (tool?.inputSchema as unknown as {
      properties?: Record<string, { description?: string }>;
    })?.properties;
    return props?.blocks?.description ?? '';
  };

  it.each(['insert_blocks', 'replace_block_range', 'rewrite_post_blocks'])(
    '%s blocks param documents innerBlocks nesting',
    (toolName) => {
      const description = blocksDescriptionOf(toolName);
      expect(description).toContain('innerBlocks');
      expect(description).toMatch(/group|container/i);
    },
  );

  it('agent guide has a container-blocks section with a worked example', () => {
    expect(AGENT_GUIDE_CONTENT).toMatch(/## Building container blocks/i);
    expect(AGENT_GUIDE_CONTENT).toContain('innerBlocks');
    expect(AGENT_GUIDE_CONTENT).toContain('core/group');
    // The wrapper-HTML requirement is the part agents get wrong — must be stated.
    expect(AGENT_GUIDE_CONTENT).toMatch(/wrapper.*innerHTML|innerHTML.*wrapper/i);
  });
});
