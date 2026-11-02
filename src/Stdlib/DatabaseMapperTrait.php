<?php declare(strict_types=1);

namespace Mapper\Stdlib;

use Mapper\Api\Representation\MapperRepresentation;

/**
 * Read a mapping of the database from a reference "mapping:5" (by id) or
 * "mapping:My mapping" (by label).
 *
 * Requires the property "api" (Omeka api manager).
 */
trait DatabaseMapperTrait
{
    protected function isDatabaseReference(string $reference): bool
    {
        return strncmp($reference, 'mapping:', 8) === 0;
    }

    protected function readDatabaseMapper(string $reference): ?MapperRepresentation
    {
        if (!$this->api || !$this->isDatabaseReference($reference)) {
            return null;
        }
        $identifier = substr($reference, 8);
        try {
            return ctype_digit($identifier)
                ? $this->api->read('mappers', ['id' => (int) $identifier])->getContent()
                : ($this->api->search('mappers', ['label' => $identifier, 'limit' => 1])->getContent()[0] ?? null);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
