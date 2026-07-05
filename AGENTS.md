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
