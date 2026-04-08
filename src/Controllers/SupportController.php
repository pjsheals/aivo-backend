<?php

declare(strict_types=1);

namespace Aivo\Controllers;

/**
 * Support ticketing system.
 *
 * Tables (auto-created on first use):
 *   support_tickets        — one row per ticket
 *   support_ticket_messages — threaded messages per ticket
 *
 * Routes to add to routes/api.php:
 *   GET  /api/support/tickets     → SupportController@myTickets   (auth required)
 *   POST /api/support/ticket      → SupportController@createTicket (auth required)
 *   POST /api/support/reply       → SupportController@userReply    (auth required)
 *   GET  /api/admin/tickets       → SupportController@adminTickets  (superadmin)
 *   POST /api/admin/ticket/reply  → SupportController@adminReply    (superadmin)
 *   POST /api/admin/ticket/status → SupportController@adminStatus   (superadmin)
 */
class SupportController
{
    private const SUPERADMIN_EMAILS = [
        'paul@aivoedge.net',
        'tim@aivoedge.net',
        'paul@aivoevidentia.com',
    ];

    private const NOTIFY_EMAIL = 'paul@aivoedge.net';
    private const FROM_EMAIL   = 'edge@aivoedge.net';
    private const FROM_NAME    = 'AIVO Optimize Support';

    private const VALID_CATEGORIES = ['billing', 'technical', 'results', 'account', 'general'];
    private const VALID_STATUSES   = ['open', 'replied', 'closed'];

    // ── Table bootstrap ──────────────────────────────────────────
    private function ensureTables(): void
    {
        $db = \Illuminate\Database\Capsule\Manager::getConnection();

        $db->statement("
            CREATE TABLE IF NOT EXISTS support_tickets (
                id           SERIAL PRIMARY KEY,
                user_id      INTEGER,
                user_email   VARCHAR(255) NOT NULL,
                user_name    VARCHAR(255),
                ref          VARCHAR(30) UNIQUE NOT NULL,
                subject      VARCHAR(500) NOT NULL,
                category     VARCHAR(100) DEFAULT 'general',
                status       VARCHAR(50)  DEFAULT 'open',
                priority     VARCHAR(50)  DEFAULT 'normal',
                created_at   TIMESTAMP    DEFAULT NOW(),
                updated_at   TIMESTAMP    DEFAULT NOW()
            )
        ");

        $db->statement("
            CREATE TABLE IF NOT EXISTS support_ticket_messages (
                id          SERIAL PRIMARY KEY,
                ticket_id   INTEGER NOT NULL REFERENCES support_tickets(id) ON DELETE CASCADE,
                sender      VARCHAR(10) NOT NULL,
                sender_name VARCHAR(255),
                message     TEXT NOT NULL,
                created_at  TIMESTAMP DEFAULT NOW()
            )
        ");
    }

    // ── Auth helpers ─────────────────────────────────────────────
    private function requireAuth(): object
    {
        $headers = getallheaders();
        $auth    = $headers['Authorization'] ?? $headers['authorization'] ?? '';
        if (!str_starts_with($auth, 'Bearer ')) abort(403, 'Forbidden');
        $token = trim(substr($auth, 7));
        if (!$token) abort(403, 'Forbidden');

        $user = \Illuminate\Database\Capsule\Manager::table('users')
            ->where('session_token', $token)
            ->where('session_expires', '>', now())
            ->first();

        if (!$user) abort(403, 'Forbidden');
        return $user;
    }

    private function requireSuperadmin(): object
    {
        $user = $this->requireAuth();
        if (!in_array(strtolower($user->email ?? ''), self::SUPERADMIN_EMAILS, true)) {
            abort(403, 'Forbidden');
        }
        return $user;
    }

    // ── Ticket reference generator ───────────────────────────────
    private function generateRef(): string
    {
        $prefix = 'TKT-' . date('Ym') . '-';
        $last   = \Illuminate\Database\Capsule\Manager::table('support_tickets')
            ->where('ref', 'like', $prefix . '%')
            ->count();
        return $prefix . str_pad((string)($last + 1), 4, '0', STR_PAD_LEFT);
    }

    // ── Email sender (via Resend REST API) ───────────────────────
    private function sendEmail(string $to, string $toName, string $subject, string $html): void
    {
        $apiKey = $_ENV['RESEND_API_KEY'] ?? getenv('RESEND_API_KEY') ?? '';
        if (!$apiKey) return; // Silently skip if not configured

        $payload = json_encode([
            'from'    => self::FROM_NAME . ' <' . self::FROM_EMAIL . '>',
            'to'      => [$toName ? "{$toName} <{$to}>" : $to],
            'subject' => $subject,
            'html'    => $html,
        ]);

        $ch = curl_init('https://api.resend.com/emails');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT        => 8,
        ]);
        curl_exec($ch);
        curl_close($ch);
    }

