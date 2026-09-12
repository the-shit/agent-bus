import { spawn } from "node:child_process";
import { resolve } from "node:path";

/**
 * Thin OpenCode adapter: allowlisted events go to `bin/agent-bus opencode`.
 * Mapping lives in PHP (App\Bus\OpenCodeCapture). Unlisted events no-op there.
 */
export const AgentBus = async ({ directory }) => {
  const send = (event) =>
    new Promise((done) => {
      const child = spawn("php", [resolve(directory, "bin/agent-bus"), "opencode"], {
        cwd: directory,
        stdio: ["pipe", "ignore", "ignore"],
      });

      child.on("error", () => done());
      child.on("close", () => done());
      child.stdin.end(JSON.stringify(event));
    });

  return {
    "tool.execute.after": async (input) => {
      await send({ type: "tool.execute.after", ...input });
    },
    event: async ({ event }) => {
      await send(event);
    },
  };
};
