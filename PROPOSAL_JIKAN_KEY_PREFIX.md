# Proposal: Change API Key Prefix from `sk_` to `jikan_`

## Problem
Currently, generated API keys use the `sk_` prefix (inherited from Stripe conventions). This is confusing because:
- Jikan is a distinct product with its own identity
- The `sk_` prefix signals "Stripe key" to anyone reading logs or documentation
- It's unclear at a glance that the key is scoped to Jikan/mg.robnugen.com

## Solution
Change API key generation to use `jikan_` prefix instead.

### Rationale
- **Clarity**: Immediately obvious that the key is for Jikan, not a payment service
- **Branding**: Aligns with naming conventions in other MCP/AI tool ecosystems
- **Developer Experience**: Makes key scope obvious in logs, env vars, and documentation

## Scope

### Backend Changes (mg.robnugen.com)
- Update key generation logic to prefix new keys with `jikan_` instead of `sk_`
- Likely location: `wwwroot/settings/` API key generation endpoint or `classes/` auth logic
- Decide on migration strategy for existing `sk_` keys:
  - Option A: Support both `sk_` and `jikan_` indefinitely
  - Option B: Deprecation timeline (e.g., "support until 2026-07-01")
  - Option C: Automatic rotation (regenerate existing keys)

### Documentation Updates (jikan repo)
- Update README.md examples to reference `jikan_` keys
- Update `claude_desktop_config.json` example
- Update any other references to `sk_your_key_here`

### Testing
- Verify new keys are generated with `jikan_` prefix
- Verify existing `sk_` keys still work (if keeping backward compatibility)
- Update tests in mg.robnugen.com and jikan repos

## Acceptance Criteria
- [ ] New API keys generated on `/settings/` use `jikan_` prefix
- [ ] Jikan README and examples reference `jikan_` keys
- [ ] Backward compatibility decision documented (support both? deprecate?)
- [ ] Existing keys continue to work (or migration path clear)
- [ ] Tests updated and passing

## Related Issues/PRs
- See also: API key generation in mg.robnugen.com
- Jikan repo: https://github.com/thunderrabbit/jikan

---

**Created:** 2026-05-10  
**Status:** Proposal (awaiting feedback)