    // ── GET /api/support/tickets ─────────────────────────────────
    // Returns all tickets for the logged-in user, with their messages.
    public function myTickets(): void
    {
        $this->ensureTables();
        $user = $this->requireAuth();

        $tickets = \Illuminate\Database\Capsule\Manager::table('support_tickets')
            ->where('user_email', strtolower($user->email))
            ->orderBy('updated_at', 'desc')
            ->get();

        $result = $tickets->map(function ($t) {
            $messages = \Illuminate\Database\Capsule\Manager::table('support_ticket_messages')
                ->where('ticket_id', $t->id)
                ->orderBy('created_at', 'asc')
                ->get()
                ->map(fn($m) => [
                    'sender'      => $m->sender,
                    'sender_name' => $m->sender_name,
                    'message'     => $m->message,
                    'created_at'  => $m->created_at,
                ])->toArray();

            return [
                'id'         => $t->id,
                'ref'        => $t->ref,
                'subject'    => $t->subject,
                'category'   => $t->category,
                'status'     => $t->status,
                'priority'   => $t->priority,
                'created_at' => $t->created_at,
                'updated_at' => $t->updated_at,
                'messages'   => $messages,
            ];
        })->toArray();

        json_response(['tickets' => $result]);
    }

    // ── POST /api/support/ticket ─────────────────────────────────
    // Creates a new ticket from a logged-in user.
    public function createTicket(): void
    {
        $this->ensureTables();
        $user = $this->requireAuth();
        $body = request_body();

        $subject  = trim($body['subject']  ?? '');
        $message  = trim($body['message']  ?? '');
        $category = $body['category'] ?? 'general';

        if (!$subject) abort(422, 'Subject is required');
        if (!$message) abort(422, 'Message is required');
        if (!in_array($category, self::VALID_CATEGORIES, true)) $category = 'general';

        $ref  = $this->generateRef();
        $name = $user->name ?? '';

        $ticketId = \Illuminate\Database\Capsule\Manager::table('support_tickets')
            ->insertGetId([
                'user_id'    => $user->id,
                'user_email' => strtolower($user->email),
                'user_name'  => $name,
                'ref'        => $ref,
                'subject'    => $subject,
                'category'   => $category,
                'status'     => 'open',
                'priority'   => 'normal',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        \Illuminate\Database\Capsule\Manager::table('support_ticket_messages')
            ->insert([
                'ticket_id'   => $ticketId,
                'sender'      => 'user',
                'sender_name' => $name ?: $user->email,
                'message'     => $message,
                'created_at'  => now(),
            ]);

        // Notify admin
        $this->sendEmail(
            self::NOTIFY_EMAIL,
            'AIVO Admin',
            "[{$ref}] New support ticket: {$subject}",
            "
            <p>New support ticket raised by <strong>" . htmlspecialchars($name ?: $user->email) . "</strong>
            ({$user->email}).</p>
            <p><strong>Reference:</strong> {$ref}<br>
            <strong>Category:</strong> {$category}<br>
            <strong>Subject:</strong> " . htmlspecialchars($subject) . "</p>
            <blockquote style='border-left:3px solid #FC8337;padding-left:12px;color:#555'>"
            . nl2br(htmlspecialchars($message)) . "</blockquote>
            <p>Log in to the admin panel to reply.</p>
            "
        );

        // Confirm to user
        $this->sendEmail(
            $user->email,
            $name,
            "We've received your request [{$ref}]",
            "
            <p>Hi {$name},</p>
            <p>Thanks for getting in touch. We've received your support request and will get back to you shortly.</p>
            <p><strong>Reference:</strong> {$ref}<br>
            <strong>Subject:</strong> " . htmlspecialchars($subject) . "</p>
            <p>You can view your ticket status by logging in to
            <a href='https://app.aivooptimize.com'>app.aivooptimize.com</a>
            and clicking Help &amp; Support.</p>
            <p>— The AIVO Optimize team</p>
            "
        );

        json_response(['success' => true, 'ref' => $ref, 'ticket_id' => $ticketId]);
    }

    // ── POST /api/support/reply ──────────────────────────────────
    // User adds a reply to their own existing ticket.
    public function userReply(): void
    {
        $this->ensureTables();
        $user    = $this->requireAuth();
        $body    = request_body();
        $ticketId = (int)($body['ticket_id'] ?? 0);
        $message  = trim($body['message'] ?? '');

        if (!$ticketId) abort(422, 'ticket_id required');
        if (!$message)  abort(422, 'message required');

        $ticket = \Illuminate\Database\Capsule\Manager::table('support_tickets')
            ->where('id', $ticketId)
            ->where('user_email', strtolower($user->email))
            ->first();

        if (!$ticket) abort(404, 'Ticket not found');
        if ($ticket->status === 'closed') abort(409, 'Ticket is closed');

        \Illuminate\Database\Capsule\Manager::table('support_ticket_messages')
            ->insert([
                'ticket_id'   => $ticketId,
                'sender'      => 'user',
                'sender_name' => $user->name ?? $user->email,
                'message'     => $message,
                'created_at'  => now(),
            ]);

        \Illuminate\Database\Capsule\Manager::table('support_tickets')
            ->where('id', $ticketId)
            ->update(['status' => 'open', 'updated_at' => now()]);

        // Notify admin of user reply
        $this->sendEmail(
            self::NOTIFY_EMAIL,
            'AIVO Admin',
            "[{$ticket->ref}] User replied: {$ticket->subject}",
            "<p><strong>" . htmlspecialchars($user->name ?? $user->email) . "</strong> replied to ticket {$ticket->ref}:</p>
            <blockquote style='border-left:3px solid #FC8337;padding-left:12px;color:#555'>"
            . nl2br(htmlspecialchars($message)) . "</blockquote>"
        );

        json_response(['success' => true]);
    }

    // ── GET /api/admin/tickets ───────────────────────────────────
    // Returns all tickets for the admin panel, newest first.
    public function adminTickets(): void
    {
        $this->ensureTables();
        $this->requireSuperadmin();

        $status = $_GET['status'] ?? 'all';

        $query = \Illuminate\Database\Capsule\Manager::table('support_tickets')
            ->orderBy('updated_at', 'desc');

        if ($status !== 'all' && in_array($status, self::VALID_STATUSES, true)) {
            $query->where('status', $status);
        }

        $tickets = $query->get();

        $result = $tickets->map(function ($t) {
            $messages = \Illuminate\Database\Capsule\Manager::table('support_ticket_messages')
                ->where('ticket_id', $t->id)
                ->orderBy('created_at', 'asc')
                ->get()
                ->map(fn($m) => [
                    'id'          => $m->id,
                    'sender'      => $m->sender,
                    'sender_name' => $m->sender_name,
                    'message'     => $m->message,
                    'created_at'  => $m->created_at,
                ])->toArray();

            return [
                'id'         => $t->id,
                'ref'        => $t->ref,
                'user_email' => $t->user_email,
                'user_name'  => $t->user_name,
                'subject'    => $t->subject,
                'category'   => $t->category,
                'status'     => $t->status,
                'priority'   => $t->priority,
                'created_at' => $t->created_at,
                'updated_at' => $t->updated_at,
                'messages'   => $messages,
            ];
        })->toArray();

        json_response(['tickets' => $result]);
    }

    // ── POST /api/admin/ticket/reply ─────────────────────────────
    // Admin replies to a ticket.
    public function adminReply(): void
    {
        $this->ensureTables();
        $admin   = $this->requireSuperadmin();
        $body    = request_body();
        $ticketId = (int)($body['ticket_id'] ?? 0);
        $message  = trim($body['message']   ?? '');

        if (!$ticketId) abort(422, 'ticket_id required');
        if (!$message)  abort(422, 'message required');

        $ticket = \Illuminate\Database\Capsule\Manager::table('support_tickets')
            ->where('id', $ticketId)
            ->first();

        if (!$ticket) abort(404, 'Ticket not found');

        $adminName = $admin->name ?? 'AIVO Support';

        \Illuminate\Database\Capsule\Manager::table('support_ticket_messages')
            ->insert([
                'ticket_id'   => $ticketId,
                'sender'      => 'admin',
                'sender_name' => $adminName,
                'message'     => $message,
                'created_at'  => now(),
            ]);

        \Illuminate\Database\Capsule\Manager::table('support_tickets')
            ->where('id', $ticketId)
            ->update(['status' => 'replied', 'updated_at' => now()]);

        // Email the user
        $this->sendEmail(
            $ticket->user_email,
            $ticket->user_name ?? '',
            "Re: [{$ticket->ref}] {$ticket->subject}",
            "
            <p>Hi " . htmlspecialchars($ticket->user_name ?: $ticket->user_email) . ",</p>
            <p>We've replied to your support request <strong>{$ticket->ref}</strong>:</p>
            <blockquote style='border-left:3px solid #FC8337;padding-left:12px;color:#555'>"
            . nl2br(htmlspecialchars($message)) . "</blockquote>
            <p>You can reply or view the full thread by logging in to
            <a href='https://app.aivooptimize.com'>app.aivooptimize.com</a>
            and clicking Help &amp; Support.</p>
            <p>— {$adminName}, AIVO Optimize</p>
            "
        );

        json_response(['success' => true]);
    }

    // ── POST /api/admin/ticket/status ────────────────────────────
    // Update ticket status and/or priority.
    public function adminStatus(): void
    {
        $this->ensureTables();
        $this->requireSuperadmin();
        $body     = request_body();
        $ticketId = (int)($body['ticket_id'] ?? 0);
        $status   = $body['status']   ?? null;
        $priority = $body['priority'] ?? null;

        if (!$ticketId) abort(422, 'ticket_id required');

        $update = ['updated_at' => now()];
        if ($status   && in_array($status,   self::VALID_STATUSES,             true)) $update['status']   = $status;
        if ($priority && in_array($priority, ['low','normal','high','urgent'],  true)) $update['priority'] = $priority;

        \Illuminate\Database\Capsule\Manager::table('support_tickets')
            ->where('id', $ticketId)
            ->update($update);

        json_response(['success' => true]);
    }
}
