<?php

namespace Ramon\MybbMigrator\Gui;

use Flarum\Foundation\Paths;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Database\ConnectionInterface;
use Ramon\MybbMigrator\MybbDatabase;

/**
 * Descobre sozinho as duas configurações que ninguém deveria ter de digitar:
 *
 *  - **quais hosts internalizar**: em vez de o admin adivinhar, lemos TODOS os
 *    posts já migrados e rankeamos os hosts realmente usados nas imagens. Todos
 *    entram no filtro, inclusive a cauda longa de hosts com uma imagem só — são
 *    justamente esses os mais propensos a sumir da internet. O ranking volta
 *    junto para o painel mostrar de onde vem cada coisa.
 *
 *  - **onde está a pasta `uploads` do MyBB**: procuramos candidatos perto da
 *    instalação do Flarum e VALIDAMOS cada um contra `attachname` reais da
 *    tabela de anexos. Só vira resposta o diretório onde um anexo conhecido
 *    existe de fato — palpite que não se prova não é usado.
 */
class MediaDetector
{
    /**
     * 0 = varre TODOS os posts com imagem.
     *
     * Amostrar era um cuidado desnecessário: no fórum de referência são ~49 mil
     * posts com `<IMG`, varridos em 1,8 s. E amostra tem um custo real — o
     * ranking sai incompleto, então "todos os hosts" não seria todos.
     */
    public const SCAN_POSTS = 0;

    /** Quantos hosts do ranking cabem na resposta da API (a UI não lista 400). */
    private const RANKING_LIMIT = 200;

    public function __construct(
        protected ConnectionInterface $db,
        protected SettingsRepositoryInterface $settings,
        protected Paths $paths,
        protected FilesystemFactory $filesystem,
    ) {
    }

    /**
     * Detecta as duas configurações e (opcionalmente) as persiste.
     *
     * @return array<string, mixed>
     */
    public function detect(bool $apply = true, int $scanPosts = self::SCAN_POSTS): array
    {
        $hosts = $this->detectHosts($scanPosts);
        $uploads = $this->detectUploadsDir();

        if ($apply) {
            if ($hosts['applied'] !== []) {
                $this->settings->set('mybb-migrator.image_hosts', implode(',', $hosts['applied']));
            }
            if ($uploads['path'] !== null) {
                $this->settings->set('mybb-migrator.attachments_dir', $uploads['path']);
            }
        }

        return ['hosts' => $hosts, 'uploads' => $uploads, 'applied' => $apply];
    }

