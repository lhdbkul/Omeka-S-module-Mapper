<?php declare(strict_types=1);

namespace Mapper\Api\Representation;

use Omeka\Api\Representation\AbstractEntityRepresentation;

class MapperRepresentation extends AbstractEntityRepresentation
{
    public function getControllerName()
    {
        return 'mapper';
    }

    public function getJsonLdType()
    {
        return 'o-module-mapper:Mapper';
    }

    public function getJsonLd()
    {
        $owner = $this->owner();

        $getDateTimeJsonLd = function (?\DateTime $dateTime): ?array {
            return $dateTime
                ? [
                    '@value' => $dateTime->format('c'),
                    '@type' => 'http://www.w3.org/2001/XMLSchema#dateTime',
                ]
                : null;
        };

        return [
            'o:id' => $this->id(),
            'o:owner' => $owner ? $owner->getReference()->jsonSerialize() : null,
            'o:label' => $this->label(),
            'o-module-mapper:mapping' => $this->mapping(),
            'o:created' => $getDateTimeJsonLd($this->resource->getCreated()),
            'o:modified' => $getDateTimeJsonLd($this->resource->getModified()),
        ];
    }

    protected function formatDateTime(?\DateTime $dateTime): ?array
    {
        return $dateTime
            ? [
                '@value' => $dateTime->format('c'),
                '@type' => 'http://www.w3.org/2001/XMLSchema#dateTime',
            ]
            : null;
    }

    public function owner(): ?\Omeka\Api\Representation\UserRepresentation
    {
        $owner = $this->resource->getOwner();
        return $owner
            ? $this->getAdapter('users')->getRepresentation($owner)
            : null;
    }

    public function label(): string
    {
        return $this->resource->getLabel();
    }

    public function mapping(): string
    {
        return $this->resource->getMapping();
    }

    public function created(): \DateTime
    {
        return $this->resource->getCreated();
    }

    public function modified(): ?\DateTime
    {
        return $this->resource->getModified();
    }

    public function adminUrl($action = null, $canonical = false)
    {
        $url = $this->getViewHelper('Url');
        return $url(
            'admin/mapper/id',
            [
                'id' => $this->id(),
                'action' => $action,
            ],
            ['force_canonical' => $canonical]
        );
    }
}
