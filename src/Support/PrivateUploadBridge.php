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
 *
 * ESCOLHER ONDE A PASTA FICA. O caminho `storage/<nome>` acima é uma CONSTANTE
 * no código do dfs — não uma configuração — porque é ele quem serve esses
 * arquivos de volta (rota com checagem de permissão). Gravar em outro lugar
 * faria essas imagens ficarem inacessíveis para sempre, até para quem tem
 * permissão de ver a discussão, o que é pior do que não ter a proteção. Por
 * isso {@see useDirectory()} nunca move o caminho canônico: quando o admin
 * escolhe uma pasta (outro disco, por exemplo), o canônico passa a ser um
 * LINK para ela — o dfs (e qualquer outra extensão que sirva uploads
 * privados pelo mesmo caminho) continua achando os arquivos onde sempre
 * olhou, e os bytes moram onde o admin escolheu.
 */
final class PrivateUploadBridge
{
    /** Fallback do nome da pasta, caso o dfs esteja presente só como tabela. */
    private const FALLBACK_DIRECTORY = 'dfs-private-uploads';

    private ?bool $available = null;

    /** @var array<int, bool> discussion id => visível ao visitante */
    private array $guestVisible = [];

    /** Pasta real escolhida pelo admin (ver useDirectory()), ou null = padrão. */
    private ?string $realDirectory = null;

    /** O que deu errado ao tentar ligar o caminho canônico à pasta escolhida. */
    private ?string $linkNotice = null;

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
     * Escolhe onde a pasta privada FICA de verdade, sem mudar por onde ela é
     * ACHADA: o caminho canônico ({@see directoryHint()}) vira um link para
     * `$path`. `null`/vazio volta ao padrão (canônico como pasta de verdade).
     *
     * Nunca destrutivo: uma pasta canônica já existente com arquivos (não um
     * link) é deixada como está — mover dados de produção sozinho é risco
     * demais para um comando de migração decidir. {@see directoryNotice()}
     * explica o que fazer à mão nesse caso, ou quando o link não pôde ser
     * criado (Windows sem Modo desenvolvedor/administrador, por exemplo).
     */
    public function useDirectory(?string $path): self
    {
        $path = $path === null ? '' : rtrim(trim($path), '/\\');
        $this->linkNotice = null;

        // Mesmo caminho do padrão: não é uma escolha, é o padrão escrito por
        // extenso. Trata como ausência de override — sem isso o passo abaixo
        // veria a pasta canônica "já existindo com arquivos" e reclamaria de
        // um link que nem precisa existir.
        if ($path !== '' && rtrim(str_replace('\\', '/', $path), '/') === rtrim(str_replace('\\', '/', $this->directoryHint()), '/')) {
            $path = '';
        }

        $this->realDirectory = $path === '' ? null : $path;

        if ($this->realDirectory !== null) {
            $this->linkNotice = $this->ensureLink($this->realDirectory);
        }

        return $this;
    }

    /** A pasta real escolhida pelo admin, quando diferente do padrão — só para exibir no log. */
    public function realDirectoryHint(): ?string
    {
        return $this->realDirectory;
    }

    /**
     * O que deu errado ao aplicar {@see useDirectory()} — null quando não há
     * override ou quando o link já está apontando para o lugar certo.
     */
    public function directoryNotice(): ?string
    {
        return $this->linkNotice;
    }

    /**
     * Garante que o caminho canônico seja um link para `$real`, criando a
     * pasta real quando falta. Devolve uma mensagem de aviso (para o console)
     * quando não dá para garantir isso, ou null quando está tudo certo.
     */
    private function ensureLink(string $real): ?string
    {
        $canonical = $this->directoryHint();

        if (! is_dir($real) && ! @mkdir($real, 0775, true) && ! is_dir($real)) {
            return "não foi possível criar a pasta {$real}";
        }

        // is_dir()/realpath() do MESMO caminho podem ter sido checados mais
        // cedo nesta chamada (ex.: available()/directoryHint() de fora) e o
        // cache de stat do PHP ainda responder com o que viu antes.
        clearstatcache(true, $canonical);
        clearstatcache(true, $real);

        $realResolved = realpath($real) ?: $real;

        if (is_dir($canonical)) {
            // realpath() ATRAVESSA link e junção — bate com o destino
            // escolhido quando (e só quando) o canônico já aponta para lá.
            // É mais confiável que is_link()/readlink(): no Windows, is_link()
            // só reconhece o reparse tag de SYMLINK (uma junção tem outro tag
            // e ele devolve false mesmo apontando certo), e o readlink() desta
            // build devolve o PRÓPRIO caminho, em vez de false, para uma pasta
            // comum — os dois dariam falso negativo/positivo aqui.
            if ($this->samePath((string) realpath($canonical), $realResolved)) {
                return null; // já aponta para o lugar certo
            }

            $entries = array_diff((array) @scandir($canonical), ['.', '..']);
            if ($entries !== []) {
                return "{$canonical} já existe (com arquivos, ou como link para outro lugar) — mova/ajuste manualmente para religar em {$real}, ou deixe a pasta escolhida em branco para continuar usando o caminho padrão";
            }

            // Vazia (pasta comum OU link/junção vazios): sai do caminho para o
            // link entrar. rmdir() remove os dois casos sem seguir o alvo.
            if (! @rmdir($canonical)) {
                return "não foi possível remover {$canonical} para criar o link";
            }
            clearstatcache(true, $canonical);
        }

        if (@symlink($realResolved, $canonical)) {
            return null;
        }

        // No Windows, symlink() de diretório exige Modo desenvolvedor ligado
        // ou elevação. Uma junção NTFS (mklink /J) resolveria sem precisar de
        // nenhum dos dois, mas criá-la exige um shell (mklink é built-in do
        // cmd.exe, sem executável próprio) — e `cmd /c` sempre reinterpreta a
        // linha inteira como sintaxe de shell, então nenhuma forma de invocar
        // um processo externo aqui é estruturalmente segura contra os dois
        // caminhos (o padrão e o escolhido pelo admin). Preferimos NÃO
        // executar nada e devolver o comando pronto para rodar à mão.
        if (PHP_OS_FAMILY === 'Windows') {
            return "não foi possível criar o link {$canonical} -> {$realResolved}: symlink() "
                . 'precisa do Modo desenvolvedor ligado ou de elevação neste PHP. '
                . 'Ligue o Modo desenvolvedor (ou rode a migração como administrador) e tente de novo, '
                . "ou crie a junção você mesmo num prompt: mklink /J \"{$canonical}\" \"{$realResolved}\"";
        }

        return "não foi possível criar o link {$canonical} -> {$realResolved}";
    }

    private function samePath(string $a, string $b): bool
    {
        return rtrim(str_replace('\\', '/', $a), '/') === rtrim(str_replace('\\', '/', $b), '/');
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
