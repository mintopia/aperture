<local-dev>
Local dev environment is deployed in docker using docker-compose. The URL is https://aperture.local.js42.io.
</local-dev>
<quality-guidelines>
# Quality Guidelines

These requirements must be met before changes are pushed. Even if the errors found do not relate to work you have done, they must still be resolved.

 - TDD must be used, with Red/Green approach
 - All code must meet 100% Code Coverage with passing tests
 - All code must be linted and formatted appropriately
 - Use subagents with the appropriate expertise and skills:
   - code-reviewer
   - qa-expert
   - test-automator
   - audit (impeccable)

## Workflow

Use the following workflow:

1. test-automator subagent to review work and create failing tests
2. appropriate skilled implementation subagent to implement work until tests pass
3. code-reviewer subagent reviews the work completed, compares it against spec and performs linting/formatting tests.
4. If any changes are needed, add to handover, return to step 2.
5. qa-expert subagent performs QA and runs tests for the affected areas
6. If any failures, add to handover, return to step 2.
7. Impeccable `audit` skill or `audit` subagent reviews user interface changes
8. If any medium or higher changes suggested, add to handover, return to step 2.
9. Work is complete

### Handover

Handover should be brief, conveying only necessary information and not verbose.

#### Header/Structure

```json
{
	"task": "<brief description of task>",
	"gates": {
		"coverage": false,
		"tests": false,
		"formatting": false,
		"review": false,
		"ui": false
	},
	"actions": []
}
```

The gates are set to false and only when all are set to true, on completion of that aspect, is the task considered done.

#### Per-Action

Each action executed in the workflow adds to the handover:

```json
{
	"type": "<action name>",
	"agent": "<agent type>",
	"description": "<brief description of work done>",
	"created": [
		"<filename>"
	],
	"updated": [
		"<filename>"
	],
	"deleted": [
		"<filename>"
	],
	"problems": {
		"critical": [
			"<problem>"
		],
		"high": [
			"<problem>"
		],
		"medium": [
			"<problem>"
		],
		"low": [
			"<problem>"
		]
	},
	"success": "<result>"
}
```

This includes a brief description of either work done. Where files are modified, created or deleted, they are added to the appropriate array.

If problems were encountered, e.g. code review, linting, tests, they are added to the appropriate severity array in the problems object.

#### Example

```json
{
	"task": "<brief description of task>",
	"gates": {
		"coverage": false,
		"tests": false,
		"formatting": false,
		"review": false,
		"ui": false
	},
	"action": [{
		"type": "test-creation",
		"agent": "test-automator",
		"description": "<brief description of work done>",
		"created": [
			"<filename>"
		],
		"updated": [
			"<filename>"
		],
		"deleted": [
			"<filename>"
		],
		"success": true
	}, {
		"action": "implementation",
		"agent": "laravel-expert",
		"description": "<brief description of work done>",
		"created": [
			"<filename>"
		],
		"updated": [
			"<filename>"
		],
		"deleted": [
			"<filename>"
		],
		"success": true
	}, {
		"action": "code-review",
		"agent": "code-reviewer",
		"description": "<brief description of problems>",
		"success": false
	}, {
		"action": "implementation",
		"agent": "laravel-expert",
		"description": "<brief description of work done>",
		"updated": [
			"<filename>"
		],
		"success": true
	}]
}
```

## Language Specifics
### PHP
  - All code must be formatted with Laravel Pint
  - PHPStan Level 8. All baseline entries must be justified.
  - RectorPHP must be used with a Laravel ruleset
  - PHPUnit for Tests
  - Use Parallel Tests where possible
  - Use mock APIs/interfaces for external components and surfaces
  - Code Coverage using XDebug with XDEBUG_MODE=coverage
  - SQLite for databases, array for cache and session
  - Dependency injection should be used to allow dependencies to be mocked. If there are cases where testing is made complex due to a lack of DI, then implement DI.

### Typescript/Javascript
  - eslint
  - prettier
  - vitest

### User Interface
  - Playwright and the Playwright CLI/MCP can be used
  - All elements under test must have a test ID for easy use in tests
  - All user stories and journeys must have a Playwright test
  - If the `audit` skill or `audit` subagent type is available, use it to validate the user interface and implement any critical or high fixes.
  - User interface should match wireframes/mockups where provided.

</quality-guidelines>

## Decision memory

Before proposing architecture, tooling, style, testing, repo structure, or workflow changes:

1. Search `docs/decisions/`.
2. Treat accepted ADRs as binding unless explicitly overridden.
3. If a decision is outdated, create a new ADR that supersedes it.
4. Do not re-litigate accepted decisions unless new constraints are introduced.
5. ADRs must follow the adr-template below.

<adr-template>
# ADR ###: Title of ADR
Status: Accepted
Date: 2026-05-09

## Context
The context for the ADR.

## Decision
The decision that was made.

## Consequences
Description of consequences from this ADR.

## Supersedes
N/A or ADR ###
</adr-template>
