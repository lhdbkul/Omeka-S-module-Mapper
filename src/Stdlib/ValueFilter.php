<?php declare(strict_types=1);

namespace Mapper\Stdlib;

use Laminas\Log\Logger;

/**
 * Apply the filters of the patterns outside of the mapper, for example to the
 * params of a mapping.
 */
class ValueFilter
{
    use FilterTrait;

    /**
     * @var Logger|null
     */
    protected $logger;

    /**
     * Config of the mapping, for the named tables.
     *
     * @var MapperConfig|null
     */
    protected $mapperConfig;

    /**
     * @var \Laminas\I18n\Translator\TranslatorInterface|null
     */
    protected $translator;

    public function __construct(?Logger $logger = null, ?MapperConfig $mapperConfig = null)
    {
        $this->logger = $logger;
        $this->mapperConfig = $mapperConfig;
    }

    /**
     * Apply a filter with its arguments, like "split('/')".
     *
     * @return mixed A string, or an array for some filters like "split".
     */
    public function apply($value, string $filter)
    {
        return $this->processFilter($value, $filter);
    }
}
