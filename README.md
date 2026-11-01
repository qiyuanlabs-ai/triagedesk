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
| Model provider | Anthropic by default; OpenAI, Gemini and the rest of the SDK's providers by changing one env var |

## What it does

- **Automatic triage.** A new ticket goes through `TriageAgent`, which returns structured output (category, priority, sentiment, one-line summary) that is written straight onto the ticket.
- **Proposals.** `SupportAgent` then investigates with read-only tools and proposes actions with write tools. You can also chat with it on any ticket.
- **Approval inbox.** Every proposed write appears as a card showing the tool, its arguments, the agent's reason and why it needs a human. Approve it, edit the arguments and approve, or reject it with a note the agent reads.
- **Amount-based approval.** `IssueRefund` runs at once for refunds up to $50 (`REFUND_AUTO_APPROVE_LIMIT`) and asks for approval above that.
- **Skills.** Two file skills (`resources/skills`) and per-team skills loaded from the database by a closure. The agent sees only names and descriptions until it loads one.
- **Activity log.** Every write the agent proposed or ran, who decided, and the result.
- **Demo guardrails.** Per-IP daily limits, a per-run token cap, a site-wide daily token budget and a nightly reset.

## Quick start

Requirements: PHP 8.3+ with the `pdo_sqlite` extension, Composer, Node 20.19+ (for Vite).

```bash
git clone <your fork> triagedesk && cd triagedesk
composer run setup          # installs, creates .env, migrates and seeds, builds assets
```

Add a key for your model provider to `.env`:

```dotenv
AI_PROVIDER=anthropic
ANTHROPIC_API_KEY=sk-ant-...
```

Then start the server, queue worker and Vite together:

```bash
composer run dev
```

Open http://localhost:8000 and sign in as one of the three seeded teammates (no passwords: it is a demo).

Things to try:

1. On **Tickets**, press **Simulate a new ticket**. The queue triages it within a few seconds and the agent's proposals appear in the **Approval inbox**.
2. Open an untriaged ticket and press **Run triage**.
3. Open a "You charged me TWICE" ticket from a Business customer and ask the agent: *Refund the duplicate charge and reply to the customer.* The $149 refund and the reply wait for you; a $49 Team duplicate refunds straight away.
4. Reject a proposal with a note ("check with billing first") and watch the agent adjust.
5. Sign in as Jordan and try to approve an action on Sam's ticket.

### Switching providers

`AI_PROVIDER` picks any provider configured in `config/ai.php`:

```dotenv
AI_PROVIDER=openai
OPENAI_API_KEY=sk-...

# or
AI_PROVIDER=gemini
GEMINI_API_KEY=...
```

Leave `AI_TRIAGE_MODEL` and `AI_SUPPORT_MODEL` blank to use the provider's cheapest model for triage and its default model for the chat, or set them to specific model ids.

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
| `app/Ai/Agents/SupportAgent.php` | `Conversational`, `HasTools`, `HasSkills`, `HasMiddleware`. One conversation per ticket, with the `Ticket` model as the participant. `#[MaxSteps(8)]`, prompt caching attributes and a `Stop` middleware token cap. |
| `app/Ai/Tools/*` | Read tools (`SearchTickets`, `GetTicket`, `LookupCustomer`, `SearchKnowledgeBase`) and write tools (`UpdateTicket`, `PostReply`, `IssueRefund`) that implement `Approvable`. |
| `app/Listeners/RecordAgentActivity.php` | Turns `ToolApprovalRequested` into inbox rows, writes outcomes from `ToolApprovalResolved`, and counts tokens for the daily budget. |
| `app/Services/ApprovalService.php` | Authorizes and records a decision; resumes only when the whole paused turn is decided. |
| `app/Services/AgentRunner.php` | The only place that talks to the agents: triage, chat, resume. |
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

35 tests, no API key, no network: `Http::preventStrayRequests()` fails any request that is not faked. `tests/Concerns/FakesAnthropic.php` scripts model turns (tool calls and text) at the HTTP layer, which covers the full approve, edit and reject paths, the per-turn resume rule, the auto-approved small refund, authorization, rate limits, the token cap and the daily budget. CI runs Pint and the suite on every push (`.github/workflows/tests.yml`).

## Deploying

See [docs/deploy-lightsail.md](docs/deploy-lightsail.md) for a single $12/month AWS Lightsail instance running Nginx, PHP-FPM, the queue worker and SQLite, with a spend limit on the model provider.

The public demo runs in Docker next to another app on a shared Lightsail instance: see [docs/deploy-docker.md](docs/deploy-docker.md).

## Public demo settings

With `DEMO_MODE=true`:

| Setting | Default | What it does |
|---|---|---|
| `DEMO_CHATS_PER_IP_PER_DAY` | 20 | Chat messages per visitor per day |
| `DEMO_TRIAGES_PER_IP_PER_DAY` | 20 | Simulated tickets and manual triage runs per visitor per day |
| `AI_RUN_TOKEN_LIMIT` | 40000 | Stops a single agent run after this many tokens (applies outside demo mode too) |
| `AI_DAILY_TOKEN_BUDGET` | 1000000 | Site-wide tokens per day; after that the app is browse-only until midnight (server time) |

The scheduler also runs `migrate:fresh --seed` every night so each day starts from the same data. Refunds and replies only change the demo database; nothing is charged or emailed.

## License

MIT
