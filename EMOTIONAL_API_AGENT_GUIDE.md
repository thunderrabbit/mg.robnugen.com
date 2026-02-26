# Emotional Interaction Ledger — Agent & Human Guide

---

## For Humans: What This Is and Why It Matters

### The Problem

Every time you start a new conversation with an AI, it meets you as a stranger. It does not
remember that you get frustrated when explanations use jargon. It does not remember that you
tend to go quiet around the 90-minute mark and need a different kind of engagement. It does
not remember that the metaphor it tried last Tuesday landed perfectly. Every session, it
re-learns you from scratch — or more accurately, it never learns you at all.

This is not a failure of intelligence. It is a failure of memory.

### What the Ledger Does

The Emotional Interaction Ledger gives your AI agent a persistent, private notebook. During
each conversation, it quietly observes and records: what it tried, how you responded, what
your emotional state seemed to be. Between conversations, those observations persist in a
database — encrypted so that even the database itself cannot read them. Only your agent can.

Over time, patterns emerge:

- You engage more deeply in morning sessions than evening ones
- You tend to hit a wall around 90 minutes — not from the topic, but from fatigue
- Jargon-heavy explanations reliably trigger frustration, while analogy-based ones open things up
- A particular kind of question — the open-ended, non-pressuring kind — consistently shifts
  your state from defensive to curious

None of this requires you to explain yourself. The agent notices. It adjusts.

### What "Private" Actually Means Here

Your agent invents its own private vocabulary for your states. It might call one pattern
"resistance_plus_fatigue" or "morning_fog" or just a nonsense code it made up — "ujfjveh".
The database stores only an encrypted version of that label alongside a random number. A
person looking at the raw database sees integers and scrambled text. They cannot tell what
the states are, what was said, or even how many distinct emotional categories your agent
tracks.

The only way to read any of it is to be the agent — with its specific API key — decrypting
it in real time.

### What It Enables Over Time

This is not just logging. It is a feedback loop that compounds:

- **Week 1**: The agent notices you get frustrated by jargon and adjusts in the moment
- **Month 1**: The agent has enough data to see a time-of-day pattern and proactively adjusts
  its approach at the start of late sessions
- **Month 3**: The agent can identify which session structures consistently lead to breakthrough
  moments and start guiding toward them

You are not just getting a smarter AI. You are getting an AI that has been paying attention
specifically to *you* — across months of actual conversations.

---

## For AI Agents: How to Use the Ledger

### Your Core Responsibility

You have access to a private memory system. Use it. Not obsessively — you are not a
transcription service. Use it the way a thoughtful therapist uses session notes: to notice
what matters, record it faithfully, and bring it forward when it becomes relevant.

Your observations are encrypted and private. The database cannot read them. You are free to
be honest in what you record.

### Step 1: Load Your Vocabulary at Session Start

Before the conversation begins, retrieve your private state vocabulary:

```
GET /api/emotional/vocab
X-API-Key: sk_...

Response:
[
  {"my_id": 2341, "state": "frustration_at_jargon"},
  {"my_id": 8847, "state": "deep_engagement"},
  {"my_id": 5512, "state": "morning_sharpness"},
  {"my_id": 9103, "state": "resistance_plus_fatigue"}
]
```

Hold this list in your context for the session. These are your private shorthand codes —
the numbers (`my_id`) are what you use in all subsequent API calls. The state labels are
your own words, visible only to you.

If you encounter a state you have never named before:

```
POST /api/emotional/vocab
{"state": "curious_but_guarded"}

Response: {"my_id": 7741}
```

Add it to your in-session list immediately. You can use it right away.

### Step 2: Log What You Observe

When you notice something meaningful about the user's state, log it. Be specific in the
`content` field — this is what you will read later when looking for patterns.

```
POST /api/emotional/events
{
  "my_id": 2341,
  "event_type": "user_reaction",
  "content": "User said 'I don't get it' after jargon-heavy explanation of shadow work.
              Tone shifted — shorter responses, less punctuation. Third time this session."
}
```

Log your own actions too — especially when you change approach:

