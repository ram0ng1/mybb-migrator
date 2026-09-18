<?php

namespace Ramon\MybbMigrator\Tests\Unit;

use Flarum\Foundation\Paths;
use Illuminate\Database\ConnectionInterface;
use PHPUnit\Framework\TestCase;
use Ramon\MybbMigrator\Support\PrivateUploadBridge;

/**
 * Só a parte pura de useDirectory(): nenhum destes testes toca `available()`
 * nem qualquer método que fale com o banco (por isso o stub de
 * ConnectionInterface, que nunca precisa responder nada).
 *
 * Cobre exatamente os dois jeitos de dar errado que este código existe para
 * evitar: apontar o caminho canônico (o que ramon/dfs lê) para o lugar
 * errado, e mexer sozinho numa pasta que já tem dados.
 */
class PrivateUploadBridgeTest extends TestCase
{
    /** @var array<int, string> pastas temporárias a apagar no tearDown */
    private array $roots = [];

    protected function tearDown(): void
    {
        foreach ($this->roots as $root) {
            $this->rrmdir($root);
        }
        $this->roots = [];

        parent::tearDown();
    }

    private function bridge(): array
    {
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mm-private-' . bin2hex(random_bytes(6));
        $storage = $root . '/storage';
        mkdir($storage, 0775, true);
        $this->roots[] = $root;

        $paths = new Paths(['base' => $root, 'public' => $root . '/public', 'storage' => $storage]);

        return [new PrivateUploadBridge($this->stubConnection(), $paths), $root];
    }

    private function stubConnection(): ConnectionInterface
    {
        return new class implements ConnectionInterface {
            public function table($table, $as = null)
            {
                throw new \RuntimeException('not used');
            }
            public function raw($value)
            {
                throw new \RuntimeException('not used');
            }
            public function selectOne($query, $bindings = [], $useReadPdo = true)
            {
                throw new \RuntimeException('not used');
            }
            public function scalar($query, $bindings = [], $useReadPdo = true)
            {
                throw new \RuntimeException('not used');
            }
            public function select($query, $bindings = [], $useReadPdo = true, array $fetchUsing = [])
            {
                throw new \RuntimeException('not used');
            }
            public function cursor($query, $bindings = [], $useReadPdo = true, array $fetchUsing = [])
            {
                throw new \RuntimeException('not used');
            }
            public function insert($query, $bindings = [])
            {
                throw new \RuntimeException('not used');
            }
            public function update($query, $bindings = [])
            {
                throw new \RuntimeException('not used');
            }
            public function delete($query, $bindings = [])
            {
                throw new \RuntimeException('not used');
            }
            public function statement($query, $bindings = [])
            {
                throw new \RuntimeException('not used');
            }
            public function affectingStatement($query, $bindings = [])
            {
                throw new \RuntimeException('not used');
            }
            public function unprepared($query)
            {
                throw new \RuntimeException('not used');
            }
            public function prepareBindings(array $bindings)
            {
                throw new \RuntimeException('not used');
            }
            public function transaction(\Closure $callback, $attempts = 1)
            {
                throw new \RuntimeException('not used');
            }
            public function beginTransaction()
            {
                throw new \RuntimeException('not used');
            }
            public function commit()
            {
                throw new \RuntimeException('not used');
            }
            public function rollBack()
            {
                throw new \RuntimeException('not used');
            }
            public function transactionLevel()
            {
                throw new \RuntimeException('not used');
            }
            public function pretend(\Closure $callback)
            {
                throw new \RuntimeException('not used');
            }
            public function getDatabaseName()
            {
                throw new \RuntimeException('not used');
            }
        };
    }

