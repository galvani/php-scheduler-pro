<?php declare(strict_types=1);

namespace Scheduling\Tests\Bridge\Symfony;

use PDO;
use Redis;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class ServiceVisibility implements CompilerPassInterface
{
    /** @var list<string> */
    public array $publicIds = [];

    /** @param list<string> $expose ids made public for the test after visibility is recorded */
    public function __construct(private readonly array $expose = []) {}

    /**
     * Modifies the container before it is dumped to PHP code.
     */
    public function process(ContainerBuilder $container): void
    {
        foreach ($container->getDefinitions() as $id => $definition) {
            if ($this->owned($id) && $definition->isPublic()) {
                $this->publicIds[] = $id;
            }
        }
        foreach ($container->getAliases() as $id => $alias) {
            if ($this->owned($id) && $alias->isPublic()) {
                $this->publicIds[] = $id;
            }
        }
        foreach ($this->expose as $id) {
            $container->getDefinition($id)->setPublic(true);
        }
    }

    private function owned(string $id): bool
    {
        return str_starts_with($id, 'Scheduling\\') || str_starts_with($id, 'Crunz\\')
            || str_starts_with($id, 'scheduling.') || Redis::class === $id || PDO::class === $id;
    }
}