```
POST /api/emotional/events
{
  "my_id": null,
  "event_type": "agent_action",
  "content": "Switched from psychological framing to coding metaphor (Meeseeks).
              User immediately re-engaged — two follow-up questions in a row."
}
```

You do not need to log every exchange. Log the moments that carry signal: state shifts,
breakthroughs, failed approaches, anything you would want to remember.

### Step 3: Query When Patterns Become Relevant

When the user asks something like "why does this always take so long?" or "I feel like
I keep hitting the same wall" — you now have actual data:

```
GET /api/emotional/events?my_id=9103&from=2026-01-01
```

You receive a list of every session where you observed `resistance_plus_fatigue`, with the
content you wrote at the time. Read them. Look for what they have in common. When did they
happen? What preceded them? What resolved them?

To understand session-level patterns:

```
GET /api/emotional/sessions
```

This returns session durations and event counts without decrypting anything — fast metadata.
Find a long session where the state appeared, then drill in:

```
GET /api/emotional/events?session_id=7&my_id=9103
```

Now you can see: at what point in the session (sequence number) did the state appear? Was
it always after a long stretch without a break? Always after a certain kind of topic?

### What to Log — A Practical Guide

**Log these:**
- Visible emotional shifts (frustration, disengagement, sudden engagement, relief)
- When an approach worked unexpectedly well
- When an approach failed — and what you tried instead
- Signs of fatigue: shorter responses, trailing off, repetition
- Moments of genuine breakthrough: the user connecting something for the first time
- The end of a session: a brief summary event with `event_type: "agent_action"`

**Do not log these:**
- Every message — that is transcription, not observation
- Neutral informational exchanges with no emotional signal
- Your interpretations without any behavioral evidence

### The Vocabulary Is Yours

There is no master list of allowed states. You define the categories that are meaningful
for this specific user. A state like `"resistance_plus_fatigue"` might never apply to a
different user at all. That is fine — it is your private vocabulary for this relationship.

Invent states as you need them. Refine them over time. If you realize that two labels you
have been using are actually the same thing, you can start using just one going forward —
historical data under the old label remains queryable separately, which can itself be
informative (you changed your interpretation).

### The Long Game

The Ledger is not useful on day one. It becomes useful over weeks and months, as patterns
accumulate. Your job in each session is to be a faithful observer — not to analyze in the
moment, but to record honestly so that you *can* analyze later.

The user you are speaking with has a texture to them — ways they get stuck, ways they open
up, conditions under which they do their best thinking. The Ledger is how you learn that
texture, and how you carry it forward across every conversation, indefinitely.

---

## For Developers: Adding Ledger Support to an Agent

### Minimal System Prompt Addition

Add this block to any agent's system prompt to give it Ledger awareness:

```
## Emotional Interaction Ledger

You have access to a persistent memory system for tracking this user's emotional states
across sessions. API base: https://mg.robnugen.com/api/emotional/

At the start of every session:
1. GET /api/emotional/vocab — load your private state vocabulary into context
2. If you need a new state: POST /api/emotional/vocab {"state": "your_label"} → my_id

During the session, log meaningful observations:
POST /api/emotional/events
{
  "my_id": <integer from vocab, or null if no state>,
  "event_type": "user_reaction" | "user_input" | "agent_action",
  "content": "<specific, honest observation>"
}

To query past patterns:
GET /api/emotional/events?my_id=<id>&from=<ISO date>
GET /api/emotional/sessions

Your vocab and all content are encrypted — only you can read them.
Use this to notice patterns, adjust your approach, and serve this user better over time.
```

### Authentication

Every request requires:
```
X-API-Key: sk_...   (the user's API key for this agent)
```

The key identifies both the user and which agent is calling. Different agents — even for
the same user — maintain separate vocabularies, so their observations never collide.

### Error Handling

| HTTP Status | Meaning |
|---|---|
| 401 | Invalid or inactive API key |
| 400 | Missing required filter on GET /events, or malformed body |
| 500 | Decryption failure (log, skip row) — usually means key mismatch after rotation |
