import { describe, it, expect } from 'vitest';

// The runner is a plain-ESM helper shared with scripts/highlight-code-blocks.mjs
// (which has no TS build step); type it at the import boundary.
type SpawnResult = {
  status: number | null;
  error?: Error;
  stdout?: string;
  stderr?: string;
};
type Runner = (
  cmd: string,
  opts?: {
    input?: string;
    wpCliSsh?: string;
    gkcloneDir?: string;
    spawn?: (...args: unknown[]) => SpawnResult;
  },
) => string;

const { runWpCli } = (await import('../scripts/wp-exec.mjs')) as { runWpCli: Runner };

function recordingSpawn(returns: SpawnResult) {
  const calls: unknown[][] = [];
  const spawn = (...args: unknown[]): SpawnResult => {
    calls.push(args);
    return returns;
  };
  return { spawn, calls };
}

describe('runWpCli', () => {
  it('local branch invokes wp-env argv directly with no shell', () => {
    // Teeth: a shell-string form (the pre-hardening execSync path) would make
    // args[0] the whole command line and set no argv array — these fail then.
    const { spawn, calls } = recordingSpawn({ status: 0, stdout: '12,34\n' });

    const out = runWpCli('eval-file -', { input: 'PHP', gkcloneDir: '/gk', spawn });

    expect(out).toBe('12,34');
    expect(calls[0][0]).toBe('npx');
    expect(calls[0][1]).toEqual(['wp-env', 'run', 'cli', '--', 'wp', 'eval-file', '-']);
    const options = calls[0][2] as Record<string, unknown>;
    expect(options.shell).toBeUndefined();
    expect(options.cwd).toBe('/gk');
    expect(options.input).toBe('PHP');
  });

  it('throws on a non-zero exit instead of returning empty output', () => {
    // Teeth: this is the swallowed-failure regression. Drop the status check
    // and runWpCli returns '' here instead of throwing, so this goes red.
    const { spawn } = recordingSpawn({ status: 1, stdout: 'partial', stderr: 'DB is down' });

    expect(() => runWpCli('eval-file -', { gkcloneDir: '/gk', spawn })).toThrow(/exit 1.*DB is down/);
  });

  it('rethrows a spawn error verbatim', () => {
    // Teeth: without the result.error check, this falls through to the status
    // branch and throws "exit null" instead of the real ENOENT error.
    const bootError = new Error('spawn npx ENOENT');
    const { spawn } = recordingSpawn({ status: null, error: bootError });

    expect(() => runWpCli('eval-file -', { gkcloneDir: '/gk', spawn })).toThrow(bootError);
  });

  it('SSH branch runs through a shell with the operator-bearing prefix intact', () => {
    // Teeth: the buggy naive-split rewrite would call spawn('sshpass', [...])
    // and shred `&&` / quotes into separate argv tokens with no shell — the
    // single-string arg and shell option below both fail then.
    const { spawn, calls } = recordingSpawn({ status: 0, stdout: 'ok' });

    runWpCli('eval-file -', {
      wpCliSsh: 'sshpass -p pw ssh host "cd /srv && wp"',
      gkcloneDir: '/gk',
      spawn,
    });

    expect(calls[0][0]).toBe('sshpass -p pw ssh host "cd /srv && wp" eval-file -');
    const options = calls[0][1] as Record<string, unknown>;
    expect(options.shell).toBe('/bin/bash');
  });

  it('strips wp-cli status noise and trims the result', () => {
    // Teeth: pins the output contract — dropping the filter leaks the ℹ / ✔ /
    // PHP Warning / PHP Notice lines back into the returned value.
    const { spawn } = recordingSpawn({
      status: 0,
      stdout: 'ℹ Loading\n12,34\n✔ Success: done\nPHP Warning: deprecated\nPHP Notice: undefined\n',
    });

    expect(runWpCli('eval-file -', { gkcloneDir: '/gk', spawn })).toBe('12,34');
  });
});