    /**
     * Ranking dos hosts de imagem usados pelos posts migrados.
     *
     * @return array{ranking: array<int, array{host: string, count: int}>, applied: array<int, string>, scanned: int, truncated: bool, total_hosts: int, total_images: int}
     */
    public function detectHosts(int $scanPosts = self::SCAN_POSTS): array
    {
        $localHost = strtolower((string) parse_url($this->localBase(), PHP_URL_HOST));

        $counts = [];
        $scanned = 0;

        $query = $this->db->table('posts')
            ->select('content')
            ->where('type', 'comment')
            ->where('content', 'like', '%<IMG %')
            ->orderBy('id');

        if ($scanPosts > 0) {
            $query->limit($scanPosts);
        }

        $rows = $query->cursor();

        foreach ($rows as $row) {
            $scanned++;

            if (! preg_match_all('/<IMG\b[^>]*\ssrc="([^"]*)"/i', (string) $row->content, $matches)) {
                continue;
            }

            foreach ($matches[1] as $escaped) {
                $url = html_entity_decode((string) $escaped, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $host = strtolower((string) parse_url($url, PHP_URL_HOST));

                // Sem host = URL relativa (já interna); host local = já migrada.
                if ($host === '' || $host === $localHost) {
                    continue;
                }

                $counts[$host] = ($counts[$host] ?? 0) + 1;
            }
        }

        arsort($counts);

        $ranking = [];
        foreach ($counts as $host => $n) {
            $ranking[] = ['host' => $host, 'count' => $n];
        }

        // TODOS os hosts encontrados entram no filtro. Cortar a cauda longa
        // (aqui, 173 hosts com uma imagem só) deixaria justamente as imagens
        // raras apontando para servidores de terceiros, que é o que a migração
        // existe para evitar. O que muda é o número de tentativas de download —
        // e para isso existem os limites por execução.
        $applied = array_column($ranking, 'host');

        return [
            'ranking'     => array_slice($ranking, 0, self::RANKING_LIMIT),
            'applied'     => $applied,
            'scanned'     => $scanned,
            'truncated'   => $scanPosts > 0 && $scanned >= $scanPosts,
            'total_hosts' => count($ranking),
            'total_images' => array_sum($counts),
        ];
    }

    /**
     * Procura a pasta `uploads` do MyBB e prova o palpite contra anexos reais.
     *
     * @return array{path: ?string, checked: array<int, string>, roots: array<int, string>, samples: array<int, string>, reason: ?string}
     */
    public function detectUploadsDir(): array
    {
        $samples = $this->attachmentSamples();

        $roots = $this->searchRoots();
        $candidates = $this->uploadCandidates($roots);
        $checked = [];

        foreach ($candidates as $dir) {
            $checked[] = $dir;

            if (! is_dir($dir)) {
                continue;
            }

            // Sem anexos no MyBB não há como provar; aceitamos a pasta que ao
            // menos existe e se parece com um uploads (tem subpasta de mês ou
            // arquivos .attach).
            if ($samples === []) {
                if ($this->looksLikeUploads($dir)) {
                    return ['path' => $dir, 'checked' => $checked, 'roots' => $roots, 'samples' => [], 'reason' => 'no-attachments'];
                }
                continue;
            }

            foreach ($samples as $attachName) {
                $relative = str_replace('/', DIRECTORY_SEPARATOR, ltrim(str_replace('\\', '/', $attachName), '/'));
                if (is_file(rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $relative)) {
                    return ['path' => $dir, 'checked' => $checked, 'roots' => $roots, 'samples' => $samples, 'reason' => null];
                }
            }
        }

        return [
            'path'    => null,
            'checked' => $checked,
            // Onde procuramos: sem isso, "não encontrei" não diz ao admin se o
            // problema é o lugar da busca ou a ausência da pasta.
            'roots'   => $roots,
            'samples' => $samples,
            'reason'  => $candidates === [] ? 'no-candidates' : 'not-found',
        ];
    }

    /**
     * Diretórios a testar, do mais provável ao menos. A busca é deliberadamente
     * RASA (irmãos da instalação do Flarum e um nível abaixo): varrer o disco
     * atrás de uma pasta uploads seria caro e imprevisível.
     *
     * @param array<int, string> $roots
     * @return array<int, string>
     */
    private function uploadCandidates(array $roots): array
    {
        $out = [];

        $add = function (?string $dir) use (&$out): void {
            if ($dir === null) {
                return;
            }
            $dir = rtrim(str_replace('\\', '/', trim($dir)), '/');
            if ($dir !== '' && ! in_array($dir, $out, true)) {
                $out[] = $dir;
            }
        };

        // 1. O que já estiver configurado tem prioridade (revalidação).
        $configured = trim((string) ($this->settings->get('mybb-migrator.attachments_dir') ?? ''));
        if ($configured !== '') {
            $add($configured);
        }

        // 2. `uploadspath` do próprio MyBB (ex.: "./uploads") relativo a cada
        //    raiz plausível de instalação.
        $uploadsLeaf = $this->mybbUploadsLeaf();

        $base = rtrim(str_replace('\\', '/', $this->paths->base), '/');
        $roots = [dirname($base), $base];

        foreach ($roots as $root) {
            if (! is_dir($root)) {
                continue;
            }

            foreach ((array) @scandir($root) as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                $path = $root . '/' . $entry;
                if (! is_dir($path)) {
                    continue;
                }

                // Uma pasta "uploads" solta (dump copiado sem o MyBB inteiro).
                if (strcasecmp($entry, 'uploads') === 0) {
                    $add($path);
                    continue;
                }

                // Uma instalação MyBB: reconhecida pelo seu marcador de config.
                if (is_file($path . '/inc/config.php') || is_file($path . '/inc/settings.php')) {
                    $add($path . '/' . $uploadsLeaf);
                }
            }
        }

        return $out;
    }

    /**
     * Diretórios onde a busca acontece: a pasta do Flarum e a pasta que a contém
     * (típico de `www/flarum` + `www/mybb`).
     *
     * @return array<int, string>
     */
    private function searchRoots(): array
    {
        $base = rtrim(str_replace(DIRECTORY_SEPARATOR, '/', $this->paths->base), '/');

        return array_values(array_unique([dirname($base), $base]));
    }

    /** Último trecho de `uploadspath` do MyBB ("./uploads" -> "uploads"). */
    private function mybbUploadsLeaf(): string
    {
        try {
            $mybb = MybbDatabase::fromSettings($this->settings);
            $value = (string) $mybb->scalar(
                'SELECT value FROM ' . $mybb->table('settings') . " WHERE name = 'uploadspath' LIMIT 1"
            );
        } catch (\Throwable $e) {
            $value = '';
        }

        $value = trim(str_replace('\\', '/', $value), "./ \t\n\r");

        return $value === '' ? 'uploads' : $value;
    }

    /**
     * Alguns `attachname` reais, usados para PROVAR que um candidato é a pasta
     * certa (em vez de aceitar qualquer diretório chamado uploads).
     *
     * @return array<int, string>
     */
    private function attachmentSamples(): array
    {
        try {
            $mybb = MybbDatabase::fromSettings($this->settings);
            $rows = $mybb->select(
                'SELECT attachname FROM ' . $mybb->table('attachments') . " WHERE attachname <> '' ORDER BY aid LIMIT 5"
            );
        } catch (\Throwable $e) {
            return [];
        }

        $out = [];
        while ($row = $rows->fetch()) {
            $name = trim((string) ($row['attachname'] ?? ''));
            if ($name !== '' && ! str_contains($name, '..')) {
                $out[] = $name;
            }
        }

        return $out;
    }

    /** Heurística para fóruns sem nenhum anexo: a pasta ao menos parece um uploads. */
    private function looksLikeUploads(string $dir): bool
    {
        foreach ((array) @scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (preg_match('/\.(attach|thumb)$/i', (string) $entry) || preg_match('/^\d{6}$/', (string) $entry)) {
                return true;
            }
        }

        return false;
    }

    private function localBase(): string
    {
        try {
            return (string) $this->filesystem->disk('flarum-assets')->url('x');
        } catch (\Throwable $e) {
            return '';
        }
    }
}
