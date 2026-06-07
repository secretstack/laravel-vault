---
name: adr
description: Write a new Architecture Decision Record in docs/adr/ following the project's established ADR format
disable-model-invocation: false
---

Create a new ADR in docs/adr/ for this project.

Steps:
1. List existing ADRs in docs/adr/ to get the next sequence number
2. Read the most recent ADR to internalize the exact format (Title, Status, Context, Decision, Consequences)
3. Create docs/adr/XXXX-<kebab-title>.md with the same structure
4. Status should be "Accepted" unless told otherwise
5. Cross-reference any related ADRs (e.g. "supersedes ADR-000X" or "extends ADR-000X")
6. Report the new ADR number and title — remind user to update CLAUDE.md if the ADR changes non-negotiable invariants or the consumer surface

Arguments: $ARGUMENTS — treat as "title | context description"
