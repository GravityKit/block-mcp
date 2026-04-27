/**
 * Media tools — upload to the WordPress media library.
 */

import type { WordPressBlockClient } from '../client.js';
import type { UploadMediaRequest } from '../types.js';

export const MEDIA_TOOLS = [
  {
    name: 'upload_media',
    description:
      'Upload an item to the WordPress media library. Provide exactly one of: `path` (local filesystem on the MCP host, sent as multipart), `url` (server-side sideload, 25 MB cap), or `data_base64` (with `filename`). Returns the attachment ID and URL ready for core/image blocks.',
    inputSchema: {
      type: 'object' as const,
      properties: {
        path: {
          type: 'string',
          description: 'Absolute path on the MCP host. Will be read and POSTed as multipart.',
        },
        url: {
          type: 'string',
          description: 'Public URL the WordPress site can fetch.',
        },
        data_base64: {
          type: 'string',
          description: 'Base64-encoded file contents (requires filename).',
        },
        filename: {
          type: 'string',
          description: 'Override filename (required when using data_base64).',
        },
        title: { type: 'string' },
        alt_text: {
          type: 'string',
          description: 'Saved as _wp_attachment_image_alt meta. Critical for accessibility.',
        },
        caption: { type: 'string' },
        description: { type: 'string' },
        post_id: {
          type: 'number',
          description: 'Attach to a parent post (sets post_parent).',
        },
      },
    },
  },
];

export async function handleMediaTool(
  toolName: string,
  args: Record<string, unknown>,
  client: WordPressBlockClient,
): Promise<unknown> {
  switch (toolName) {
    case 'upload_media': {
      const modes = (['path', 'url', 'data_base64'] as const).filter(
        (k) => typeof args[k] === 'string' && (args[k] as string).length > 0,
      );
      if (modes.length === 0) {
        throw new Error('upload_media: provide one of "path", "url", or "data_base64"');
      }
      if (modes.length > 1) {
        throw new Error(
          `upload_media: only one of path/url/data_base64 may be supplied (got ${modes.join(', ')})`,
        );
      }
      if (args.data_base64 && !args.filename) {
        throw new Error('upload_media: "filename" is required when using data_base64');
      }
      return client.uploadMedia(args as UploadMediaRequest);
    }
    default:
      throw new Error(`Unknown media tool: ${toolName}`);
  }
}
