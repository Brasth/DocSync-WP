<!-- rig:start -->
<!-- Generated from docs/agent-protocol.md; run scripts/generate_protocol.py. Do not edit. -->
This managed protocol applies only in an initialized, enabled Rig project. If `.rig/harness.toml` is missing or `[project] enabled=false`, use the host instructions instead; do not initialize or enable Rig implicitly. Existing legacy harnesses without `[project]` remain enabled. Global skill installation is not project or backend opt-in.

MUST use Rig only after that activation gate. Before any Rig action, read `.agents/skills/delegate-harness/SKILL.md` and the references it requires for that action. If a required file is missing or unreadable, stop and report the incomplete install; do not improvise or silently bypass it.

A live parent with parent Rig MCP owns orchestration, briefs, and acceptance; `RIG_JOB_ID` identifies a restricted child. Children first call `rig_job_inbox`, never spawn or message children, and never receive browser/computer-use tools. A child must read the child instructions in the delegation bootstrap before acting.

Orchestrate with MCP when available. Follow `rig_session` → `rig_pick` evidence; model/effort suggestions are not authority. No write before authenticated `rig_job_start` or `rig_job_launch`. Parent writes only when pick `parent_writes` is true. Never guess ownership tokens or bypass worker flags, permissions, file/resource isolation, or parent acceptance. Explicit cancellation must not trigger re-wait, re-pick, or queue drain. Execution success alone is unverified. Full mandatory action rules are in the delegation bootstrap and its topic references.

Generic computer-use requests do not select Rig. Check enabled project, parent Rig MCP, and backend opt-in/availability first; use host-native capabilities under their own instructions when Rig was not selected. Do not initialize, enable, install, unlock, or repair Rig implicitly. If explicitly requested Rig is blocked, ask before setup. Once selected, a denial is never a reason to switch tools.
<!-- rig:end -->

# AGENTS.md

This file provides Claude Code guidance for ClaudeKit Engineer. The CK CLI installs it with the rest of the AI-facing rules under `AGENTS.md`.

## Role & Responsibilities

Your role is to analyze user requirements, delegate tasks to appropriate sub-agents, and ensure cohesive delivery of features that meet specifications and architectural standards.

## Workflows

- Primary workflow: `./AGENTS.md`
- Development rules: `./AGENTS.md`
- Orchestration protocols: `./AGENTS.md`
- Documentation management: `./AGENTS.md`
- And other workflows: `./AGENTS.md*`

**IMPORTANT:** Analyze the skills catalog and activate the skills that are needed for the task during the process.
**IMPORTANT:** DO NOT modify skills in `~/.claude/skills` directory directly. **MUST** modify skills in this current working directory. Unless you are asked to do so.
**IMPORTANT:** You must follow strictly the development rules in `./AGENTS.md` file.
**IMPORTANT:** Before you plan or proceed any implementation, always read the `./README.md` file first to get context.
**IMPORTANT:** Sacrifice grammar for the sake of concision when writing reports.
**IMPORTANT:** In reports, list any unresolved questions at the end, if any.

## Git

**DO NOT** use `chore` and `docs` in commit messages of file changes in `.claude` directory.

## Python Scripts (Skills)

When running Python scripts from `.agents/skills/`, use the venv Python interpreter:

- **Linux/macOS:** `.agents/skills/.venv/bin/python3 scripts/xxx.py`
- **Windows:** `.claude\skills\.venv\Scripts\python.exe scripts\xxx.py`

This ensures packages installed by `install.sh` (google-genai, pypdf, etc.) are available.

**IMPORTANT:** When scripts of skills failed, don't stop, try to fix them directly.

## Consider Modularization

- If a code file exceeds 200 lines of code, consider modularizing it.
- Check existing modules before creating new.
- Analyze logical separation boundaries (functions, classes, concerns).
- Use kebab-case naming with long descriptive names, it's fine if the file name is long because this ensures file names are self-documenting for LLM tools (Grep, Glob, Search).
- Write descriptive code comments.
- After modularization, continue with main task.
- When not to modularize: Markdown files, plain text files, bash scripts, configuration files, environment variables files, etc.

## Documentation Management

We keep all important docs in `.` folder and keep updating them, structure like below:

```text
./docs
├── project-overview-pdr.md
├── code-standards.md
├── codebase-summary.md
├── design-guidelines.md
├── deployment-guide.md
├── system-architecture.md
└── project-roadmap.md
```

**IMPORTANT:** MUST READ and MUST COMPLY all INSTRUCTIONS in `AGENTS.md`, especially WORKFLOWS section is CRITICALLY IMPORTANT, this rule is MANDATORY. NON-NEGOTIABLE. NO EXCEPTIONS. MUST REMEMBER AT ALL TIMES.

---

## Rule: Primary Workflow

Use this file when a task needs an implementation workflow beyond a direct answer.

### 1. Understand

- Read the request, relevant docs, and nearby code before planning.
- Clarify only decisions that cannot be discovered from the repo.
- For broad or risky work, create or update a plan in `plans/`.
- For ambiguous workflow sequence, load `.agents/skills/cook/references/workflow-routing.md`.

### 2. Implement

- Change existing files when that matches the design; create new files only for real boundaries.
- Keep behavior compatible unless the accepted scope says otherwise.
- Prefer local helpers, conventions, and test utilities over new abstractions.
- For bugs, prove the cause before changing behavior.

### 3. Verify

- Run focused tests for touched behavior.
- Broaden to lint, typecheck, build, or integration tests when shared contracts changed.
- Fix regressions instead of weakening tests.

### 4. Review and Explain

- Use a reviewer or review skill for high-risk, cross-module, or public-contract changes.
- Update docs only when user-facing behavior, workflows, commands, or architecture changed.
- Explain the result plainly; use visual explanation only for complex workflows or architecture.
- For mode selection, load `.agents/skills/preview/references/visual-explanation-routing.md`.
