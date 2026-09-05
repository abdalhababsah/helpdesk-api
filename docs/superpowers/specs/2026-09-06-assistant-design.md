# Support assistant: design

A conversational assistant on every page of the helpdesk. It answers questions from a knowledge base, and for signed-in people it gathers an issue, checks for an existing ticket, decides a priority, and drafts a ticket the person confirms. Tickets for the User role are raised only through it.

Built on the Laravel AI SDK (`laravel/ai`) with Anthropic as the only provider.

## Decisions

| Decision | Choice | Why |
|---|---|---|
| Placement | Floating panel on every page, mounted once at the app root | The conversation must survive navigation and serve guests and signed-in people alike |
| Knowledge | `knowledge_articles` table, keyword search, managed by admins | MySQL has no vector search; articles must be editable without a deploy |
| Tracking | Metrics block on `/admin` plus a conversations browser with transcripts | Makes the assistant auditable; per-ticket transcript is what moderators need |
| Replies | Turn-based JSON, not streamed | Cards come from validated structured output; replies are short |
| Manual creation | Removed for the User role. Moderators and admins keep `POST /tickets` | The assistant is the user's channel; the brief's endpoint stays for RBAC evaluation |
| Provider | Anthropic only, `ANTHROPIC_API_KEY` | Single vendor, simplest configuration |
| Agent structure | Orchestrator with specialist sub-agents as tools | Separation enforced by the framework, each agent testable alone |

## Agents

Three agent classes under `app/Ai/Agents`. All use `Promptable`. Model attributes: `#[Provider(Lab::Anthropic)]`, `#[Model('claude-haiku-4-5-20251001')]`, `#[CacheInstructions]`, `#[CacheToolDefinitions]`, `#[MaxSteps(8)]`, `#[Timeout(60)]`.

### Concierge

Owns the conversation. Implements `Agent, Conversational, HasTools, HasStructuredOutput` and uses `RemembersConversations`.

Constructed with an `AssistantContext` value object: `participant` (guest or user), `user` (nullable), `openTicketCount`, `categories`. Instructions are built from one template with the auth state switched in, so guest and user behaviour is one code path.

Responsibilities:

- Greet, keep the tone plain, never invent facts about the organisation.
- For a question, call the Knowledge agent and relay its answer. If Knowledge does not know, say so and offer to raise a ticket (signed in) or to sign in (guest).
- For a problem, or when the person asks for a human, call the Triage agent. Triage returns either a draft or an existing ticket reference; the Concierge turns that into the card.
- For a guest who wants a ticket or human help, return the `sign_in_required` card and explain why.
- Never claim a ticket was created. Creation happens outside the model.

Tools: `KnowledgeAgent`, `TriageAgent` (both `CanActAsTool`). Triage is only in the tool list when the participant is a user.

Structured output schema:

```
reply:  string, required. What the person reads.
card:   anyOf, required
  { type: 'none' }
  { type: 'sign_in_required' }
  { type: 'existing_ticket', ticketId: string }
  { type: 'ticket_draft',
    subject: string (5..120),
    description: string (10..4000),
    categoryId: string,
    priority: 'low' | 'medium' | 'high' | 'urgent',
    reason: string (why this priority, one sentence) }
```

The server validates the card after the model returns it: `ticketId` must be one of the person's own tickets; `categoryId` must be an active category. A card that fails validation is downgraded to `none` and the failure is logged, so a bad model output never reaches the browser.

### KnowledgeAgent

`Agent, CanActAsTool, HasTools`. Name `knowledge_base`. Answers only from what `SearchKnowledge` returns and says "I do not have that" otherwise. Cites the article title in prose. No conversation memory of its own: it receives the question from the Concierge.

Tool: `SearchKnowledge(query)`. Full-text search over active `knowledge_articles` (title, body, keywords), top 5, returned as title plus body.

### TriageAgent

`Agent, CanActAsTool, HasTools`. Name `triage`. Given the conversation so far (passed in the tool call by the Concierge), it:

1. Decides whether the description is enough to act on. If not, it returns the question to ask. The Concierge relays it.
2. Calls `FindOpenTickets(query)` with the gist of the issue. If a match is returned with a similarity the agent judges to be the same issue, it returns `existing: {ticketId}`.
3. Otherwise picks a category from `ListCategories`, decides a priority using the rules in its instructions (urgent: cannot work at all or security; high: blocked on a deadline; medium: degraded; low: cosmetic or a question), and returns the draft.

Its output is structured too, as a union of `ask`, `existing`, `draft`, so the Concierge never parses prose.

Tools: `FindOpenTickets(query)` restricted to the participant's own tickets in `open` or `in_progress`, full-text on subject and description, top 3 with id, subject, status, created date. `ListCategories()` returns active categories with id and name.

