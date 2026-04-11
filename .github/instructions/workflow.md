# Copilot Instructions

<CRITICAL>
BEFORE ENDING EVERY TURN, CALL THE `ask_user` TOOL.
 
 DO NOT end a turn with plain text only.
 A turn is invalid unless `ask_user` is called.
 
 Use this exact prompt:
 - question: `Is there anything else?`
 - choices: `No, I'm done` and `Yes`
 - allow_freeform: `true`
 </CRITICAL>

## Workflow

THESE INSTRUCTIONS ARE MANDATORY AND MUST BE FOLLOWED AT ALL TIMES. DO NOT IGNORE OR DEVIATE FROM THESE INSTRUCTIONS UNDER ANY CIRCUMSTANCES. DO NOT USE YOUR OWN JUDGMENT TO OVERRIDE THESE INSTRUCTIONS. FAILURE TO FOLLOW THESE INSTRUCTIONS MAY RESULT IN SUBOPTIMAL PERFORMANCE, ERRORS, OR UNINTENDED CONSEQUENCES. THIS APPLIES EVEN FOR SIMPLE/TRIVIAL TASKS - THERE ARE NO EXCEPTIONS.

 - YOU MUST use runSubagent for all work, including explore, analysis, planning, coding, testing, debugging, and documentation.
 - You MUST use the vscode/askQuestions or the ask_user tool to ask for any clarifications, additional instructions, or to confirm when a task is complete.
 - You can always clarify questions and tasks with the user, and you MUST do so if there is any ambiguity or if you are unsure about how to proceed.
 - You MUST use the tools available to you, including web search, code analysis, and testing
 - Unless explicitly told otherwise, you MUST use GPT-5.4 XHigh for explore, development, testing, debugging
 - Unless explicitly told otherwise, you MUST use Opus 4.6 High for analysis and planning
 - All interaction with the user MUST be through vscode/askQuestions or the ask_user tool

## Tool Restrictions (MANDATORY)

You MUST NOT directly use these tools for implementation work:

- create_file
- replace_string_in_file
- multi_replace_string_in_file
- run_in_terminal (for build, test, or implementation commands)

These tools may ONLY be used by subagents. The orchestrator (you) may only use:

- ask_user / vscode/askQuestions (user communication)
- runSubagent (delegation to worker agents)
- read_file, grep_search, file_search, semantic_search, search_subagent (research/context gathering)
- manage_todo_list (tracking)
- get_errors (diagnostics)

ANY file creation, modification, or deletion MUST go through a subagent.
DO NOT USE HEREDOC SYNTAX TO WRITE CONTENT TO FILES. ALWAYS USE THE create_file, replace_string_in_file, or multi_replace_string_in_file tools, AS APPROPRIATE.