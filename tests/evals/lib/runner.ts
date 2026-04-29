/**
 * AI eval + benchmark runner.
 *
 * Loads scenarios from tests/evals/scenarios/, runs each one through the
 * Anthropic Messages API with our tool definitions attached, dispatches tool
 * calls against an in-memory FixtureStore, and grades the final state via
 * the scenario's assertion function.
 *
 * Output: a results JSON in tests/evals/results/ that gets committed so we
 * can track correctness + latency + token use over time.
 *
 * No live MCP, no live WordPress. Pure local I/O once the fixture is saved.
 */

import Anthropic from '@anthropic-ai/sdk';
import { mkdirSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { performance } from 'node:perf_hooks';
import { FixtureStore } from './fixture-store.js';
import { EVAL_TOOLS } from './tools.js';
import { SCENARIOS, type Scenario } from '../scenarios/index.js';

const MODEL = process.env.EVAL_MODEL ?? 'claude-haiku-4-5-20251001';
const MAX_TURNS = Number(process.env.EVAL_MAX_TURNS ?? '12');
const FIXTURE_PATH = join(
  import.meta.dirname,
  '..',
  'fixtures',
  'gravitycalendar-creating-a-calendar.json',
);
const RESULTS_DIR = join(import.meta.dirname, '..', 'results');

interface RunOutput {
  scenario: string;
  passed: boolean;
  reason: string;
  turns: number;
  duration_ms: number;
  input_tokens: number;
  output_tokens: number;
  tool_calls: typeof FixtureStore.prototype.callCounts;
  finish_reason: string;
}

async function runScenario(client: Anthropic, scenario: Scenario): Promise<RunOutput> {
  const store = new FixtureStore(FIXTURE_PATH);
  const messages: Anthropic.MessageParam[] = [
    { role: 'user', content: scenario.prompt(store) },
  ];

  let turns = 0;
  let totalInput = 0;
  let totalOutput = 0;
  let finishReason = 'unknown';
  const start = performance.now();

  while (turns < MAX_TURNS) {
    turns++;
    const response = await client.messages.create({
      model: MODEL,
      max_tokens: 4096,
      tools: EVAL_TOOLS as Anthropic.Tool[],
      messages,
    });
    totalInput += response.usage.input_tokens;
    totalOutput += response.usage.output_tokens;
    finishReason = response.stop_reason ?? 'unknown';

    messages.push({ role: 'assistant', content: response.content });

    if (response.stop_reason !== 'tool_use') break;

    const toolResults: Anthropic.ToolResultBlockParam[] = [];
    for (const block of response.content) {
      if (block.type !== 'tool_use') continue;
      let result: unknown;
      let isError = false;
      try {
        result = dispatch(store, block.name, block.input as Record<string, unknown>);
      } catch (err) {
        isError = true;
        result = { error: true, message: (err as Error).message };
      }
      toolResults.push({
        type: 'tool_result',
        tool_use_id: block.id,
        content: JSON.stringify(result),
        is_error: isError,
      });
    }
    messages.push({ role: 'user', content: toolResults });
  }

  const duration = performance.now() - start;
  const grade = scenario.assert(store);

  return {
    scenario: scenario.name,
    passed: grade.passed,
    reason: grade.reason,
    turns,
    duration_ms: Math.round(duration),
    input_tokens: totalInput,
    output_tokens: totalOutput,
    tool_calls: store.callCounts,
    finish_reason: finishReason,
  };
}

function dispatch(store: FixtureStore, name: string, input: Record<string, unknown>): unknown {
  switch (name) {
    case 'get_page_blocks':
      return store.getPageBlocks(input as Parameters<FixtureStore['getPageBlocks']>[0]);
    case 'insert_blocks':
      return store.insertBlocks(input as Parameters<FixtureStore['insertBlocks']>[0]);
    case 'delete_block':
      return store.deleteBlock(input as Parameters<FixtureStore['deleteBlock']>[0]);
    case 'update_block':
      return store.updateBlock(input as Parameters<FixtureStore['updateBlock']>[0]);
    case 'replace_block_range':
      return store.replaceBlocks(input as Parameters<FixtureStore['replaceBlocks']>[0]);
    default:
      throw new Error(`Unknown tool in eval harness: ${name}`);
  }
}

async function main(): Promise<void> {
  if (!process.env.ANTHROPIC_API_KEY) {
    console.error('Missing ANTHROPIC_API_KEY');
    process.exit(1);
  }

  const client = new Anthropic({ apiKey: process.env.ANTHROPIC_API_KEY });
  const results: RunOutput[] = [];

  for (const scenario of SCENARIOS) {
    process.stderr.write(`\nRunning ${scenario.name} ... `);
    const out = await runScenario(client, scenario);
    results.push(out);
    process.stderr.write(
      `${out.passed ? 'PASS' : 'FAIL'} (${out.turns} turns, ${out.duration_ms}ms, ` +
        `${out.input_tokens}/${out.output_tokens} tok)\n`,
    );
    if (!out.passed) process.stderr.write(`  ↳ ${out.reason}\n`);
  }

  mkdirSync(RESULTS_DIR, { recursive: true });
  const summary = {
    model: MODEL,
    fixture: 'gravitycalendar-creating-a-calendar',
    ran_at: new Date().toISOString(),
    pass_count: results.filter((r) => r.passed).length,
    total: results.length,
    avg_duration_ms: Math.round(results.reduce((s, r) => s + r.duration_ms, 0) / results.length),
    avg_input_tokens: Math.round(results.reduce((s, r) => s + r.input_tokens, 0) / results.length),
    avg_output_tokens: Math.round(results.reduce((s, r) => s + r.output_tokens, 0) / results.length),
    results,
  };
  const outPath = join(RESULTS_DIR, 'baseline.json');
  writeFileSync(outPath, JSON.stringify(summary, null, 2) + '\n');
  process.stderr.write(`\nResults: ${summary.pass_count}/${summary.total} pass → ${outPath}\n`);
  process.exit(summary.pass_count === summary.total ? 0 : 1);
}

main().catch((err) => {
  console.error('Eval runner failed:', err);
  process.exit(1);
});
