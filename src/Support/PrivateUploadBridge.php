<?php

namespace Ramon\MybbMigrator\Support;

use Flarum\Discussion\Discussion;
use Flarum\Foundation\Paths;
use Flarum\User\Guest;
use Illuminate\Database\ConnectionInterface;

/**
 * Decide, ANTES de gravar os bytes, se a imagem pertence a uma discussão que o
 * visitante não pode ver — e, nesse caso, grava fora do document root.
 *
 * Por que antes e não depois: `public/assets/files` é servido pelo servidor web
 * sem passar por PHP, então enquanto o arquivo estiver lá nenhuma permissão se
 * aplica a ele. O `dfs:uploads:sync` conserta isso depois, mas "depois" é uma
 * janela real — no fórum de referência, seis imagens de uma tag restrita ficaram
 * legíveis por qualquer um entre o fim da migração e o próximo sync. Gravando já
 * no lado certo, essa janela deixa de existir.
 *
 * ACOPLAMENTO DELIBERADAMENTE FRACO com ramon/dfs:
 *  - a regra de visibilidade sai do core (`Discussion::whereVisibleTo(Guest)`),
 *    que é a mesma chamada que o dfs faz — não uma releitura das tags;
 *  - o diretório privado vem da constante do próprio dfs quando ele está
 *    presente, para as duas extensões nunca discordarem do caminho;
 *  - sem o dfs instalado tudo isso se desliga e a gravação segue pública, que é
 *    o único destino possível nessa situação.
 *
 * A regra copiada do dfs é a MENOS restritiva: um arquivo é público se ao menos
 * uma discussão em que ele aparece for visível ao visitante. Aqui vemos um post
 * por vez, então o caso "mesma imagem em duas discussões" fica para o
 * `dfs:uploads:sync` — ele reconcilia, e o pior cenário nesse meio-tempo é uma
 * imagem servida via PHP em vez de direto pelo servidor web (lenta, não exposta).
 */
final class PrivateUploadBridge
{
    /** Fallback do nome da pasta, caso o dfs esteja presente só como tabela. */
    private const FALLBACK_DIRECTORY = 'dfs-private-uploads';

    private ?bool $available = null;

    /** @var array<int, bool> discussion id => visível ao visitante */
    private array $guestVisible = [];

    public function __construct(
        private ConnectionInterface $db,
        private Paths $paths,
    ) {
    }

    /**
     * O subsistema de uploads privados do ramon/dfs existe nesta instalação?
     *
     * A tabela é o sinal: ela é criada pela migração do dfs e é onde o "este
     * arquivo está do lado privado" mora. Sem ela, gravar fora do document root
     * só produziria arquivos que ninguém sabe servir.
     */
    public function available(): bool
    {
        if ($this->available === null) {
            try {
                $this->available = $this->db->getSchemaBuilder()->hasTable('dfs_private_uploads');
            } catch (\Throwable $e) {
                $this->available = false;
            }
        }

        return $this->available;
    }

    /**
     * A imagem deste post deve nascer fora do document root?
     *
     * Discussão desconhecida (0) responde `false`: sem discussão não há tag, e
     * esconder por precaução deixaria arquivos privados que ninguém reclassifica.
     */
    public function shouldBePrivate(int $discussionId): bool
    {
        if (! $this->available() || $discussionId <= 0) {
            return false;
        }

        return ! $this->isGuestVisible($discussionId);
    }

    /**
     * Memoizado porque uma migração percorre os posts em ordem de id: a mesma
     * discussão reaparece dezenas de vezes seguidas.
     */
    public function isGuestVisible(int $discussionId): bool
    {
        return $this->guestVisible[$discussionId] ??= $this->queryGuestVisible($discussionId);
    }

    /** Caminho absoluto do arquivo no armazenamento privado. */
    public function privatePath(string $name): string
    {
        return rtrim($this->paths->storage, '/\\')
            . DIRECTORY_SEPARATOR . $this->directory()
            . DIRECTORY_SEPARATOR . $name;
    }

    /** Pasta privada absoluta, só para exibir no log. */
    public function directoryHint(): string
    {
        return rtrim($this->paths->storage, '/\\') . DIRECTORY_SEPARATOR . $this->directory();
    }

    /**
     * Marca (ou desmarca) o arquivo como privado no registro do dfs.
     *
     * A linha entra DEPOIS dos bytes, como no `makePrivate()` do dfs: se algo
     * falhar no meio, o arquivo é considerado público — que é o que os bytes
     * ainda são do ponto de vista de quem lê. A ordem inversa anunciaria uma URL
     * protegida para bytes que ninguém consegue ler.
     */
    public function markPrivate(int $fileId): void
    {
        if (! $this->available() || $fileId <= 0) {
            return;
        }

        try {
            $this->db->table('dfs_private_uploads')->updateOrInsert(
                ['file_id' => $fileId],
                ['moved_at' => date('Y-m-d H:i:s')]
            );
        } catch (\Throwable $e) {
            // registro é do dfs; falhar aqui não invalida a migração da imagem
        }
    }

    public function markPublic(int $fileId): void
    {
        if (! $this->available() || $fileId <= 0) {
            return;
        }

        try {
            $this->db->table('dfs_private_uploads')->where('file_id', $fileId)->delete();
        } catch (\Throwable $e) {
        }
    }

    /**
     * Nome da pasta privada. Vem da constante do dfs quando a classe está
     * carregada, para as duas extensões jamais divergirem do caminho; a constante
     * local só cobre o caso de a tabela existir sem o código (extensão desabilitada
     * mas migrada).
     */
    private function directory(): string
    {
        $class = 'Ramon\Dfs\Upload\PrivateUploadStore';

        if (class_exists($class) && defined($class . '::DIRECTORY')) {
            return (string) constant($class . '::DIRECTORY');
        }

        return self::FALLBACK_DIRECTORY;
    }

    /**
     * Visibilidade pelo scoper do próprio Flarum (`whereVisibleTo`), que já
     * entende tag restrita, tag-filha, permissão por grupo e discussão oculta.
     * Reimplementar isso lendo `discussion_tag` daria uma resposta que diverge do
     * que o fórum realmente mostra.
     */
    private function queryGuestVisible(int $discussionId): bool
    {
        try {
            return Discussion::whereVisibleTo(new Guest())
                ->where('id', $discussionId)
                ->exists();
        } catch (\Throwable $e) {
            // Na dúvida, PRIVADO. Só chegamos aqui com o dfs disponível, então a
            // rota protegida existe e libera quem enxerga a discussão — um
            // arquivo que devia ser público continua abrindo, apenas servido por
            // PHP. O erro contrário (público por engano) é exposição, e essa não
            // tem como ser desfeita depois que alguém copiou a URL.
            return false;
        }
    }
}
