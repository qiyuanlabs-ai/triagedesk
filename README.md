# TriageDesk

An open-source support-ticket agent built with the [Laravel AI SDK](https://laravel.com/docs/ai-sdk), Livewire and SQLite.

The agent triages new tickets, looks up customers, payments and help-center articles, and proposes what to do next: change the ticket, reply to the customer, or refund. **Nothing that changes data runs until a person approves it** in an approval inbox, except refunds small enough to fall under a configurable limit.

It is a single-team demo meant to be read: small files, the non-obvious decisions explained in comments, and a test suite that runs the real agent loop offline.

| | |
|---|---|
| Laravel | 13 |
| Laravel AI SDK (`laravel/ai`) | ^1.3 |
| Livewire | 4 |
| Database / queue | SQLite, database queue |
| Model providers | Anthropic and OpenAI together, each with its own daily dollar limit and automatic failover; other SDK providers by config |

## What it does

- **Automatic triage.** A new ticket goes through `TriageAgent`, which returns structured output (category, priority, sentiment, one-line summary) that is written straight onto the ticket.
- **Proposals.** `SupportAgent` then investigates with read-only tools and proposes actions with write tools. You can also chat with it on any ticket.
- **Approval inbox.** Every proposed write appears as a card showing the tool, its arguments, the agent's reason and why it needs a human. Approve it, edit the arguments and approve, or reject it with a note the agent reads.
- **Amount-based approval.** `IssueRefund` runs at once while the running total stays within $50 (`REFUND_AUTO_APPROVE_LIMIT`): the refund, plus what was already refunded on that payment, plus what earlier calls auto-refunded on the ticket. Splitting a big refund into small ones still reaches a human.
- **Skills.** Two file skills (`resources/skills`) and per-team skills loaded from the database by a closure. The agent sees only names and descriptions until it loads one.
- **Activity log.** Every write the agent proposed or ran, who decided, and the result.
- **Scoped tools.** Every tool is built for one ticket. Write tools act on that ticket only, and read tools see only its customer, so text planted in a ticket cannot steer the agent at other customers.
- **Cost controls for a public demo.** Visitors browse recorded real runs; live runs need an access code. Each provider has a daily dollar limit counted from real token usage, checked before every model call, plus a daily run cap, per-IP limits and a per-run token cap.

## Quick start

Requirements: PHP 8.3+ with the `pdo_sqlite` extension, Composer, Node 20.19+ (for Vite).

```bash
git clone <your fork> triagedesk && cd triagedesk
composer run setup          # installs, creates .env, migrates and seeds, builds assets
```

Add a key for one or both providers to `.env`, and turn on demo mode so you can sign in without a password (pick any access code for live runs on your machine):

```dotenv
ANTHROPIC_API_KEY=sk-ant-...
OPENAI_API_KEY=sk-...
DEMO_MODE=true
DEMO_ACCESS_CODE=pick-a-code
```

Then start the server, queue worker and Vite together:

```bash
composer run dev
```

Open http://localhost:8000, sign in as one of the three seeded teammates (no passwords: it is a demo), and enter your access code in the bar at the top to run the agent live.

Things to try:

1. On **Tickets**, press **Simulate a new ticket**. The queue triages it within a few seconds and the agent's proposals appear in the **Approval inbox**.
2. Open an untriaged ticket and press **Run triage**.
3. Open a "You charged me TWICE" ticket from a Business customer and ask the agent: *Refund the duplicate charge and reply to the customer.* The $149 refund and the reply wait for you; a $49 Team duplicate refunds straight away.
4. Reject a proposal with a note ("check with billing first") and watch the agent adjust.
5. Sign in as Jordan and try to approve an action on Sam's ticket.

### Providers, routing and daily limits

Each agent tries its providers in order and skips any provider with no API key or with its daily limit spent; the SDK also fails over when a provider is down, overloaded or rate limited.

| Setting | Default | What it does |
|---|---|---|
| `AI_TRIAGE_PROVIDERS` | `openai,anthropic` | Order for the one-shot triage classification |
| `AI_SUPPORT_PROVIDERS` | `anthropic,openai` | Order for the tool-using agent and its approval flow |
| `AI_ANTHROPIC_MODEL`, `AI_OPENAI_MODEL` | blank | Blank uses each provider's cheapest model (Claude Haiku 5.5, GPT-6 Luna) |
| `AI_ANTHROPIC_DAILY_LIMIT_USD` | 3 | Daily spend limit for Anthropic; 0 switches it off |
| `AI_OPENAI_DAILY_LIMIT_USD` | 6 | Daily spend limit for OpenAI; 0 switches it off |
| `AI_BUDGET_TIMEZONE` | `Asia/Shanghai` | The day for the limits starts at midnight here |

Spend is counted per model call from the usage the provider reports (uncached input, cache reads, cache writes and output, with long-context rates) at the list prices in `config/triagedesk.php`. A model missing from that table is counted at a deliberately high price, so a forgotten entry stops early rather than overspending. The check runs before every model call, so a limit can only be overshot by the one call already in flight.

## How the approval flow works

```
new ticket ─▶ TriageTicket job ─▶ TriageAgent (structured output, no tools) ─▶ ticket fields
                                         │
                                         ▼
                 SupportAgent in the ticket's conversation, with 7 tools
                                         │
           read tools run ◀──────────────┤
                                         │ write tool call
                                         ▼
                    needs approval?  ── no (refund ≤ $50) ──▶ runs now, logged
                                         │ yes
                                         ▼
            the SDK pauses the run and fires ToolApprovalRequested
                                         │
                                         ▼
          RecordAgentActivity writes one agent_actions row per call ─▶ inbox
                                         │
                 a teammate approves / edits / rejects (TicketPolicy)
                                         │
             when every call in that paused turn has a decision:
                                         ▼
        ResumeAgentRun: SupportAgent::continue($id)->prompt(Decisions::from([...]))
                                         │
                                         ▼
          approved tools run, rejected ones return the note, the model continues
```

The pieces, in the order a request touches them:

| File | Role |
|---|---|
| `app/Ai/Agents/TriageAgent.php` | Classifies a ticket with `HasStructuredOutput`. Uses the provider's cheapest model. |
| `app/Ai/Agents/SupportAgent.php` | `Conversational`, `HasTools`, `HasSkills`, `HasMiddleware`. One conversation per ticket, with the `Ticket` model as the participant, and every tool built for that ticket. `#[MaxSteps(8)]`, prompt caching attributes, the budget middleware and a `Stop` middleware token cap. |
| `app/Ai/Middleware/EnforceAiBudget.php` | Before each model call, stops if the provider's daily limit is spent; after it, records the call's cost. |
| `app/Services/AiRouting.php`, `AiBudget.php` | Provider order, keys and limits; per-provider daily spend in micro-dollars. |
| `app/Services/LiveAccess.php` | Demo mode: only a session that entered the access code may start live runs. |
| `app/Ai/Tools/*` | Read tools (`SearchTickets`, `GetTicket`, `LookupCustomer`, `SearchKnowledgeBase`) and write tools (`UpdateTicket`, `PostReply`, `IssueRefund`) that implement `Approvable`. |
| `app/Listeners/RecordAgentActivity.php` | Turns `ToolApprovalRequested` into inbox rows on the conversation's own ticket, and writes outcomes from `ToolApprovalResolved`. |
| `app/Services/ApprovalService.php` | Authorizes and records a decision; resumes only when the whole paused turn is decided. |
| `app/Services/AgentRunner.php` | The only place that talks to the agents: triage, chat, resume. Checks the budget and run cap first, and claims a paused run atomically so two workers cannot resume it twice. |
| `app/Policies/TicketPolicy.php` | Admins work any ticket; teammates work their own and unassigned ones. |
| `app/Livewire/*` | Ticket list, ticket page with the agent panel, approval inbox, activity log, skills. |

### Things worth knowing about the SDK

These came from reading `laravel/ai` 1.3.0's source while building this:

- **`continue()` does not check who owns a conversation.** Anyone who can call it with an id can resume someone else's run, so `ApprovalService` authorizes the decider before anything resumes.
- **Every paused call needs a decision.** If the model proposed two writes in one turn, resuming with a decision for only one throws `ApprovalMismatchException`. The inbox stores each call separately and resumes once the last one is decided.
- **A rejection without a message ends the run;** a rejection with a message is sent back to the model and the run continues. TriageDesk always sends a message, including the approver's note when there is one.
- **Approved results are stored before the model continues.** If the model call after an approval fails, sending the same decisions again throws; the ticket shows the error and the teammate sends a normal chat message instead.
- **Approval gating is re-checked on resume** against the original arguments, so editing a $149 refund down to $40 still needs the approval you are giving it.
- **New conversations cost an extra model call** by default, to generate a title. TriageDesk turns that off (`ai.conversations.generate_title`) because it has one conversation per ticket.
- **Faked agents do not execute approved tools on resume.** `SupportAgent::fake()` is fine for testing prompts, but the approval tests fake the provider's HTTP API instead, so the SDK's real tool loop, pause and resume all run.

## Tests

```bash
php artisan test
```

No API key, no network: `Http::preventStrayRequests()` fails any request that is not faked. `tests/Concerns/FakesAnthropic.php` scripts model turns (tool calls and text) at the HTTP layer, which covers the full approve, edit and reject paths, the per-turn resume rule, the auto-approved small refund, authorization, rate limits, the token cap, pricing, per-provider limits, routing, the access code and recorded runs. `tests/Feature/SecurityRegressionTest.php` reproduces each finding of a security review (split refunds, cross-ticket writes, a refund race, image exfiltration and CSP, unauthorized re-triage, resume budget and double resume, debug and password-free sign-in). CI runs Pint and the suite on every push (`.github/workflows/tests.yml`).

## Deploying

See [docs/deploy-lightsail.md](docs/deploy-lightsail.md) for a single $12/month AWS Lightsail instance running Nginx, PHP-FPM, the queue worker and SQLite, with a spend limit on the model provider.

The public demo runs in Docker next to another app on a shared Lightsail instance: see [docs/deploy-docker.md](docs/deploy-docker.md).

## Public demo settings

With `DEMO_MODE=true`, visitors are in **replay mode**: they browse real runs recorded earlier, and nothing they do calls a model. Entering `DEMO_ACCESS_CODE` in the bar at the top switches that browser session to **live mode**. Without an access code nobody can start a live run.

| Setting | Default | What it does |
|---|---|---|
| `DEMO_ACCESS_CODE` | blank | Code that unlocks live runs; 5 wrong tries per visitor per 15 minutes |
| `DEMO_LIVE_RUNS_PER_DAY` | 400 | Site-wide live runs per day (a triage, a chat message or a resumed approval each count as one) |
| `DEMO_CHATS_PER_IP_PER_DAY` | 15 | Chat messages per visitor per day |
| `DEMO_TRIAGES_PER_IP_PER_DAY` | 10 | Simulated tickets and manual triage runs per visitor per day |
| `AI_RUN_TOKEN_LIMIT` | 40000 | Stops a single agent run after this many tokens (applies outside demo mode too) |

Record the runs visitors see with `php artisan triagedesk:record --fresh`: it resets the data, triages six seed tickets for real, approves what the agent proposes as the admin, and saves the result to `DEMO_RECORDINGS_PATH`. `php artisan triagedesk:reset-demo` (run nightly) restores the seed data and those recorded runs while keeping the day's spend counters. Refunds and replies only change the demo database; nothing is charged or emailed.

Password-free sign-in only works with `DEMO_MODE=true`, whatever `APP_ENV` is. `.env.example` ships with `APP_ENV=production` and `APP_DEBUG=false`.

A refund runs without approval only while it, plus what was already refunded on that payment and what was auto-refunded to the same customer in the last `REFUND_AUTO_APPROVE_WINDOW_DAYS` (30) days on any ticket, stays within `REFUND_AUTO_APPROVE_LIMIT` ($50). If today's budget runs out between an approval and the run resuming, the approved actions go back to pending with a note on the ticket; approve them again once the budget resets.

## License

MIT