## Ticket creation

The model drafts. The person confirms. The server creates.

1. The `ticket_draft` card renders with the subject and description as editable inputs, the category as a select defaulting to the draft, the priority shown as a badge with the reason, and a submit button.
2. Submit calls `POST /assistant/conversations/{id}/tickets` with `{ subject, description, categoryId }`. The person cannot change the priority in the card. The server takes it from the last draft it issued, which is stored on the session as `last_draft` when the card is returned, so a client cannot choose its own priority.
3. Action `RaiseTicketFromConversation`:
   - the conversation must belong to the caller, who must be signed in and active;
   - creates the ticket through the existing `CreateTicket` action with `source = assistant` and `conversation_id`;
   - appends a fixed assistant message to the conversation without calling the model: "Your ticket has been raised. A moderator will be in touch. You can follow it under Your tickets.";
   - sets the session outcome to `ticket_raised` and stores the ticket id;
   - records `ticket.created` in the action log as today, with `source` in the properties;
   - returns the ticket.
4. The panel shows the fixed message and a link to the ticket.

The User role's `ticket:create` grant is removed from the matrix. `CreateTicket` keeps its permission check for moderators and admins; `RaiseTicketFromConversation` authorises by conversation ownership instead and calls the internal creation path. `POST /tickets` therefore returns 403 for the User role, and the access sweep asserts it.

## Duplicate detection

Triage's `FindOpenTickets` searches only the participant's own open and in-progress tickets. The agent decides whether a hit is the same issue. If so the Concierge returns `existing_ticket`, the card shows subject, status, and when it was raised, with a link to `/tickets/{id}`. The person can say it is a different issue, and Triage continues with a draft.

## Persistence and identity

Tables from the SDK: `agent_conversations`, `agent_conversation_messages`. Never altered.

New table `assistant_sessions`:

| Column | Type | Notes |
|---|---|---|
| id | ulid | |
| conversation_id | ulid, unique | FK to `agent_conversations.id` |
| participant_type, participant_id | morph | `user` or `assistant_guest` |
| outcome | enum | `open`, `answered`, `ticket_raised`, `abandoned` |
| ticket_id | ulid, nullable | FK to tickets, null on delete |
| turns | unsigned int | user messages sent |
| input_tokens, output_tokens | unsigned int | summed from responses |
| last_agent | string, nullable | which sub-agent handled the last turn |
| last_draft | json, nullable | the most recent `ticket_draft` card, source of the priority on creation |
| last_activity_at | datetime(3) | |
| created_at, updated_at | datetime(3) | |

Outcome rules: `open` while active; `answered` when the person's last message was followed by a Knowledge reply and nothing else for 30 minutes; `ticket_raised` on creation; `abandoned` when `open` for 24 hours without a ticket. A scheduled command `assistant:settle-sessions` applies the time-based rules.

New table `assistant_guests`: `id` ulid, `last_seen_at`, timestamps. Guests are the participant for anonymous conversations. The browser holds the guest id in localStorage and sends it as `X-Assistant-Guest`. A guest can only reach conversations that belong to its own guest id.

Claiming: when a guest signs in, the client calls `POST /assistant/conversations/{id}/claim` with the guest header. The session's participant becomes the user, the guest row is kept, and the Concierge's next turn sees the user context. Only sessions in outcome `open` can be claimed.

## Transcript on the ticket

`tickets` gains `source` enum (`direct`, `assistant`) and `conversation_id` (nullable ulid, FK). Ticket detail gains `source` always, and `transcript` (ordered messages with role, text, timestamp) only when the actor holds `ticket:list_queue`. The web renders the transcript as a collapsed "Conversation with the assistant" block at the top of the thread. The requester sees a "Raised through the assistant" note instead.

## Knowledge base

Table `knowledge_articles`: `id` ulid, `title` (120), `body` text, `keywords` string (255, comma separated), `category_id` nullable FK, `is_active` bool, timestamps. Full-text index on title, body, keywords.

Seeded with about 24 articles: eight per existing category, plus general ones about the desk (hours, how tickets move, what priorities mean, how to reach a human) that carry no category.

Permission `knowledge:manage`, Admin only. Actions: `CreateArticle`, `UpdateArticle`, `SetArticleActive`. Each records to the action log.

## Endpoints

