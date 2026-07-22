/**
 * FSE Template Tools
 *
 * `list_templates` / `get_template` browse a block theme's templates and
 * template parts and read one's block content, the same way the Site
 * Editor does. Templates are index-addressed only — ref-based tools
 * (update_block, edit_block_tree by ref) do not apply to them.
 *
 * `update_template` / `reset_template` write to templates. Both require the
 * site's "Let the assistant edit theme templates" toggle (off by default);
 * without it the server returns a 403 with an actionable message.
 */

import type { WordPressBlockClient } from '../client.js';
import type { TemplateType, TemplateSource, BlockInput } from '../types.js';
import { BLOCK_INPUT_SCHEMA } from './write.js';

const READ_ANNOT = { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: true } as const;
const WRITE_ANNOT = { readOnlyHint: false, destructiveHint: true, idempotentHint: false, openWorldHint: true } as const;

const TEMPLATE_TYPE_ENUM = ['wp_template', 'wp_template_part'] as const;
const TEMPLATE_SOURCE_ENUM = ['theme', 'plugin', 'custom'] as const;

export const TEMPLATE_TOOLS = [
  {
    name: 'list_templates',
    description:
      'List a block theme\'s templates (page layouts like "single", "archive") or template parts (reusable regions like "header", "footer"). Each row includes `wp_id` — non-null only when a database override shadows the theme file, which is what makes a template editable via update_template. On a classic (non-block) theme, returns an empty list with a `note` explaining why.',
    annotations: { ...READ_ANNOT, title: 'List templates' },
    inputSchema: {
      type: 'object' as const,
      properties: {
        type: {
          type: 'string',
          enum: [...TEMPLATE_TYPE_ENUM],
          description: 'wp_template (page layouts) or wp_template_part (reusable regions). Default wp_template.',
        },
        area: {
          type: 'string',
          description: 'Template-part area filter (e.g. "header", "footer"). wp_template_part only.',
        },
        post_type: {
          type: 'string',
          description: 'Scope wp_template results to templates usable by this post type.',
        },
        slug: {
          type: 'string',
          description: 'Comma-separated slugs to match exactly (e.g. "single,page").',
        },
        source: {
          type: 'string',
          enum: [...TEMPLATE_SOURCE_ENUM],
          description: '"theme" (theme file, unmodified), "custom" (database override), or "plugin" (plugin-registered).',
        },
      },
    },
  },
  {
    name: 'get_template',
    description:
      'A single template or template part\'s metadata, raw block markup (`content`), and parsed `blocks`. Use after list_templates. `wp_id` tells you whether a database override shadows the theme file — null means the id currently resolves to the theme file itself.',
    annotations: { ...READ_ANNOT, title: 'Get template' },
    inputSchema: {
      type: 'object' as const,
      properties: {
        id: {
          type: 'string',
          description: 'Template id from list_templates, e.g. "twentytwentyfive//index".',
        },
        type: {
          type: 'string',
          enum: [...TEMPLATE_TYPE_ENUM],
          description: 'wp_template or wp_template_part. Default wp_template.',
        },
      },
      required: ['id'],
    },
  },
  {
    name: 'update_template',
    description:
      'Replace a template or template part\'s entire content — whole-template replacement, like rewrite_post_blocks, not a per-block edit. Requires the site\'s "Let the assistant edit theme templates" toggle (off by default; returns a 403 with an actionable message when off). If the id currently resolves to the theme file (`wp_id: null`), a database override is created (`override_created: true`) and the theme file itself is never touched. `content` and `blocks` are mutually exclusive — `blocks` goes through the same registry/tier/dual-storage validation as any other structured-block write. Use reset_template to revert.',
    annotations: { ...WRITE_ANNOT, title: 'Update template' },
    inputSchema: {
      type: 'object' as const,
      properties: {
        id: {
          type: 'string',
          description: 'Template id from list_templates, e.g. "twentytwentyfive//index".',
        },
        type: {
          type: 'string',
          enum: [...TEMPLATE_TYPE_ENUM],
          description: 'wp_template or wp_template_part. Default wp_template.',
        },
        content: {
          type: 'string',
          description: 'Raw block markup to replace the template with. Mutually exclusive with blocks.',
        },
        blocks: {
          type: 'array',
          description: 'Structured blocks to replace the template with. Mutually exclusive with content.',
          items: BLOCK_INPUT_SCHEMA,
        },
      },
      required: ['id'],
    },
  },
  {
    name: 'reset_template',
    description:
      'Delete a template\'s database override, reverting it to the theme file. Requires the site\'s "Let the assistant edit theme templates" toggle (off by default; returns a 403 with an actionable message when off). 404s when the id has no override to remove.',
    annotations: { ...WRITE_ANNOT, title: 'Reset template' },
    inputSchema: {
      type: 'object' as const,
      properties: {
        id: {
          type: 'string',
          description: 'Template id from list_templates, e.g. "twentytwentyfive//index".',
        },
        type: {
          type: 'string',
          enum: [...TEMPLATE_TYPE_ENUM],
          description: 'wp_template or wp_template_part. Default wp_template.',
        },
      },
      required: ['id'],
    },
  },
];

export async function handleTemplateTool(
  toolName: string,
  args: Record<string, unknown>,
  client: WordPressBlockClient
): Promise<unknown> {
  switch (toolName) {
    case 'list_templates':
      return await client.getTemplates({
        type: args.type as TemplateType | undefined,
        area: args.area as string | undefined,
        post_type: args.post_type as string | undefined,
        slug: args.slug as string | undefined,
        source: args.source as TemplateSource | undefined,
      });

    case 'get_template': {
      const id = args.id;
      if (typeof id !== 'string' || id.length === 0) {
        throw new Error('get_template: a non-empty "id" is required');
      }
      return await client.getTemplate(id, args.type as TemplateType | undefined);
    }

    case 'update_template': {
      const id = args.id;
      if (typeof id !== 'string' || id.length === 0) {
        throw new Error('update_template: a non-empty "id" is required');
      }
      const hasContent = typeof args.content === 'string';
      const hasBlocks = Array.isArray(args.blocks);
      if (hasContent === hasBlocks) {
        throw new Error('update_template: provide exactly one of "content" or "blocks"');
      }
      return await client.updateTemplate(id, args.type as TemplateType | undefined, {
        content: hasContent ? (args.content as string) : undefined,
        blocks: hasBlocks ? (args.blocks as BlockInput[]) : undefined,
      });
    }

    case 'reset_template': {
      const id = args.id;
      if (typeof id !== 'string' || id.length === 0) {
        throw new Error('reset_template: a non-empty "id" is required');
      }
      return await client.resetTemplate(id, args.type as TemplateType | undefined);
    }

    default:
      throw new Error(`Unknown template tool: ${toolName}`);
  }
}
