<?php

namespace Ramon\MybbMigrator\Console;

use Flarum\Console\AbstractCommand;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Database\ConnectionInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Migra o feedback de trader do MyBB (plugin iTrader, tabela
 * `{prefix}trade_feedback`) para a extensão huseyinfiliz/traderfeedback
 * (`tfb_feedbacks`), preservando o `fid` como id para reexecução idempotente.
 *
 * Mapeamento:
 *   giver     → from_user_id        receiver  → to_user_id
 *   value     → type (1/0/-1 → positive/neutral/negative)
 *   type      → role (buyer/seller/trader; vazio → trader)
 *   comments  → comment (strip_tags)   approved → is_approved
 *   dateline  → created_at/updated_at  tid       → discussion_id (sempre 0 → null)
 *
 * Linhas com giver/receiver ausente, iguais, ou apontando a usuários não
 * migrados são puladas. Relatórios (`reported`) não são importados: o MyBB
 * não guarda autor nem motivo do report.
 *
 * Após o import, recalcula `tfb_stats` (positive/neutral/negative + score) de
 * todos os destinatários a partir do feedback aprovado.
 */
class MigrateTradeFeedbackCommand extends AbstractCommand
{
    use MybbConnectionOptions;

    private const BATCH = 5000;

    public function __construct(
        protected ConnectionInterface $db,
        protected SettingsRepositoryInterface $settings,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('mybb:trade-feedback')
            ->setDescription('Migra o feedback de trader (iTrader) do MyBB para huseyinfiliz/traderfeedback.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Confirma execução.');
        $this->addMybbConnectionOptions();
    }

    protected function fire(): int
    {
        if (! $this->input->getOption('force')) {
            $this->error('Rode com --force.');
            return 1;
        }

        if (! $this->db->getSchemaBuilder()->hasTable('tfb_feedbacks')) {
            $this->error('Tabela tfb_feedbacks não existe. Instale e migre a extensão huseyinfiliz/traderfeedback primeiro.');
            return 1;
        }

        $mybb = $this->buildMybbDatabase($this->settings);
        $prefix = $mybb->prefix();
        $table = "{$prefix}trade_feedback";

        $columns = [];
        foreach ($mybb->select("SHOW COLUMNS FROM {$table}") as $col) {
            $columns[strtolower((string) $col['Field'])] = true;
        }

        foreach (['fid', 'giver', 'receiver', 'value', 'type', 'comments', 'approved', 'dateline'] as $required) {
            if (! isset($columns[$required])) {
                $this->error("Tabela {$table} não tem a coluna esperada '{$required}'. Colunas: " . implode(',', array_keys($columns)));
                return 1;
            }
        }

        $total = (int) $mybb->scalar("SELECT COUNT(*) FROM {$table}");
        $this->info("Feedbacks a migrar: {$total}");

        $userIds = $this->loadIdSet('users');
        $this->info('Usuários no Flarum: ' . count($userIds));

        $this->db->statement('SET FOREIGN_KEY_CHECKS=0');

        $batch = [];
        $inserted = 0;
        $skippedUser = 0;
        $skippedSelf = 0;
        $receivers = [];

        try {
            $sql = "SELECT fid, giver, receiver, value, type, comments, approved, dateline FROM {$table} ORDER BY fid";
            foreach ($mybb->cursor($sql) as $row) {
                $giver = (int) ($row['giver'] ?? 0);
                $receiver = (int) ($row['receiver'] ?? 0);

                if ($giver <= 0 || $receiver <= 0 || ! isset($userIds[$giver]) || ! isset($userIds[$receiver])) {
                    $skippedUser++;
                    continue;
                }
                if ($giver === $receiver) {
                    $skippedSelf++;
                    continue;
                }

                $ts = (int) ($row['dateline'] ?? 0);
                $date = $ts > 0 ? date('Y-m-d H:i:s', $ts) : date('Y-m-d H:i:s');

                $batch[] = [
                    'id' => (int) $row['fid'],
                    'from_user_id' => $giver,
                    'to_user_id' => $receiver,
                    'type' => $this->mapType((int) $row['value']),
                    'role' => $this->mapRole((string) ($row['type'] ?? '')),
                    'comment' => $this->sanitizeComment((string) ($row['comments'] ?? '')),
                    'discussion_id' => null,
                    'is_approved' => ((int) ($row['approved'] ?? 0)) === 1 ? 1 : 0,
                    'approved_by_id' => null,
                    'created_at' => $date,
                    'updated_at' => $date,
                ];

                $receivers[$receiver] = true;

                if (count($batch) >= self::BATCH) {
                    $this->db->table('tfb_feedbacks')->insertOrIgnore($batch);
                    $inserted += count($batch);
                    $batch = [];
                    $this->info("  {$inserted} inseridos...");
                }
            }

            if ($batch !== []) {
                $this->db->table('tfb_feedbacks')->insertOrIgnore($batch);
                $inserted += count($batch);
            }
        } finally {
            $this->db->statement('SET FOREIGN_KEY_CHECKS=1');
        }

        $this->info("Import concluído. inseridos={$inserted}, pulados(usuário ausente)={$skippedUser}, pulados(self)={$skippedSelf}");

        $statsCount = $this->rebuildStats();
        $this->info("Stats recalculados para {$statsCount} usuários.");

        return 0;
    }

