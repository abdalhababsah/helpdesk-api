<?php

namespace Database\Seeders;

use App\Enums\RoleSlug;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Category;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TicketSeeder extends Seeder
{
    private const COUNT = 200;

    private const DAYS_BACK = 90;

    private const SOFT_DELETED = 5;

    /** Share of unresolved tickets still inside their SLA. */
    private const WITHIN_SLA_PERCENT = 80;

    /** Fixed so a rerun and a grader's run produce the same dataset. */
    private const RANDOM_SEED = 20260905;

    /** @var array<string, list<array{0: string, 1: string}>> */
    private const ISSUES = [
        'it-hardware' => [
            ['Laptop battery not charging', 'Battery sits at 0% even when the charger is connected. The charging light does not come on.'],
            ['External monitor flickers on the dock', 'Screen flickers every few seconds when connected through the docking station. Direct HDMI is fine.'],
            ['Keyboard keys unresponsive', 'The E, R and T keys stopped registering this morning. An external keyboard works normally.'],
            ['Docking station not detecting ethernet', 'No wired network through the dock. Wifi works, so it is not the account.'],
            ['Printer on floor 3 jams constantly', 'Paper jams on every job over two pages. Cleared the tray twice already.'],
            ['Laptop fan running at full speed', 'Fan is loud and constant even when idle. The machine is noticeably hot underneath.'],
            ['Webcam not detected in meetings', 'Camera shows as unavailable. Device manager lists it with a warning icon.'],
            ['Request second monitor for new starter', 'New joiner starts Monday and needs a second display set up at desk 4B.'],
        ],
        'it-access' => [
            ['VPN will not connect from home', 'Connection fails at the handshake step with a timeout. Works fine on the office network.'],
            ['Locked out after password reset', 'Reset the password this morning and now every login attempt says the credentials are invalid.'],
            ['MFA device replaced, need re-enrolment', 'Got a new phone and no longer have the authenticator codes for my account.'],
            ['Cannot reach the shared finance drive', 'Access denied when opening the finance folder. Colleagues on the same team can open it.'],
            ['SSO redirect loop on the reporting tool', 'Signing in bounces between the login page and the dashboard until it errors.'],
            ['VPN drops every twenty minutes', 'Connection holds for about twenty minutes then drops and needs a manual reconnect.'],
            ['Need access to the deployment console', 'Joining the release rota next sprint and need permission to view deployments.'],
            ['Guest wifi password not working', 'Visiting auditors cannot get onto the guest network with the password we were given.'],
        ],
        'hr-payroll' => [
            ['Payslip missing overtime hours', 'Worked twelve approved overtime hours last month and they are not on the payslip.'],
            ['Tax code looks incorrect', 'Deductions jumped this month and the tax code on the payslip changed without notice.'],
            ['Expense reimbursement not received', 'Submitted travel expenses six weeks ago, approved, but nothing has been paid.'],
            ['Leave balance shows wrong number of days', 'Portal shows four days remaining, my own records and my manager both say nine.'],
            ['Bank details update not applied', 'Updated my account details before the cut-off and the payment went to the old account.'],
            ['Bonus not reflected in this cycle', 'Annual bonus was confirmed in writing but does not appear on the payslip.'],
            ['Pension contribution rate change', 'Asked to raise my contribution to eight percent, still showing five.'],
            ['Maternity leave pay query', 'Need a breakdown of how statutory and company maternity pay combine for my start date.'],
        ],
    ];

    public function run(): void
    {
        // Re-running must not duplicate the demo set. A fresh dataset comes
        // from migrate:fresh --seed rather than from re-running this seeder.
        if (Ticket::withTrashed()->exists()) {
            return;
        }

        mt_srand(self::RANDOM_SEED);

        $categories = Category::pluck('id', 'slug');
        $requesters = User::whereRelation('role', 'slug', RoleSlug::User->value)
            ->where('is_active', true)->pluck('id')->all();
        $agents = User::whereRelation('role', 'slug', '!=', RoleSlug::User->value)
            ->where('is_active', true)->pluck('id')->all();

        $now = Carbon::now();

        DB::transaction(function () use ($categories, $requesters, $agents, $now): void {
            foreach (range(1, self::COUNT) as $i) {
                $slug = array_rand(self::ISSUES);
                [$subject, $description] = self::ISSUES[$slug][array_rand(self::ISSUES[$slug])];

                $priority = TicketPriority::from($this->weighted([
                    'low' => 25, 'medium' => 40, 'high' => 25, 'urgent' => 10,
                ]));
                $status = TicketStatus::from($this->weighted([
                    'open' => 35, 'in_progress' => 25, 'resolved' => 25, 'closed' => 15,
                ]));

                // Unresolved tickets are aged relative to their own SLA rather
                // than over a fixed window, so the share that reads as overdue
                // is a deliberate number instead of a side effect of the window
                // being wider than the deadlines. Finished tickets are spread
                // over the full period, since their age no longer affects it.
                $createdAt = $status->isFinished()
                    ? $now->copy()->subMinutes(mt_rand(
                        ($status === TicketStatus::Closed ? 7 : 3) * 24 * 60,
                        self::DAYS_BACK * 24 * 60,
                    ))
                    : $now->copy()->subMinutes((int) (
                        $priority->slaHours() * 60 * (mt_rand(1, 100) <= self::WITHIN_SLA_PERCENT
                            ? mt_rand(5, 95) / 100
                            : mt_rand(105, 260) / 100)
                    ));
                $dueAt = $createdAt->copy()->addHours($priority->slaHours());

                // Open tickets are mostly unassigned so the queue has something
                // to triage; anything past open must have an owner.
                $assigneeId = match (true) {
                    $status === TicketStatus::Open => mt_rand(1, 10) <= 3 ? $agents[array_rand($agents)] : null,
                    default => $agents[array_rand($agents)],
                };

                $resolvedAt = null;
                $closedAt = null;
                if ($status->isFinished()) {
                    // Spread either side of the SLA so both met and breached
                    // resolutions appear in the average.
                    $resolvedAt = $createdAt->copy()
                        ->addMinutes(mt_rand(60, (int) ($priority->slaHours() * 60 * 1.6)));
                    // Clamp back into the window between creation and now.
                    // Subtracting a fixed offset from now instead would land
                    // before creation for a ticket raised in the last few hours.
                    $resolvedAt = $this->clampBetween($resolvedAt, $createdAt, $now);

                    if ($status === TicketStatus::Closed) {
                        $closedAt = $this->clampBetween(
                            $resolvedAt->copy()->addHours(mt_rand(1, 48)), $resolvedAt, $now
                        );
                    }
                }

                $ticket = new Ticket;
                $ticket->forceFill([
                    // Derived from created_at so the id stays a creation-order
                    // sort key, which the list query relies on as a tiebreaker.
                    'id' => strtolower((string) Str::ulid($createdAt)),
                    'subject' => $subject,
                    'description' => $description,
                    'status' => $status,
                    'priority' => $priority,
                    'category_id' => $categories[$slug],
                    'requester_id' => $requesters[array_rand($requesters)],
                    'assignee_id' => $assigneeId,
                    'due_at' => $dueAt,
                    'resolved_at' => $resolvedAt,
                    'closed_at' => $closedAt,
                    'created_at' => $createdAt,
                    'updated_at' => $closedAt ?? $resolvedAt ?? $createdAt,
                ])->save();

                $this->seedComments($ticket, $status, $assigneeId, $resolvedAt ?? $now, $now);

                if ($i <= self::SOFT_DELETED) {
                    $ticket->delete();
                }
            }
        });
    }

    private function seedComments(Ticket $ticket, TicketStatus $status, ?string $assigneeId, Carbon $until, Carbon $now): void
    {
        $count = $status === TicketStatus::Open && $assigneeId === null
            ? mt_rand(0, 1)
            : mt_rand(1, 4);

        $bodies = [
            'Thanks for raising this, taking a look now.',
            'Could you confirm which building you are in?',
            'Tried the suggested steps, no change so far.',
            'Reproduced it here. Escalating to the network team.',
            'Replacement is on order, should arrive within two days.',
            'This is resolved on my side, closing unless you see it again.',
            'Adding the reference number for the supplier ticket.',
            'Any update on this? It is blocking my work.',
        ];

        $window = max(1, (int) $ticket->created_at->diffInMinutes($until));

        for ($i = 0; $i < $count; $i++) {
            // Alternate sides so a thread reads as a conversation rather than
            // a monologue. Unassigned tickets have nobody to answer yet.
            $authorId = ($i % 2 === 0 && $assigneeId !== null)
                ? $assigneeId
                : $ticket->requester_id;

            $at = $ticket->created_at->copy()->addMinutes((int) ($window * ($i + 1) / ($count + 1)));
            if ($at->greaterThan($now)) {
                $at = $now->copy()->subMinutes(mt_rand(1, 30));
            }

            $comment = new TicketComment;
            $comment->forceFill([
                'id' => strtolower((string) Str::ulid($at)),
                'ticket_id' => $ticket->id,
                'author_id' => $authorId,
                'body' => $bodies[array_rand($bodies)],
                'created_at' => $at,
                'updated_at' => $at,
            ])->save();
        }
    }

    /**
     * Pull a moment back inside [$after, $ceiling], keeping it strictly after
     * $after so an event can never predate the thing it followed.
     */
    private function clampBetween(Carbon $moment, Carbon $after, Carbon $ceiling): Carbon
    {
        if ($moment->lessThanOrEqualTo($ceiling)) {
            return $moment;
        }

        $span = max(1, (int) $after->diffInMinutes($ceiling));

        return $after->copy()->addMinutes(mt_rand(1, $span));
    }

    /**
     * @param  array<string, int>  $weights
     */
    private function weighted(array $weights): string
    {
        $roll = mt_rand(1, array_sum($weights));

        foreach ($weights as $key => $weight) {
            $roll -= $weight;
            if ($roll <= 0) {
                return $key;
            }
        }

        return array_key_first($weights);
    }
}
