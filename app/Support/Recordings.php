<?php

namespace App\Support;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Saves real agent runs to a JSON file and puts them back after the nightly reset, so the public demo
 * shows complete runs (triage, tool calls, approvals, replies) without calling a model.
 *
 * The seed data is fixed text with fixed ids, so a recording lines up with every fresh seed.
 */
class Recordings
{
    public const VERSION = 1;

    public static function path(): string
    {
        return (string) config('triagedesk.demo.recordings_path');
    }

    /**
     * @param  list<int>  $ticketIds
     * @return array<string, mixed>
     */
    public static function export(array $ticketIds): array
    {
        $tickets = DB::table('tickets')->whereIn('id', $ticketIds)->orderBy('id')->get();
        $conversationIds = $tickets->pluck('agent_conversation_id')->filter()->values();
        $customerIds = $tickets->pluck('customer_id')->unique()->values();

        return [
            'version' => self::VERSION,
            'recorded_at' => now()->toIso8601String(),
            'tickets' => $tickets->map(fn ($row) => [...(array) $row, 'recorded' => true, 'agent_error' => null])->all(),
            'ticket_messages' => static::rows('ticket_messages', 'ticket_id', $ticketIds),
            'agent_actions' => static::rows('agent_actions', 'ticket_id', $ticketIds),
            'agent_conversations' => static::rows(static::conversationsTable(), 'id', $conversationIds->all()),
            'agent_conversation_messages' => static::rows(static::messagesTable(), 'conversation_id', $conversationIds->all()),
            'payments' => static::rows('payments', 'customer_id', $customerIds->all()),
        ];
    }

    public static function save(array $recording): void
    {
        $path = static::path();
        $temporary = $path.'.tmp';

        if (! is_dir(dirname($path)) && ! mkdir(dirname($path), 0775, true) && ! is_dir(dirname($path))) {
            throw new RuntimeException("Could not create the folder for {$path}.");
        }

        file_put_contents($temporary, json_encode($recording, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        rename($temporary, $path);
    }

    /**
     * Put the saved runs back. Returns how many tickets were restored, or 0 when there is no recording.
     */
    public static function import(): int
    {
        $path = static::path();

        if (! is_file($path)) {
            return 0;
        }

        $recording = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        if (($recording['version'] ?? null) !== self::VERSION) {
            throw new RuntimeException("{$path} was made by another version of TriageDesk. Record it again.");
        }

        $ticketIds = Arr::pluck($recording['tickets'], 'id');

        DB::transaction(function () use ($recording, $ticketIds) {
            foreach ($recording['tickets'] as $ticket) {
                DB::table('tickets')->where('id', $ticket['id'])->update(Arr::except($ticket, ['id']));
            }

            DB::table('ticket_messages')->whereIn('ticket_id', $ticketIds)->delete();
            DB::table('agent_actions')->whereIn('ticket_id', $ticketIds)->delete();

            // These rows are only found through their ticket, so they take fresh ids.
            static::insert('ticket_messages', array_map(fn ($row) => Arr::except($row, ['id']), $recording['ticket_messages']));
            static::insert('agent_actions', array_map(fn ($row) => Arr::except($row, ['id']), $recording['agent_actions']));

            $conversationIds = Arr::pluck($recording['agent_conversations'], 'id');
            DB::table(static::messagesTable())->whereIn('conversation_id', $conversationIds)->delete();
            DB::table(static::conversationsTable())->whereIn('id', $conversationIds)->delete();
            static::insert(static::conversationsTable(), $recording['agent_conversations']);
            static::insert(static::messagesTable(), $recording['agent_conversation_messages']);

            foreach ($recording['payments'] as $payment) {
                DB::table('payments')->where('id', $payment['id'])
                    ->update(Arr::only($payment, ['refunded_cents', 'status', 'updated_at']));
            }
        });

        return count($ticketIds);
    }

    protected static function rows(string $table, string $column, array $values): array
    {
        return DB::table($table)->whereIn($column, $values)->orderBy('id')->get()
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    protected static function insert(string $table, array $rows): void
    {
        foreach (array_chunk($rows, 50) as $chunk) {
            DB::table($table)->insert($chunk);
        }
    }

    protected static function conversationsTable(): string
    {
        return config('ai.conversations.tables.conversations', 'agent_conversations');
    }

    protected static function messagesTable(): string
    {
        return config('ai.conversations.tables.messages', 'agent_conversation_messages');
    }
}
