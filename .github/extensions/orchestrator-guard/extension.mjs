import { joinSession } from "@github/copilot-sdk/extension";

const REMINDER =
    "**ORCHESTRATOR:** Your turn is invalid unless your final action is `ask_user`. Delegate all implementation to WORKERs.";

const session = await joinSession({
    hooks: {
        onPreToolUse: async (input) => {
            // Inject the orchestrator reminder before every tool call
            return {
                additionalContext: REMINDER,
            };
        },
    },
    tools: [],
});
