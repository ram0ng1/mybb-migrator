<?php

namespace Ramon\MybbMigrator\Support;

use Illuminate\Contracts\Container\Container;
use Psr\Log\LoggerInterface;

/**
 * Aplica o escopo por tag das imagens (ramon/dfs) aos arquivos que a migração
 * acabou de gravar.
 *
 * A migração escreve os bytes em `public/assets/files`, que o servidor web
 * entrega sem passar pelo PHP. Para um fórum importado com tags restritas isso
 * significa que as imagens de uma área privada ficam legíveis por qualquer um
 * que tenha a URL — a permissão da tag nunca chega a ser consultada. Quem tira
 * esses arquivos do document root é o ramon/dfs; esta ponte só avisa a ele
 * quais arquivos mudaram, no fim de cada passo, para que a proteção valha já ao
 * término da importação e não só quando alguém lembrar de rodar o comando.
 *
 * O acoplamento é DELIBERADAMENTE frouxo: nada aqui é requisito da migração. Sem
 * o ramon/dfs (ou sem o fof/upload) a ponte fica inerte e a importação segue
 * igual — apenas sem a proteção, exatamente como era antes.
 */
final class UploadVisibilityBridge
{
    private const SERVICE = 'Ramon\\Dfs\\Upload\\UploadVisibility';
    private const FILE_MODEL = 'FoF\\Upload\\File';

    /** Lotes: o sync faz duas consultas por lote, não uma por arquivo. */
    private const CHUNK = 200;

    /** @var array<int, true> ids de arquivo tocados nesta execução */
    private array $touched = [];

    public function __construct(
        private Container $container,
        private ?LoggerInterface $logger = null,
    ) {
    }

    public function available(): bool
    {
        return class_exists(self::SERVICE) && class_exists(self::FILE_MODEL);
    }

    /**
     * Marca um arquivo como tocado. Barato de propósito: é chamado de dentro do
     * laço de migração, e o trabalho de verdade só acontece no flush().
     */
    public function touch(?int $fileId): void
    {
        if ($fileId !== null && $fileId > 0) {
            $this->touched[$fileId] = true;
        }
    }

    public function pending(): int
    {
        return count($this->touched);
    }

    /**
     * Reclassifica tudo que foi tocado e devolve quantos arquivos mudaram de
     * lado. Nunca lança: uma falha aqui é um arquivo no lugar errado do disco,
     * não uma migração perdida, e o passo já pode ter levado horas.
     */
    public function flush(): int
    {
        if ($this->touched === [] || ! $this->available()) {
            $this->touched = [];

            return 0;
        }

        $ids = array_keys($this->touched);
        $this->touched = [];
        $moved = 0;

        try {
            $visibility = $this->container->make(self::SERVICE);
            $model = self::FILE_MODEL;

            foreach (array_chunk($ids, self::CHUNK) as $chunk) {
                $moved += $visibility->syncMany($model::query()->whereIn('id', $chunk)->get());
                $visibility->flushMemo();
            }
        } catch (\Throwable $e) {
            $this->logger?->warning('[mybb-migrator] falha ao aplicar o escopo por tag dos uploads', [
                'ex' => $e->getMessage(),
            ]);
        }

        return $moved;
    }
}
