# Veylora SitePilot — AI Architecture

## Principle

AI is an assistant and reasoning layer inside a deterministic operational system.

It does not become the authorization system.

## AI responsibilities

### Observe
Consume normalized SitePilot observations.

### Understand
Summarize health, changes and incidents.

### Analyze
Generate hypotheses and identify likely causes.

### Plan
Produce structured maintenance plans.

### Recommend
Suggest actions with rationale, impact and confidence.

### Assist
Generate reports and natural-language explanations.

## AI action boundary

AI output must be structured into a proposed intent.

Example:

{
  "target": "site",
  "action": "plugin_update",
  "plugin": "example",
  "reason": "...",
  "risk": "medium"
}

Then deterministic systems evaluate:
- permission
- policy
- capability
- approval
- maintenance window
- verification strategy

Only after passing those checks can an operation execute.

## No direct tool trust

AI must not receive unrestricted shell, SQL, filesystem or arbitrary HTTP execution access in the product architecture.

Tool access is capability-based and mediated by the application.

## AI observability

Record:
- model/provider
- task type
- input references
- structured output
- policy result
- execution result
- latency/cost where available

Do not store sensitive raw data unnecessarily.

## Initial AI milestones

1. AI health summary
2. AI incident explanation
3. AI maintenance recommendations
4. AI maintenance plan generation
5. Human-approved execution assistance
6. Carefully bounded automation

Autonomous high-impact operations are not part of the initial release.
