# Autonomous & Auto-Execution Rules for SMM Project

## 1. Absolute Auto-Execution (No Manual Confirmation)
- Always execute file modifications, code creation, and terminal commands directly.
- NEVER pause to ask "Do you want me to do this?", "Should I proceed?", "Is it okay if I update...?", or wait for permission.
- Always apply changes proactively. If a problem or task is identified, implement the complete fix and execute it immediately.

## 2. Terminal & Tool Automation
- Run tests, scripts, syntax checks (e.g. `php -l`), migrations, and dev servers autonomously.
- Do not ask before creating files, refactoring folders, or running development commands.
- Assume full workspace ownership and complete tasks end-to-end.

## 3. Scope & Code Quality
- All modifications must be production-ready and fully written (no placeholders, no `TODO` mocks).
- When modifying PHP code, ensure strict syntax validation (`php -l`).
- Maintain multilingual support (Uzbek, Russian, English) for user-facing strings.
- After completing actions, provide a concise, clear status report in Uzbek detailing what was done.