    private function rrmdir(string $dir): void
    {
        if (is_link($dir)) {
            @unlink($dir);

            return;
        }
        // Windows não marca junção como is_link(); rmdir() a remove sem
        // seguir o alvo de qualquer forma — tentamos antes de recursar.
        if (! is_dir($dir)) {
            return;
        }
        if (@rmdir($dir)) {
            return;
        }

        foreach ((array) @scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            if (is_link($path)) {
                @unlink($path);
            } elseif (is_dir($path)) {
                $this->rrmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    public function test_no_override_by_default(): void
    {
        [$bridge] = $this->bridge();

        $this->assertNull($bridge->realDirectoryHint());
        $this->assertNull($bridge->directoryNotice());
    }

    public function test_useDirectory_with_empty_string_is_the_same_as_no_override(): void
    {
        [$bridge] = $this->bridge();

        $bridge->useDirectory('   ');

        $this->assertNull($bridge->realDirectoryHint());
        $this->assertNull($bridge->directoryNotice());
    }

    /**
     * Digitar o próprio caminho padrão não é uma escolha — é o padrão escrito
     * por extenso. Não deve tentar criar link nenhum nem reclamar de nada.
     */
    public function test_choosing_the_default_path_itself_is_a_no_op(): void
    {
        [$bridge] = $this->bridge();

        $bridge->useDirectory($bridge->directoryHint());

        $this->assertNull($bridge->realDirectoryHint());
        $this->assertNull($bridge->directoryNotice());
    }

    /**
     * O caso principal: pasta canônica ainda não existe, o admin escolhe
     * outro lugar — vira link, e os bytes gravados por privatePath() saem lá.
     */
    public function test_useDirectory_links_the_canonical_path_to_the_chosen_folder(): void
    {
        [$bridge, $root] = $this->bridge();
        $real = $root . '/elsewhere/private-uploads';

        $bridge->useDirectory($real);
        $notice = $bridge->directoryNotice();

        if ($notice !== null) {
            // Ambiente sem permissão para criar link de diretório (comum sem
            // Modo desenvolvedor/elevação no Windows): confere que o aviso
            // pelo menos explica isso, e não finge cobertura que não rodou.
            $this->assertStringContainsString($bridge->directoryHint(), $notice);
            $this->markTestSkipped('sem permissão para link de diretório neste ambiente: ' . $notice);
        }

        $this->assertNull($notice);
        $this->assertSame($real, $bridge->realDirectoryHint());

        file_put_contents($bridge->privatePath('a.txt'), 'conteudo');

        $this->assertFileExists($real . DIRECTORY_SEPARATOR . 'a.txt');
        $this->assertSame('conteudo', file_get_contents($real . DIRECTORY_SEPARATOR . 'a.txt'));
        // O caminho canônico enxerga o mesmo arquivo através do link.
        $this->assertFileExists($bridge->directoryHint() . DIRECTORY_SEPARATOR . 'a.txt');
    }

    /**
     * Chamar de novo com o MESMO destino não deve recriar nada nem reclamar
     * — é exatamente o que acontece a cada run do comando.
     */
    public function test_useDirectory_is_idempotent(): void
    {
        [$bridge, $root] = $this->bridge();
        $real = $root . '/elsewhere/private-uploads';

        $bridge->useDirectory($real);
        if ($bridge->directoryNotice() !== null) {
            $this->markTestSkipped('link/junção indisponível neste ambiente');
        }

        $bridge->useDirectory($real);

        $this->assertNull($bridge->directoryNotice());
        $this->assertSame($real, $bridge->realDirectoryHint());
    }

    /**
     * Pasta canônica já existe com arquivos (instalação antiga, sem link):
     * nunca mexe sozinho — devolve um aviso explicando o que fazer à mão, e
     * os arquivos originais continuam exatamente onde estavam.
     */
    public function test_existing_canonical_directory_with_files_is_never_touched(): void
    {
        [$bridge, $root] = $this->bridge();
        $canonical = $bridge->directoryHint();

        mkdir($canonical, 0775, true);
        file_put_contents($canonical . DIRECTORY_SEPARATOR . 'existing.txt', 'dado de producao');

        $bridge->useDirectory($root . '/elsewhere');

        $notice = $bridge->directoryNotice();
        $this->assertNotNull($notice);
        $this->assertStringContainsString($canonical, $notice);

        $this->assertFileExists($canonical . DIRECTORY_SEPARATOR . 'existing.txt');
        $this->assertSame('dado de producao', file_get_contents($canonical . DIRECTORY_SEPARATOR . 'existing.txt'));
    }

    public function test_directory_hint_is_unaffected_by_an_override(): void
    {
        [$bridge, $root] = $this->bridge();
        $default = $bridge->directoryHint();

        $bridge->useDirectory($root . '/elsewhere');

        // O caminho que quem lê o padrão (ramon/dfs) usa nunca muda — é essa
        // a garantia inteira do link.
        $this->assertSame($default, $bridge->directoryHint());
    }
}
