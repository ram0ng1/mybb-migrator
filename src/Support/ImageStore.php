<?php

namespace Ramon\MybbMigrator\Support;

use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Database\ConnectionInterface;
use Psr\Log\LoggerInterface;

/**
 * Grava os arquivos baixados NO MESMO LUGAR que o fof/upload usa no adaptador
 * local — `public/assets/files/` — e, quando a extensão está instalada, também
 * registra a linha em `fof_upload_files` (+ o vínculo com o post), de modo que o
 * arquivo apareça no gerenciador de mídia e não seja tratado como órfão.
 *
 * O disco usado é o `flarum-assets` do core (raiz `public/assets`, URL
 * `<forum>/assets`), com o prefixo `files/`: o resultado em disco e na URL é
 * idêntico ao do fof/upload, mas funciona mesmo com a extensão ainda não
 * instalada — que é o caso enquanto se testa a migração.
 *
 * O esquema do `fof_upload_files` mudou várias vezes ao longo das versões
 * (colunas de post movidas para uma pivô, uuid, shared, dimensões...). Por isso
 * a inserção é INTROSPECTIVA: montamos o conjunto completo de valores e
 * gravamos só as colunas que existem naquela instalação.
 */
final class ImageStore
{
    /** Subpasta dentro de `public/assets` — a mesma do adaptador local do fof/upload. */
    public const DIR = 'files';

    private ?array $uploadColumns = null;
    private ?bool $hasPivot = null;

    /** @var array<string, int|false> nome do arquivo => id da linha (false = não existe) */
    private array $idCache = [];

    public function __construct(
        private FilesystemFactory $filesystem,
        private ConnectionInterface $db,
        private PrivateUploadBridge $private,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Nome de arquivo DETERMINÍSTICO a partir da URL de origem: a mesma imagem
     * remota sempre vira o mesmo arquivo local, então re-executar o comando não
     * duplica nada e "pular já populados" é só um teste de existência.
     */
    public function nameFor(string $sourceUrl, string $ext, string $prefix = 'mybb'): string
    {
        return $prefix . '-' . substr(sha1($sourceUrl), 0, 32) . '.' . strtolower($ext);
    }

    /**
     * O arquivo já existe em QUALQUER um dos dois lados?
     *
     * Checar só o público faria uma imagem já gravada no armazenamento privado
     * parecer ausente, e ela seria baixada de novo a cada execução.
     */
    public function exists(string $name): bool
    {
        return $this->disk()->exists(self::DIR . '/' . $name)
            || is_file($this->private->privatePath($name));
    }

    /**
     * Grava os bytes DO LADO CERTO e devolve a URL pública do arquivo.
     *
     * A URL é a mesma nos dois casos, de propósito: é ela que fica congelada no
     * XML do post, e é por ela (`/assets/files/…`) que o `GatePrivateUploads` do
     * ramon/dfs reconhece o que precisa passar pela rota com permissão. O que
     * muda é só onde os bytes ficam.
     */
    public function put(string $name, string $bytes, bool $private = false): string
    {
        if ($private) {
            $this->putPrivate($name, $bytes);

            return $this->urlFor($name);
        }

        $this->disk()->put(self::DIR . '/' . $name, $bytes);

        return $this->urlFor($name);
    }

    /**
     * Grava fora do document root. Escrita direta (não via disco do Flarum)
     * porque, por construção, o destino está fora de qualquer disco público.
     */
    private function putPrivate(string $name, string $bytes): void
    {
        $path = $this->private->privatePath($name);
        $dir = dirname($path);

        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new \RuntimeException("não foi possível criar a pasta privada: {$dir}");
        }

        if (@file_put_contents($path, $bytes) === false) {
            throw new \RuntimeException("não foi possível gravar em {$path}");
        }
    }