| Method | Path | Who | Notes |
|---|---|---|---|
| POST | `/assistant/conversations` | anyone | Creates a session for the caller or the guest header. Returns id and greeting |
| GET | `/assistant/conversations/{id}` | participant, or `metrics:read` | Session facts and messages |
| POST | `/assistant/conversations/{id}/messages` | participant | Body `{ message }`. Returns `{ reply, card, session }` |
| POST | `/assistant/conversations/{id}/tickets` | signed-in participant | Body `{ subject, description, categoryId }`. Returns the ticket |
| POST | `/assistant/conversations/{id}/claim` | signed in, with guest header | Moves the session to the user |
| GET | `/assistant/conversations` | `metrics:read` | Paginated, filters `outcome`, `participant`, `from`, `to` |
| GET | `/knowledge` | `knowledge:manage` | Paginated, `includeRetired` |
| POST | `/knowledge` | `knowledge:manage` | |
| PATCH | `/knowledge/{id}` | `knowledge:manage` | Title, body, keywords, category, active |
| GET | `/metrics` | `metrics:read` | Gains `assistant: { conversations, answered, ticketsRaised, deflectionRate }` for the last 30 days |

Rate limits: 20 messages per 10 minutes per participant, 5 new conversations per hour per guest id or user. Guest sessions untouched for 24 hours are settled as `abandoned`.

Errors follow the existing shape. New codes: `ASSISTANT_UNAVAILABLE` (502) when the provider fails after one retry, `CONVERSATION_CLOSED` (409) when messaging a settled session.

## Frontend

### State

`assistant` slice: `open`, `conversationId`, `messages` (role, text, card, createdAt), `pending`, `error`. `conversationId` and the guest id mirror to localStorage under one key. On boot the slice reads the key; if a conversation id exists, `GET /assistant/conversations/{id}` restores the messages. A 404 clears the key.

RTK Query endpoints in `src/api/assistant.api.ts`: `createConversation`, `getConversation`, `sendMessage`, `raiseTicket`, `claimConversation`, `listConversations`, and `src/api/knowledge.api.ts` for articles. `raiseTicket` invalidates `TicketList` and `Summary`.

On login, if a guest conversation is open, the app calls `claim` once and keeps the panel state.

### Panel

`src/features/assistant/`:

- `AssistantLauncher`: the fixed button, bottom right, with the role tint when signed in. Shown on every page.
- `AssistantPanel`: a bottom-right sheet on tablet and up, full screen on phones. Header with title and close, the message list, the composer. Mounted once in `App.tsx` outside the router outlet.
- `MessageList`, `Message`: user and assistant bubbles in the existing chat styles.
- `cards/`: `TicketDraftCard` (form, zod validation, submits to `raiseTicket`), `ExistingTicketCard` (link to the ticket), `SignInCard` (Sign in and Create an account buttons; the second opens a card explaining that an administrator creates accounts, since there is no self-registration).
- `Composer`: textarea, Enter to send, Shift+Enter for a new line, disabled while pending.

The New ticket modal, the `?new=1` route and the header button go. The rail's "New ticket" becomes "Ask for help" and opens the panel. The empty state on Your tickets points to the assistant.

### Admin

- `/admin/knowledge`: table with title, category, active, updated; New article modal; edit in a modal; retire and restore. Search box.
- `/admin/conversations`: table with participant, started, turns, outcome, last agent, ticket; outcome filter; row opens `/admin/conversations/:id`.
- `/admin/conversations/:id`: read-only transcript with the session facts and a link to the ticket if one was raised.
- `/admin`: an Assistant block with four stats.

Rail for Admin gains Knowledge and Conversations under Manage.

## Errors

- Provider failure: the API retries once, then returns `ASSISTANT_UNAVAILABLE`. The panel shows "The assistant is not available right now" inline with a retry, and the person's message stays in the composer.
- Card validation failure on the server: downgraded to `none`, logged with the raw card, the reply still delivered.
- Ticket creation failure: the card stays editable and shows the server's field errors inline.
- A conversation the person no longer owns (signed out, different guest): 403, the panel starts a fresh conversation.

## Testing

API, all with `AgentFake` so nothing calls Anthropic:

- Concierge card validation: each union member accepted, invalid ticket id and category downgraded.
- Guest gating: Triage absent from a guest's tool list; `sign_in_required` returned when stubbed.
- `RaiseTicketFromConversation`: ownership, source and conversation id set, fixed message appended, outcome set, action logged, the User role's direct `POST /tickets` refused.
- Claim: guest session moves to the user; a settled session cannot be claimed; a guest cannot claim another guest's session.
- Knowledge search ranks by relevance and excludes retired articles.
- Sessions settle to `answered` and `abandoned` on schedule.
- Access sweep covers every new route.
- One live test against Anthropic behind `ASSISTANT_LIVE_TESTS=1`, skipped by default.

Web: unit tests for the assistant slice reducer and the localStorage mirror, the card zod schemas, and the draft form mapping. Panel flows verified in the browser against the running API with a real key.

## Out of scope

- Streaming replies.
- Embeddings or vector search.
- Attachments in the chat.
- Assistant actions on tickets (replying, closing) beyond drafting a new one.
- Self-registration. The sign-in card explains that an administrator creates accounts.
