// Runs a wp-cli command for the code-highlighting script.
//
// The local path passes argv with no shell, so nothing in `cmd` is
// shell-interpreted. The SSH path keeps a shell because WP_CLI_SSH is a
// trusted operator prefix that may carry quoting, `&&`, or an sshpass
// wrapper. Non-zero exits and spawn errors throw: spawnSync, unlike
// execSync, does not, so a failure would otherwise return empty output.

import { spawnSync } from 'child_process';

export function runWpCli(cmd, { input, wpCliSsh = '', gkcloneDir, spawn = spawnSync } = {}) {
  const options = {
    encoding: 'utf-8',
    maxBuffer: 50 * 1024 * 1024,
    input,
    stdio: input !== undefined ? ['pipe', 'pipe', 'pipe'] : undefined,
  };

  let result;
  if (wpCliSsh) {
    result = spawn(`${wpCliSsh} ${cmd}`, { ...options, shell: '/bin/bash' });
  } else {
    const cmdArgs = cmd.split(/\s+/).filter(Boolean);
    result = spawn('npx', ['wp-env', 'run', 'cli', '--', 'wp', ...cmdArgs], { ...options, cwd: gkcloneDir });
  }

  if (result.error) {
    throw result.error;
  }
  if (result.status !== 0) {
    const stderr = String(result.stderr ?? '').trim();
    throw new Error(`wp command failed (exit ${result.status})${stderr ? `: ${stderr}` : ''}`);
  }

  return String(result.stdout ?? '').split('\n')
    .filter(l => !l.startsWith('ℹ') && !l.startsWith('✔') && !l.startsWith('PHP Warning') && !l.startsWith('PHP Notice'))
    .join('\n')
    .trim();
}