    /**
     * Bytes de um arquivo já gravado, venha ele do disco público ou do
     * armazenamento privado — quem lê não precisa saber de que lado ele está.
     */
    public function read(string $name): ?string
    {
        $private = $this->private->privatePath($name);
        if (is_file($private)) {
            $bytes = @file_get_contents($private);

            return $bytes === false ? null : $bytes;
        }

        try {
            return $this->disk()->exists(self::DIR . '/' . $name)
                ? (string) $this->disk()->get(self::DIR . '/' . $name)
                : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Todo arquivo já gravado por esta extensão, dos DOIS lados.
     *
     * Serve à varredura de otimização: o mapa `mybb_migrated_images` conhece o
     * que ESTA extensão baixou, mas a pasta também guarda o que veio por outros
     * caminhos (uploads de verdade, execuções antigas, arquivos adotados). Uma
     * varredura "todas as imagens" precisa enxergar o disco, não só o mapa.
     *
     * @return array<int, string> nomes de arquivo (sem caminho)
     */
    public function listStoredNames(): array
    {
        $names = [];

        try {
            foreach ($this->disk()->files(self::DIR) as $path) {
                $names[] = basename((string) $path);
            }
        } catch (\Throwable $e) {
            // disco indisponível: a varredura segue com o que houver do outro lado
        }

        $privateDir = $this->private->directoryHint();
        if (is_dir($privateDir)) {
            foreach ((array) scandir($privateDir) as $entry) {
                if ($entry !== '.' && $entry !== '..' && is_file($privateDir . DIRECTORY_SEPARATOR . $entry)) {
                    $names[] = $entry;
                }
            }
        }

        return array_values(array_unique($names));
    }

    /** O arquivo mora fora do document root? */
    public function storedPrivately(string $name): bool
    {
        return is_file($this->private->privatePath($name));
    }

    /**
     * Apaga o arquivo dos DOIS lados. Usado quando um re-encode troca a
     * extensão: o arquivo velho vira lixo no mesmo instante em que o novo passa
     * a ser referenciado.
     */
    public function delete(string $name): void
    {
        $private = $this->private->privatePath($name);
        if (is_file($private)) {
            @unlink($private);
        }

        try {
            if ($this->disk()->exists(self::DIR . '/' . $name)) {
                $this->disk()->delete(self::DIR . '/' . $name);
            }
        } catch (\Throwable $e) {
            // arquivo órfão no disco é desperdício, não corrupção
        }
    }

    /**
     * Reaponta a linha de `fof_upload_files` para o arquivo re-encodado.
     * INTROSPECTIVA como a inserção: grava só as colunas que existem nesta
     * instalação (o esquema mudou várias vezes ao longo das versões).
     *
     * Devolve false quando não havia linha para adotar — o que é normal em
     * instalação sem fof/upload.
     */
    public function repointUploadFile(
        string $oldName,
        string $newName,
        string $url,
        string $mime,
        int $size,
        ?string $baseName = null,
        ?string $bytes = null,
    ): bool {
        $columns = $this->uploadColumns();
        if ($columns === []) {
            return false;
        }

        $candidate = [
            'path'      => $newName,
            'url'       => $url,
            'type'      => $mime,
            'size'      => $size,
            'base_name' => $baseName,
        ];

        if ($baseName === null) {
            unset($candidate['base_name']);
        }

        if ($bytes !== null) {
            [$width, $height] = $this->dimensions($bytes);
            $candidate['width'] = $width;
            $candidate['height'] = $height;
        }

        $row = array_intersect_key($candidate, array_flip($columns));

        try {
            $affected = $this->db->table('fof_upload_files')->where('path', $oldName)->update($row);
        } catch (\Throwable $e) {
            $this->logger?->warning('[mybb-migrator] falha ao reapontar upload', [
                'path' => $oldName,
                'ex'   => $e->getMessage(),
            ]);

            return false;
        }

        // O cache de ids é por nome de arquivo: sem invalidar, o nome antigo
        // continuaria resolvendo para uma linha que não aponta mais para ele.
        unset($this->idCache[$oldName], $this->idCache[$newName]);

        return $affected > 0;
    }

    public function urlFor(string $name): string
    {
        return $this->disk()->url(self::DIR . '/' . $name);
    }

    /** A extensão fof/upload está com as tabelas criadas nesta instalação? */
    public function uploadTableAvailable(): bool
    {
        return $this->uploadColumns() !== [];
    }

    /**
     * Id da linha de `fof_upload_files` que já aponta para este arquivo, se houver.
     *
     * O nome do arquivo é determinístico (ver nameFor), então ele é a chave
     * natural: a mesma imagem remota sempre produz o mesmo `path`.
     */
    public function fileIdForPath(string $name): ?int
    {
        if (array_key_exists($name, $this->idCache)) {
            return $this->idCache[$name] === false ? null : $this->idCache[$name];
        }

        // Sem a tabela não há resposta possível — e cachear "não existe" faria a
        // resposta grudar se a extensão for instalada no meio da migração.
        if ($this->uploadColumns() === []) {
            return null;
        }

        try {
            $id = $this->db->table('fof_upload_files')->where('path', $name)->value('id');
        } catch (\Throwable $e) {
            $id = null;
        }

        $this->idCache[$name] = $id === null ? false : (int) $id;

        return $id === null ? null : (int) $id;
    }

    /**
     * Registra o arquivo em `fof_upload_files`. Devolve o id da linha, ou null
     * quando a tabela não existe (fof/upload não instalado) ou a inserção falha —
     * nesses casos a migração segue: o post já aponta para a URL local.
     *
     * IDEMPOTENTE por `path`: se a linha já existe (outra execução, ou o mesmo
     * arquivo aparecendo em mais de um post) ela é reaproveitada e só o vínculo
     * com o post é criado. Isso também ADOTA arquivos que ficaram órfãos quando
     * uma execução anterior gravou os bytes mas perdeu a inserção — sem isso
     * eles não existem para nenhum código que passe pelo model File, incluindo o
     * escopo por tag das imagens (ramon/dfs).
     */
    public function registerUploadFile(
        string $name,
        string $url,
        string $mime,
        int $size,
        ?int $actorId,
        ?int $postId = null,
        ?int $discussionId = null,
        ?string $baseName = null,
        ?string $bytes = null,
    ): ?int {
        $columns = $this->uploadColumns();
        if ($columns === []) {
            return null;
        }

        if (($existing = $this->fileIdForPath($name)) !== null) {
            if ($postId !== null) {
                $this->linkToPost($existing, $postId);
            }

            return $existing;
        }

        $candidate = [
            'actor_id'      => $actorId,
            'base_name'     => $baseName ?? $name,
            'path'          => $name,
            'url'           => $url,
            'type'          => $mime,
            'size'          => $size,
            'upload_method' => 'local',
            'created_at'    => date('Y-m-d H:i:s'),
            'uuid'          => $this->uuid(),
            'tag'           => null,
            'remote_id'     => null,
            'shared'        => 0,
            'hidden'        => 0,
            'hide_from_media_manager' => 0,
            // esquemas antigos guardavam o vínculo direto na própria linha
            'post_id'       => $this->hasPivot() ? null : $postId,
            'discussion_id' => $this->hasPivot() ? null : $discussionId,
        ];

        if ($bytes !== null) {
            [$width, $height] = $this->dimensions($bytes);
            $candidate['width'] = $width;
            $candidate['height'] = $height;
        }

        $row = array_intersect_key($candidate, array_flip($columns));

        try {
            $id = (int) $this->db->table('fof_upload_files')->insertGetId($row);
        } catch (\Throwable $e) {
            // Silenciar aqui já custou caro: os bytes ficam no disco, o post
            // aponta para eles, e o mapa marca a URL como 'ok' — então nenhuma
            // execução seguinte tenta de novo, e o arquivo fica para sempre sem
            // linha. Continuar é certo (o post funciona), mas tem que aparecer.
            $this->logger?->warning('[mybb-migrator] falha ao registrar upload', [
                'path' => $name,
                'ex'   => $e->getMessage(),
            ]);

            return null;
        }

        $this->idCache[$name] = $id;

        if ($postId !== null) {
            $this->linkToPost($id, $postId);
        }

        return $id;
    }

    public function linkToPost(int $fileId, int $postId): void
    {
        if (! $this->hasPivot()) {
            return;
        }

        try {
            $exists = $this->db->table('fof_upload_file_posts')
                ->where('file_id', $fileId)
                ->where('post_id', $postId)
                ->exists();

            if (! $exists) {
                $this->db->table('fof_upload_file_posts')->insert([
                    'file_id' => $fileId,
                    'post_id' => $postId,
                ]);
            }
        } catch (\Throwable $e) {
            // vínculo é conveniência (gerenciador de mídia); nunca derruba o passo
        }
    }

    /** Caminho absoluto da pasta de destino, só para exibir no log. */
    public function directoryHint(string $publicPath): string
    {
        return rtrim($publicPath, '/\\') . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . self::DIR;
    }

    private function disk(): \Illuminate\Contracts\Filesystem\Filesystem
    {
        return $this->filesystem->disk('flarum-assets');
    }

    /**
     * @return array<int, string>
     */
    private function uploadColumns(): array
    {
        if ($this->uploadColumns === null) {
            try {
                $schema = $this->db->getSchemaBuilder();
                $this->uploadColumns = $schema->hasTable('fof_upload_files')
                    ? $schema->getColumnListing('fof_upload_files')
                    : [];
            } catch (\Throwable $e) {
                $this->uploadColumns = [];
            }
        }

        return $this->uploadColumns;
    }

    private function hasPivot(): bool
    {
        if ($this->hasPivot === null) {
            try {
                $this->hasPivot = $this->db->getSchemaBuilder()->hasTable('fof_upload_file_posts');
            } catch (\Throwable $e) {
                $this->hasPivot = false;
            }
        }

        return $this->hasPivot;
    }

    /**
     * @return array{0: ?int, 1: ?int}
     */
    private function dimensions(string $bytes): array
    {
        if (! function_exists('getimagesizefromstring')) {
            return [null, null];
        }

        $info = @getimagesizefromstring($bytes);

        return $info === false ? [null, null] : [(int) $info[0], (int) $info[1]];
    }

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0F) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