    /**
     * Recalcula `tfb_stats` a partir do feedback aprovado e não deletado,
     * via um único GROUP BY (sem N+1). Faz upsert por user_id.
     */
    private function rebuildStats(): int
    {
        $agg = [];

        $rows = $this->db->table('tfb_feedbacks')
            ->where('is_approved', true)
            ->whereNull('deleted_at')
            ->selectRaw('to_user_id, type, COUNT(*) AS c')
            ->groupBy('to_user_id', 'type')
            ->get();

        foreach ($rows as $row) {
            $uid = (int) $row->to_user_id;
            $agg[$uid] ??= ['positive_count' => 0, 'neutral_count' => 0, 'negative_count' => 0];
            $agg[$uid][$row->type . '_count'] = (int) $row->c;
        }

        $now = date('Y-m-d H:i:s');
        $payload = [];
        foreach ($agg as $uid => $counts) {
            $totalCount = $counts['positive_count'] + $counts['neutral_count'] + $counts['negative_count'];
            $score = $totalCount > 0 ? round(($counts['positive_count'] / $totalCount) * 100, 2) : 0;
            $payload[] = [
                'user_id' => $uid,
                'positive_count' => $counts['positive_count'],
                'neutral_count' => $counts['neutral_count'],
                'negative_count' => $counts['negative_count'],
                'score' => $score,
                'last_updated' => $now,
            ];
        }

        foreach (array_chunk($payload, self::BATCH) as $chunk) {
            $this->db->table('tfb_stats')->upsert(
                $chunk,
                ['user_id'],
                ['positive_count', 'neutral_count', 'negative_count', 'score', 'last_updated']
            );
        }

        return count($payload);
    }

    private function mapType(int $value): string
    {
        return match (true) {
            $value > 0 => 'positive',
            $value < 0 => 'negative',
            default => 'neutral',
        };
    }

    private function mapRole(string $type): string
    {
        $type = strtolower(trim($type));
        return in_array($type, ['buyer', 'seller', 'trader'], true) ? $type : 'trader';
    }

    private function sanitizeComment(string $raw): string
    {
        $text = trim(html_entity_decode(strip_tags($raw), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        return $text !== '' ? $text : '—';
    }

    /**
     * @return array<int, bool>
     */
    private function loadIdSet(string $table): array
    {
        $set = [];
        foreach ($this->db->table($table)->select('id')->cursor() as $row) {
            $set[(int) $row->id] = true;
        }
        return $set;
    }
}
