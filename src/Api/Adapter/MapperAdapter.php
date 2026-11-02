<?php declare(strict_types=1);

namespace Mapper\Api\Adapter;

use Doctrine\ORM\QueryBuilder;
use Mapper\Api\Representation\MapperRepresentation;
use Mapper\Entity\Mapper;
use Omeka\Api\Adapter\AbstractEntityAdapter;
use Omeka\Api\Request;
use Omeka\Entity\EntityInterface;
use Omeka\Stdlib\ErrorStore;

class MapperAdapter extends AbstractEntityAdapter
{
    protected $sortFields = [
        'id' => 'id',
        'label' => 'label',
        'owner_id' => 'owner',
        'created' => 'created',
        'modified' => 'modified',
    ];

    protected $scalarFields = [
        'id' => 'id',
        'label' => 'label',
        'owner' => 'owner',
        'mapping' => 'mapping',
        'created' => 'created',
        'modified' => 'modified',
    ];

    public function getResourceName()
    {
        return 'mappers';
    }

    public function getRepresentationClass()
    {
        return MapperRepresentation::class;
    }

    public function getEntityClass()
    {
        return Mapper::class;
    }

    public function buildQuery(QueryBuilder $qb, array $query): void
    {
        $expr = $qb->expr();

        if (isset($query['label'])) {
            $qb->andWhere($expr->eq(
                'omeka_root.label',
                $this->createNamedParameter($qb, $query['label'])
            ));
        }

        if (isset($query['owner_id']) && is_numeric($query['owner_id'])) {
            $userAlias = $this->createAlias();
            $qb
                ->innerJoin('omeka_root.owner', $userAlias)
                ->andWhere($expr->eq(
                    $userAlias . '.id',
                    $this->createNamedParameter($qb, $query['owner_id'])
                ));
        }
    }

    /**
     * Only the label and the mapping are hydrated: the owner and the dates are
     * managed by the api.
     */
    public function hydrate(Request $request, EntityInterface $entity, ErrorStore $errorStore): void
    {
        $data = $request->getContent();

        if ($this->shouldHydrate($request, 'o:label')) {
            $entity->setLabel(trim((string) ($data['o:label'] ?? '')));
        }

        // The prefix of the mapping is not always the same in the callers. The
        // mapping is kept when it is not set in an update.
        $mappingKeys = array_intersect(['o-module-mapper:mapping', 'o-mapper:mapping', 'o:mapping'], array_keys($data));
        if ($mappingKeys) {
            $mapping = $data[reset($mappingKeys)];
            $entity->setMapping(is_scalar($mapping) ? (string) $mapping : null);
        }

        $this->hydrateOwner($request, $entity);
        $this->updateTimestamps($request, $entity);
    }

    public function validateEntity(EntityInterface $entity, ErrorStore $errorStore): void
    {
        $label = $entity->getLabel();
        if (!trim($label)) {
            $errorStore->addError('o:label', 'The label cannot be empty.'); // @translate
        }
        if (!$this->isUnique($entity, ['label' => $label])) {
            $errorStore->addError('o:label', 'The label is already taken.'); // @translate
        }
    }
}
